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

namespace Uhifadhi\Patrol\Model;

use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;

/**
 * Everything the dashboard screen binds, in one immutable bag — computed by
 * {@see \Uhifadhi\Patrol\Service\PatrolDashboardService}, consumed by
 * the dashboard and widget-library templates (both render the same widgets).
 */
final readonly class PatrolDashboard
{
    /**
     * HOW MANY STATIONS THE RANKING SHOWS — the design's five, capped here
     * rather than in the template so every reader of the ranking agrees on
     * how long it is.
     */
    public const int STATION_ROWS = 5;

    /** And how many rangers the effort ranking shows. */
    public const int EFFORT_ROWS = 6;

    /**
     * @param list<Patrol>                                                                             $patrols          latest first — the log/feed rows, scoped to the month on screen (the map + log + charts all read one month, driven by the MONTH filter)
     * @param int                                                                                      $monthCount       patrols started this month
     * @param float                                                                                    $monthDistanceKm  distance sum this month
     * @param array<string, int>                                                                       $monthTypeCounts  this month, keyed by type
     * @param float|null                                                                               $coverageFraction PL·03 — the share of the area within {@see \Uhifadhi\Patrol\Service\PatrolDashboardService::COVERAGE_BUFFER_M} of a track recorded this month, as a fraction of 1; null where there is nothing to measure (no recorded track this month, or an area with no boundary) — the KPI then shows the design's em dash rather than a false 0 %
     * @param array<string, int>                                                                       $typeCounts       the month's listed patrols, every configured type present (filter chips)
     * @param list<array{label: string, counts: array<string, int>}>                                   $weeklySeries     five weeks, oldest first
     * @param list<array{station: string, label: string, count: int}>                                  $stationSeries    this month, ranked — `station` is the record KEY (what a filter link carries), `label` what the chart prints
     * @param list<array{lead: UserInterface, hours: float}>                                           $effortSeries     patrol-hours this month per patrol lead, ranked — the "Effort by ranger" widget (PL·17); a patrol with no committed lead or no measured duration credits nobody and is absent
     * @param array<string, string>                                                                    $stations         the month's stations as key → label (filter menu); the key is what ?station= carries
     * @param list<string>                                                                             $zones            distinct zones the month's patrols set out in, sorted (filter menu) — computed by a PostGIS spatial join against the host's zone polygons, never a stored field
     * @param list<array{date: \DateTimeImmutable, patrols: list<Patrol>, today: bool, outside: bool}> $calendar         42 Monday-start cells for the month on screen
     */
    public function __construct(
        public array $patrols,
        public int $monthCount,
        public float $monthDistanceKm,
        public array $monthTypeCounts,
        public ?float $coverageFraction,
        public array $typeCounts,
        public int $totalCount,
        public ?Patrol $lastPatrol,
        public array $weeklySeries,
        public array $stationSeries,
        public array $effortSeries,
        public array $stations,
        public array $zones,
        public array $calendar,
    ) {
    }

    /**
     * THE MONTH'S WEEKS, AS THE ATLAS DRAWS THEM — one set of bars per
     * configured patrol type across the five weeks.
     *
     * THE MODULE STATES; IT DOES NOT PLOT. This used to be an `<svg>` in the
     * widget with its own axis maths, its own gridlines and its own bar
     * geometry — a fourth answer to what a bar chart is, beside the atlas's
     * one. What only this module knows is here: which series there are, what
     * they are called, what category each wears and what the counts are.
     *
     * A SERIES IS A CATEGORY, NEVER A COLOUR. The position comes from
     * {@see \Uhifadhi\Patrol\Service\PatrolDashboardService::typePositions()},
     * the same map the chips, the legend and the tracks read, so the chart
     * cannot disagree with the rest of the screen about what a type looks
     * like. A type the deployment has since dropped is in none of them and
     * takes the next category in order rather than one invented here.
     *
     * A NOUGHT IS NOT A HOLE. Every week was counted; a type with nothing in
     * it that week is a measured nought, and a gap in the bars would say
     * instead that nobody reported the week.
     *
     * @param array<string, array{label: string}> $types   the deployment's type vocabulary, in its own order
     * @param array<string, int>                  $typeCat type key → category position
     */
    public function weeklyChart(array $types, array $typeCat): AtlasChart
    {
        $labels = [];
        $keys = array_keys($types);
        foreach ($this->weeklySeries as $week) {
            $labels[] = $week['label'];
            foreach (array_keys($week['counts']) as $key) {
                if (!\in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        $series = [];
        foreach ($keys as $key) {
            $series[] = new ChartSeries(
                label: mb_strtolower($types[$key]['label'] ?? $key),
                points: array_map(
                    static fn (array $week): float => (float) ($week['counts'][$key] ?? 0),
                    $this->weeklySeries,
                ),
                cat: $typeCat[$key] ?? null,
            );
        }

        return new AtlasChart(ChartKind::Bar, $labels, $series, unit: 'patrols');
    }

    /**
     * THE BUSIEST STATIONS THIS MONTH, ranked, capped at the design's five.
     *
     * ONE SERIES, because the question is a comparison across stations rather
     * than a run over time — and the cap is here rather than in the template
     * so the chart and anything else reading the ranking agree on how long it
     * is.
     */
    public function stationChart(): AtlasChart
    {
        $rows = \array_slice($this->stationSeries, 0, self::STATION_ROWS);

        return new AtlasChart(
            ChartKind::Bar,
            array_map(static fn (array $row): string => $row['label'], $rows),
            [new ChartSeries('Patrols', array_map(static fn (array $row): float => (float) $row['count'], $rows))],
            unit: 'patrols',
        );
    }

    /**
     * WHO CARRIED THE MONTH — patrol-hours per lead, ranked.
     *
     * HOURS, NOT ROWS. Counting patrols rewards whoever logs the most short
     * ones; the hours are measured in
     * {@see \Uhifadhi\Patrol\Service\PatrolDashboardService} as the time
     * between a patrol's start and its close, credited to the committed lead.
     *
     * The lead is named the way every patrol row names one — an initial and a
     * surname — so a chart label and a table cell cannot read differently.
     */
    public function effortChart(): AtlasChart
    {
        $rows = \array_slice($this->effortSeries, 0, self::EFFORT_ROWS);

        return new AtlasChart(
            ChartKind::Bar,
            array_map(static fn (array $row): string => self::leadName($row['lead']), $rows),
            [new ChartSeries('Patrol-hours', array_map(static fn (array $row): float => $row['hours'], $rows))],
            unit: 'patrol-hours',
        );
    }

    private static function leadName(UserInterface $lead): string
    {
        return mb_substr((string) $lead->getFirstName(), 0, 1).'. '.$lead->getLastName();
    }
}
