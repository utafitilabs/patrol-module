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

namespace Uhifadhi\Patrol\Module;

use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureRequest;
use Uhifadhi\Contracts\Kpi\StationFigures;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * WHAT WENT OUT OF EACH STATION OF AN AREA — one headline per post, measured
 * in one pass over the whole set the caller is about to draw.
 *
 * ONE FIGURE AND ONE ONLY, because a station's dock draws one row per module:
 * the number of complete patrols in the period that STARTED at the post, with
 * the kilometres they covered said in the caption rather than in a second key.
 *
 * WHAT "STARTED HERE" MEANS. A patrol points at the area's station by id
 * (0.8), and that is the join. Two fallbacks remain for patrols that carry no
 * id — rows recorded before 0.8 whose module station had no point, and rows
 * whose handset named a place the area does not keep:
 *
 *  - the patrol's station word equals the post's name, trimmed and case-folded;
 *  - failing that — and only where the patrol names no station at all — its
 *    track begins within {@see self::PROXIMITY_M} of the post's point.
 *
 * A patrol that named a post is attributed to THAT post even when it set off
 * beside another, because a person's word beats a coordinate.
 *
 * ZERO AND UNKNOWN ARE DIFFERENT FACTS. A post that launched nothing in a month
 * its area patrolled reads zero — the month was measured there. Every post of
 * an area that recorded no patrol at all is left out of the answer, and the
 * station surfaces render that absence in the product's own words.
 */
final class PatrolStationFigureProvider implements StationFigureProviderInterface
{
    /**
     * How close a track's first fix has to begin to a post to count as having
     * set off from it, in metres.
     *
     * Three hundred is the yard rather than the neighbourhood: a vehicle parked
     * at the gate, a fix taken at the barrier or across the compound is inside
     * it, and the next post along a road is not.
     */
    public const float PROXIMITY_M = 300.0;

    public function __construct(
        private readonly PatrolRepository $patrols,
        /** The slug this module is registered under in the registry's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * THE PERIOD ANSWERED IS THE PERIOD ASKED FOR — a patrol carries the instant
     * it started and the query windows on that, so there is no window this
     * module can measure and the caller cannot ask for.
     */
    public function figuresFor(StationFigureRequest $request): StationFigures
    {
        if ($request->isEmpty()) {
            return StationFigures::none($request->period);
        }

        $measured = $this->patrols->stationFiguresFor(
            $request->stationUuids(),
            self::PROXIMITY_M,
            $request->period->from,
            $request->period->until,
        );

        $byStation = [];
        foreach ($request->stations as $station) {
            $figures = $measured[$station->stationUuid] ?? null;
            if (null === $figures || !$figures['areaRecorded']) {
                continue;
            }

            $byStation[$station->stationUuid] = [$this->headline($figures)];
        }

        return new StationFigures($byStation, $request->period);
    }

    /**
     * The post's one plate: the count it publishes and, in the caption, the
     * distance that count is worth.
     *
     * `previous` and the sparkline are left empty on purpose: the seam asks
     * about ONE period for a whole set of posts, and a month-over-month move
     * would be a second pass per post for a row that does not draw one. No URL
     * is carried either — the core resolves the dock's link from the slug.
     *
     * @param array{patrols: int, distanceKm: float, areaRecorded: bool} $figures
     */
    private function headline(array $figures): DepartmentKpi
    {
        return new DepartmentKpi(
            self::HEADLINE,
            'Patrols out',
            $this->slug,
            $this->name,
            (float) $figures['patrols'],
            '',
            null,
            [],
            \sprintf('out of here · %s km', self::kilometres($figures['distanceKm'])),
        );
    }

    /** Kilometres as a caption prints them: whole where they are whole. */
    private static function kilometres(float $distanceKm): string
    {
        $formatted = number_format($distanceKm, 1, '.', ',');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }
}
