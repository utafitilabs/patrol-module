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

namespace Uhifadhi\Patrol\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Access\PatrolConcerns;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Security\PatrolWriteVoter;

/**
 * The three questions every sync endpoint asks before it does anything: who is
 * calling, which patrol, and what did they send.
 *
 * Answered in one place so no endpoint can quietly skip a step. Every one of
 * these routes WRITES field records, so every one requires the same permission
 * the entry flow does — `patrols.record`, declared by this module and
 * granted by the host. A module that authenticated its API differently from its
 * own UI would be two security models pretending to be one.
 */
final class PatrolApiContext
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly PatrolRepository $patrols,
        private readonly ObservationRepository $observations,
        private readonly AreaOfInterestRepository $areas,
    ) {
    }

    /**
     * The signed-in field worker, once confirmed they may record patrols
     * ON THIS GROUND.
     *
     * THE AREA IS A REQUIRED ARGUMENT, and it is the whole point of the
     * method's shape. `patrols.record` asked with no subject means "no area
     * in context", which any placement reaching any ground at all satisfies —
     * so a handset whose ranger is placed at one area could write a patrol
     * into another. Every caller resolves the ground first and names it here.
     *
     * NULL IS ALLOWED AND MEANS "THERE IS NO SUCH GROUND": the uri named a
     * patrol this server does not have, or the body named an area it does not
     * know. The gate is still asked, and is still the first thing to refuse,
     * because a caller who may not record must be told 403 rather than handed
     * a 404 that says which uuids exist. The caller then throws the 404 or the
     * 422 it was going to throw anyway.
     *
     * 403, not 401: the token is valid and the caller is known — they simply do
     * not hold this permission, and the app shows a different thing for each
     * (§10).
     *
     * @throws PatrolApiException
     */
    public function requireRecorder(?AreaOfInterest $area): UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof UserInterface) {
            throw new PatrolApiException(401, 'unauthorized', 'Sign in again.');
        }

        if (!$this->authorizationChecker->isGranted((string) Grant::of(PatrolConcerns::PATROLS, Verb::Record), $area)) {
            throw PatrolApiException::forbidden();
        }

        return $user;
    }

    /**
     * The patrol a URI names, or null where this server has never seen that
     * uuid — so a caller can name the ground before it asks the gate, and
     * still answer 404 afterwards.
     */
    /**
     * THE PATROL IS THE CALLER'S TO WRITE TO, or a refusal (ruled 30 Sep, #67):
     * its lead, or the tiers. Asked after the record gate, so a caller who may
     * not record at all still learns nothing about which patrols exist.
     */
    public function requireWriter(Patrol $patrol): void
    {
        if (!$this->authorizationChecker->isGranted(PatrolWriteVoter::WRITE, $patrol)) {
            throw PatrolApiException::forbidden();
        }
    }

    public function findPatrol(string $uuid): ?Patrol
    {
        return Uuid::isValid($uuid)
            ? $this->patrols->findOneByClientUuid(Uuid::fromString($uuid))
            : null;
    }

    /**
     * The area an id names, or null where it is not one this server issued.
     * It does not throw: the services that consume the id own the 422 and its
     * wording, and this exists only so the gate can be asked with the ground.
     */
    public function findArea(string $areaId): ?AreaOfInterest
    {
        return Uuid::isValid($areaId)
            ? $this->areas->findOneBy(['uuid' => Uuid::fromString($areaId)])
            : null;
    }

    /**
     * The patrol a URI names, addressed by the CLIENT's uuid — the only
     * identifier the phone has ever seen for it.
     *
     * @throws PatrolApiException
     */
    public function patrol(string $uuid): Patrol
    {
        return $this->findPatrol($uuid) ?? throw PatrolApiException::unknownPatrol($uuid);
    }

    /** @throws PatrolApiException */
    public function observation(string $uuid): Observation
    {
        return $this->findObservation($uuid) ?? throw PatrolApiException::unknownObservation($uuid);
    }

    /** The same, without the refusal — see {@see self::findPatrol()}. */
    public function findObservation(string $uuid): ?Observation
    {
        return Uuid::isValid($uuid)
            ? $this->observations->findOneByClientUuid(Uuid::fromString($uuid))
            : null;
    }

    /**
     * The decoded JSON body.
     *
     * Read from the raw request rather than deserialized into a DTO: these
     * payloads are batches of heterogeneous rows whose validity rules are
     * per-field and contract-specific ({@see Payload}), and a DTO layer in
     * between would only be a second place for the field names to drift.
     *
     * @return array<string, mixed>
     *
     * @throws PatrolApiException
     */
    public function body(): array
    {
        $request = $this->request();
        $content = $request->getContent();

        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw PatrolApiException::invalidPayload('The request body is not valid JSON.', ['reason' => $exception->getMessage()]);
        }

        if (!\is_array($decoded)) {
            throw PatrolApiException::invalidPayload('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /** @throws PatrolApiException */
    public function request(): Request
    {
        return $this->requestStack->getCurrentRequest()
            ?? throw PatrolApiException::invalidPayload('There is no request to read.');
    }

    /**
     * The uuid from the URI. api-platform hands uriVariables to the processor;
     * this only turns a missing one into the contract's own error rather than a
     * type error.
     *
     * @param array<string, mixed> $uriVariables
     *
     * @throws PatrolApiException
     */
    public function uriUuid(array $uriVariables, string $key = 'uuid'): string
    {
        $value = $uriVariables[$key] ?? null;

        return \is_string($value) && '' !== $value
            ? $value
            : throw PatrolApiException::invalidPayload(\sprintf('The URI is missing "%s".', $key));
    }
}
