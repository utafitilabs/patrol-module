<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Patrol\Service\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;

/**
 * `POST /api/patrols` — API-CONTRACT.md §4. The first part of every upload, and
 * the one the rest hangs off.
 *
 * The whole service is one promise: **the same clientUuid always yields the same
 * patrol**. The phone retries aggressively and cannot tell a lost response from
 * a lost request, so a second create is normal traffic, not an error — and if it
 * ever produced a second patrol, a day's effort would be double-counted in every
 * coverage figure the module reports.
 */
final class PatrolUpsertService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolRepository $patrols,
        private readonly RangerResolver $rangers,
        private readonly PatrolVocabularyService $vocabulary,
    ) {
    }

    /**
     * Create the patrol, or hand back the one this clientUuid already made.
     *
     * @param array<string, mixed> $data the decoded request body
     *
     * @return array{0: Patrol, 1: bool} [patrol, wasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function upsert(array $data, UserInterface $recorder): array
    {
        $clientUuid = Payload::uuid($data, 'clientUuid');

        $existing = $this->patrols->findOneByClientUuid($clientUuid);
        if ($existing instanceof Patrol) {
            return [$this->acknowledgeExisting($existing), true];
        }

        $area = $this->resolveArea(Payload::requiredString($data, 'areaId'));

        /*
         * THE WIRE IS STILL STRINGS, and it stays that way — the handset sends
         * `type` and `stationId` as words, exactly as the contract writes them.
         * What changed is what happens on this side of the door: each word is
         * resolved to one of the AREA's own records.
         *
         * NEITHER IS EVER REFUSED. The contract names no error code for an
         * unknown type and none for an unknown station, and refusing would throw
         * away a real patrol because a settings screen and an app build
         * disagreed about a word — a stray word shows as itself on the page, a
         * discarded patrol is gone. An observation's category and sub-category
         * are read the same way, for the same reason
         * ({@see ObservationSyncService}). So an unheard-of word becomes a RETIRED
         * record: the patrol is kept, and the disagreement is visible on the
         * Patrol types or Stations section for somebody to rename or reactivate.
         */
        $patrol = new Patrol($area, $this->vocabulary->resolveType($area, Payload::requiredString($data, 'type')))
            ->setClientUuid($clientUuid)
            // Recording, not Complete: the parts are still arriving, and until
            // `complete` the module must not draw this patrol anywhere.
            ->setStatus(PatrolStatusEnum::Recording)
            ->setSource(PatrolSourceEnum::Api)
            ->setLead($recorder)
            // The area's station the handset named — or, where the area keeps
            // no such station, the word alone (§ stations: nothing is invented).
            ->setStationRecord($this->vocabulary->resolveStation($area, Payload::string($data, 'stationId')))
            ->setStationWord(Payload::string($data, 'stationId'))
            ->setStartedAt(Payload::timestamp($data, 'startedAt'))
            // Null is legal and meaningful: a live upload of a patrol still
            // under way (§4). It is not backfilled with "now".
            ->setEndedAt(Payload::timestamp($data, 'endedAt'))
            ->setDroneId(Payload::string($data, 'droneId'))
            ->setMission(Payload::string($data, 'mission'))
            ->setDeviceId(Payload::string($data, 'deviceId'))
            ->setAppVersion(Payload::string($data, 'appVersion'));

        $team = Payload::strings($data, 'team');
        $patrol->setTeamRangerIds($team)->setTeam($this->rangers->describe($team));

        /*
         * A patrol may ARRIVE discarded — the ranger threw it away before the
         * handset ever had signal, which is the common case for a false start.
         * It is stored exactly like any other, with no floor of any kind on its
         * size or duration: a forty-second, three-point patrol is a real thing
         * that really happened, and refusing it would leave the phone holding a
         * record it can never hand over and can never safely delete.
         *
         * The reason is mandatory here (Payload::discardReason), so a patrol
         * cannot enter the module discarded and mute.
         */
        $discardReason = Payload::discardReason($data, $clientUuid->toRfc4122());
        if (null !== $discardReason) {
            $patrol->discard($discardReason);
        }

        $this->entityManager->persist($patrol);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            /*
             * Two retries of the same create arriving at once. The unique index
             * on client_uuid is what actually enforces the contract's "exactly
             * once" — the SELECT above is only the fast path, and between it and
             * the INSERT another request can win. Losing that race is still
             * success: re-read and answer as a duplicate.
             */
            return [$this->rereadAfterRace($clientUuid), true];
        }

        return [$patrol, false];
    }

    /**
     * A patrol we already hold. Nothing is written — §1 says a repeated POST
     * changes nothing — but a patrol a person has since corrected in the web
     * module refuses the phone outright, because the phone's copy is stale and
     * applying it would undo the correction (§10).
     *
     * "Nothing is written" includes a `status` the re-send happens to carry. A
     * second POST is a RETRY, and a retry that could change a stored patrol
     * would make the endpoint's answer depend on whether the first response was
     * lost. Discarding a patrol the server already holds has its own two doors,
     * both of which say when it happened: a `discarded` event (§9A) or
     * `complete` with the discard on it (§9).
     *
     * @throws PatrolApiException
     */
    private function acknowledgeExisting(Patrol $patrol): Patrol
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        return $patrol;
    }

    /** @throws PatrolApiException */
    private function rereadAfterRace(Uuid $clientUuid): Patrol
    {
        $this->entityManager->clear();

        $patrol = $this->patrols->findOneByClientUuid($clientUuid)
            ?? throw PatrolApiException::invalidPayload('That patrol could not be stored.');

        return $this->acknowledgeExisting($patrol);
    }

    /**
     * The area is addressed by the uuid `/api/areas/mine` handed out. Unknown
     * ids are refused rather than defaulted: filing a patrol against the wrong
     * area would put rangers' effort on somebody else's map.
     *
     * @throws PatrolApiException
     */
    private function resolveArea(string $areaId): AreaOfInterest
    {
        if (!Uuid::isValid($areaId)) {
            throw new PatrolApiException(422, 'unknown_area', 'That area id is not one this server issued.', details: ['areaId' => $areaId]);
        }

        $area = $this->entityManager->getRepository(AreaOfInterest::class)
            ->findOneBy(['uuid' => Uuid::fromString($areaId)]);

        return $area instanceof AreaOfInterest
            ? $area
            : throw new PatrolApiException(422, 'unknown_area', 'No area is known by that id.', details: ['areaId' => $areaId]);
    }
}
