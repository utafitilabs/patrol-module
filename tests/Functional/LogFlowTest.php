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

namespace Uhifadhi\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Patrol\Upload\PatrolObservationPhotoTarget;
use Uhifadhi\Patrol\Upload\PatrolTrackTarget;
use Uhifadhi\Storage\Service\EvidenceStorage;
use Uhifadhi\Storage\Upload\UploadDom;

/**
 * THE ONE ENTRY FLOW — the settled design `log.html`, end to end.
 *
 * One page creates every patrol in this module: the track is dropped on the
 * platform's upload component if there is one (PL·01), the details are confirmed
 * (PL·02), the observations and their photographs are recorded (PL·03), and one
 * submit writes the lot. There is no second screen: `patrol_import` is a
 * permanent redirect into this one.
 *
 * The files arrive BEFORE the patrol exists, so the page carries a DRAFT and the
 * two upload targets file against it. What this asserts, beyond the form's own
 * rules, is the seam that makes that safe: a draft resolves to an area, the
 * permission is asked on that area, and saving re-homes every byte under the
 * patrol's own prefix and leaves the draft holding nothing.
 */
final class LogFlowTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private User $recorder;
    private User $staff;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[-30.1,-1.1],[-29.9,-1.1],[-29.9,-0.9],[-30.1,-0.9],[-30.1,-1.1]]]]}',
        );
        $this->em->persist($this->area);

        $this->recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->staff = new User()->setPassword('x')->setEmail('staff@example.test')
            ->setFirstName('Sam')->setLastName('Staff');
        $this->em->persist($this->recorder);
        $this->em->persist($this->staff);
        // The chips offer the AREA's own records, so they have to exist before a
        // patrol can name one.
        Vocabulary::station($this->em, $this->area, 'North post');
        Vocabulary::station($this->em, $this->area, 'South post');
        Vocabulary::kind($this->em, $this->area, 'Wildlife', ['sighting', 'spoor']);
        Vocabulary::kind($this->em, $this->area, 'Carcass', ['natural']);
        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    // ── the gate ──────────────────────────────────────────────────────────────

    public function testTheScreenIsDeniedWithoutTheRecordPermission(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', $this->logUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testLoggingIsDeniedWithoutTheRecordPermission(): void
    {
        $this->client->loginUser($this->recorder);
        $submission = $this->validSubmission($this->openDraft());

        $this->client->loginUser($this->staff);
        $this->client->request('POST', $this->logUrl(), $submission);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->patrolCount());
    }

    public function testAnAnonymousRequestIsNotServedTheScreen(): void
    {
        $this->client->request('GET', $this->logUrl());

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The upload endpoint asks the target, and the target asks the AREA. A person
     * who may not record a patrol here may not put a file against one either.
     */
    public function testAViewerWithoutTheRecordPermissionIsRefusedOnUpload(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();
        $token = $this->uploadToken();

        $this->client->loginUser($this->staff);
        $this->postUpload(PatrolTrackTarget::KIND.':'.$draft, $this->gpxFile(), $token);

        self::assertResponseStatusCodeSame(403);
    }

    // ── the page ──────────────────────────────────────────────────────────────

    public function testThePageDrawsEveryStepTheDesignPlacesOnIt(): void
    {
        $this->client->loginUser($this->recorder);
        $page = $this->client->request('GET', $this->logUrl());

        self::assertResponseIsSuccessful();

        /*
         * THREE NUMBERED STEPS, IN ORDER — the design's PL·01 / PL·02 / PL·03
         * cards.
         *
         * Asserted by the step NUMBER the card wears, not by the design's own
         * index: "PL·01" is the design workspace's referencing system and this
         * repo already rules that it does not ship (see NoWorkshopLabelsTest).
         * The card tab carries "step 1" instead, which is what the step rail
         * beside it says.
         */
        self::assertSame(
            ['1', '2', '3'],
            $page->filter('[data-patrol-log] .patrol-pstep')->each(
                static fn (Crawler $node): string => trim($node->filter('.n')->text()),
            ),
        );
        self::assertSame(
            ['The track', 'Patrol details', 'Observations'],
            $page->filter('[data-patrol-log] .patrol-pstep .t')->each(
                static fn (Crawler $node): string => trim($node->text()),
            ),
        );
        // "may be skipped" on the track, "as many as you like" on observations.
        self::assertCount(2, $page->filter('[data-patrol-log] .patrol-opt'));

        // Every chipset is the AREA's own records, one chip per row.
        self::assertCount(
            $this->typeCount(),
            $page->filter('[data-patrol-log] [data-patrol-types] .mchip'),
        );
        // The stations carry a "—" chip beside the area's own: a patrol that set
        // off from nowhere in particular is a real answer.
        self::assertCount(
            $this->stationCount() + 1,
            $page->filter('[data-patrol-log] [data-patrol-stations] .mchip'),
        );
        self::assertCount(
            $this->kindCount(),
            $page->filter('[data-patrol-log] [data-patrol-kinds="1"] .mchip'),
        );
        self::assertCount(
            $this->subcategoryCount(),
            $page->filter('[data-patrol-log] [data-patrol-subcategories="1"] .mchip'),
        );

        // BOTH presentations of the one upload component are on the page: the
        // dropzone card for the track, an add tile in the evidence grid.
        self::assertCount(1, $page->filter('[data-upl-presentation="zone"]'));
        self::assertCount(1, $page->filter('[data-upl-presentation="tile"]'));

        // Every target carries the draft this page opened, so a file can be
        // filed before the patrol it belongs to exists.
        $draft = $this->draftOf($page);
        self::assertNotSame('', $draft);
        self::assertSame(
            PatrolTrackTarget::KIND.':'.$draft,
            $page->filter('[data-upl-presentation="zone"]')->attr(UploadDom::TARGET),
        );
        self::assertSame(
            PatrolObservationPhotoTarget::KIND.':'.$draft.'-1',
            $page->filter('[data-upl-presentation="tile"]')->attr(UploadDom::TARGET),
        );

        // The design's own controls: add an observation, cancel, save.
        self::assertCount(1, $page->filter('[data-patrol-log] button[name="addObservation"].sadd'));
        self::assertCount(1, $page->filter('[data-patrol-log] .staddrow .cta'));
        self::assertCount(1, $page->filter('[data-patrol-log] .staddrow .tgl'));
        // The route sketch is offered only where step 1 was skipped, which a
        // fresh page is.
        self::assertCount(1, $page->filter('[data-patrol-sketch]'));
    }

    /** A draft belongs to the person who opened it and to this area only. */
    public function testOpeningThePageOpensADraftForThisAreaAndPerson(): void
    {
        $this->client->loginUser($this->recorder);
        $page = $this->client->request('GET', $this->logUrl());

        $draft = $this->draftRow($this->draftOf($page));
        self::assertSame($this->area->getId(), $draft->getArea()->getId());
        self::assertSame($this->recorder->getId(), $draft->getOwner()?->getId());
    }

    // ── the two targets ───────────────────────────────────────────────────────

    public function testAGpxDroppedOnTheTrackTargetComesBackParsed(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $this->postUpload(PatrolTrackTarget::KIND.':'.$draft, $this->gpxFile(), $this->uploadToken());

        self::assertResponseIsSuccessful();
        $receipt = $this->jsonResponse();
        // The chip carries what the module made of the file, which for a track
        // is what it parsed out of it.
        self::assertIsString($receipt['kind']);
        self::assertStringStartsWith('parsed · ', $receipt['kind']);
        self::assertStringContainsString(' km', $receipt['kind']);
        self::assertStringContainsString(' h ', $receipt['kind']);
        self::assertStringContainsString('gap', $receipt['kind']);
        // The name a person uploaded it under is what the row prints.
        self::assertSame('walk.gpx', $receipt['label']);
        // The bytes are the source file and they stay.
        self::assertIsString($receipt['key']);
        self::assertStringStartsWith(PatrolTrackTarget::KIND.'/'.$draft.'/', $receipt['key']);
        self::assertTrue($this->storage()->exists($receipt['key']));

        // And the draft holds it, so a reload — or a save — still knows about it.
        self::assertSame([$receipt['key']], $this->draftKeys($draft));
    }

    public function testAPhotographDroppedOnAnObservationSlotIsHeldAgainstTheDraft(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $this->postUpload(
            PatrolObservationPhotoTarget::KIND.':'.$draft.'-1',
            $this->photoFile(),
            $this->uploadToken(),
        );

        self::assertResponseIsSuccessful();
        $receipt = $this->jsonResponse();
        self::assertSame(PatrolObservationPhotoTarget::KIND_WORD, $receipt['kind']);
        self::assertIsString($receipt['key']);
        self::assertStringStartsWith(PatrolObservationPhotoTarget::KIND.'/'.$draft.'-1/', $receipt['key']);
        self::assertSame([$receipt['key']], $this->draftKeys($draft));
    }

    // ── saving ────────────────────────────────────────────────────────────────

    public function testSavingWritesThePatrolAndRehomesEveryDraftedByte(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();
        $token = $this->uploadToken();

        $this->postUpload(PatrolTrackTarget::KIND.':'.$draft, $this->gpxFile(), $token);
        $trackKey = $this->uploadedKey();
        $this->postUpload(PatrolObservationPhotoTarget::KIND.':'.$draft.'-1', $this->photoFile(), $token);
        $photoKey = $this->uploadedKey();

        $this->client->request('POST', $this->logUrl(), [
            ...$this->validSubmission($draft),
            'trackKey' => $trackKey,
            'observations' => [
                1 => [
                    'kind' => 'wildlife',
                    'subcategory' => 'sighting',
                    'time' => '06:05',
                    'note' => 'buffalo herd at the spring',
                    'photoKeys' => [$photoKey],
                ],
            ],
        ]);

        $patrol = $this->onlyPatrol();
        self::assertResponseRedirects(
            '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$patrol->getUuid()->toRfc4122(),
        );

        // THE TRACK. Time and distance came out of the file, not out of the form.
        self::assertSame(PatrolSourceEnum::Gpx, $patrol->getSource());
        self::assertNotNull($patrol->getTrack());
        self::assertSame('2026-08-22 05:55', $patrol->getStartedAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-08-22 06:15', $patrol->getEndedAt()?->format('Y-m-d H:i'));
        self::assertNotNull($patrol->getDistanceKm());
        self::assertGreaterThan(0.0, $patrol->getDistanceKm());

        // THE OBSERVATION, at the place the track says the patrol was at 06:05.
        $observations = $patrol->getObservations();
        self::assertCount(1, $observations);
        $observation = $observations->first();
        self::assertNotFalse($observation);
        self::assertSame('sighting', $observation->getCategory());
        self::assertSame('buffalo herd at the spring', $observation->getNote());
        self::assertSame('2026-08-22 06:05', $observation->getLoggedAt()?->format('Y-m-d H:i'));
        /** @var array{type: string, coordinates: array{0: float, 1: float}} $position */
        $position = json_decode((string) $observation->getPosition(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Point', $position['type']);
        // The second fix of the fixture track, which is the one timed 06:05.
        self::assertEqualsWithDelta(-30.01, $position['coordinates'][0], 0.0001);
        self::assertEqualsWithDelta(-1.01, $position['coordinates'][1], 0.0001);

        // THE PHOTOGRAPH, under the PATROL's own prefix rather than the draft's.
        $photos = $observation->getPhotos();
        self::assertCount(1, $photos);
        $photo = $photos->first();
        self::assertNotFalse($photo);
        self::assertStringStartsWith(
            PhotoEvidenceKey::prefixFor($observation).'/',
            $photo->getStoragePath(),
        );
        self::assertTrue($this->storage()->exists($photo->getStoragePath()));

        // The source GPX moves with it, and the patrol remembers where.
        self::assertNotNull($patrol->getTrackFileKey());
        self::assertStringStartsWith(
            PhotoEvidenceKey::prefixForPatrol($patrol).'/',
            (string) $patrol->getTrackFileKey(),
        );

        // AND THE DRAFT IS EMPTY: the storage has no rename, so the re-homing is
        // a copy and then a delete, and the draft's keys are gone with it.
        self::assertFalse($this->storage()->exists($photoKey));
        self::assertFalse($this->storage()->exists($trackKey));
        self::assertSame([], $this->draftKeys($draft));
        self::assertNull($this->findDraft($draft));
    }

    public function testSavingWithNoTrackKeepsTheTypedDistanceAndStaysManual(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $this->client->request('POST', $this->logUrl(), $this->validSubmission($draft));

        $patrol = $this->onlyPatrol();
        self::assertSame(PatrolSourceEnum::Manual, $patrol->getSource());
        self::assertNull($patrol->getTrack());
        self::assertNull($patrol->getTrackFileKey());
        self::assertSame(12.8, $patrol->getDistanceKm());
        self::assertSame('2026-08-22 05:55', $patrol->getStartedAt()?->format('Y-m-d H:i'));
    }

    /**
     * THE LEAD ROW'S DEFAULT IS "—", AND IT SAVES.
     *
     * The design draws the row with nobody chosen, which posts an empty string,
     * and a patrol with no lead is an ordinary record — plenty of shifts are
     * written up without one and the column has always been nullable.
     *
     * It answered `400 Input value "lead" cannot be converted to "int"` instead:
     * `InputBag::getInt()` refuses anything that is not a whole number, so the
     * one state the form ships in was the one state it could not save. The pair
     * below asserts the FIELD rather than the request, because an optional
     * relation has two real answers and both have to be exercised.
     */
    public function testAPatrolIsLoggedWithNoLeadWhenTheRowIsLeftOnTheDash(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $this->client->request('POST', $this->logUrl(), [
            ...$this->validSubmission($draft),
            'lead' => '',
        ]);

        $patrol = $this->onlyPatrol();
        self::assertResponseRedirects(
            '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$patrol->getUuid()->toRfc4122(),
        );
        self::assertNull($patrol->getLead());
    }

    public function testAPatrolKeepsTheLeadThatWasChosen(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $this->client->request('POST', $this->logUrl(), $this->validSubmission($draft));

        self::assertSame($this->recorder->getId(), $this->onlyPatrol()->getLead()?->getId());
    }

    /**
     * A person the deployment does not have is the same fact as none. The select
     * only ever offers live people, so anything else is a stale form rather than
     * somebody's intent — the reading the station chips already take, and never a
     * protocol error thrown at a reader.
     */
    public function testALeadTheDeploymentDoesNotHaveRecordsNoLead(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $this->client->request('POST', $this->logUrl(), [
            ...$this->validSubmission($draft),
            'lead' => 'nobody',
        ]);

        self::assertResponseStatusCodeSame(302);
        self::assertNull($this->onlyPatrol()->getLead());
    }

    /**
     * A key the form posts is the browser's word about what it uploaded, and the
     * draft's own rows are the evidence. One draft's key attaches nothing to
     * another draft's patrol.
     */
    public function testAKeyHeldByAnotherDraftIsNotAttachedToThisPatrol(): void
    {
        $this->client->loginUser($this->recorder);
        $first = $this->openDraft();
        $this->postUpload(PatrolTrackTarget::KIND.':'.$first, $this->gpxFile(), $this->uploadToken());
        $strayKey = $this->uploadedKey();

        $second = $this->openDraft();
        $this->client->request('POST', $this->logUrl(), [
            ...$this->validSubmission($second),
            'trackKey' => $strayKey,
        ]);

        $patrol = $this->onlyPatrol();
        self::assertNull($patrol->getTrack());
        self::assertSame(PatrolSourceEnum::Manual, $patrol->getSource());
    }

    // ── the form's own rules ──────────────────────────────────────────────────

    public function testAPatrolWithoutATypeIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();
        $this->client->request('POST', $this->logUrl(), [
            'draft' => $draft,
            '_token' => $this->formToken(),
            'type' => 'unlisted',
            'startedAt' => '2026-08-22T05:55',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertSelectorTextContains('[data-patrol-error]', 'Choose a patrol type');
    }

    public function testAPatrolWithoutAStartIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();
        $this->client->request('POST', $this->logUrl(), [
            'draft' => $draft,
            '_token' => $this->formToken(),
            'type' => 'walk',
            'startedAt' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertSelectorTextContains('[data-patrol-error]', 'time it started');
    }

    public function testAPatrolThatEndsBeforeItStartedIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();
        $this->client->request('POST', $this->logUrl(), [
            'draft' => $draft,
            '_token' => $this->formToken(),
            'type' => 'walk',
            'startedAt' => '2026-08-22T11:35',
            'endedAt' => '2026-08-22T05:55',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertSelectorTextContains('[data-patrol-error]', 'cannot end before it started');
    }

    public function testASubmissionWithoutTheFormTokenIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();
        $this->client->request('POST', $this->logUrl(), [
            ...$this->validSubmission($draft),
            '_token' => 'not-the-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->patrolCount());
    }

    /** "+ Add observation" is a submit: the page comes back with one more grid. */
    public function testAddingAnObservationRedrawsTheFormWithAnotherRecord(): void
    {
        $this->client->loginUser($this->recorder);
        $draft = $this->openDraft();

        $page = $this->client->request('POST', $this->logUrl(), [
            'draft' => $draft,
            '_token' => $this->formToken(),
            'type' => 'walk',
            'addObservation' => '1',
            'observations' => [1 => ['kind' => 'wildlife', 'subcategory' => 'sighting']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('[data-patrol-log] .patrol-pobs'));
        self::assertCount(2, $page->filter('[data-upl-presentation="tile"]'));
        self::assertSame(
            PatrolObservationPhotoTarget::KIND.':'.$draft.'-2',
            $page->filter('[data-upl-presentation="tile"]')->eq(1)->attr(UploadDom::TARGET),
        );
        self::assertSame(0, $this->patrolCount());
    }

    // ── the retired screen ────────────────────────────────────────────────────

    public function testTheImportScreenIsGoneAndPointsHere(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/import');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects($this->logUrl());
    }

    // ── fixtures and helpers ──────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validSubmission(string $draft): array
    {
        return [
            'draft' => $draft,
            '_token' => $this->formToken(),
            'type' => 'boat',
            'station' => 'north-post',
            'lead' => (string) $this->recorder->getId(),
            'team' => 'B. Beta, C. Gamma',
            'startedAt' => '2026-08-22T05:55',
            'endedAt' => '2026-08-22T11:35',
            'distanceKm' => '12.8',
            'note' => 'lake shore round',
        ];
    }

    /** Open the page and return the draft id it minted. */
    private function openDraft(): string
    {
        return $this->draftOf($this->client->request('GET', $this->logUrl()));
    }

    private function draftOf(Crawler $page): string
    {
        return $page->filter('[data-patrol-log] input[name="draft"]')->attr('value') ?? '';
    }

    private function findDraft(string $uuid): ?PatrolDraft
    {
        $this->em->clear();

        return $this->em->getRepository(PatrolDraft::class)->findOneBy(['uuid' => $uuid]);
    }

    private function draftRow(string $uuid): PatrolDraft
    {
        $draft = $this->findDraft($uuid);
        self::assertInstanceOf(PatrolDraft::class, $draft);

        return $draft;
    }

    /** @return list<string> */
    private function draftKeys(string $uuid): array
    {
        $draft = $this->findDraft($uuid);
        if (!$draft instanceof PatrolDraft) {
            return [];
        }

        return array_values(array_map(
            static fn (PatrolDraftFile $file): string => $file->getStorageKey(),
            $draft->getFiles()->toArray(),
        ));
    }

    /** The component's token, read off the rendered page exactly as the browser does. */
    private function uploadToken(): string
    {
        $page = $this->client->request('GET', $this->logUrl());

        return $page->filter('[data-upl-presentation="zone"]')->attr(UploadDom::TOKEN) ?? '';
    }

    private function formToken(): string
    {
        $page = $this->client->request('GET', $this->logUrl());

        return $page->filter('[data-patrol-log] input[name="_token"]')->attr('value') ?? '';
    }

    private function postUpload(string $target, UploadedFile $file, string $token): void
    {
        $this->client->request(
            'POST',
            '/files/upload',
            ['target' => $target, '_token' => $token],
            ['file' => $file],
        );
    }

    /** The key the component was handed back for the file it just sent. */
    private function uploadedKey(): string
    {
        $key = $this->jsonResponse()['key'] ?? null;
        self::assertIsString($key);

        return $key;
    }

    /** @return array<string, mixed> */
    private function jsonResponse(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * Three fixes ten minutes apart, the second timed 06:05 — which is what the
     * observation in the save test asks the track for.
     */
    private function gpxFile(): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gpx').'.gpx';
        file_put_contents($path, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
              <trk><trkseg>
                <trkpt lat="-1.0" lon="-30.0"><time>2026-08-22T05:55:00Z</time></trkpt>
                <trkpt lat="-1.01" lon="-30.01"><time>2026-08-22T06:05:00Z</time></trkpt>
                <trkpt lat="-1.02" lon="-30.02"><time>2026-08-22T06:15:00Z</time></trkpt>
              </trkseg></trk>
            </gpx>
            XML);

        return new UploadedFile($path, 'walk.gpx', 'application/gpx+xml', test: true);
    }

    private function photoFile(): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'img').'.jpg';
        $image = imagecreatetruecolor(8, 8);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'IMG_1208.jpg', 'image/jpeg', test: true);
    }

    private function storage(): EvidenceStorage
    {
        /** @var EvidenceStorage $storage */
        $storage = static::getContainer()->get(EvidenceStorage::class);

        return $storage;
    }

    private function typeCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(PatrolType::class)->findBy(['active' => true]));
    }

    private function stationCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(Station::class)->findBy(['active' => true]));
    }

    private function kindCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(TaxonomyKind::class)->findBy(['active' => true]));
    }

    private function subcategoryCount(): int
    {
        $this->em->clear();
        $total = 0;
        foreach ($this->em->getRepository(TaxonomyKind::class)->findBy(['active' => true]) as $kind) {
            $total += \count($kind->getSubcategories());
        }

        return $total;
    }

    private function logUrl(): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/log';
    }

    private function patrolCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(Patrol::class)->findAll());
    }

    private function onlyPatrol(): Patrol
    {
        $this->em->clear();
        $patrols = $this->em->getRepository(Patrol::class)->findAll();
        self::assertCount(1, $patrols);

        return $patrols[0];
    }
}
