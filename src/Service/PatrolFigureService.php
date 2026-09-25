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
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Patrol\Facts\PatrolFactPeriod;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;
use Uhifadhi\Patrol\Model\PatrolTally;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;
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
    public function __construct(
        private PatrolRepository $patrols,
        private PatrolCorridorRepository $corridors,
        private FactReaderInterface $facts,
    ) {
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
     * THE SHARE OF THE GROUND WITHIN THE MODULE'S ONE WIDTH OF A COMPLETE
     * TRACK, IN POINTS — 54.0 for 54 %.
     *
     * Read from the STORED CORRIDORS ({@see PatrolCorridorRepository}): the
     * window's corridors unioned and clipped, and no track buffered. Every
     * contract this module publishes carries a share as the number a plate
     * prints, and its movement is read in POINTS.
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
            ? $this->corridors->fractionAcrossAreas($from, $until)
            : $this->corridors->fractionWithin($within, $from, $until);

        return null === $fraction ? null : $fraction * 100.0;
    }

    /**
     * THE SHARE OF EACH OF A SET OF ZONES COVERED, IN POINTS — read from the
     * facts ledger, where the worker filed it; keyed by the zone's published
     * uuid, and only for the zones it has filed a figure for.
     *
     * Each track counts at its own type's width, the module's figure standing
     * in where a type sets none. The window is answered by the ledger period
     * that matches it ({@see PatrolFactPeriod::answering()}).
     *
     * NULL STAYS NULL: the area recorded no track in the period. A zone the
     * worker has not measured yet is absent, not nought.
     *
     * @param list<string> $zoneUuids
     *
     * @return array<string, float|null> zone uuid to its share of covered ground
     */
    public function zoneCoverage(array $zoneUuids, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ([] === $zoneUuids) {
            return [];
        }

        $period = PatrolFactPeriod::answering($from, $until);
        $shares = [];
        foreach ($this->facts->batch(FactSubject::ZONE, $zoneUuids, [PatrolFactProvider::ZONE_COVERAGE], $period->key) as $uuid => $figures) {
            $fact = $figures[PatrolFactProvider::ZONE_COVERAGE] ?? null;
            if (null !== $fact) {
                $shares[$uuid] = $fact->value;
            }
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
