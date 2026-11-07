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
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Model\PatrolOrgFacts;
use Uhifadhi\Patrol\Model\PatrolOrgList;
use Uhifadhi\Patrol\Model\PatrolOrgRow;
use Uhifadhi\Patrol\Model\PatrolOrgTypeCell;
use Uhifadhi\Patrol\Model\PatrolOrgTypeRow;
use Uhifadhi\Patrol\Model\PatrolOutRow;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;

/**
 * PATROLS READ ACROSS EVERY AREA — the area pages one scope wider.
 *
 * EVERY FIGURE IS THE AREA QUERY WITH THE AREA FILTER WIDENED. This class
 * counts nothing of its own: it resolves which areas a scope reaches and calls
 * the same services and repositories the area's own pages call, once per area,
 * folding what comes back. A second aggregate would be two numbers for one
 * question, and the day they disagreed nobody could say which was right.
 *
 * ONE AREA IS NOT A SPECIAL CASE: a scope naming one area walks a list of one.
 *
 * THE AREAS ARE THE ONES RUNNING THE MODULE. A roll-up is across every area
 * the module is switched on in; an area that parked it is not reporting
 * nought patrols, it is not patrolling here at all.
 *
 * WHICH AREA IS A COLUMN AND NOT A COLOUR. Every row carries its area's place
 * in the declared order; the shell turns that into a swatch.
 */
