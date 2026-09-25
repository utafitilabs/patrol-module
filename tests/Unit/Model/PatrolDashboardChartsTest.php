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

namespace Uhifadhi\Patrol\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFigures;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Model\PatrolDashboard;

/**
 * THE DASHBOARD STATES ITS CHARTS; IT DOES NOT DRAW THEM.
 *
 * The three chart widgets used to carry a hand-built `<svg>` apiece — their
 * own axis maths, their own gridlines, their own bar geometry and their own
 * annotation styles. Three charts drawn by one module is three chances to
 * disagree with every other chart in the product, which is the whole reason
 * the atlas exists. What is left in this module is what only this module
 * knows: which series there are, what they are called, what category each
 * wears and what the numbers are.
 *
 * WHAT IS ASSERTED HERE IS THE STATEMENT, not the picture: the atlas's own
 * suite says what an AtlasChart becomes.
 */
final class PatrolDashboardChartsTest extends TestCase
{
    /** @var array<string, array{label: string}> */
    private const array TYPES = ['walk' => ['label' => 'Walking round'], 'boat' => ['label' => 'Boat']];

    /** @var array<string, int> */
    private const array CATEGORIES = ['walk' => 1, 'boat' => 2];

    /**
     * ONE SERIES PER CONFIGURED TYPE, over the five weeks — the grouped bars
     * the design draws, stated rather than plotted.
     */
    public function testTheWeeklyChartIsOneSeriesPerTypeAcrossTheWeekLabels(): void
    {
        $chart = self::dashboard()->weeklyChart(self::TYPES, self::CATEGORIES);

        self::assertSame(ChartKind::Bar, $chart->kind);
        self::assertSame(['W1', 'W2'], $chart->labels);
        self::assertSame('patrols', $chart->unit);
        // The design's row of type chips under the plate, and no figures on
        // the grouped bars (PL·09).
        self::assertSame(ChartLegend::Chips, $chart->legend);
        self::assertNull($chart->figures);
        self::assertCount(2, $chart->series);

        self::assertSame('walking round', $chart->series[0]->label);
        self::assertSame([3.0, 5.0], $chart->series[0]->points);
        self::assertSame(1, $chart->series[0]->cat);

        self::assertSame('boat', $chart->series[1]->label);
        self::assertSame([1.0, 0.0], $chart->series[1]->points);
        self::assertSame(2, $chart->series[1]->cat);
    }

    /**
     * A WEEK NOBODY WALKED IN IS A NOUGHT, NOT A HOLE. The week was counted
     * and the answer was none — which a gap in the bars would misreport as a
     * week nobody reported.
     */
    public function testAWeekWithNoPatrolsOfATypeIsANoughtAndNotAGap(): void
    {
        $points = self::dashboard()->weeklyChart(self::TYPES, self::CATEGORIES)->series[1]->points;

        self::assertNotContains(null, $points);
    }

    /**
     * A TYPE THE DEPLOYMENT HAS SINCE DROPPED still has patrols against it,
     * and dropping the series would make the chart disagree with the KPI that
     * counted them.
     */
    public function testATypeNoLongerConfiguredKeepsItsSeries(): void
    {
        $chart = self::dashboard()->weeklyChart(['walk' => ['label' => 'Walking round']], ['walk' => 1]);

        self::assertCount(2, $chart->series);
        self::assertSame('boat', $chart->series[1]->label);
        // Not one of the configured set, so it takes the next category in
        // order rather than a colour this module invented.
        self::assertNull($chart->series[1]->cat);
    }

    /** The five busiest stations, ranked sideways, the count on every bar (PL·10). */
    public function testTheStationChartIsOneRankedSeriesWithItsFigures(): void
    {
        $chart = self::dashboard()->stationChart();

        self::assertSame(ChartKind::Ranked, $chart->kind);
        self::assertSame(['North post', 'South post'], $chart->labels);
        self::assertSame('patrols', $chart->unit);
        self::assertCount(1, $chart->series);
        self::assertSame([9.0, 4.0], $chart->series[0]->points);
        self::assertEquals(new ChartFigures(), $chart->figures);
    }

    /** HOURS, NOT ROWS — ranked the same way, the figure in hours (PL·17). */
    public function testTheEffortChartIsPatrolHoursPerLeadRankedWithHoursOnTheBar(): void
    {
        $chart = self::dashboard()->effortChart();

        self::assertSame(ChartKind::Ranked, $chart->kind);
        self::assertSame(['A. Ranger'], $chart->labels);
        self::assertSame('patrol-hours', $chart->unit);
        self::assertSame([6.5], $chart->series[0]->points);
        self::assertEquals(new ChartFigures(unit: 'h'), $chart->figures);
    }

    /**
     * THE THREE BAR CHARTS SHARE ONE AXIS RULE, and it is the design's: the
     * top is the smallest multiple of three that still covers the largest
     * bar, never less than three — so the gridlines land on whole numbers
     * and an empty month keeps a width to measure against. "By station" and
     * "per week" count patrols; "Effort" plots hours, whose maximum is a
     * fraction as soon as the longest patrol is shorter than an hour.
     */
    public function testEveryBarChartRoundsItsAxisUpToTheSmallestCoveringMultipleOfThree(): void
    {
        $dashboard = self::dashboard();

        self::assertEquals(new AxisScale(6.0, 2.0), $dashboard->weeklyChart(self::TYPES, self::CATEGORIES)->axis, 'five walks in W2');
        self::assertEquals(new AxisScale(9.0, 3.0), $dashboard->stationChart()->axis, 'nine at the north post');
        self::assertEquals(new AxisScale(9.0, 3.0), $dashboard->effortChart()->axis, 'six and a half hours');

        $empty = new PatrolDashboard([], 0, 0.0, [], null, [], 0, null, [], [], [], [], [], []);
        self::assertEquals(new AxisScale(3.0, 1.0), $empty->stationChart()->axis, 'a month with nothing to plot');
    }

    /** A month nobody has closed a patrol in states nothing to draw. */
    public function testAChartWithNoRowsIsEmpty(): void
    {
        $empty = new PatrolDashboard([], 0, 0.0, [], null, [], 0, null, [], [], [], [], [], []);

        self::assertTrue($empty->stationChart()->isEmpty());
        self::assertTrue($empty->effortChart()->isEmpty());
    }

    private static function dashboard(): PatrolDashboard
    {
        $lead = new User();
        $lead->setFirstName('Asha');
        $lead->setLastName('Ranger');

        return new PatrolDashboard(
            patrols: [],
            monthCount: 0,
            monthDistanceKm: 0.0,
            monthTypeCounts: [],
            coverage: null,
            typeCounts: [],
            totalCount: 0,
            lastPatrol: null,
            weeklySeries: [
                ['label' => 'W1', 'counts' => ['walk' => 3, 'boat' => 1]],
                ['label' => 'W2', 'counts' => ['walk' => 5, 'boat' => 0]],
            ],
            stationSeries: [
                ['station' => 'north', 'label' => 'North post', 'count' => 9],
                ['station' => 'south', 'label' => 'South post', 'count' => 4],
            ],
            effortSeries: [['lead' => $lead, 'hours' => 6.5]],
            stations: [],
            zones: [],
            calendar: [],
        );
    }
}
