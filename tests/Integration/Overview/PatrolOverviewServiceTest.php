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

namespace Uhifadhi\Patrol\Tests\Integration\Overview;

use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * The module's reading of one morning — the numbers all five contributions are
 * drawn from.
 *
 * Every assertion here is about the difference between ABSENT and ZERO, because
 * that is the only way the overview can be wrong without looking wrong: a 0 km
 * on a card is a measurement, and a day of hand-logged patrols has not been
 * measured.
 */
final class PatrolOverviewServiceTest extends PatrolOverviewTestCase
{
    private function overview(): PatrolOverviewService
    {
        $service = $this->service(PatrolOverviewService::class);
        \assert($service instanceof PatrolOverviewService);

        return $service;
    }

    // ---- who is out -------------------------------------------------------

    public function testAPatrolPingingRecentlyIsNotCalledOut(): void
    {
        $patrol = $this->makePatrol('river', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($patrol, [['2026-03-21T11:30:00+00:00', -29.95, -2.95]]);

        $row = $this->overview()->out($this->area, $this->now())[0];

        self::assertFalse($row['stale']);
        self::assertSame('12 min', $row['pingLabel']);
        self::assertSame('6 h 22', $row['outLabel']);
    }

    public function testAPatrolSilentForLongerThanTheThresholdIsCalledOut(): void
    {
        $patrol = $this->makePatrol('ridge', 'boat', '2026-03-21T10:37:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($patrol, [['2026-03-21T09:32:00+00:00', -29.95, -2.95]]);

        $row = $this->overview()->out($this->area, $this->now())[0];

        self::assertTrue($row['stale']);
        self::assertSame('2 h 10', $row['pingLabel']);
    }

    public function testAPatrolJustOpenedWithNoPingsYetIsNotInTrouble(): void
    {
        $this->makePatrol('ridge', 'walk', '2026-03-21T11:38:00+00:00', status: PatrolStatusEnum::Recording);

        $row = $this->overview()->out($this->area, $this->now())[0];

        // Four minutes out and no batch yet is a patrol whose first points have
        // not arrived, not a patrol that has gone quiet.
        self::assertFalse($row['stale']);
        self::assertNull($row['pingLabel']);
        self::assertNull($row['lastPingAt']);
    }

    public function testAPatrolLongOutThatHasNeverPingedIsInTrouble(): void
    {
        $this->makePatrol('ridge', 'walk', '2026-03-21T05:00:00+00:00', status: PatrolStatusEnum::Recording);

        $row = $this->overview()->out($this->area, $this->now())[0];

        self::assertTrue($row['stale']);
        self::assertNull($row['pingSeconds']);
    }

    public function testTheTrailAndItsHeadComeBackWithTheRow(): void
    {
        $patrol = $this->makePatrol('river', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($patrol, [
            ['2026-03-21T11:00:00+00:00', -29.99, -2.95],
            ['2026-03-21T11:30:00+00:00', -29.95, -2.95],
        ]);

        $row = $this->overview()->out($this->area, $this->now())[0];

        self::assertStringContainsString('LineString', (string) $row['line']);
        self::assertStringContainsString('Point', (string) $row['point']);
    }

    // ---- the day ----------------------------------------------------------

    public function testTheDayCountsWhatCLOSEDAgainstTheSameWeekdayAWeekAgo(): void
    {
        // Today: two closed, one of them overnight.
        $this->makePatrol('a', 'walk', '2026-03-20T20:00:00+00:00', '2026-03-21T04:00:00+00:00')->setDistanceKm(12.0);
        $this->makePatrol('b', 'boat', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00')->setDistanceKm(8.5);
        // Last saturday: one closed.
        $this->makePatrol('c', 'walk', '2026-03-14T06:00:00+00:00', '2026-03-14T09:00:00+00:00')->setDistanceKm(30.0);
        // Yesterday, which is neither.
        $this->makePatrol('d', 'walk', '2026-03-20T06:00:00+00:00', '2026-03-20T09:00:00+00:00')->setDistanceKm(99.0);
        $this->em->flush();

        $today = $this->overview()->today($this->area, $this->now());

        self::assertSame(2, $today['closed']);
        self::assertSame(1, $today['closedLastWeek']);
        self::assertSame(20.5, $today['distanceKm']);
        self::assertSame(30.0, $today['distanceKmLastWeek']);
        self::assertSame(['walk' => 1, 'boat' => 1], $today['typeCounts']);
        self::assertSame(['A', 'B'], $today['stations']);
    }

    public function testEveryTypeTheAreaKeepsIsCountedIncludingTheOnesThatDidNothing(): void
    {
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00');
        // A type this area keeps but nobody used today.
        Vocabulary::type($this->em, $this->area, 'boat');
        $this->em->flush();

        $today = $this->overview()->today($this->area, $this->now());

        // A type the area keeps that did nothing today really did nothing — this
        // 0 is a measurement, not a stand-in for an unknown.
        self::assertSame(0, $today['typeCounts']['boat']);
    }

    public function testADayOfHandLoggedPatrolsWalkedADistanceNobodyMeasured(): void
    {
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00');

        $today = $this->overview()->today($this->area, $this->now());

        self::assertSame(1, $today['closed']);
        // Not 0.0. Nobody recorded how far they went.
        self::assertNull($today['distanceKm']);
    }

    public function testADiscardedPatrolClosedNothing(): void
    {
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00', PatrolStatusEnum::Discarded)
            ->setDistanceKm(40.0);
        $this->em->flush();

        $today = $this->overview()->today($this->area, $this->now());

        self::assertSame(0, $today['closed']);
        self::assertNull($today['distanceKm']);
    }

    public function testTheDayNamesWhoIsStillOutAndTheMonthTheyBelongTo(): void
    {
        $out = $this->makePatrol('ridge', 'walk', '2026-03-21T06:00:00+00:00', status: PatrolStatusEnum::Recording);
        $this->makePatrol('a', 'walk', '2026-03-05T06:00:00+00:00', '2026-03-05T09:00:00+00:00')->setDistanceKm(10.0);
        $this->em->flush();

        $today = $this->overview()->today($this->area, $this->now());

        self::assertSame(1, $today['stillOut']);
        self::assertSame([$out->getRef()], $today['stillOutRefs']);
        // The month figure exists to say the day's numbers do NOT add up to it.
        self::assertSame(1, $today['monthCount']);
        self::assertSame(10.0, $today['monthDistanceKm']);
    }

    // ---- where nobody has been -------------------------------------------

    public function testAZoneNoTrackEverEnteredHasNoAgeAndSortsFirst(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makeZone('South', -3.0, -2.95);
        $this->makePatrol('a', 'walk', '2026-03-19T06:00:00+00:00', '2026-03-19T09:00:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->runWorker();

        $gaps = $this->overview()->gaps($this->area, $this->now());

        self::assertSame('South', $gaps['zones'][0]['zone']);
        self::assertTrue($gaps['zones'][0]['never']);
        self::assertNull($gaps['zones'][0]['daysSince']);
        self::assertSame('North', $gaps['zones'][1]['zone']);
        self::assertSame(2, $gaps['zones'][1]['daysSince']);
        self::assertNotNull($gaps['zones'][1]['lastPatrol']);
    }

    public function testDaysSinceIsWholeCalendarDaysNotHours(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        // Entered at 23:50 last night. This morning that is one day ago, not
        // "still today" for another eight hours.
        $this->makePatrol('a', 'walk', '2026-03-20T23:50:00+00:00', '2026-03-21T00:30:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->runWorker();

        $gaps = $this->overview()->gaps($this->area, $this->now());

        self::assertSame(1, $gaps['zones'][0]['daysSince']);
    }

    /**
     * BEFORE THE WORKER HAS RUN a zone is neither a gap nor patrolled: it is
     * not computed yet, sorts after every measured zone, and claims no date.
     */
    public function testAZoneTheWorkerHasNotMeasuredIsNotComputedAndSortsLast(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makeZone('South', -3.0, -2.95);
        $this->makePatrol('a', 'walk', '2026-03-19T06:00:00+00:00', '2026-03-19T09:00:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->em->flush();

        $gaps = $this->overview()->gaps($this->area, $this->now());

        foreach ($gaps['zones'] as $zone) {
            self::assertFalse($zone['computed']);
            self::assertFalse($zone['never']);
            self::assertNull($zone['daysSince']);
            self::assertNull($zone['coverageFraction']);
        }
        self::assertNull($gaps['asOf']);
        self::assertNull($gaps['areaCoverageFraction']);
    }

    /** The area's share and the zones' come from the same run, and the card says when. */
    public function testTheWorkersRunGivesTheAreaShareAndTheStamp(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makePatrol('a', 'walk', '2026-03-19T06:00:00+00:00', '2026-03-19T09:00:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->runWorker();

        $gaps = $this->overview()->gaps($this->area, $this->now());

        self::assertNotNull($gaps['asOf']);
        self::assertNotNull($gaps['areaCoverageFraction']);
        self::assertGreaterThan(0.0, $gaps['areaCoverageFraction']);
        self::assertLessThan(1.0, $gaps['areaCoverageFraction']);
        self::assertNotNull($gaps['zones'][0]['coverageFraction']);
    }

    public function testAnAreaWithNoZonesMeasuresNoAbsence(): void
    {
        self::assertSame([], $this->overview()->gaps($this->area, $this->now())['zones']);
    }

    // ---- the observation queue -------------------------------------------

    public function testObservationsComeBackOldestFirstWithTheirAgeAndZone(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $patrol = $this->makePatrol('a', 'walk', '2026-03-19T06:00:00+00:00', '2026-03-19T09:00:00+00:00');
        $older = $this->makeObservation($patrol, '2026-03-16T11:42:00+00:00', '{"type":"Point","coordinates":[-29.95,-2.92]}');
        $newer = $this->makeObservation($patrol, '2026-03-21T05:42:00+00:00');

        $rows = $this->overview()->observations($this->area, $this->now())['rows'];

        self::assertSame($older->getId(), $rows[0]['observation']->getId());
        self::assertSame('5 d', $rows[0]['ageLabel']);
        self::assertSame('North', $rows[0]['zone']);
        self::assertSame($newer->getId(), $rows[1]['observation']->getId());
        self::assertSame('6 h 00', $rows[1]['ageLabel']);
        // An unpositioned observation is given no zone rather than a guess.
        self::assertNull($rows[1]['zone']);
    }

    public function testTheQueueIsAPageOfWorkAndNotAnArchive(): void
    {
        $patrol = $this->makePatrol('a', 'walk', '2026-03-19T06:00:00+00:00', '2026-03-19T09:00:00+00:00');
        for ($i = 0; $i < PatrolOverviewService::OBSERVATION_ROWS + 3; ++$i) {
            $this->makeObservation($patrol, \sprintf('2026-03-%02dT06:00:00+00:00', 10 + $i));
        }

        $observations = $this->overview()->observations($this->area, $this->now());

        self::assertCount(PatrolOverviewService::OBSERVATION_ROWS, $observations['rows']);
        // The month figure counts them all — the page is a page, not the total.
        self::assertSame(PatrolOverviewService::OBSERVATION_ROWS + 3, $observations['monthCount']);
    }

    // ---- how long, said once ---------------------------------------------

    public function testAgeIsSaidTheWayTheDesignSaysIt(): void
    {
        self::assertSame('0 min', PatrolOverviewService::ageLabel(0));
        self::assertSame('12 min', PatrolOverviewService::ageLabel(12 * 60));
        self::assertSame('2 h 10', PatrolOverviewService::ageLabel(130 * 60));
        self::assertSame('6 h 00', PatrolOverviewService::ageLabel(6 * 3600));
        self::assertSame('9 d', PatrolOverviewService::ageLabel(9 * 86400));
    }
}