final readonly class PatrolOrgService
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private AreaModuleService $areaModules,
        private PatrolOverviewService $overview,
        private PatrolFigureService $figures,
        private PatrolRepository $patrols,
        private PatrolTypeRepository $types,
        private PatrolListService $list,
    ) {
    }

    /**
     * THE AREAS A SCOPE REACHES that run the module, in the declared order.
     *
     * NULL OFFERS EVERY AREA; a list is what one reader was offered, and an
     * empty one reaches nothing — a reader who may open no area is never shown
     * every area instead.
     *
     * @param list<Scope>|null $available everything the reader was offered, or null for every area
     *
     * @return list<AreaOfInterest>
     */
    public function areasIn(Scope $scope, ?array $available): array
    {
        $offered = null;
        if (null !== $available) {
            $offered = [];
            foreach ($available as $one) {
                if (null !== $one->areaUuid) {
                    $offered[$one->areaUuid] = true;
                }
            }
        }

        $reached = [];
        foreach ($this->declaredOrder() as $area) {
            $uuid = (string) $area->getUuidString();
            if (null !== $offered && !isset($offered[$uuid])) {
                continue;
            }
            if (!$scope->isOrganization() && $uuid !== $scope->areaUuid) {
                continue;
            }
            if ($this->areaModules->isActive($area, PatrolModuleProvider::SLUG)) {
                $reached[] = $area;
            }
        }

        return $reached;
    }

    /** An area's 1-based place in the declared order, the swatch it is drawn in. */
    public function positionOf(AreaOfInterest $area): int
    {
        foreach ($this->declaredOrder() as $index => $candidate) {
            if ($candidate->getId() === $area->getId()) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * THE OVERVIEW'S STRIP — each area's morning and week, added.
     *
     * @param list<AreaOfInterest> $areas
     */
    public function facts(array $areas, \DateTimeImmutable $now): PatrolOrgFacts
    {
        $reporting = [];
        $outNow = 0;
        $outAreas = 0;
        $finished = 0;

        foreach ($areas as $area) {
            $out = \count($this->overview->out($area, $now));
            $outNow += $out;
            $outAreas += $out > 0 ? 1 : 0;

            if ($this->overview->hasRegister($area)) {
                $reporting[] = $area;
                $finished += $this->overview->today($area, $now)['closed'];
            }
        }

        // THE WEEK BY THE MODULE'S OWN COUNTING RULE, in the one place it is
        // written: a discarded outing did not happen, and one still recording
        // has not finished happening.
        $week = $this->figures->tally($reporting, PatrolOrgOverviewService::weekStart($now), $now);

        return new PatrolOrgFacts(
            areas: \count($areas),
            reporting: \count($reporting),
            outNow: $outNow,
            outAreas: $outAreas,
            finishedToday: $finished,
            thisWeek: $week->patrols,
            // NO PATROL, NO DISTANCE: nothing counted is not 0 km walked.
            weekDistanceKm: 0 === $week->patrols ? null : $week->distanceKm,
        );
    }

    /**
     * THE PATROLS OUT NOW THAT HAVE GONE SILENT, anywhere — the area
     * overview's own "has not pinged" rule, read per area. Longest out first.
     *
     * @param list<AreaOfInterest> $areas
     *
     * @return list<PatrolOutRow>
     */
    public function silent(array $areas, \DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($areas as $area) {
            foreach ($this->overview->out($area, $now) as $row) {
                if (!$row['stale']) {
                    continue;
                }

                $rows[] = new PatrolOutRow(
                    ref: $row['patrol']->getRef(),
                    areaName: (string) $area->getName(),
                    startedAt: $row['patrol']->getStartedAt(),
                    kindLabel: $row['patrol']->getTypeLabel(),
                    rangers: null,
                    pingLabel: $row['pingLabel'] ?? $row['outLabel'],
                    stale: true,
                    url: $row['url'],
                );
            }
        }

        usort($rows, static fn (PatrolOutRow $a, PatrolOutRow $b): int => ($a->startedAt?->getTimestamp() ?? \PHP_INT_MIN) <=> ($b->startedAt?->getTimestamp() ?? \PHP_INT_MIN));

        return $rows;
    }

    /**
     * EVERY PATROL THE MONTH HOLDS, ACROSS THE AREAS IN SCOPE — the area's own
     * list, answered per area by {@see PatrolListService}, folded.
     *
     * THE AREA IS THE SCOPE, so the counts beside the area options are asked
     * over every area the reader is offered: each says what choosing that area
     * would leave, with every other choice kept.
     *
     * @param list<AreaOfInterest> $offered every area the reader is offered that runs the module
     * @param list<AreaOfInterest> $inScope the ones the scope reaches
     */
    public function patrols(array $offered, array $inScope, PatrolFilter $filter): PatrolOrgList
    {
        [$from, $until] = $filter->window();
        $scoped = [];
        foreach ($inScope as $area) {
            $scoped[(int) $area->getId()] = true;
        }

        $rows = [];
        $allCount = 0;
        $typeLabels = [];
        $typeCounts = [];
        $stations = [];
        $stationCounts = [];
        $areaCounts = [];

        foreach ($offered as $area) {
            $types = $this->types->findVocabularyByArea($area);
            $patrols = $this->patrols->findByAreaStartedBetweenLatestFirst($area, $from, $until);
            $zones = $this->patrols->zonesForPatrols($area, $from, $until);
            $answer = $this->list->build($patrols, $zones, $types, $filter, 1);

            $areaCounts[] = ['uuid' => (string) $area->getUuidString(), 'name' => (string) $area->getName(), 'count' => $answer->totalCount];

            if (!isset($scoped[(int) $area->getId()])) {
                continue;
            }

            $allCount += $answer->allCount;
            foreach ($types as $key => $type) {
                $typeLabels[$key] ??= $type['label'];
            }
            foreach ($answer->typeCounts as $key => $count) {
                $typeCounts[$key] = ($typeCounts[$key] ?? 0) + $count;
            }
            $stations += $answer->stations;
            foreach ($answer->stationCounts as $key => $count) {
                $stationCounts[$key] = ($stationCounts[$key] ?? 0) + $count;
            }

            $position = $this->positionOf($area);
            foreach ($this->list->filtered($patrols, $zones, $filter) as $patrol) {
                $rows[] = new PatrolOrgRow($patrol, (string) $area->getName(), (string) $area->getUuidString(), $position, $types);
            }
        }

        // NEWEST FIRST ACROSS AREAS, as each area's list is; which area a row
        // is in is a column, not an ordering.
        usort($rows, static fn (PatrolOrgRow $a, PatrolOrgRow $b): int => ($b->patrol->getStartedAt()?->getTimestamp() ?? \PHP_INT_MIN) <=> ($a->patrol->getStartedAt()?->getTimestamp() ?? \PHP_INT_MIN));
        asort($stations);

        return new PatrolOrgList($rows, $allCount, $typeLabels, $typeCounts, $stations, $stationCounts, $areaCounts);
    }

    /**
     * EVERY PATROL TYPE ANY AREA IN SCOPE KEEPS, with every area's answer for
     * it — the area's own Patrol types section, one scope wider, read-only.
     *
     * The types are taken in the order the areas keep them, the first area to
     * name a key giving it its place and its label.
     *
     * @param list<AreaOfInterest> $areas
     *
     * @return list<PatrolOrgTypeRow>
     */
    public function types(array $areas, \DateTimeImmutable $now): array
    {
        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);

        $labels = [];
        $kept = [];
        $months = [];
        $allTime = [];

        foreach ($areas as $area) {
            $id = (int) $area->getId();
            foreach ($this->types->findByArea($area) as $type) {
                $labels[$type->getKey()] ??= $type->getLabel();
                $kept[$id][$type->getKey()] = $type;
            }

            $months[$id] = [];
            foreach ($this->patrols->findByAreaStartedBetween($area, $monthStart, $nextMonth) as $patrol) {
                if ($patrol->getStatus()->countsTowardsStatistics()) {
                    $months[$id][$patrol->getType()] = ($months[$id][$patrol->getType()] ?? 0) + 1;
                }
            }

            $allTime[$id] = $this->types->countPatrolsByArea($area);
        }

        $rows = [];
        $position = 0;
        foreach ($labels as $key => $label) {
            $key = (string) $key;
            $cells = [];
            foreach ($areas as $area) {
                $id = (int) $area->getId();
                $type = $kept[$id][$key] ?? null;
                $cells[] = null === $type
                    ? new PatrolOrgTypeCell((string) $area->getName(), (string) $area->getUuidString(), $this->positionOf($area), false)
                    : new PatrolOrgTypeCell(
                        (string) $area->getName(),
                        (string) $area->getUuidString(),
                        $this->positionOf($area),
                        true,
                        $type->isActive(),
                        $type->getBase(),
                        $type->getCoverageBufferM(),
                        $months[$id][$key] ?? 0,
                        $allTime[$id][$key] ?? 0,
                    );
            }

            $rows[] = new PatrolOrgTypeRow($key, $label, ++$position, $cells);
        }

        return $rows;
    }

    /**
     * THE AREAS AS THE PRODUCT DECLARES THEM — one order, everywhere, so an
     * area's swatch is the same on every surface that draws one.
     *
     * @return list<AreaOfInterest>
     */
    private function declaredOrder(): array
    {
        return $this->areas->findBy([], ['id' => 'ASC']);
    }
}
