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
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;

/**
 * The patrols widget dashboard: the KPI strip, the coverage map payload, the
 * filter chips, the patrol log, both charts and the month calendar (the feed is
 * off the shipped composition — owner ruling 2026-09-08) —
 * all rendered from real rows, with the deployment's own type vocabulary
 * (TestKernel configures the synthetic "walk"/"boat" types).
 */
final class DashboardPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;
    use StoredCoverage;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $walkWithObservations;
    private Patrol $boat;

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

        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        // Today's patrol: a recorded track and two en-route observations.
        $this->walkWithObservations = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->walkWithObservations);
        foreach (['maintenance', 'maintenance'] as $category) {
            $this->em->persist(new Observation($this->walkWithObservations, $category));
        }

        // A second walk, same day, so the type count is 2 and the calendar cell
        // carries two pills.
        $secondWalk = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 05:55'))
            ->setEndedAt(new \DateTimeImmutable('today 11:35'))
            ->setDistanceKm(12.8);
        $this->em->persist($secondWalk);

        // A different type, a different station.
        $this->boat = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'boat'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'South landing'))
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'))
            ->setEndedAt(new \DateTimeImmutable('today 11:30'))
            ->setDistanceKm(58.3)
            ->setTrack('{"type":"LineString","coordinates":[[12.40,-5.60],[12.45,-5.58]]}');
        $this->em->persist($this->boat);

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

    public function testTheDashboardRendersEveryWidgetFromRealRows(): void
    {
        // The worker has run: the month's coverage is filed on the ledger.
        $this->fileFacts(new \DateTimeImmutable());

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();

        // Page header: "<Area> — Patrols", per the design's title convention.
        self::assertSelectorTextContains('h1.pg', 'demo reserve — Patrols');

        // The subtitle is the settled design's own words (index.html pgsub),
        // ported verbatim.
        self::assertSelectorTextContains(
            'p.pgsub',
            'Every patrol logged in this area — where they went, how far, how much of the area they reached, and what they saw. One filter drives the map, the log and the charts together.',
        );

        /*
         * THE TAB TITLE NAMES THE AREA EXACTLY ONCE.
         *
         * The shell's document composes it as page — place — brand, where the
         * place is the area the request is in, so a page that names the area
         * itself prints it twice ("Demo Reserve — Patrols — Demo Reserve —
         * Uhifadhi"). Every screen of this module did, which is what a
         * `layout.html.twig` that composed nothing left behind.
         */
        self::assertSame(1, substr_count($crawler->filter('title')->first()->text(), 'demo reserve'));

        // KPI strip: this month's count, its per-type breakdown, the distance
        // sum, and PL·03's coverage as a whole percent. The two recorded tracks
        // (~20 km between them) sweep a 2 km buffer over roughly a tenth of the
        // ~1 100 km² fixture square — the plate says 9 %.
        self::assertSelectorTextContains('[data-kpi="month"] .kpi b', '3');
        self::assertSelectorTextContains('[data-kpi="month"] .kpi span', '2 walking round');
        self::assertSelectorTextContains('[data-kpi="distance"] .kpi b', '85');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi b', '9%');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi span', 'of area within 2 km of a track · as of');
        self::assertSelectorTextContains('[data-kpi="last"] .kpi span', $this->boat->getRef());

        // Filter chips: one per configured type, each with its live count.
        // The type chip is a DROPDOWN — a set the deployment writes has no length
        // a pill row can plan for — so the counts live in its options.
        self::assertStringContainsString('walking round2', (string) $crawler->filter('[data-w="map"] .lfilt')->text());
        self::assertStringContainsString('boat1', (string) $crawler->filter('[data-w="map"] .lfilt')->text());

        // Patrol log: one row per patrol, with the ref, the explicit lowercase
        // start ("sat 22 aug · 06:10"), the observation chip and Open →.
        self::assertCount(3, $crawler->filter('[data-patrol-log] tbody tr[data-patrol]'));
        // A narrowed view that matches nothing renders no rows at all now, so
        // there is no hidden stand-in row to reveal.
        // Scoped to the log: the register row names the layer and the feature
        // the coverage plate spotlights when the row is hovered.
        $row = $crawler->filter('[data-patrol-log] [data-patrol="'.$this->walkWithObservations->getUuid()->toRfc4122().'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString($this->walkWithObservations->getRef(), $row->text());
        self::assertSame(
            strtolower(new \DateTimeImmutable('today 06:10')->format('D j M')).' · 06:10',
            trim($row->filter('[data-patrol-start]')->text()),
        );
        self::assertStringContainsString('2 obs', $row->text());
        self::assertStringContainsString('Open', $row->text());

        // Feed: OFF the shipped composition now (owner ruling 2026-09-08) — it drew
        // the same latest-N patrols the log register already lists. The default
        // dashboard renders no feed at all; the register carries the recent window.
        self::assertCount(0, $crawler->filter('[data-patrol-feed]'));

        // CHARTS: THE ATLAS'S, NOT THIS MODULE'S. Both widgets render the
        // component's plate with the library's canvas in it — no `<svg>` of
        // this module's anywhere on the page — and what is asserted here is
        // what this module PUT IN one: the five week labels with one series
        // per configured type, and the station ranking by name.
        $weekly = $crawler->filter('[data-patrol-weekly] .chart-plate canvas');
        self::assertCount(1, $weekly);
        /** @var array{data: array{labels: list<string>, datasets: list<array{label: string, data: list<float>}>}} $view */
        $view = json_decode((string) $weekly->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['W1', 'W2', 'W3', 'W4', 'W5'], $view['data']['labels']);
        self::assertCount(2, $view['data']['datasets']);

        $stations = $crawler->filter('[data-patrol-stations] .chart-plate canvas');
        self::assertCount(1, $stations);
        /** @var array{data: array{labels: list<string>}} $stationView */
        $stationView = json_decode((string) $stations->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains('North post', $stationView['data']['labels']);

        // And no module-drawn chart is left anywhere on the page.
        self::assertCount(0, $crawler->filter('svg.ch'));

        // Calendar: the HOUSE month — whole weeks, today ringed, one mark per
        // patrol on its day. The grid is the atlas's `atlas_calendar()`, so what
        // is asserted here is what this module puts IN it.
        $cells = $crawler->filter('[data-w="cal"] .cal .dc');
        self::assertContains($cells->count(), [35, 42]);
        self::assertCount(1, $crawler->filter('[data-w="cal"] .cal .dc.today'));
        self::assertCount(3, $crawler->filter('[data-w="cal"] .cal .cal-mark'));

        // Coverage map: the shipped dashboard carries ONE plate, and what is on
        // it is stated in PHP — the area's boundary, and one layer per patrol
        // type holding the tracks that were actually recorded.
        $plate = self::plate($crawler);
        $boundary = $plate['boundary'];
        self::assertIsArray($boundary);
        self::assertStringContainsString('MultiPolygon', json_encode($boundary['geojson'], \JSON_THROW_ON_ERROR));

        // Only the two patrols that actually recorded a track are drawn, whatever
        // types they were patrolled as.
        $drawn = [];
        foreach ($plate['layers'] as $layer) {
            $id = $layer['id'] ?? null;
            if (\is_string($id) && str_starts_with($id, 'patrol.tracks.')) {
                $drawn = [...$drawn, ...self::features($plate, $id)];
            }
        }
        self::assertCount(2, $drawn);
        self::assertStringContainsString('LineString', json_encode($drawn, \JSON_THROW_ON_ERROR));
        // Each drawn track knows which patrol it is and which colour to wear.
        foreach ($drawn as $feature) {
            $properties = $feature['properties'] ?? null;
            self::assertIsArray($properties);
            self::assertArrayHasKey('ref', $properties);
            self::assertArrayHasKey('color', $properties);
        }

        // The plate's own frame, and no chrome markup of the module's: the
        // controls are the atlas's, built by its one map controller.
        self::assertCount(1, $crawler->filter('.map-plate .viewer .map-canvas'));
        self::assertCount(0, $crawler->filter('.patrol-zoomui'));

        // The chrome is STYLED by AtlasBundle's map.css, which the base template
        // must link — without it the zoom pills, the Satellite/Map toggle and
        // fullscreen are built as DOM but invisible. Found in a browser: a map
        // with a legend and tiles but no controls.
        self::assertStringContainsString(
            'atlas/map',
            (string) $this->client->getResponse()->getContent(),
            'the patrol base must link the atlas map.css so the map chrome is visible',
        );

        // The filter chips are real LINKS carrying the query they select, so one
        // request drives the map, the log AND the charts.
        $types = $crawler->filter('[data-w="map"] .lfilt .i-ddmenu[aria-label="Filter by patrol type"] a.i-ddopt');
        self::assertCount(3, $types); // all types + the two configured ones
        self::assertStringNotContainsString('type=', (string) $types->first()->attr('href'));

        // The log rows name what the coverage plate spotlights when one is
        // hovered: the layer their own type is drawn in, and the reference that
        // layer identifies a feature by. Asserted on the log because it is the
        // one list the shipped composition draws.
        self::assertCount(3, $crawler->filter('[data-patrol-log] tbody tr[data-atlas-highlight]'));
        self::assertSame(
            'patrol.tracks.walk:'.$this->walkWithObservations->getRef(),
            $crawler->filter('[data-patrol-log] tbody tr[data-patrol="'.$this->walkWithObservations->getUuid()->toRfc4122().'"]')->attr('data-atlas-highlight'),
        );

        // Station markers: the design labels each station on the map. A station
        // has no coordinates of its own, so only stations whose patrols recorded
        // a track can be placed — and the rows state their station so the
        // station menu filters the list as well as the map.
        $stations = array_map(
            static fn (array $feature): mixed => \is_array($feature['properties'] ?? null) ? $feature['properties']['label'] ?? null : null,
            self::features($plate, 'patrol.stations'),
        );
        self::assertSame(['South landing', 'North post'], $stations);
        self::assertCount(
            1,
            $crawler->filter('[data-patrol-log] .lfilt .i-ddmenu[aria-label="Filter by station"] a.i-ddopt')
                ->reduce(static fn ($n): bool => 'North post' === trim($n->text())),
        );
    }

    /**
     * OVERFLOW RULE (owner: an overview card never grows with data). The log shows
     * the LATEST few of the month, not all of them, so a month of many patrols
     * leaves the card the same height as a quiet one. The whole month is still on
     * the map and the calendar — this is the recent window. (The feed obeyed the
     * same rule and was asserted here too; it is off the shipped composition now,
     * so the register is the capped list on the default screen).
     */
    public function testTheLogIsCappedToTheLatestFewOfTheMonth(): void
    {
        // Push this month well past the cards' cap of eight (3 already exist).
        $monthStart = new \DateTimeImmutable('first day of this month')->setTime(8, 0);
        for ($i = 0; $i < 10; ++$i) {
            $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
                ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
                ->setStartedAt($monthStart->modify(\sprintf('+%d minutes', $i))));
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();

        // Thirteen patrols this month, but the register renders only the latest
        // eight and says so ("latest 8 of 13").
        self::assertCount(8, $crawler->filter('[data-patrol-log] tbody tr[data-patrol]'));
        self::assertSelectorTextContains('[data-patrol-log] .tab .src', 'latest 8 of 13');
        // The feed obeyed the same cap; it is off the default composition now, so
        // it renders nowhere on this screen.
        self::assertCount(0, $crawler->filter('[data-patrol-feed]'));

        // The KPI still counts the whole month — the cap is on the card, not the
        // month.
        self::assertSelectorTextContains('[data-kpi="month"] .kpi b', '13');
    }

    /**
     * The filter bar carries THREE dropdowns in the incidents bar's chrome
     * (.i-dd*) beside the type toggles: station, zone and month. Every option in
     * every one of them is a real link that re-queries the whole dashboard, so
     * the map, the log and the charts move together.
     */
    public function testTheFilterBarCarriesStationZoneAndMonthDropdowns(): void
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();

        // FOUR dropdowns in the shared chrome, inside the map row: the patrol
        // type is one of them now, because a vocabulary a deployment writes has
        // no length a pill row can plan for.
        $dd = $crawler->filter('[data-w="map"] .lfilt .i-dd');
        self::assertCount(4, $dd);

        // Every station, plus "all", is a real option re-querying the page.
        $stations = $crawler->filter('[data-w="map"] .lfilt .i-ddmenu[aria-label="Filter by station"] a.i-ddopt')
            ->each(static fn ($n): string => trim($n->text()));
        self::assertContains('All stations', $stations);
        self::assertContains('North post', $stations);
        self::assertContains('South landing', $stations);
        self::assertCount(3, $stations);

        // The ZONE dropdown exists and its options publish the client-side filter
        // (chooseZone). This fixture draws no zone polygons, so the menu is the
        // honest empty state rather than a dead control.
        $zoneMenu = $crawler->filter('[data-w="map"] .lfilt .i-ddmenu[aria-label="Filter by zone"]');
        self::assertCount(1, $zoneMenu);

        // The MONTH dropdown is real now: this month and the five before it, each
        // a link that re-queries the dashboard (?month=YYYY-MM), the current month
        // marked as chosen. No dead indicator chip when the route is mounted.
        $monthOptions = $crawler->filter('[data-w="map"] .lfilt .i-ddmenu[aria-label="Choose month"] a.i-ddopt');
        self::assertCount(6, $monthOptions);
        self::assertStringContainsString('month=', (string) $monthOptions->first()->attr('href'));
        self::assertCount(
            1,
            $crawler->filter('[data-w="map"] .lfilt .i-ddmenu[aria-label="Choose month"] a.i-ddopt.on'),
        );
        // No dashed ghost chip, and no plain indicator chip (the fallback is only
        // for a host with no dashboard route).
        self::assertCount(0, $crawler->filter('[data-w="map"] .lfilt .patrol-ghost'));
        self::assertCount(0, $crawler->filter('[data-w="map"] .lfilt .patrol-monthchip'));

        // Every type option is a real link driving ?type= rather than a browser
        // event, so the map, the log and the charts move together.
        $typeOptions = $crawler->filter('[data-w="map"] .lfilt .i-ddmenu[aria-label="Filter by patrol type"] a.i-ddopt');
        self::assertCount(3, $typeOptions);
        self::assertStringContainsString('type=walk', (string) $typeOptions->eq(1)->attr('href'));
    }

    /**
     * THE MONTH DROPDOWN RE-SCOPES THE MAP AND LOG — the fix for the dead month
     * indicator. A patrol filed last month is absent from the default (this
     * month) view and present when last month is chosen through ?month=, and the
     * this-month patrol swaps out the other way. One filter drives the whole
     * screen, exactly as incidents does.
     */
    public function testTheMonthDropdownReScopesTheMapAndLog(): void
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('two-month reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($area);

        $this->em->persist(new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'This Month Post'))
            ->setStartedAt(new \DateTimeImmutable('first day of this month 08:00'))
            ->setEndedAt(new \DateTimeImmutable('first day of this month 10:00'))
            ->setDistanceKm(5.0));
        $this->em->persist(new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'Last Month Post'))
            ->setStartedAt(new \DateTimeImmutable('first day of last month 08:00'))
            ->setEndedAt(new \DateTimeImmutable('first day of last month 10:00'))
            ->setDistanceKm(6.0));
        $this->em->flush();
        // The new area must be running the module, exactly as an install would.
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        $base = '/areas/'.$area->getUuidString().'/modules/patrols';

        // Default view — the current month only.
        $this->client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-patrol-log] tbody', 'This Month Post');
        self::assertSelectorTextNotContains('[data-patrol-log] tbody', 'Last Month Post');

        // Choose last month — the log and the map swap to it.
        $lastMonth = new \DateTimeImmutable('first day of last month')->format('Y-m');
        $this->client->request('GET', $base.'?month='.$lastMonth);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-patrol-log] tbody', 'Last Month Post');
        self::assertSelectorTextNotContains('[data-patrol-log] tbody', 'This Month Post');
    }

    /**
     * A month that does not parse is untrusted input, not an error: the dashboard
     * degrades to the current month rather than throwing a 400.
     */
    public function testAnUnreadableMonthDegradesToTheCurrentMonth(): void
    {
        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols?month=not-a-month');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-w="map"] .lfilt .i-ddmenu[aria-label="Choose month"] a.i-ddopt.on', strtolower(new \DateTimeImmutable()->format('F Y')));
    }

    /**
     * PL·03 with nothing to measure: an area whose month holds only hand-logged
     * patrols has no geometry to buffer, so the plate shows the design's empty
     * state — an em dash and the same caption — never a false 0 %.
     */
    public function testTheCoverageKpiShowsTheEmptyStateWithoutARecordedTrack(): void
    {
        $bare = new AreaOfInterest()->setSource('test fixture')->setName('sketch reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($bare);
        $this->em->persist(new Patrol($bare, Vocabulary::type($this->em, $bare, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setDistanceKm(9.4));
        $this->em->flush();
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        $this->client->request('GET', '/areas/'.$bare->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi b', '—');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi span', 'of area within 2 km of a track');
    }

    /**
     * BEFORE THE WORKER HAS RUN the coverage KPI claims nothing — not 0 %, an
     * em dash — and its caption says when the figure comes.
     */
    public function testBeforeTheWorkerHasRunTheCoverageKpiSaysNotComputedYet(): void
    {
        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi b', '—');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi span', 'of area within 2 km of a track · not computed yet · runs hourly');
    }

    /**
     * A DOOR THAT IS LOCKED IS NOT DRAWN.
     *
     * "Log patrol" opens the ONE screen that creates patrols, and it enforces
     * `patrols.record` in code. Whether the SCREEN exists is a question about the
     * installation (it needs SecurityBundle); whether THIS PERSON may open it is
     * a question about the viewer, and the dashboard was only ever asking the
     * first. Somebody without the permission was handed a link that answered
     * 403 — the fleet's own rule is that a control the viewer may not have is
     * ABSENT rather than greyed out, and a link that fails when you follow it is
     * worse than either.
     *
     * Found in a browser, in a real installation, on a page every test called
     * successful.
     */
    public function testSomebodyWhoMayNotRecordIsNotOfferedTheEntryFlow(): void
    {
        // The bystander the suite signs in with: somebody who may read this
        // module and record nothing.
        $this->signIn($this->client, $this->em);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('.pgact')->count() ? $crawler->filter('.pgact')->html() : '';
        self::assertStringNotContainsString('Import GPX', $actions);
        self::assertStringNotContainsString('Log patrol', $actions);

        // And the route agrees, which is the half that already worked: the
        // absence above is the page telling the same truth the screen enforces.
        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/log');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSomebodyWhoMayRecordIsOfferedTheEntryFlow(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('.pgact')->html();
        // ONE entry flow, so one action: importing a GPX is step 1 of logging a
        // patrol now, not a door of its own.
        self::assertStringNotContainsString('Import GPX', $actions);
        self::assertStringContainsString('Log patrol', $actions);
        // Recording is not managing: the recorder may log a patrol but not name
        // the words everybody else must use, so the taxonomy door is not drawn.
        self::assertStringNotContainsString('Observation kinds', $actions);
    }

    /**
     * THE SAME LOCKED-DOOR RULE, FOR THE TAXONOMY ADMIN.
     *
     * "Observation kinds" opens the area's observation-taxonomy admin, every
     * route of which enforces `patrols.manage`. The whole screen is built and
     * routed, but until this it had no entry point in the product — a
     * fully-implemented, ruled screen a user could not reach. It is offered on
     * exactly the same terms as the entry flow: the route must exist (it
     * needs SecurityBundle) AND the viewer must hold the permission.
     */
    /**
     * THE READ-ONLY KINDS CARD — what a ranger may log here, with this month's
     * count under each, and one link out to the section that edits them.
     */
    public function testTheDashboardShowsTheKindsARangerCanLog(): void
    {
        $kind = new TaxonomyKind($this->area, 'maintenance', 'Maintenance');
        $this->em->persist($kind);
        $this->em->persist(new TaxonomySubcategory($kind, 'fence', 'Fence'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-w="kinds"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Maintenance', $card->filter('.kx-h b')->text());
        // Two observations were filed under this wire-code in the fixture.
        self::assertStringContainsString('2', $card->filter('.kx-h .n')->text());
        self::assertStringContainsString('fence', $card->filter('.kx-s')->text());
        self::assertStringContainsString('Edit in Configure', $card->filter('.kx-foot')->text());
    }

    public function testSomebodyWhoMayNotManageCannotOpenTheKindsScreen(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);

        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/kinds');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * THE MODULE DRAWS NO CONFIGURATION BUTTON AT ALL. There is one
     * configuration entry per surface and the shell renders it; a kinds link, a
     * Settings button and a Widget library button in a module's own action row
     * is what that ruling replaced.
     */
    public function testTheDashboardOffersNoConfigurationButtonOfItsOwn(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('.pgact')->count() ? $crawler->filter('.pgact')->html() : '';
        self::assertStringNotContainsString('Observation kinds', $actions);
        self::assertStringNotContainsString('Widget library', $actions);
        self::assertStringNotContainsString('Settings', $actions);
    }

    /**
     * THE OLD ADDRESS IS KEPT ALIVE. `…/taxonomy` was the screen's address
     * before the word a person reads became "kinds"; a saved link must not
     * become a 404 over a rename.
     */
    public function testTheOldTaxonomyAddressRedirectsToTheKindsScreen(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);

        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/taxonomy');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/areas/'.$this->area->getUuidString().'/modules/patrols/kinds');
    }

    public function testSomebodyWhoMayManageOpensTheKindsScreen(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);

        // The door opens: a manager reaches the kinds screen.
        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/kinds');
        self::assertResponseIsSuccessful();
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
}
