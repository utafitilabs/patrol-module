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

namespace Uhifadhi\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Atlas\PlatePalette;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * The dashboard's data contract — everything the widget screen binds, computed
 * from plain entities so it is testable without a container. "Now" is always
 * passed in: the service must never call the clock itself.
 */
final class PatrolDashboardServiceTest extends TestCase
{
    /** @var array<string, array{label: string}> */
    private const array TYPES = ['walk' => ['label' => 'Walking round'], 'boat' => ['label' => 'Boat']];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-03-21T12:00:00Z');
    }

    private function patrol(string $type, string $startedAt, float $km, ?string $station = null, int $observations = 0): Patrol
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $patrol = new Patrol($area, Vocabulary::type(null, $area, $type))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStationRecord(Vocabulary::station(null, $area, $station))
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setEndedAt(new \DateTimeImmutable($startedAt)->modify('+2 hours'))
            ->setDistanceKm($km);
        for ($i = 0; $i < $observations; ++$i) {
            new Observation($patrol, 'maintenance');
        }

        return $patrol;
    }

    public function testKpisSummariseTheMonth(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post', observations: 2),
            $this->patrol('walk', '2026-03-05T06:00:00Z', 4.5),
            $this->patrol('boat', '2026-03-19T08:00:00Z', 20.5),
            // Previous month — the map, log and chips all read ONE month, so this
            // one is out of every figure (it still shows on the calendar, dimmed,
            // and in the five-week chart, which reach past the month by design).
            $this->patrol('walk', '2026-02-27T06:00:00Z', 7.0),
        ], self::TYPES, $this->now);

        self::assertSame(3, $dashboard->monthCount);
        self::assertEqualsWithDelta(35.0, $dashboard->monthDistanceKm, 0.001);
        self::assertSame(['walk' => 2, 'boat' => 1], $dashboard->monthTypeCounts);
        // The map + log read the month, so the log rows are the month's only.
        self::assertCount(3, $dashboard->patrols);
        // Type counts and the total drive the filter chips, and they too are the
        // month's — the previous month's walk is not among them.
        self::assertSame(['walk' => 2, 'boat' => 1], $dashboard->typeCounts);
        self::assertSame(3, $dashboard->totalCount);
        // Last patrol: the latest start in the month.
        self::assertNotNull($dashboard->lastPatrol);
        self::assertSame('North post', $dashboard->lastPatrol->getStation());
    }

    /**
     * THE MONTH FILTER re-scopes the whole screen: handed a month, the map, log
     * and chips read THAT month, not the one containing "now". This is the fix for
     * the dead month indicator — a selected month drives everything.
     */
    public function testAChosenMonthReScopesTheMapLogAndChips(): void
    {
        $patrols = [
            $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post'),
            $this->patrol('boat', '2026-03-19T08:00:00Z', 20.5, 'Jetty'),
            $this->patrol('walk', '2026-02-27T06:00:00Z', 7.0, 'North post'),
            $this->patrol('walk', '2026-02-10T06:00:00Z', 3.0, 'North post'),
        ];

        // Ask for FEBRUARY explicitly, though "now" is in March.
        $february = new \DateTimeImmutable('2026-02-01T00:00:00Z');
        $dashboard = new PatrolDashboardService()->build($patrols, self::TYPES, $this->now, null, new PatrolFilter($february));

        self::assertSame(2, $dashboard->monthCount);
        self::assertCount(2, $dashboard->patrols, 'The log reads the chosen month, not the current one.');
        self::assertSame(['walk' => 2, 'boat' => 0], $dashboard->typeCounts);
        self::assertSame(2, $dashboard->totalCount);
        // The calendar is the chosen month too: February 2026 begins on a Sunday,
        // so a Monday-start grid opens on Jan 26.
        self::assertSame('2026-01-26', $dashboard->calendar[0]['date']->format('Y-m-d'));
    }

    /**
     * ONE FILTER DRIVES EVERYTHING. Type, station and zone are query parameters
     * now, so the narrowing happens HERE, once, and the map, the log, the KPIs
     * and the charts are all readings of the same narrowed set — they cannot
     * disagree the way three widgets answering a browser event could.
     */
    public function testTheFilterNarrowsEveryFigureOnTheScreenAtOnce(): void
    {
        $walkNorth = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $walkJetty = $this->patrol('walk', '2026-03-19T06:00:00Z', 4.0, 'Jetty');
        $boatNorth = $this->patrol('boat', '2026-03-18T08:00:00Z', 20.5, 'North post');

        $dashboard = new PatrolDashboardService()->build(
            [$walkNorth, $walkJetty, $boatNorth],
            self::TYPES,
            $this->now,
            null,
            new PatrolFilter(new \DateTimeImmutable('2026-03-01T00:00:00Z'), type: 'walk'),
        );

        self::assertCount(2, $dashboard->patrols, 'The log reads the filtered set.');
        self::assertSame(2, $dashboard->monthCount);
        self::assertEqualsWithDelta(14.0, $dashboard->monthDistanceKm, 0.001);
        self::assertSame(['walk' => 2, 'boat' => 0], $dashboard->typeCounts);
        // The charts narrow too: no bar anywhere counts the boat patrol.
        self::assertSame(0, array_sum(array_map(
            static fn (array $week): int => $week['counts']['boat'] ?? 0,
            $dashboard->weeklySeries,
        )));
        self::assertSame(
            [['station' => Vocabulary::stationKey('north-post'), 'label' => 'North post', 'count' => 1], ['station' => Vocabulary::stationKey('jetty'), 'label' => 'Jetty', 'count' => 1]],
            $dashboard->stationSeries,
        );
    }

    /** Station and zone narrow the same way, and the calendar follows. */
    public function testTheStationAndZoneAxesNarrowTheScreenToo(): void
    {
        $north = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $jetty = $this->patrol('boat', '2026-03-19T08:00:00Z', 20.5, 'Jetty');
        $zones = [
            $north->getUuid()->toRfc4122() => 'Highland',
            $jetty->getUuid()->toRfc4122() => 'Basin floor',
        ];
        $march = new \DateTimeImmutable('2026-03-01T00:00:00Z');

        $byStation = new PatrolDashboardService()->build(
            [$north, $jetty],
            self::TYPES,
            $this->now,
            null,
            new PatrolFilter($march, station: Vocabulary::stationKey('north-post')),
            $zones,
        );
        self::assertCount(1, $byStation->patrols);
        self::assertSame(1, $byStation->monthCount);

        $byZone = new PatrolDashboardService()->build(
            [$north, $jetty],
            self::TYPES,
            $this->now,
            null,
            new PatrolFilter($march, zone: 'Basin floor'),
            $zones,
        );
        self::assertCount(1, $byZone->patrols);
        self::assertSame($jetty->getRef(), $byZone->patrols[0]->getRef());
        // The calendar draws the filtered set, so a filtered-out day is an empty day.
        self::assertSame(1, array_sum(array_map(
            static fn (array $cell): int => \count($cell['patrols']),
            $byZone->calendar,
        )));
    }

    /**
     * THE MENUS STAY REACHABLE. The station you chose must not be the only one
     * the station menu still offers, or the filter is a door that locks behind
     * you. The menus are the month's, before the narrowing; the counts are after.
     */
    public function testTheFilterMenusListTheWholeMonthNotTheNarrowedView(): void
    {
        $north = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $jetty = $this->patrol('boat', '2026-03-19T08:00:00Z', 20.5, 'Jetty');
        $zones = [
            $north->getUuid()->toRfc4122() => 'Highland',
            $jetty->getUuid()->toRfc4122() => 'Basin floor',
        ];

        $dashboard = new PatrolDashboardService()->build(
            [$north, $jetty],
            self::TYPES,
            $this->now,
            null,
            new PatrolFilter(new \DateTimeImmutable('2026-03-01T00:00:00Z'), station: Vocabulary::stationKey('north-post')),
            $zones,
        );

        self::assertSame([Vocabulary::stationKey('jetty') => 'Jetty', Vocabulary::stationKey('north-post') => 'North post'], $dashboard->stations);
        self::assertSame(['Basin floor', 'Highland'], $dashboard->zones);
    }

    /**
     * THE ZONE FILTER's menu is the distinct zones the month's patrols set out in,
     * sorted — computed from the spatial join the caller hands in (patrol id →
     * zone name), never a stored field. A patrol absent from the map (no track, so
     * no zone) contributes nothing.
     */
    public function testZonesListTheDistinctZonesTheMonthsPatrolsSetOutIn(): void
    {
        $north = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $ridge = $this->patrol('boat', '2026-03-19T08:00:00Z', 20.5, 'Ridge camp');
        $northAgain = $this->patrol('walk', '2026-03-18T06:00:00Z', 4.0, 'North post');
        $unzoned = $this->patrol('walk', '2026-03-17T06:00:00Z', 2.0, 'Lake post');

        $patrolZones = [
            $north->getUuid()->toRfc4122() => 'Highland',
            $ridge->getUuid()->toRfc4122() => 'Basin floor',
            $northAgain->getUuid()->toRfc4122() => 'Highland',
            // $unzoned is absent — its start fell in no zone.
        ];

        $dashboard = new PatrolDashboardService()->build(
            [$north, $ridge, $northAgain, $unzoned],
            self::TYPES,
            $this->now,
            null,
            null,
            $patrolZones,
        );

        // Distinct and sorted, one entry per zone however many patrols fell in it.
        self::assertSame(['Basin floor', 'Highland'], $dashboard->zones);
    }

    /**
     * The LOAD window is wider than the month: it must cover the calendar grid's
     * dimmed neighbours and the five-week chart's reach before the month, so a
     * single query feeds all three.
     */
    public function testLoadRangeSpansTheMonthItsGridAndTheFiveWeekChart(): void
    {
        // March 2026, viewed from within it.
        [$from, $until] = PatrolDashboardService::loadRange(
            new \DateTimeImmutable('2026-03-01T00:00:00Z'),
            $this->now,
        );

        // The grid opens on Feb 23 (Monday before Mar 1, a Sunday); the five weeks
        // to Mar 21 open on Feb 16. The wider of the two wins.
        self::assertSame('2026-02-16 00:00:00', $from->format('Y-m-d H:i:s'));
        // The grid ends 42 days after Feb 23 — Apr 6 — past the month's own close.
        self::assertSame('2026-04-06 00:00:00', $until->format('Y-m-d H:i:s'));
    }

    /**
     * PL·03 is queried (PostGIS), not derived from the rows, so the service only
     * carries it — and carries "unknown" as null rather than flattening it to
     * 0.0, which the KPI would print as a false 0 %.
     */
    public function testCoverageIsCarriedThroughAndDefaultsToUnknown(): void
    {
        $patrols = [$this->patrol('walk', '2026-03-20T06:00:00Z', 1.0)];

        self::assertNull(new PatrolDashboardService()->build($patrols, self::TYPES, $this->now)->coverageFraction);
        self::assertSame(0.63, new PatrolDashboardService()->build($patrols, self::TYPES, $this->now, 0.63)->coverageFraction);
    }

    /**
     * The window PL·03's query must be asked for is the very window PL·01 and
     * PL·02 count in — half-open, from midnight on the first.
     */
    public function testMonthRangeIsTheWindowTheMonthKpisCountIn(): void
    {
        [$from, $until] = PatrolDashboardService::monthRange($this->now);

        self::assertSame('2026-03-01 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-04-01 00:00:00', $until->format('Y-m-d H:i:s'));
    }

    public function testTypesWithoutPatrolsStillGetAChipCount(): void
    {
        $dashboard = new PatrolDashboardService()->build(
            [$this->patrol('walk', '2026-03-20T06:00:00Z', 1.0)],
            self::TYPES,
            $this->now,
        );

        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->typeCounts);
    }

    public function testWeeklySeriesCoversTheLastFiveWeeksByType(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 1.0), // this week (W5)
            $this->patrol('boat', '2026-03-16T06:00:00Z', 1.0), // this week (W5)
            $this->patrol('walk', '2026-03-10T06:00:00Z', 1.0), // last week (W4)
            $this->patrol('walk', '2026-02-16T06:00:00Z', 1.0), // W1
            $this->patrol('walk', '2026-01-01T06:00:00Z', 1.0), // older — outside the window
        ], self::TYPES, $this->now);

        self::assertCount(5, $dashboard->weeklySeries);
        $labels = array_column($dashboard->weeklySeries, 'label');
        self::assertSame(['W1', 'W2', 'W3', 'W4', 'W5'], $labels);
        self::assertSame(['walk' => 1, 'boat' => 1], $dashboard->weeklySeries[4]['counts']);
        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->weeklySeries[3]['counts']);
        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->weeklySeries[0]['counts']);
    }

    public function testStationSeriesRanksThisMonthsStations(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 1.0, 'North post'),
            $this->patrol('walk', '2026-03-18T06:00:00Z', 1.0, 'North post'),
            $this->patrol('boat', '2026-03-17T06:00:00Z', 1.0, 'Jetty'),
            $this->patrol('walk', '2026-03-16T06:00:00Z', 1.0, null), // no station — grouped as unassigned
        ], self::TYPES, $this->now);

        self::assertSame([['station' => Vocabulary::stationKey('north-post'), 'label' => 'North post', 'count' => 2], ['station' => Vocabulary::stationKey('jetty'), 'label' => 'Jetty', 'count' => 1]], $dashboard->stationSeries);
        // The CHART is ranked; the MENU is sorted, because a list somebody has to
        // find a name in is read alphabetically, not by how busy the month was.
        self::assertSame([Vocabulary::stationKey('jetty') => 'Jetty', Vocabulary::stationKey('north-post') => 'North post'], $dashboard->stations);
    }

    public function testCalendarPlacesPatrolsOnTheirDays(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 1.0),
            $this->patrol('boat', '2026-03-20T09:00:00Z', 1.0),
            $this->patrol('walk', '2026-03-02T06:00:00Z', 1.0),
        ], self::TYPES, $this->now);

        // March 2026 starts on a Sunday; a Monday-start grid opens on Feb 23.
        $calendar = $dashboard->calendar;
        self::assertSame('2026-02-23', $calendar[0]['date']->format('Y-m-d'));
        self::assertCount(42, $calendar);

        $byDate = [];
        foreach ($calendar as $cell) {
            $byDate[$cell['date']->format('Y-m-d')] = $cell;
        }
        self::assertCount(2, $byDate['2026-03-20']['patrols']);
        self::assertCount(1, $byDate['2026-03-02']['patrols']);
        self::assertTrue($byDate['2026-03-21']['today']);
        self::assertFalse($byDate['2026-03-20']['today']);
        self::assertTrue($byDate['2026-02-23']['outside']);
        self::assertFalse($byDate['2026-03-02']['outside']);
    }

    /* ── an ARBITRARY month (the calendar's ‹ › navigation) ──────────────── */

    public function testCalendarForGroupsAnArbitraryMonthByDay(): void
    {
        // August 2026 starts on a Saturday, so a Monday-start grid opens on
        // Jul 27 and the month's first two days sit in the opening week.
        $august = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $cells = new PatrolDashboardService()->calendarFor([
            // The BOUNDARIES: the very first and the very last day of the month.
            $this->patrol('walk', '2026-08-01T06:00:00Z', 1.0),
            $this->patrol('boat', '2026-08-31T18:30:00Z', 1.0),
            $this->patrol('walk', '2026-08-31T05:00:00Z', 1.0),
            // A neighbouring month's patrol: it still shows, on its own day, in
            // the dimmed leading/trailing cells the grid draws anyway.
            $this->patrol('walk', '2026-07-30T06:00:00Z', 1.0),
        ], $august, $this->now);

        self::assertCount(42, $cells);
        self::assertSame('2026-07-27', $cells[0]['date']->format('Y-m-d'));

        $byDate = [];
        foreach ($cells as $cell) {
            $byDate[$cell['date']->format('Y-m-d')] = $cell;
        }
        self::assertCount(1, $byDate['2026-08-01']['patrols']);
        self::assertCount(2, $byDate['2026-08-31']['patrols']);
        self::assertCount(1, $byDate['2026-07-30']['patrols']);
        self::assertTrue($byDate['2026-07-30']['outside']);
        self::assertFalse($byDate['2026-08-01']['outside']);
        self::assertFalse($byDate['2026-08-31']['outside']);
        // "Today" is a fact about the clock, not about the month on screen: a
        // month that does not contain today rings nothing.
        foreach ($cells as $cell) {
            self::assertFalse($cell['today']);
        }
    }

    public function testCalendarForRendersAnEmptyMonthAsAFullGridOfEmptyDays(): void
    {
        $cells = new PatrolDashboardService()->calendarFor(
            [],
            new \DateTimeImmutable('2027-02-01T00:00:00Z'),
            $this->now,
        );

        self::assertCount(42, $cells);
        // February 2027 starts on a Monday — the grid opens on the 1st itself.
        self::assertSame('2027-02-01', $cells[0]['date']->format('Y-m-d'));
        foreach ($cells as $cell) {
            self::assertSame([], $cell['patrols']);
        }
    }

    public function testCalendarForMarksTodayInTheMonthThatContainsIt(): void
    {
        $cells = new PatrolDashboardService()->calendarFor(
            [],
            new \DateTimeImmutable('2026-03-14T09:00:00Z'), // any instant in the month
            $this->now,
        );

        $today = array_values(array_filter($cells, static fn (array $cell): bool => $cell['today']));
        self::assertCount(1, $today);
        self::assertSame('2026-03-21', $today[0]['date']->format('Y-m-d'));
    }

    public function testCalendarRangeIsTheGridTheCellsCover(): void
    {
        [$from, $until] = PatrolDashboardService::calendarRange(new \DateTimeImmutable('2026-08-17T13:45:00Z'));

        self::assertSame('2026-07-27 00:00:00', $from->format('Y-m-d H:i:s'));
        // Exclusive: the day after the grid's last cell (42 days on).
        self::assertSame('2026-09-07 00:00:00', $until->format('Y-m-d H:i:s'));
    }

    /* ── the coverage map's payload ──────────────────────────────────────── */

    public function testCoveragePayloadCarriesTheBoundaryAndEveryRecordedTrack(): void
    {
        $service = new PatrolDashboardService();
        $walk = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $walk->setTrack('{"type":"LineString","coordinates":[[-29.5,-3.2],[-29.4,-3.3]]}');
        $boat = $this->patrol('boat', '2026-03-19T06:00:00Z', 4.0);
        $boat->setTrack('{"type":"LineString","coordinates":[[-29.9,-3.1],[-29.8,-3.15]]}');

        $boundary = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.1,-3.0],[-29.1,-3.6],[-30.0,-3.6],[-30.0,-3.0]]]]}';
        $payload = $service->coveragePayload(
            $boundary,
            $service->build([$walk, $boat], self::TYPES, $this->now),
            self::TYPES,
        );

        self::assertSame($boundary, $payload['boundary']);
        self::assertCount(2, $payload['patrols']);
        self::assertSame($walk->getUuid()->toRfc4122(), $payload['patrols'][0]['uuid']);
        self::assertSame($walk->getRef(), $payload['patrols'][0]['ref']);
        self::assertSame('walk', $payload['patrols'][0]['type']);
        // The swatch is the plate token this type's CATEGORY resolves to — the
        // same category the chips, charts and legend put it in.
        self::assertSame(PlatePalette::category(1), $payload['patrols'][0]['color']);
        self::assertSame(PatrolDashboardService::typeSwatches(self::TYPES)['walk'], $payload['patrols'][0]['color']);
        self::assertSame($walk->getTrack(), $payload['patrols'][0]['track']);
        self::assertSame('boat', $payload['patrols'][1]['type']);
    }

    public function testCoveragePayloadLeavesOutPatrolsWithoutATrack(): void
    {
        $service = new PatrolDashboardService();
        // A hand-logged patrol carries no geometry — it must not be drawn, and
        // it must not break the payload either.
        $sketch = $this->patrol('walk', '2026-03-20T06:00:00Z', 3.0);
        $recorded = $this->patrol('boat', '2026-03-19T06:00:00Z', 4.0);
        $recorded->setTrack('{"type":"LineString","coordinates":[[-29.9,-3.1],[-29.8,-3.15]]}');

        $payload = $service->coveragePayload(
            null,
            $service->build([$sketch, $recorded], self::TYPES, $this->now),
            self::TYPES,
        );

        self::assertNull($payload['boundary']);
        self::assertCount(1, $payload['patrols']);
        self::assertSame($recorded->getUuid()->toRfc4122(), $payload['patrols'][0]['uuid']);
        // A list, never a gappy array: json_encode must emit [] not {"1":…}.
        self::assertSame(range(0, \count($payload['patrols']) - 1), array_keys($payload['patrols']));
    }

    public function testCoveragePayloadOfAnAreaWithoutPatrolsStillCarriesTheBoundary(): void
    {
        $service = new PatrolDashboardService();
        $boundary = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.1,-3.0],[-29.1,-3.6],[-30.0,-3.6],[-30.0,-3.0]]]]}';

        $payload = $service->coveragePayload($boundary, $service->build([], self::TYPES, $this->now), self::TYPES);

        self::assertSame($boundary, $payload['boundary']);
        self::assertSame([], $payload['patrols']);
        self::assertSame([], $payload['stations']);
    }

    /**
     * A STATION STANDS WHERE THE AREA PUT IT — its record carries a point. A
     * station the area does not keep is only a word on the patrol, and the map
     * draws the word where that patrol set out, the best evidence there is.
     */
    public function testCoveragePayloadPlacesAStationWhereItStandsAndAWordWhereItsPatrolSetOut(): void
    {
        $service = new PatrolDashboardService();
        $north = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $north->setTrack('{"type":"LineString","coordinates":[[-29.5,-3.2],[-29.4,-3.3]]}');
        // A second patrol from the same station: one marker, not two.
        $northAgain = $this->patrol('boat', '2026-03-18T06:00:00Z', 5.0, 'North post');
        $northAgain->setTrack('{"type":"LineString","coordinates":[[-29.45,-3.25],[-29.3,-3.4]]}');
        // A word off a handset, no record: placed at its patrol's first fix.
        $jetty = $this->patrol('boat', '2026-03-19T06:00:00Z', 4.0);
        $jetty->setStationWord('Jetty');
        $jetty->setTrack('{"type":"LineString","coordinates":[[-29.9,-3.1],[-29.8,-3.15]]}');
        // No station, and a word whose patrol recorded no track: neither can
        // be placed on a map, so neither is invented.
        $anonymous = $this->patrol('walk', '2026-03-17T06:00:00Z', 2.0);
        $anonymous->setTrack('{"type":"LineString","coordinates":[[-29.1,-3.9],[-29.05,-3.95]]}');
        $unplaceable = $this->patrol('walk', '2026-03-16T06:00:00Z', 2.0);
        $unplaceable->setStationWord('Sketch camp');

        $payload = $service->coveragePayload(
            null,
            $service->build([$north, $northAgain, $jetty, $anonymous, $unplaceable], self::TYPES, $this->now),
            self::TYPES,
        );

        [$lon, $lat] = Vocabulary::stationPoint('north-post');
        self::assertSame([
            ['name' => 'North post', 'lon' => $lon, 'lat' => $lat],
            ['name' => 'Jetty', 'lon' => -29.9, 'lat' => -3.1],
        ], $payload['stations']);
        // Each track states its station too, so the station filter can drive the
        // map the same way the type chips do.
        self::assertSame(Vocabulary::stationKey('north-post'), $payload['patrols'][0]['station']);
        self::assertSame('', $payload['patrols'][3]['station']);
    }

    /**
     * THE EXCLUSION, stated once over every figure the service computes.
     *
     * A discarded patrol is in the list and in nothing else. The two patrols
     * here are deliberately identical apart from the discard, so every
     * assertion below is about that one difference and nothing else.
     */
    public function testADiscardedPatrolIsListedAndCountedNowhere(): void
    {
        $kept = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post', observations: 2);
        $thrownAway = $this->patrol('walk', '2026-03-21T06:00:00Z', 40.0, 'South post', observations: 1)
            ->discard('Started by mistake');

        $dashboard = new PatrolDashboardService()->build([$thrownAway, $kept], self::TYPES, $this->now);

        // The register keeps it — a ranger who uploaded a patrol must be able to
        // find it, whatever became of it.
        self::assertCount(2, $dashboard->patrols);

        // And every figure ignores it.
        self::assertSame(1, $dashboard->monthCount);
        self::assertEqualsWithDelta(10.0, $dashboard->monthDistanceKm, 0.001, 'The discarded 40 km must not be in the month total.');
        self::assertSame(['walk' => 1], $dashboard->monthTypeCounts);
        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->typeCounts);
        self::assertSame(1, $dashboard->totalCount);
        self::assertSame([['station' => Vocabulary::stationKey('north-post'), 'label' => 'North post', 'count' => 1]], $dashboard->stationSeries);

        // The last-patrol line names the last patrol that COUNTS, even though
        // the discarded one started later.
        self::assertNotNull($dashboard->lastPatrol);
        self::assertSame('North post', $dashboard->lastPatrol->getStation());

        // …including the five-week series, whose current week holds both starts.
        $currentWeek = $dashboard->weeklySeries[\count($dashboard->weeklySeries) - 1];
        self::assertSame(['walk' => 1, 'boat' => 0], $currentWeek['counts']);
    }

    /**
     * The coverage map draws ground covered, so a discarded track is not on it —
     * while a discarded patrol with the same geometry stays in the list beside
     * the map.
     */
    public function testADiscardedTrackIsNotOnTheCoverageMap(): void
    {
        $line = '{"type":"LineString","coordinates":[[-29.6,-3.2],[-29.5,-3.1]]}';
        $kept = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post')->setTrack($line);
        $thrownAway = $this->patrol('boat', '2026-03-20T07:00:00Z', 10.0, 'South post')
            ->setTrack($line)
            ->discard('Testing');

        $service = new PatrolDashboardService();
        $dashboard = $service->build([$thrownAway, $kept], self::TYPES, $this->now);
        $payload = $service->coveragePayload(null, $dashboard, self::TYPES);

        self::assertCount(1, $payload['patrols']);
        self::assertSame('walk', $payload['patrols'][0]['type']);
        // The station marker is placed from a drawn track, so the discarded
        // patrol's station gets none either — no half-presence on the map.
        self::assertSame(['North post'], array_column($payload['stations'], 'name'));
    }

    /**
     * The calendar is a list of days, not a figure about them: a patrol
     * discarded on the 20th is still findable on the 20th.
     */
    public function testTheCalendarKeepsADiscardedPatrolOnItsDay(): void
    {
        $thrownAway = $this->patrol('walk', '2026-03-20T06:00:00Z', 3.0)->discard('Started by mistake');

        $cells = new PatrolDashboardService()->calendarFor([$thrownAway], $this->now, $this->now);

        $twentieth = array_values(array_filter(
            $cells,
            static fn (array $cell): bool => '2026-03-20' === $cell['date']->format('Y-m-d'),
        ));
        self::assertCount(1, $twentieth);
        self::assertCount(1, $twentieth[0]['patrols']);
    }

    /**
     * EFFORT BY RANGER — patrol-hours this month per committed lead, ranked, most
     * first. Hours are the span between a patrol's start and its close; a patrol
     * with no lead credits nobody and one still open (no close) has no measured
     * duration — neither is 0 h, both are simply absent.
     */
    public function testEffortSeriesRanksPatrolHoursByLead(): void
    {
        $laizer = new User()->setPassword('x')->setEmail('sl@example.test')->setFirstName('Suzan')->setLastName('Laizer');
        $mollel = new User()->setPassword('x')->setEmail('jm@example.test')->setFirstName('John')->setLastName('Mollel');

        // Laizer: 3 h + 2 h = 5 h across two patrols; Mollel: 4 h across one.
        $a = $this->patrol('walk', '2026-03-10T06:00:00Z', 12.0)->setLead($laizer);
        $a->setEndedAt(new \DateTimeImmutable('2026-03-10T09:00:00Z'));
        $b = $this->patrol('walk', '2026-03-12T06:00:00Z', 8.0)->setLead($laizer);
        $b->setEndedAt(new \DateTimeImmutable('2026-03-12T08:00:00Z'));
        $c = $this->patrol('boat', '2026-03-11T06:00:00Z', 20.0)->setLead($mollel);
        $c->setEndedAt(new \DateTimeImmutable('2026-03-11T10:00:00Z'));
        // No lead — credits nobody. Still open — no measured duration.
        $d = $this->patrol('walk', '2026-03-13T06:00:00Z', 5.0);
        $e = $this->patrol('walk', '2026-03-14T06:00:00Z', 5.0)->setLead($mollel);
        $e->setEndedAt(null);
        // Previous month — out of the month's effort.
        $f = $this->patrol('walk', '2026-02-20T06:00:00Z', 5.0)->setLead($laizer);
        $f->setEndedAt(new \DateTimeImmutable('2026-02-20T15:00:00Z'));

        $dashboard = new PatrolDashboardService()->build([$a, $b, $c, $d, $e, $f], self::TYPES, $this->now);

        self::assertCount(2, $dashboard->effortSeries);
        // Ranked most-first: Laizer 5 h before Mollel 4 h.
        self::assertSame($laizer, $dashboard->effortSeries[0]['lead']);
        self::assertEqualsWithDelta(5.0, $dashboard->effortSeries[0]['hours'], 0.001);
        self::assertSame($mollel, $dashboard->effortSeries[1]['lead']);
        self::assertEqualsWithDelta(4.0, $dashboard->effortSeries[1]['hours'], 0.001);
    }
}
