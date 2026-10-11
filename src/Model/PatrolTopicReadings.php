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

use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Patrol\Facts\PatrolFactPeriod;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;

/**
 * WHAT THE WORKER FILED FOR THE PERFORMANCE PAGE'S GROUND, period by period —
 * read once per period for every area the page holds, and asked again by
 * every figure, row and chart line that needs it.
 *
 * One instance answers one drawing of the topic: it remembers what it read,
 * so a page asks the ledger a fixed number of questions however many
 * departments it holds and however many patrols they ran.
 *
 * NULL IS NO FIGURE. A period nobody computed, or one whose area the worker
 * has not reached yet, is no tally and no share — never a nought.
 */
final class PatrolTopicReadings
{
    private const array COUNTS = [
        PatrolFactProvider::AREA_PATROLS,
        PatrolFactProvider::AREA_DISTANCE_KM,
        PatrolFactProvider::AREA_OBSERVATIONS,
    ];

    /** @var array<string, array{areas: array<string, array<string, Fact>>, organization: Fact|null}> period key to what was filed */
    private array $filed = [];

    /**
     * @param list<string> $areaUuids every area the page's ground holds
     */
    public function __construct(
        private readonly FactReaderInterface $facts,
        private readonly array $areaUuids,
    ) {
    }

    /** The ground's counted figures in one period, or null where any of its areas has none filed. */
    public function tally(PatrolTopicGround $ground, FigurePeriod $period): ?PatrolTally
    {
        $filed = $this->at($period)['areas'];

        $patrols = 0.0;
        $km = 0.0;
        $observations = 0.0;
        foreach ($ground->areas as $area) {
            $figures = $filed[(string) $area->getUuidString()] ?? [];
            $counts = [];
            foreach (self::COUNTS as $key) {
                $value = ($figures[$key] ?? null)?->value;
                if (null === $value) {
                    return null;
                }
                $counts[$key] = $value;
            }
            $patrols += $counts[PatrolFactProvider::AREA_PATROLS];
            $km += $counts[PatrolFactProvider::AREA_DISTANCE_KM];
            $observations += $counts[PatrolFactProvider::AREA_OBSERVATIONS];
        }

        return [] === $ground->areas ? null : new PatrolTally((int) $patrols, $km, (int) $observations);
    }

    /**
     * The share of the ground covered in one period, in points: the one
     * area's own where the ground is one area, the organization's combined
     * share where it rolls every area up.
     */
    public function coverage(PatrolTopicGround $ground, FigurePeriod $period): ?float
    {
        return $this->coverageFact($ground, $period)?->value;
    }

    /**
     * WHEN THE GROUND'S FIGURES OF ONE PERIOD ARE TRUE AS OF: the oldest of
     * those still open, since a sum is as old as its oldest part; null when
     * every one is its period's final figure, or none was filed.
     */
    public function asOf(PatrolTopicGround $ground, FigurePeriod $period): ?\DateTimeImmutable
    {
        $facts = [];
        $filed = $this->at($period)['areas'];
        foreach ($ground->areas as $area) {
            foreach ($filed[(string) $area->getUuidString()] ?? [] as $fact) {
                $facts[] = $fact;
            }
        }
        $coverage = $this->coverageFact($ground, $period);
        if (null !== $coverage) {
            $facts[] = $coverage;
        }

        $asOf = null;
        foreach ($facts as $fact) {
            if (!$fact->isFinal()) {
                $asOf = null === $asOf || $fact->asOf < $asOf ? $fact->asOf : $asOf;
            }
        }

        return $asOf;
    }

    private function coverageFact(PatrolTopicGround $ground, FigurePeriod $period): ?Fact
    {
        if (null === $ground->within) {
            return \count($ground->areas) > 0 ? $this->at($period)['organization'] : null;
        }

        return $this->at($period)['areas'][(string) $ground->within->getUuidString()][PatrolFactProvider::AREA_COVERAGE_UNIFORM] ?? null;
    }

    /** @return array{areas: array<string, array<string, Fact>>, organization: Fact|null} */
    private function at(FigurePeriod $period): array
    {
        $key = PatrolFactPeriod::answering($period->from, $period->until)->key;

        return $this->filed[$key] ??= [
            'areas' => [] === $this->areaUuids
                ? []
                : $this->facts->batch(FactSubject::AREA, $this->areaUuids, [...self::COUNTS, PatrolFactProvider::AREA_COVERAGE_UNIFORM], $key),
            'organization' => $this->facts->latest(FactSubject::INSTALLATION, FactSubject::INSTALLATION_UUID, PatrolFactProvider::INSTALLATION_COVERAGE_UNIFORM, $key),
        ];
    }
}
