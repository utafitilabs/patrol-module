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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * The observation detail screen: the location plate (this observation's point
 * plus the parent track as context), the meta rows, the verbatim note, the one
 * derivable history entry and the deferred photos card — plus the nesting rule
 * (an observation reached through another patrol, or a patrol reached through
 * another area, is a 404).
 */
final class ObservationDetailPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $patrol;
    private Patrol $otherPatrol;
    private Patrol $lonePatrol;
    private Observation $observation;
    private Observation $firstObservation;
    private Observation $loneObservation;

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
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->otherArea = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->otherArea);

        $recorder = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($recorder);

        $this->patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($recorder)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->patrol);

        // Two observations, so the meta row can honestly say "2 of 2".
        $this->firstObservation = new Observation($this->patrol, 'maintenance')
            ->setNote('First note.')
            ->setLoggedAt(new \DateTimeImmutable('today 06:48'));
        $this->em->persist($this->firstObservation);
        $this->observation = new Observation($this->patrol, 'maintenance')
            ->setNote('Fence line down over twenty metres; livestock crossing.')
            ->setPosition('{"type":"Point","coordinates":[12.28,-5.72]}')
            ->setLoggedAt(new \DateTimeImmutable('today 08:15'))
            ->setRecordedBy($recorder);
        $this->em->persist($this->observation);

        // A patrol with exactly ONE observation: nothing to circle, so no arrows.
        $this->lonePatrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 05:00'));
        $this->em->persist($this->lonePatrol);
        $this->loneObservation = new Observation($this->lonePatrol, 'maintenance')
            ->setNote('The only note.')
            ->setLoggedAt(new \DateTimeImmutable('today 05:20'));
        $this->em->persist($this->loneObservation);

        $this->otherPatrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'boat'))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'));
        $this->em->persist($this->otherPatrol);

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

    public function testTheObservationDetailRendersThePlateMetaNoteAndHistory(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('h1.pg', 'Observation 2 — Maintenance need');
        $subtitle = $crawler->filter('.pgsub')->text();
        self::assertStringContainsString('maintenance need', $subtitle);
        self::assertStringContainsString($this->patrol->getRef().' walking round patrol', $subtitle);
        self::assertStringContainsString('A. Alpha', $subtitle);
        // The File-as-incident button exists only when a host installs an
        // incidents module exposing `incident_new` (the contract is the route name
        // + prefill query keys). This kernel has none, so the honest page shows
        // no dead control — the design's graceful absence.
        self::assertStringNotContainsString('File as incident', $crawler->filter('.pghead')->text());

        // Back to the parent patrol, and the crumb ends at "obs 2".
        // The way back is the shell's own pill, .backbtn — the control the shell
        // ships for it — and not a chip or a pill of patrol's own.
        $back = $crawler->filter(\sprintf('a.backbtn:contains("Patrol %s")', $this->patrol->getRef()));
        self::assertCount(1, $back);
        self::assertGreaterThan(0, $back->filter('svg')->count());
        self::assertStringContainsString('obs 2', $crawler->filter('.crumb')->text());

        // PL·01 — the plate carries this observation's point AND the parent
        // track, which is drawn back because the page is about the observation.
        $plate = self::plate($crawler);
        self::assertStringContainsString('LineString', json_encode(self::features($plate, 'patrol.track'), \JSON_THROW_ON_ERROR));
        // The route is context here, so its ends are not drawn; the area's
        // zones lie under it, as on every plate of the area.
        self::assertSame(['area.zones', 'patrol.track'], array_column($plate['layers'], 'id'));
        // The area outline travels with the plate here too.
        $boundary = $plate['boundary'];
        self::assertIsArray($boundary);
        self::assertStringContainsString('MultiPolygon', json_encode($boundary['geojson'], \JSON_THROW_ON_ERROR));

        $titles = array_column(self::markers($crawler), 'title');
        self::assertContains('obs 2 · Maintenance need', $titles);
        // The caption rides in the plate's filter slot, one row above the map.
        self::assertStringContainsString('obs 2 · maintenance need · 08:15', $crawler->filter('.map-plate .map-filters')->text());

        // The identity band — the observation's own facts in the platform's
        // shared .factband below the tabs (PL·02 in the settled design is this
        // band, not a sidebar card), with the position printed as DMS
        // (5.72° = 5°43'12", 12.28° = 12°16'48") and a "The patrol →" more-link.
        $facts = $crawler->filter('.factband')->text();
        self::assertStringContainsString('2 of 2', $facts);
        self::assertStringContainsString($this->patrol->getRef(), $facts);
        self::assertStringContainsString('5°43\'12"S 12°16\'48"E', $facts);
        self::assertStringContainsString('A. Alpha', $facts);
        self::assertStringContainsString(
            strtolower(new \DateTimeImmutable('today 08:15')->format('D j M')).' · 08:15',
            $facts,
        );
        self::assertSame(
            '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$this->patrol->getUuid()->toRfc4122(),
            $crawler->filter('.factband a.more')->attr('href'),
            'the band ends with a "The patrol →" link back up to the parent patrol',
        );

        // PL·03 — the note, verbatim and quoted.
        self::assertStringContainsString(
            'Fence line down over twenty metres; livestock crossing.',
            $crawler->filter('[data-patrol-note] .patrol-quote')->text(),
        );

        // PL·04 — the single derivable history entry, with the recorder.
        $history = $crawler->filter('[data-patrol-history] .rln');
        self::assertCount(1, $history);
        self::assertStringContainsString('observation logged en route by A. Alpha', $history->text());

        // PL·05 — an observation with no photographs draws no tiles and no
        // placeholder images, and never an upload control (view-only by ruling).
        $photos = $crawler->filter('[data-patrol-photos]');
        self::assertCount(1, $photos);
        self::assertStringContainsString('Photos', $photos->text());
        self::assertCount(0, $photos->filter('img'));
        self::assertCount(0, $photos->filter('input'));
        // …and the identity band says none rather than staying silent.
        self::assertStringContainsString('Photos', $facts);
    }

    /**
     * ONE RECORD GRID, AND THE PLATE KEEPS ITS OWN COLUMN.
     *
     * The record is a single two-column grid: the plate card first in the left
     * column and the photographs directly beneath the map; the note and the
     * history beside them on the right. A second row below the first starts the
     * photos under the FULL height of the note-and-history column, which over a
     * 400px map is a hole as tall as the history is long. The amendments trail is
     * the record's own second grid and stays one.
     */
    public function testTheRecordIsOneGridWhoseLeftColumnStacksThePlateThenThePhotos(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        $columns = $crawler->filter('.recgrid > .col');
        self::assertCount(2, $columns);

        $left = $columns->eq(0)->children('.c');
        self::assertCount(2, $left);
        self::assertNotNull($left->eq(0)->attr('data-patrol-location'));
        self::assertNotNull($left->eq(1)->attr('data-patrol-photos'));

        $right = $columns->eq(1)->children('.c');
        self::assertCount(2, $right);
        self::assertNotNull($right->eq(0)->attr('data-patrol-note'));
        self::assertNotNull($right->eq(1)->attr('data-patrol-history'));

        // The trail and the rules that govern it are the second grid, and the
        // only other one: PL·06–PL·09 are one card in the state this record is
        // actually in, beside the rules.
        $grids = $crawler->filter('.recgrid');
        self::assertCount(2, $grids);
        self::assertCount(2, $grids->eq(1)->children('.c'));
        self::assertNotNull($grids->eq(1)->children('.c')->eq(0)->attr('data-patrol-amendments'));
    }

    /**
     * The photographs the phone synced, on the page — thumbnails through the
     * evidence route, each one a trigger for the shared file preview.
     */
    public function testThePhotosCardDrawsTheObservationsPhotographs(): void
    {
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c1'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c1.jpg',
        )
            ->setMimeType('image/jpeg')
            ->setThumbKey('patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c1.jpg.thumb.jpg')
            ->setTakenAt(new \DateTimeImmutable('today 08:15'));
        $this->em->persist($photo);
        // A second photograph with NO preview — the HEIC case. It must still
        // draw, falling back to the original rather than a broken image.
        $withoutThumb = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c2'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c2.heic',
        )->setMimeType('image/heic');
        $this->em->persist($withoutThumb);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-patrol-photos]');
        $tiles = $card->filter('.patrol-ph');
        self::assertCount(2, $tiles);

        // The tile draws the PREVIEW and carries the ORIGINAL for the overlay to
        // offer — both through storage-module's authenticated route, which is
        // the only way bytes leave this platform.
        $first = $tiles->eq(0);
        self::assertSame(
            '/storage/evidence/'.$photo->getThumbKey(),
            $first->filter('img')->attr('src'),
        );
        self::assertSame(
            '/storage/evidence/'.$photo->getStoragePath(),
            $first->attr('data-f-original'),
        );
        // No document-root path anywhere: nothing under /var, nothing guessable.
        self::assertStringNotContainsString('var/patrol', (string) $this->client->getResponse()->getContent());

        // The photograph that could not be previewed falls back to itself.
        self::assertSame(
            '/storage/evidence/'.$withoutThumb->getStoragePath(),
            $tiles->eq(1)->filter('img')->attr('src'),
        );

        // The count agrees with reality, in all three places the design prints it.
        self::assertStringContainsString('· 2 · from the field', $card->text());
        self::assertStringContainsString('2 photos', $crawler->filter('.pgsub')->text());
        self::assertStringContainsString('Photos', $crawler->filter('.factband')->text());
        self::assertStringContainsString('2', $crawler->filter('.factband')->text());

        // Still view-only: no upload control appeared with the photographs.
        self::assertCount(0, $card->filter('input'));
    }

    /**
     * A PHOTOGRAPH OPENS WHERE EVERY PHOTOGRAPH ON THIS PLATFORM OPENS.
     *
     * The tile is not a link to the raw bytes any more: it is a trigger for
     * storage-module's file preview, the same component the Files hub opens its
     * own tiles in. This module owns none of that markup — it includes the
     * partial and fills the contract — so what is asserted here is exactly the
     * join: the shell is on the page, and every tile speaks the contract.
     * → @UhifadhiStorage/overlay/_preview.html.twig
     */
    public function testAPhotographOpensInTheSharedFilePreview(): void
    {
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000d1'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000d1.jpg',
        )
            ->setMimeType('image/jpeg')
            ->setByteSize(2_411_724)
            ->setThumbKey('patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000d1.jpg.thumb.jpg')
            ->setTakenAt(new \DateTimeImmutable('2026-08-04 09:12'));
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        // The component's own shell, included once, with the behaviour it ships.
        $overlay = $crawler->filter('.f-ov[data-f-overlay]');
        self::assertCount(1, $overlay, 'the page includes the storage bundle’s preview, and does not draw one of its own');
        self::assertSame('uhifadhi--storage-module--preview', $overlay->attr('data-controller'));
        // THE DIGEST IS NOT ASSERTED, only the sheet. AssetMapper
        // content-versions a bundle's public/ files, so the href is
        // `/assets/bundles/uhifadhistorage/preview-<digest>.css` and pinning the
        // whole path would make this test fail every time that bundle edits its
        // stylesheet — which is a release note, not a defect here.
        self::assertMatchesRegularExpression(
            '#bundles/uhifadhistorage/preview(-[A-Za-z0-9_-]+)?\.css#',
            (string) $this->client->getResponse()->getContent(),
            'consuming the component means loading its vocabulary too',
        );

        $tile = $crawler->filter('[data-patrol-photos] .patrol-ph');
        self::assertCount(1, $tile);
        self::assertNotNull($tile->attr('data-f-preview'), 'the tile is a trigger');
        self::assertCount(
            0,
            $crawler->filter('a.patrol-ph'),
            'clicking a photograph opens the preview; it no longer walks off the page to the raw bytes',
        );

        // Everything the overlay shows travels in the attributes, so opening one
        // costs no request.
        self::assertSame('image/jpeg', $tile->attr('data-f-mime'));
        self::assertSame('2.4 MB', $tile->attr('data-f-size'));
        self::assertSame('made', $tile->attr('data-f-thumb'));
        self::assertSame('/storage/evidence/'.$photo->getThumbKey(), $tile->attr('data-f-img'));
        self::assertSame('/storage/evidence/'.$photo->getStoragePath(), $tile->attr('data-f-original'));
        self::assertStringContainsString('09:12', (string) $tile->attr('data-f-taken'));

        // THE OWNER IS THE FILE'S IDENTITY. A photograph belongs to an
        // observation in the Patrols module, and the preview says so.
        self::assertSame('patrols', $tile->attr('data-f-mod'));
        self::assertSame('Patrols', $tile->attr('data-f-modlabel'));
        self::assertSame($this->observation->getRef(), $tile->attr('data-f-rec'));
        self::assertSame(
            $this->url($this->area, $this->patrol, $this->observation),
            $tile->attr('data-f-rechref'),
        );

        // The file's own page belongs to the Files hub, which a host running
        // this module need not have. Nothing here promises one.
        self::assertSame('', $tile->attr('data-f-detail'));
    }

    /**
     * The phone promised more than arrived. The page says so — a count that
     * silently shows the smaller number would be a claim that nothing is missing.
     */
    public function testAnObservationStillSyncingSaysWhatHasNotArrived(): void
    {
        $this->observation->setPhotoCount(3);
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c3'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c3.jpg',
        );
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();
        $facts = $crawler->filter('.factband')->text();
        self::assertStringContainsString('1 of 3', $facts);
        self::assertStringContainsString('2 still syncing', $facts);
    }

    /** The parent patrol's observation rows carry the same honest count. */
    public function testThePatrolDetailRowCountsTheObservationsPhotographs(): void
    {
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c4'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c4.jpg',
        );
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request(
            'GET',
            '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$this->patrol->getUuid()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-patrol-observations] .patrol-obs-r');
        self::assertCount(2, $rows);
        // The second observation holds the photograph; the first holds none and
        // therefore says nothing rather than "0 photos".
        self::assertStringContainsString('1 photo', $rows->eq(1)->filter('em')->text());
        self::assertStringNotContainsString('photo', $rows->eq(0)->filter('em')->text());
    }

    public function testTheArrowsCircleToTheNeighbouringObservations(): void
    {
        // The LAST of two: next wraps round to the first, prev walks back to it
        // as well — a two-observation patrol is a ring of two.
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('.pgact .patrol-obsnav');
        self::assertCount(1, $nav);
        self::assertStringContainsString('2 / 2', $nav->text());

        $first = $this->url($this->area, $this->patrol, $this->firstObservation);
        self::assertSame($first, $nav->filter('a[rel="prev"]')->attr('href'));
        self::assertSame($first, $nav->filter('a[rel="next"]')->attr('href'));
    }

    public function testTheArrowsWrapAtBothEnds(): void
    {
        // The FIRST of two: prev wraps backwards to the last one.
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->firstObservation));

        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('.pgact .patrol-obsnav');
        self::assertStringContainsString('1 / 2', $nav->text());

        $last = $this->url($this->area, $this->patrol, $this->observation);
        self::assertSame($last, $nav->filter('a[rel="prev"]')->attr('href'));
        self::assertSame($last, $nav->filter('a[rel="next"]')->attr('href'));
        // The arrows say where they go, for anyone not reading the chevrons.
        self::assertStringContainsString(
            'Previous observation: 2 of 2',
            (string) $nav->filter('a[rel="prev"]')->attr('aria-label'),
        );
    }

    public function testAPatrolWithASingleObservationOffersNoArrows(): void
    {
        $crawler = $this->client->request(
            'GET',
            $this->url($this->area, $this->lonePatrol, $this->loneObservation),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.patrol-obsnav'));
    }

    public function testThePlateCarriesEveryObservationAsItsOwnMarker(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        // Every sibling that recorded a position is drawn, in the SAME order the
        // arrows walk, so a marker can be opened as well as arrowed to. The one
        // with no position holds its number in the list beside the map and is
        // not drawn — an invented position would be worse than none.
        $markers = self::markers($crawler);
        self::assertCount(1, $markers);

        $marker = $markers[0];
        self::assertSame('obs 2 · Maintenance need', $marker['title'] ?? null);
        $window = $marker['infoWindow'] ?? null;
        self::assertIsArray($window);
        self::assertIsString($window['content']);
        self::assertStringContainsString(
            $this->url($this->area, $this->patrol, $this->observation),
            $window['content'],
        );
    }

    public function testAnObservationReachedThroughAnotherPatrolIsNotFound(): void
    {
        $this->client->request('GET', $this->url($this->area, $this->otherPatrol, $this->observation));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnObservationReachedThroughAnotherAreaIsNotFound(): void
    {
        $this->client->request('GET', $this->url($this->otherArea, $this->patrol, $this->observation));

        self::assertResponseStatusCodeSame(404);
    }

    private function url(AreaOfInterest $area, Patrol $patrol, Observation $observation): string
    {
        return '/areas/'.$area->getUuidString()
            .'/modules/patrols/'.$patrol->getUuid()->toRfc4122()
            .'/observations/'.$observation->getUuid()->toRfc4122();
    }

    /**
     * WHAT THE PLATE CARRIES. The atlas writes its whole payload under one key
     * of the UX Map map's own `extra`, and UX Map forwards it to the browser as
     * a Stimulus value on the map element.
     *
     * @return array{layers: list<array<string, mixed>>, boundary: array<string, mixed>|null, ...}
     */
    private static function plate(Crawler $crawler): array
    {
        $plate = $crawler->filter('[data-controller="uhifadhi--atlas-bundle--map-plate"]');
        self::assertCount(1, $plate);

        $extra = json_decode((string) $plate->filter('[data-symfony--ux-leaflet-map--map-extra-value]')->attr('data-symfony--ux-leaflet-map--map-extra-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($extra);
        $atlas = $extra['atlas'] ?? null;
        self::assertIsArray($atlas);
        self::assertIsList($atlas['layers'] ?? null);

        /** @var array{layers: list<array<string, mixed>>, boundary: array<string, mixed>|null} $atlas */
        return $atlas;
    }

    /**
     * One of the plate's layers, by the id the module gave it.
     *
     * @param array{layers: list<array<string, mixed>>, boundary: array<string, mixed>|null} $plate
     *
     * @return array<string, mixed>
     */
    private static function layer(array $plate, string $id): array
    {
        foreach ($plate['layers'] as $layer) {
            if ($id === ($layer['id'] ?? null)) {
                return $layer;
            }
        }

        self::fail(\sprintf('The plate draws no layer "%s".', $id));
    }

    /**
     * The features of one of the plate's layers.
     *
     * @param array{layers: list<array<string, mixed>>, boundary: array<string, mixed>|null} $plate
     *
     * @return list<array<string, mixed>>
     */
    private static function features(array $plate, string $id): array
    {
        $collection = self::layer($plate, $id)['features'] ?? null;
        self::assertIsArray($collection);
        self::assertIsList($collection['features'] ?? null);

        /** @var list<array<string, mixed>> $features */
        $features = $collection['features'];

        return $features;
    }

    /**
     * The markers on the plate — the elements UX Map models itself, which the
     * atlas passes straight through.
     *
     * @return list<array<string, mixed>>
     */
    private static function markers(Crawler $crawler): array
    {
        $decoded = json_decode((string) $crawler->filter('[data-symfony--ux-leaflet-map--map-markers-value]')->attr('data-symfony--ux-leaflet-map--map-markers-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsList($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
