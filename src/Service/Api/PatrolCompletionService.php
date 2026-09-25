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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Service\PatrolCorridorQueue;

/**
 * `POST /api/patrols/{uuid}/complete` — API-CONTRACT.md §9.
 *
 * This is the call that flips a patrol into the module's map, library and
 * calendar, and it is what the phone treats as *synced*. So it verifies before
 * it agrees: the module must actually hold what the phone said it would send.
 *
 * Verifying matters because the failure it prevents is invisible. A patrol
 * published with two of its five photographs missing looks complete to everyone
 * who reads it — nothing on the screen says otherwise — and the missing evidence
 * is only noticed when somebody needs it. Refusing with `incomplete_patrol` and
 * the missing ids costs the phone one more sync; not refusing costs the record.
 */
final class PatrolCompletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolCorridorQueue $corridors,
    ) {
    }

    /**
     * Mark the patrol complete, or say exactly what is still missing.
     *
     * @param array<string, mixed> $data the decoded request body — empty for an
     *                                   ordinary complete, or carrying `status:
     *                                   "discarded"` + `discardReason`
     *
     * @return array{0: Patrol, 1: bool} [patrol, wasAlreadySettled]
     *
     * @throws PatrolApiException
     */
    public function complete(Patrol $patrol, array $data = []): array
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        $discardReason = Payload::discardReason($data, (string) $patrol->getClientUuid()?->toRfc4122());
        if (null !== $discardReason) {
            return $this->discard($patrol, $discardReason);
        }

        // Already settled: a re-sent `complete` is success and changes nothing,
        // the same as every other repeated part (§1). A patrol the ranger has
        // since discarded stays discarded — the discard is the later decision,
        // and a queued `complete` catching up with it must not undo it.
        if (PatrolStatusEnum::Complete === $patrol->getStatus() || $patrol->isDiscarded()) {
            return [$patrol, true];
        }

        $missing = $this->missingParts($patrol);
        if ([] !== $missing) {
            throw PatrolApiException::incompletePatrol($missing);
        }

        $patrol->setStatus(PatrolStatusEnum::Complete);
        $this->entityManager->flush();

        // THE COMPLETION IS THE EVENT, recorded now. Buffering the track grows
        // with the track, so it is the worker's: this sends the message and
        // answers the handset.
        $this->corridors->settled($patrol);

        return [$patrol, false];
    }

    /**
     * Close the patrol by throwing it away instead.
     *
     * NOTHING IS VERIFIED on this path, and that is the whole point of writing
     * it separately. The completeness check below exists to stop a patrol being
     * PUBLISHED with its evidence still on a handset — but a discarded patrol is
     * published nowhere: it is counted in no KPI, drawn on no map, and headed
     * for deletion. Demanding its missing photographs first would strand it as
     * `recording` forever — never presentable, never purgeable — which is the
     * one state a record must not be able to reach.
     *
     * Nor is there any floor on what a discarded patrol may contain. Forty
     * seconds and three fixes is accepted exactly as a full day is: the ranger
     * has said this one does not count, and the module's job is to record that,
     * not to second-guess how small a mistake is allowed to be.
     *
     * @return array{0: Patrol, 1: bool} [patrol, wasAlreadyDiscarded]
     */
    private function discard(Patrol $patrol, string $reason): array
    {
        if ($patrol->isDiscarded()) {
            return [$patrol, true];
        }

        $patrol->discard($reason);
        $this->entityManager->flush();

        return [$patrol, false];
    }

    /**
     * What the phone promised but has not delivered.
     *
     * Only PHOTOS can be checked, and honestly so: `photoCount` is the one
     * expectation the contract has the phone declare in advance (§6). It never
     * states a total fix count or observation count anywhere, so there is no
     * number to compare those against — and inventing a threshold ("a patrol
     * should have at least N fixes") would reject real patrols recorded under a
     * canopy. See the note in API-CONTRACT.md.
     *
     * @return array<string, mixed> keyed for the app to re-queue from
     */
    private function missingParts(Patrol $patrol): array
    {
        $observations = [];

        foreach ($patrol->getObservations() as $observation) {
            if ($observation->hasAllPhotos()) {
                continue;
            }

            $observations[] = [
                'observationUuid' => $observation->getClientUuid()?->toRfc4122() ?? $observation->getUuid()->toRfc4122(),
                'expectedPhotos' => $observation->getPhotoCount(),
                'heldPhotos' => $observation->heldPhotoCount(),
            ];
        }

        return [] === $observations ? [] : ['missingPhotos' => $observations];
    }
}
