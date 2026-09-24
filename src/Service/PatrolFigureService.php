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

namespace Uhifadhi\Patrol\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Model\PatrolTally;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * WHAT THE PATROLS MODULE RECORDED OVER A SET OF GROUND IN A WINDOW — the one
 * place the module's published figures are made.
 *
 * IT EXISTS SO THE RULE IS WRITTEN ONCE. "Does this patrol count" is a single
 * predicate ({@see \Uhifadhi\Patrol\Enum\PatrolStatusEnum::countsTowardsStatistics()})
 * and it is applied here, so the department KPI plates, the performance topic's
 * headline figures and every cell of its matrix cannot come to disagree about
 * whether a discarded outing happened. Two surfaces quoting different totals
 * for the same month is the defect this class is shaped to make impossible.
 *
 * NOTHING HERE DECIDES WHOSE FIGURES THEY ARE. A caller hands in the ground and
 * the window; who reads that ground — a department, a zone, the organization —
 * is the caller's question, and this answers the same way for all of them.
 */
final readonly class PatrolFigureService
{
    public function __construct(private PatrolRepository $patrols)
    {
    }

    /**
     * One window's counted figures over the given areas. The window is
     * HALF-OPEN [$from, $until) — the convention
     * {@see PatrolDashboardService::monthRange()} hands out.
     *
     * A DISCARDED or still-RECORDING patrol contributes nothing — not its
     * count, not its kilometres, and not the observations logged on it: a
     * discard withdraws the whole outing, and a patrol still arriving is not
     * all here yet. The repository is asked for the window's patrols unfiltered
     * on purpose — the calendar reads through the same method and DOES show
     * discards — so the exclusion is stated here, where the figures are made.
     *
     * @param list<AreaOfInterest> $areas
     */
    public function tally(array $areas, \DateTimeImmutable $from, \DateTimeImmutable $until): PatrolTally
    {
        $patrols = 0;
        $distanceKm = 0.0;
        $observations = 0;

        foreach ($areas as $area) {
            foreach ($this->patrols->findByAreaStartedBetween($area, $from, $until) as $patrol) {
                if (!$patrol->getStatus()->countsTowardsStatistics()) {
                    continue;
                }

                ++$patrols;
                $distanceKm += $patrol->getDistanceKm() ?? 0.0;
                $observations += $patrol->getObservations()->count();
            }
        }

        return new PatrolTally($patrols, $distanceKm, $observations);
    }

    /**
     * THE SHARE OF THE GROUND THE WINDOW'S TRACKS LIE OVER, IN POINTS — 54.0
     * for 54 %.
     *
     * The repository answers a fraction of 1; every contract this module
     * publishes carries a share as the number a plate prints, because a share
     * is displayed as it is given and its movement is read in POINTS.
     *
     * `$within` null asks across every area as ONE ratio — the ground covered
     * over those areas' boundaries added together — rather than as a mean of
     * their shares, which would let a small area outvote a large one.
     *
     * NULL STAYS NULL THE WHOLE WAY. No track recorded in the window is not
     * zero coverage, and every surface draws that absence in words.
     */
    public function coverage(?AreaOfInterest $within, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $fraction = null === $within
            ? $this->patrols->coverageFractionAcrossAreas(PatrolDashboardService::COVERAGE_BUFFER_M, $from, $until)
            : $this->patrols->coverageFractionWithin($within, PatrolDashboardService::COVERAGE_BUFFER_M, $from, $until);

        return null === $fraction ? null : $fraction * 100.0;
    }

    /**
     * THE SAME SHARE, FOR A SET OF ZONES AT ONCE, IN POINTS — keyed by the
     * zone's published uuid, and only for the zones the database answered for.
     *
     * ONE PASS, NOT ONE PER ZONE. Every figure here is a set operation, and
     * asking the question zone by zone would union the same buffers again for
     * each of them; {@see PatrolRepository::zoneFiguresFor()} measures the
     * whole set in one go, which is the only reason a plate of forty zones is
     * a page and not a wait.
     *
     * A ZONE'S WIDTH IS READ PER TRACK — each counts as covering its own
     * type's width, with the module's figure standing in where a type sets
     * none — so this is the zone-shaped sibling of {@see coverage()} rather
     * than a second opinion about it.
     *
     * NULL STAYS NULL, and it means the area recorded no track in the window
     * at all. A zone the window's tracks ran nowhere near, in an area that
     * recorded tracks elsewhere, is a MEASURED NOUGHT: the ground was looked
     * at and none of it was covered.
     *
     * @param list<string> $zoneUuids
     *
     * @return array<string, float|null> zone uuid to its share of covered ground
     */
    public function zoneCoverage(array $zoneUuids, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $shares = [];
        foreach ($this->patrols->zoneFiguresFor($zoneUuids, PatrolDashboardService::COVERAGE_BUFFER_M, $from, $until) as $uuid => $figures) {
            $fraction = $figures['coverageFraction'];
            $shares[$uuid] = null === $fraction ? null : $fraction * 100.0;
        }

        return $shares;
    }

    /**
     * How many patrols were out over the given ground at one instant — see
     * {@see PatrolRepository::countOutAt()} for what "out" is read from.
     *
     * @param list<AreaOfInterest> $areas
     */
    public function outAt(array $areas, \DateTimeImmutable $at): int
    {
        return $this->patrols->countOutAt($areas, $at);
    }
}
