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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * The patrol detail screen: the track plate and its payload, the meta rows,
 * the single derivable history entry and the numbered observation rows — plus
 * the area-nesting rule (a patrol reached through the wrong area is a 404).
 */
final class PatrolDetailPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $patrol;
    private Patrol $manualPatrol;
    private Observation $firstObservation;

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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->otherArea = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->otherArea);

        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        // A GPX-born patrol: a recorded track, honesty metadata, a roster and
        // two positioned observations.
        $this->patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($lead)
            ->setTeam('B. Beta · C. Gamma')
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setSource(PatrolSourceEnum::Gpx)
            ->setPointCount(1482)
            ->setGapCount(2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->patrol);

        $this->firstObservation = new Observation($this->patrol, 'maintenance')
            ->setNote('Culvert washed out on the ridge track.')
            ->setPosition('{"type":"Point","coordinates":[12.28,-5.72]}')
            ->setLoggedAt(new \DateTimeImmutable('today 06:48'))
            ->setRecordedBy($lead);
        $this->em->persist($this->firstObservation);

        // A category the deployment did NOT configure: the row falls back to
        // the stored key rather than rendering blank.
        $this->em->persist(
            new Observation($this->patrol, 'unlisted')
                ->setNote('Second note.')
                ->setLoggedAt(new \DateTimeImmutable('today 08:15')),
        );

        // A hand-logged patrol with no track and no observations: no Export GPX
        // action, no gps-points row, and the empty observations state.
        $this->manualPatrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'boat'))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'));
        $this->em->persist($this->manualPatrol);

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testThePatrolDetailRendersThePlateMetaHistoryAndObservations(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol));

        self::assertResponseIsSuccessful();

        // Header: "Patrol P-0001 — North post", with the subtitle assembled
        // from what this patrol actually knows.
        self::assertSelectorTextContains('h1.pg', 'Patrol '.$this->patrol->getRef().' — North post');
        $subtitle = $crawler->filter('.pgsub')->text();
        self::assertStringContainsString('walking round patrol', $subtitle);
        self::assertStringContainsString('A. Alpha', $subtitle);
        self::assertStringContainsString('14.2 km', $subtitle);
        self::assertStringContainsString('2 observations', $subtitle);

        // Export GPX is offered only for a recorded track.
        self::assertStringContainsString('Export GPX', $crawler->filter('.pghead')->text());

        // PL·01 — the plate is the atlas's, and what is on it is stated in PHP:
        // the track, its ends, and every positioned observation as a marker.
        $plate = self::plate($crawler);
        self::assertStringContainsString('LineString', json_encode(self::features($plate, 'patrol.track'), \JSON_THROW_ON_ERROR));
        self::assertCount(2, self::features($plate, 'patrol.endpoints'));

        // Only the observation that recorded a position can be drawn, and it is
        // a marker rather than a layer feature because it opens its own page.
        $markers = self::markers($crawler);
        self::assertCount(1, $markers);
        $marker = $markers[0];
        // The deployment's WORD for the category, never the stored key: the
        // marker reads like the chip under it.
        self::assertSame('obs 1 · Maintenance need', $marker['title'] ?? null);
        $window = $marker['infoWindow'] ?? null;
        self::assertIsArray($window);
        self::assertIsString($window['content']);
        self::assertStringContainsString('/observations/', $window['content']);

        // The plate draws the area outline the track is read against, and the
        // track wears this patrol type's one colour.
        $boundary = $plate['boundary'];
        self::assertIsArray($boundary);
        self::assertStringContainsString('MultiPolygon', json_encode($boundary['geojson'], \JSON_THROW_ON_ERROR));
        self::assertIsString(self::layer($plate, 'patrol.track')['swatch'] ?? null);

        // The controls are the atlas's, mounted by its one map controller; the
        // module renders no chrome markup of its own.
        self::assertCount(1, $crawler->filter('.map-plate .viewer .map-canvas'));
        self::assertCount(0, $crawler->filter('.patrol-zoomui'));
        // The caption rides in the plate's filter slot, one row above the map.
        self::assertStringContainsString($this->patrol->getRef().' · North post · walking round', $crawler->filter('.map-plate .map-filters')->text());

        // THE FACTS CARD (the settled design's PL·02), above the history rather
        // than a band across the top: the computed duration and average speed
        // (14.2 km over 6 h 20 = 2.24… km/h), the source and the GPS honesty
        // facts, the team, and the started stamp as a machine <time>.
        $facts = $crawler->filter('[data-patrol-facts]')->text();
        self::assertStringContainsString('North post', $facts);
        self::assertStringContainsString('6 h 20', $facts);
        self::assertStringContainsString('2.2', $facts);
        self::assertStringContainsString('km/h', $facts);
        self::assertStringContainsString('GPX import', $facts);
        self::assertStringContainsString('1,482', $facts);
        self::assertStringContainsString('Gaps', $facts);
        self::assertStringContainsString('B. Beta · C. Gamma', $facts);
        self::assertStringContainsString(
            strtolower(new \DateTimeImmutable('today 06:10')->format('D j M')).' · 06:10',
            $facts,
        );

        // PL·03 — the settled discard design's D4 grammar: a bold title per
        // entry, newest first. This patrol has no events, so the only entry is
        // the one derivable from the record itself: how it came to exist.
        $history = $crawler->filter('[data-patrol-history] .rln');
        self::assertCount(1, $history);
        self::assertStringContainsString('Track imported from GPX', $history->text());

        // PL·04 — one row per observation, numbered from 1, with the category
        // label (or the raw key when unconfigured) and DMS coordinates.
        $rows = $crawler->filter('[data-patrol-observations] .patrol-obs-r');
        self::assertCount(2, $rows);
        self::assertSame('1', trim($rows->eq(0)->filter('.patrol-obs-n')->text()));
        self::assertStringContainsString('maintenance need', $rows->eq(0)->text());
        self::assertStringContainsString('Culvert washed out on the ridge track.', $rows->eq(0)->text());
        // 5.72° = 5°43'12" and 12.28° = 12°16'48".
        self::assertStringContainsString('5°43\'12"S 12°16\'48"E', $rows->eq(0)->text());
        self::assertStringContainsString('unlisted', $rows->eq(1)->text());
        self::assertSame(
            $this->url($this->area, $this->patrol).'/observations/'.$this->firstObservation->getUuid()->toRfc4122(),
            $rows->eq(0)->filter('a.open-btn')->attr('href'),
        );
    }

    /**
     * ONE RECORD GRID, AND THE PLATE KEEPS ITS OWN COLUMN.
     *
     * The record is a single two-column grid: the plate card first in the left
     * column and, directly beneath the map, what the record lists under it; the
     * facts and the history beside them on the right. A second row below the
     * first — the observations at plate width with an empty column beside them —
     * starts the list under the FULL height of the facts column, which over a
     * 400px map is a hole as tall as the facts are long.
     */
    public function testTheRecordIsOneGridWhoseLeftColumnStacksThePlateThenTheObservations(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol));

        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.recgrid'));

        $columns = $crawler->filter('.recgrid > .col');
        self::assertCount(2, $columns);

        $left = $columns->eq(0)->children('.c');
        self::assertCount(2, $left);
        self::assertNotNull($left->eq(0)->attr('data-patrol-track'));
        self::assertNotNull($left->eq(1)->attr('data-patrol-observations'));

        $right = $columns->eq(1)->children('.c');
        self::assertCount(2, $right);
        self::assertNotNull($right->eq(0)->attr('data-patrol-facts'));
        self::assertNotNull($right->eq(1)->attr('data-patrol-history'));
    }

    /**
     * PORTABLE, VIEWER-LOCAL TIMES (Route A: store UTC, format in the browser).
     * Every human timestamp renders as a machine `<time datetime="…">` carrying
     * the instant in ISO-8601, with the readable text as the no-JS fallback. The
     * element names NO controller — the shell's frame-level `localtime` scanner,
     * mounted on the `<body>` it owns, localises every `time[datetime]` on the
     * page to the reader's own zone. That absence of coupling is what keeps this
     * template portable to any host that renders through the shell: a host that
     * runs the scanner localises the time; one that does not keeps the UTC text.
     *
     * Browser-zone conversion itself is JS and is verified separately (see the
     * shell's localtime controller); this pins the server contract the scanner
     * depends on — a real instant in `datetime`, and no controller named here.
     */
    public function testHumanTimestampsRenderAsPortableMachineTimeElements(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol));

        self::assertResponseIsSuccessful();

        // The started and ended facts are machine <time> elements.
        $times = $crawler->filter('[data-patrol-facts] time');
        self::assertGreaterThanOrEqual(2, $times->count(), 'started and ended render as machine <time> elements.');

        // The datetime attribute is the STORED INSTANT — unambiguous across
        // zones — and the visible text is the no-JS fallback that stays put.
        $started = $times->first();
        self::assertNotSame('', (string) $started->attr('datetime'));
        self::assertSame(
            new \DateTimeImmutable('today 06:10')->getTimestamp(),
            new \DateTimeImmutable((string) $started->attr('datetime'))->getTimestamp(),
            'The datetime attribute carries the stored instant, whatever the offset it is written in.',
        );
        self::assertStringContainsString('06:10', $started->text(), 'The server-rendered text remains as the no-JS fallback.');

        // PORTABILITY: no patrol <time> couples itself to a controller — the
        // frame localises them, so the template renders in a shell-less host too.
        $controllers = $times->each(static fn (Crawler $node): ?string => $node->attr('data-controller'));
        self::assertSame(
            array_fill(0, \count($controllers), null),
            $controllers,
            'A patrol <time> must name no controller; the frame localises it.',
        );

        // The numbered observation rows carry the same machine <time> contract.
        self::assertGreaterThanOrEqual(
            1,
            $crawler->filter('[data-patrol-observations] time[datetime]')->count(),
            'An observation time is a machine <time> too.',
        );
    }

    public function testAHandLoggedPatrolOffersNoExportAndStatesItsEmptyObservations(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->manualPatrol));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Export GPX', $crawler->filter('.pghead')->text());
        self::assertStringContainsString('manual', $crawler->filter('[data-patrol-facts]')->text());
        self::assertStringNotContainsString('GPS points', $crawler->filter('[data-patrol-facts]')->text());
        self::assertStringContainsString('Logged manually', $crawler->filter('[data-patrol-history]')->text());
        self::assertCount(1, $crawler->filter('[data-patrol-observations] .patrol-obs-empty'));
    }

    public function testAPatrolReachedThroughAnotherAreaIsNotFound(): void
    {
        $this->client->request('GET', $this->url($this->otherArea, $this->patrol));

        self::assertResponseStatusCodeSame(404);
    }

    private function url(AreaOfInterest $area, Patrol $patrol): string
    {
        return '/areas/'.$area->getUuidString().'/modules/patrols/'.$patrol->getUuid()->toRfc4122();
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
