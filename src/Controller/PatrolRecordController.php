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

namespace Uhifadhi\Patrol\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Exception\InvalidGpxException;
use Uhifadhi\Patrol\Exception\InvalidPatrolTimesException;
use Uhifadhi\Patrol\Exception\MissingPatrolStartException;
use Uhifadhi\Patrol\Model\LoggedObservation;
use Uhifadhi\Patrol\Model\LoggedPatrol;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Service\PatrolDraftService;
use Uhifadhi\Patrol\Service\PatrolMapService;
use Uhifadhi\Patrol\Service\PatrolRecordingService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Upload\PatrolObservationPhotoTarget;
use Uhifadhi\Patrol\Upload\PatrolTrackTarget;

/**
 * THE ONE WAY A PATROL ENTERS THIS MODULE (settled design `log`).
 *
 * There used to be two screens — import a GPX, or log a patrol by hand — and
 * they were the same screen with one card missing. A patrol somebody walked with
 * a handset and a patrol somebody walked with a flat battery are the same
 * record; the only difference is whether step 1 was used. So there is one page,
 * with three steps on it, and `patrol_import` is a permanent redirect into it.
 *
 * EVERY FILE GOES THROUGH THE PLATFORM'S UPLOAD COMPONENT. The track's dropzone
 * and every evidence tile are `render_upload()`; this controller has no file
 * handling of its own, no multipart branch, and no base64 field carrying a
 * document back and forth. What it reads off a submission is the KEY the
 * component already got back.
 *
 * WHICH MEANS THE FILES ARRIVE FIRST. The page opens a {@see PatrolDraft} — a
 * row, minted server-side, that the two upload targets file against — and
 * carries its id in a hidden field. Saving re-homes everything the draft holds
 * under the patrol's own prefix; a page nobody saves is swept by
 * `patrol:purge-discarded` on the same retention window as a discarded patrol.
 *
 * Recording is the privilege, and GET requires it too: the page mints a draft
 * and the component draws a live upload endpoint, neither of which a reader who
 * cannot record a patrol should be handed.
 *
 * Enforced by #[IsGranted], which names the concern/verb pair on the route
 * itself so the access test can walk every route and hold it against the
 * declarations. The attribute is honoured by a listener in symfony/security-http
 * — a package this bundle keeps under require-dev — so it would enforce NOTHING
 * in a host without security; that is safe here and only here, because these
 * services are registered exclusively inside the SecurityBundle guard, so where
 * the listener is absent the route does not exist at all
 * (UhifadhiPatrolBundle::loadExtension()). A screen registered OUTSIDE that
 * guard may not rely on the attribute for the same reason.
 *
 * The subject is resolved by argument NAME: `subject: 'area'` is the $area the
 * route resolved, and the listener asks the checker with it.
 *
 * @see https://symfony.com/doc/current/security.html#access-control-in-controllers
 * @see vendor/symfony/security-http/EventListener/IsGrantedAttributeListener.php
 *
 * IT WRITES NO PATROL ITSELF. {@see PatrolRecordingService} is the write path
 * for the whole submission, track and observations and photographs together, and
 * is reachable without a browser — which is what lets demo content be seeded
 * through the door a person uses. The controller authorises, reads the form,
 * and responds.
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
// EVERY ROUTE BELOW BELONGS TO THIS MODULE, and says so: where an area has
// parked Patrols, the registry closes these routes before the controller runs.
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolRecordController
{
    /** The token the one submit carries — the design names it. */
    public const string CSRF_TOKEN_ID = 'patrol_log';

    /** How many observation grids a fresh page draws. The design shows one. */
    private const int FIRST_OBSERVATION = 1;

    /** A cap on the grids one submission may ask for, so a crafted form cannot. */
    private const int MAX_OBSERVATIONS = 50;

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolTypeRepository $types,
        private readonly StationRepository $stations,
        private readonly TaxonomyKindRepository $kinds,
        private readonly PatrolVocabularyService $vocabulary,
        private readonly PatrolMapService $plates,
        private readonly PatrolDraftService $drafts,
        private readonly PatrolRecordingService $recording,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    /**
     * THE RETIRED SCREEN. Importing a GPX is step 1 of logging a patrol now, so
     * the old address is a permanent redirect rather than a second door — a link
     * in somebody's notes, a bookmark, or a training slide still arrives
     * somewhere that works.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/import',
        name: 'patrol_import',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('patrols.record', subject: 'area')]
    public function import(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        return new RedirectResponse(
            $this->urlGenerator->generate('patrol_log', ['uuid' => $area->getUuidString()]),
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    /**
     * Log a patrol — PL·01 the track, PL·02 the details, PL·03 the observations,
     * one submit.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/log',
        name: 'patrol_log',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('patrols.record', subject: 'area')]
    public function log(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $draft = $this->drafts->reopen(
            $request->isMethod('POST') ? $request->request->getString('draft') : null,
            $area,
            $this->signedInPerson(),
        );

        $form = self::submittedDetails($request);
        $observations = self::submittedObservations($request);
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->denyUnlessTokenValid($request);

            // "+ Add observation" is a submit, not a clone. The page comes back
            // with one more grid, each addressed by its own ordinal — which is
            // what the upload target files against — and every file already
            // received is still on the draft, so nothing is lost by the
            // round trip. See docs/screens.md for why this beats cloning a
            // <template>: a cloned upload component would carry its sibling's
            // target and file a photograph against the wrong observation.
            if ($request->request->has('addObservation')) {
                $observations[] = self::blankObservation(\count($observations) + 1);
            } else {
                [$patrol, $error, $status] = $this->save($area, $draft, $request, $form, $observations);
                if (null !== $patrol) {
                    $this->addFlash($request, 'success', \sprintf('Patrol %s logged.', $patrol->getRef()));

                    return new RedirectResponse($this->urlGenerator->generate('patrol_show', [
                        'uuid' => $area->getUuidString(),
                        'patrol' => $patrol->getUuid()->toRfc4122(),
                    ]));
                }
            }
        }

        if ([] === $observations) {
            $observations = [self::blankObservation(self::FIRST_OBSERVATION)];
        }

        $track = $this->heldTrack($draft);

        return new Response(
            $this->twig->render('@UhifadhiPatrol/log/show.html.twig', [
                'area' => $area,
                'draft' => $draft->getUuid()->toRfc4122(),
                'token' => $this->csrf->getToken(self::CSRF_TOKEN_ID)->getValue(),
                'trackKind' => PatrolTrackTarget::KIND,
                'photoKind' => PatrolObservationPhotoTarget::KIND,
                'track' => $track,
                'types' => $this->offeredTypes($area),
                'stations' => array_values(array_filter($this->stations->findByArea($area), static fn (Station $s): bool => $s->isActive())),
                'kinds' => $this->kinds->forArea($area),
                'users' => $this->users(),
                'form' => $form,
                'observations' => $this->withHeldEvidence($draft, $observations),
                // A sketched route is offered only where step 1 was skipped — a
                // sketch beside a real track would be two answers to one
                // question — so the plate carries the area and nothing else.
                'map' => null === $track ? $this->plates->track(['boundary' => $area->getGeom(), 'track' => null]) : null,
                'error' => $error,
            ]),
            $status,
        );
    }

    /**
     * The form's own rules, then the record's.
     *
     * @param array{type: string, station: ?string, lead: ?int, team: ?string, note: ?string, startedAt: ?string, endedAt: ?string, distanceKm: ?float} $form
     * @param list<array{ordinal: int, kind: string, subcategory: string, time: ?string, note: ?string, photoKeys: list<string>}>                       $observations
     *
     * @return array{0: ?Patrol, 1: ?string, 2: int} the patrol, or the sentence to draw and the
     *                                               status to draw it with
     */
    private function save(
        AreaOfInterest $area,
        PatrolDraft $draft,
        Request $request,
        array $form,
        array $observations,
    ): array {
        // What the FORM can answer for: a word the deployment does not use.
        // Whether the two times make a patrol is the record's own rule and is
        // settled by the service.
        $type = $this->chosenType($area, $form['type']);
        if (!$type instanceof PatrolType) {
            return [null, 'Choose a patrol type.', Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        $trackKey = self::trimmedOrNull($request->request->getString('trackKey'));

        try {
            $patrol = $this->recording->log(
                $area,
                $draft,
                new LoggedPatrol(
                    type: $type,
                    startedAt: self::parseMoment($form['startedAt']),
                    endedAt: self::parseMoment($form['endedAt']),
                    station: $this->chosenStation($area, $form['station']),
                    lead: $this->lead($form['lead']),
                    team: $form['team'],
                    note: $form['note'],
                    distanceKm: $form['distanceKm'],
                    trackKey: $trackKey,
                    observations: $this->resolveObservations($area, $observations),
                ),
                $this->signedInPerson(),
            );
        } catch (MissingPatrolStartException) {
            return [null, 'A patrol needs the time it started.', Response::HTTP_UNPROCESSABLE_ENTITY];
        } catch (InvalidPatrolTimesException) {
            return [null, 'A patrol cannot end before it started.', Response::HTTP_UNPROCESSABLE_ENTITY];
        } catch (InvalidGpxException $invalid) {
            return [null, $invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        return [$patrol, null, Response::HTTP_OK];
    }

    /**
     * PL·03's two chip rows resolved to the ONE wire code an observation stores:
     * the sub-category's where the person chose one under a kind this area
     * offers, and the kind's own where the kind has no sub-categories or none
     * was chosen.
     *
     * A code the area does not offer records nothing at all — the chips only
     * ever submit a live code, so anything else is a stale form rather than
     * somebody's intent, and the same reading the station chips already take.
     *
     * @param list<array{ordinal: int, kind: string, subcategory: string, time: ?string, note: ?string, photoKeys: list<string>}> $submitted
     *
     * @return list<LoggedObservation>
     */
    private function resolveObservations(AreaOfInterest $area, array $submitted): array
    {
        $offered = $this->kinds->forArea($area);

        $resolved = [];
        foreach ($submitted as $row) {
            $kind = null;
            foreach ($offered as $candidate) {
                if ($candidate->isActive() && $candidate->getCode() === $row['kind']) {
                    $kind = $candidate;
                    break;
                }
            }

            $code = $kind instanceof TaxonomyKind ? $kind->getCode() : '';
            if ($kind instanceof TaxonomyKind) {
                foreach ($kind->getSubcategories() as $sub) {
                    if ($sub->isActive() && $sub->getCode() === $row['subcategory']) {
                        $code = $sub->getCode();
                        break;
                    }
                }
            }

            $resolved[] = new LoggedObservation(
                ordinal: $row['ordinal'],
                category: $code,
                at: $row['time'],
                note: $row['note'],
                photoKeys: $row['photoKeys'],
            );
        }

        return $resolved;
    }

    /**
     * The grids, each with the files the draft is already holding for it — so a
     * page that comes back after "+ Add observation" redraws every tile that was
     * already there.
     *
     * @param list<array{ordinal: int, kind: string, subcategory: string, time: ?string, note: ?string, photoKeys: list<string>}> $observations
     *
     * @return list<array{ordinal: int, kind: string, subcategory: string, time: ?string, note: ?string, photoKeys: list<string>, evidence: list<PatrolDraftFile>}>
     */
    private function withHeldEvidence(PatrolDraft $draft, array $observations): array
    {
        $drawn = [];
        foreach ($observations as $row) {
            $slot = PatrolDraftFile::observationSlot($row['ordinal']);
            $evidence = [];
            foreach ($draft->getFiles() as $file) {
                if ($file->getSlot() === $slot) {
                    $evidence[] = $file;
                }
            }
            $drawn[] = [...$row, 'evidence' => $evidence];
        }

        return $drawn;
    }

    /** The track this draft is holding, if any — PL·01's "Stored" state. */
    private function heldTrack(PatrolDraft $draft): ?PatrolDraftFile
    {
        foreach ($draft->getFiles() as $file) {
            if (PatrolDraftFile::TRACK_SLOT === $file->getSlot()) {
                return $file;
            }
        }

        return null;
    }

    /**
     * One submit, one token. The uploads carry the STORAGE's token instead —
     * one token for that whole surface, minted by the component — because they
     * are that bundle's endpoint and not this screen's.
     */
    private function denyUnlessTokenValid(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('That submission did not carry a valid token.');
        }
    }

    /**
     * What the form contributed — everything a track cannot know. Read once, so
     * a re-rendered page (an added observation, or an error) shows back what was
     * typed.
     *
     * @return array{type: string, station: ?string, lead: ?int, team: ?string, note: ?string, startedAt: ?string, endedAt: ?string, distanceKm: ?float}
     */
    private static function submittedDetails(Request $request): array
    {
        $distance = trim($request->request->getString('distanceKm'));

        return [
            'type' => $request->request->getString('type'),
            'station' => self::trimmedOrNull($request->request->getString('station')),
            'lead' => self::optionalId($request->request->getString('lead')),
            'team' => self::trimmedOrNull($request->request->getString('team')),
            'note' => self::trimmedOrNull($request->request->getString('note')),
            'startedAt' => self::trimmedOrNull($request->request->getString('startedAt')),
            'endedAt' => self::trimmedOrNull($request->request->getString('endedAt')),
            'distanceKm' => is_numeric($distance) ? (float) $distance : null,
        ];
    }

    /**
     * PL·03's records as the form gave them, keyed by the ordinal that also
     * addresses their evidence grid.
     *
     * @return list<array{ordinal: int, kind: string, subcategory: string, time: ?string, note: ?string, photoKeys: list<string>}>
     */
    private static function submittedObservations(Request $request): array
    {
        /** @var array<mixed> $submitted */
        $submitted = $request->request->all('observations');

        $rows = [];
        foreach ($submitted as $ordinal => $row) {
            if (\count($rows) >= self::MAX_OBSERVATIONS) {
                break;
            }
            if (!\is_array($row) || !is_numeric($ordinal) || (int) $ordinal < 1) {
                continue;
            }

            $keys = [];
            /** @var array<mixed> $posted */
            $posted = \is_array($row['photoKeys'] ?? null) ? $row['photoKeys'] : [];
            foreach ($posted as $key) {
                if (\is_string($key) && '' !== $key) {
                    $keys[] = $key;
                }
            }

            $rows[] = [
                'ordinal' => (int) $ordinal,
                'kind' => \is_string($row['kind'] ?? null) ? $row['kind'] : '',
                'subcategory' => \is_string($row['subcategory'] ?? null) ? $row['subcategory'] : '',
                'time' => self::trimmedOrNull(\is_string($row['time'] ?? null) ? $row['time'] : ''),
                'note' => self::trimmedOrNull(\is_string($row['note'] ?? null) ? $row['note'] : ''),
                'photoKeys' => $keys,
            ];
        }

        return $rows;
    }

    /** @return array{ordinal: int, kind: string, subcategory: string, time: null, note: null, photoKeys: list<string>} */
    private static function blankObservation(int $ordinal): array
    {
        return [
            'ordinal' => $ordinal,
            'kind' => '',
            'subcategory' => '',
            'time' => null,
            'note' => null,
            'photoKeys' => [],
        ];
    }

    private static function trimmedOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }

    /**
     * AN OPTIONAL RELATION'S ID, OR NULL — AND NEVER A 400.
     *
     * `lead` is a select whose first option is the design's own "—", which posts
     * an EMPTY STRING. `InputBag::getInt()` cannot read one: it filters with
     * FILTER_VALIDATE_INT and throws a BadRequestException on anything that is
     * not a whole number, so the one row the design draws as the default answered
     * `400 Input value "lead" cannot be converted to "int"` instead of recording
     * a patrol with no lead. The entity has always allowed null; the reading had
     * not.
     *
     * So the value is read as the TEXT it is and converted only where it really
     * is an id. Everything else — an empty option, a blank, a word, a negative,
     * a stale option from a page held open — is the same fact as "nobody was
     * named", which is exactly the reading the station chips already take: the
     * control only ever submits a live value, so anything else is a stale form
     * rather than somebody's intent, and a screen must not answer a person's
     * choice with a protocol error.
     *
     * @see vendor/symfony/http-foundation/InputBag.php — getInt()
     */
    private static function optionalId(string $value): ?int
    {
        $trimmed = trim($value);

        return ctype_digit($trimmed) && (int) $trimmed > 0 ? (int) $trimmed : null;
    }

    /** A datetime-local value ("2026-08-22T05:55"), or null when absent/unreadable. */
    private static function parseMoment(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function lead(?int $id): ?UserInterface
    {
        return null !== $id ? $this->entityManager->getRepository(UserInterface::class)->find($id) : null;
    }

    /**
     * The signed-in account, narrowed to the platform's person. Null where the
     * installation's account class is not one — a draft nobody owns takes no
     * files, which is the safe direction.
     */
    private function signedInPerson(): ?UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * The people the deployment can name as a lead. `lead` is a relation to a
     * person, never free text — the detail screen prints "A. Alpha" from the
     * record. The repository is asked for by the CONTRACT, which the
     * installation has resolved to its own account class; this module never
     * learns what that class is.
     *
     * @return list<UserInterface>
     */
    private function users(): array
    {
        return $this->entityManager->getRepository(UserInterface::class)
            ->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);
    }

    /**
     * The types this area offers a ranger — its own, retired ones left out.
     *
     * An area nobody has configured yet is SEEDED from the installation's
     * `patrol.types` on the way in, which is the one thing that configuration
     * is still for: without it a brand-new area's log form would offer no type
     * at all and a patrol could not be recorded until somebody visited Settings.
     *
     * @return list<PatrolType>
     */
    private function offeredTypes(AreaOfInterest $area): array
    {
        $this->vocabulary->seedTypes($area);

        return $this->types->findByAreaActive($area);
    }

    /** The type the form chose, or null when it chose one this area does not offer. */
    private function chosenType(AreaOfInterest $area, string $key): ?PatrolType
    {
        foreach ($this->offeredTypes($area) as $type) {
            if ($type->getKey() === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The station the form chose. A blank is a real answer, and a key this area
     * does not offer is treated as one: the chips only ever submit a live key,
     * so anything else is a stale form rather than somebody's intent.
     */
    /** The area's station the form named, by its uuid; null for none. */
    private function chosenStation(AreaOfInterest $area, ?string $key): ?Station
    {
        return $this->vocabulary->resolveStation($area, $key);
    }

    /** No-op when the request has no session (stateless calls). */
    private function addFlash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
