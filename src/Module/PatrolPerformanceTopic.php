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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\DepartmentEntry;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Patrol\Model\PatrolTopicGround;
use Uhifadhi\Patrol\Model\PatrolTopicSlice;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolFigureService;

/**
 * THE PATROLS TOPIC ON THE PERFORMANCE PAGE — five headline figures, two
 * charts, and a matrix of only the departments that read this module.
 *
 * THE HISTORY IS COMPUTED FROM THIS MODULE'S OWN RECORDS, not read back out of
 * the core's period ledger. The core's own topics have to read theirs — how
 * many seats were filled in July cannot be recomputed from people who have
 * since moved — but a patrol carries the instant it started and the kilometres
 * it ran, so every past period is still here and re-measuring it gives the
 * same answer today as it did then. Nothing here writes a figure down, and
 * nothing here can go stale.
 *
 * A DEPARTMENT IS A LENS OVER GROUND, NEVER A FILTER ON RECORDS. Who led a
 * patrol, whether they hold a position and which department that position is
 * filed under change no figure on this page — exactly as
 * {@see PatrolDepartmentKpiProvider} states for the KPI plates. All a
 * department contributes to a row is HOW MUCH GROUND it reads, which
 * {@see PatrolTopicSlice} resolves as the intersection of its scope with the
 * page's.
 *
 * WHO THE ROWS ARE IS ASKED, NOT QUERIED. Enumerating departments means
 * reading the team bundle's entities and the registry's area × module ledger,
 * across two packages this module does not depend on — which is what the first
 * draft of this class had to do. {@see DepartmentDirectoryInterface} is the
 * published answer: one read gives who they are, what each is placed among,
 * what each attaches, and since when each of those modules has been running
 * somewhere that department can see it.
 *
 * THE THREE ABSENCES ARE KEPT APART, and each has exactly one cause here:
 *
 * - a NULL VALUE is a figure this module cannot measure for that ground in
 *   that period — coverage where no track was recorded, and every figure of a
 *   scope no department can be asked about this module in;
 * - a HOLE IN A HISTORY is a period before the entry's `runningSince`: the
 *   module was not yet switched on anywhere that department reads, so nobody
 *   was recording, and a nought there would draw a collapse that never
 *   happened;
 * - {@see MatrixCell::notMine()} is a department that leads with Patrols
 *   while nothing on its ground runs them. It is drawn as a ROW OF DASHES
 *   rather than dropped, because "attaches the module and is running it
 *   nowhere" is a fact a director acts on and a page that hid the row would
 *   hide it. Nobody asked it, so it did not fail to answer.
 *
 * SCOPE IS OBEYED, NOT ASSUMED. Every figure is the intersection of the page's
 * scope with the row's, so an area's page never draws the organization's
 * numbers.
 */
final readonly class PatrolPerformanceTopic implements PerformanceTopicProviderInterface
{
    /** What a sparkline and a matrix cell's run are drawn over. */
    public const int PERIODS = 6;

    /** What the charts are drawn over — a full year of the page's period. */
    public const int CHART_PERIODS = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DepartmentDirectoryInterface $directory,
        private PatrolFigureService $figures,
        /** The slug this module is registered under in the registry's catalogue. */
        private string $slug,
        private string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function key(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->name;
    }

    /**
     * THE FOUR COLUMNS THIS MODULE PUBLISHES, AND WHICH WAY EACH IS GOOD.
     *
     * Static because a column's polarity is a property of the figure and not
     * of a request: more patrols, more kilometres, more ground covered and more
     * observations recorded are all more of the work this module exists to
     * measure, so all four judge upwards. A column that made no claim would be
     * drawn untinted and its movement uncoloured, which is a different page —
     * so none of them is {@see ColumnPolarity::None} by omission.
     *
     * @return list<MatrixColumn>
     */
    public static function columns(): array
    {
        return [
            new MatrixColumn('patrols.patrols', 'Patrols', '', ColumnPolarity::Up,
                'Patrols that went out and were recorded in the period.'),
            new MatrixColumn('patrols.distance', 'Distance', 'km', ColumnPolarity::Up,
                'The kilometres those patrols ran.'),
            new MatrixColumn('patrols.coverage', 'Coverage', '%', ColumnPolarity::Up,
                'The share of the ground lying within reach of a recorded track.'),
            new MatrixColumn('patrols.observations', 'Observations', '', ColumnPolarity::Up,
                'What was logged en route.'),
        ];
    }

    public function kpis(PerformanceScope $scope, FigurePeriod $period): array
    {
        $entries = $this->answering($scope);
        if ([] === $entries) {
            return $this->nothingRunsHere($scope);
        }

        $ground = $this->groundOf($scope->areaUuid, $this->runningSince($entries));
        if ($ground->isUnrun()) {
            return $this->nothingRunsHere($scope);
        }

        $run = self::run($period, self::PERIODS);
        $previous = $period->previous();

        $patrols = [];
        $distance = [];
        $observations = [];
        $coverage = [];
        foreach ($run as $past) {
            $tally = $ground->measured($past) ? $this->figures->tally($ground->areas, $past->from, $past->until) : null;

            $patrols[] = null === $tally ? null : (float) $tally->patrols;
            $distance[] = $tally?->distanceKm;
            $observations[] = null === $tally ? null : (float) $tally->observations;
            $coverage[] = $ground->measured($past) ? $this->figures->coverage($ground->within, $past->from, $past->until) : null;
        }

        $now = $this->figures->tally($ground->areas, $period->from, $period->until);
        $was = $this->figures->tally($ground->areas, $previous->from, $previous->until);
        $share = $this->figures->coverage($ground->within, $period->from, $period->until);
        $wasShare = $this->figures->coverage($ground->within, $previous->from, $previous->until);
        $rows = \count($entries);

        return [
            new TopicKpi(
                key: 'patrols.patrols',
                label: 'Patrols',
                value: (float) $now->patrols,
                delta: (float) ($now->patrols - $was->patrols),
                history: $patrols,
                caption: \sprintf('across %d department%s that read %s', $rows, 1 === $rows ? '' : 's', $this->name),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.distance',
                label: 'Distance',
                value: $now->distanceKm,
                unit: 'km',
                delta: $now->distanceKm - $was->distanceKm,
                history: $distance,
                caption: 0 === $now->patrols ? '' : \sprintf('%s km a patrol', self::plainly($now->distanceKm / $now->patrols, 1)),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.coverage',
                label: 'Coverage',
                value: $share,
                unit: '%',
                delta: null === $share || null === $wasShare ? null : $share - $wasShare,
                history: $coverage,
                caption: $this->coverageCaption($ground),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.observations',
                label: 'Observations',
                value: (float) $now->observations,
                delta: (float) ($now->observations - $was->observations),
                history: $observations,
                caption: 0 === $now->patrols ? '' : \sprintf('%s a patrol', self::plainly($now->observations / $now->patrols, 1)),
                polarity: ColumnPolarity::Up,
            ),
        ];
    }

    public function charts(PerformanceScope $scope, FigurePeriod $period): array
    {
        $run = self::run($period, self::CHART_PERIODS);
        $labels = array_map(static fn (FigurePeriod $past): string => mb_strtolower($past->from->format('M')), $run);

        return [
            $this->distanceByDepartment($scope, $run, $labels),
            $this->coverageOverTime($scope, $run, $labels),
        ];
    }

    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
    {
        $run = self::run($period, self::PERIODS);

        $rows = [];
        foreach ($this->rowsIn($scope) as $entry) {
            $slice = PatrolTopicSlice::of($scope->areaUuid, $entry->areaUuid);
            \assert(null !== $slice);

            $rows[] = new MatrixRow(
                departmentUuid: $entry->uuid,
                departmentName: $entry->name,
                cells: $entry->canAnswerFor($this->slug)
                    ? $this->cellsFor($this->groundOf($slice->areaUuid, $entry->runningSince[$this->slug] ?? null), $period, $run)
                    : self::notMineCells(),
                band: $entry->band,
                mark: $entry->mark,
            );
        }

        return new TopicMatrix(
            self::columns(),
            $rows,
            \sprintf('Only the departments that read the %s module are rows, and each reads the ground its own scope covers.', $this->name),
        );
    }

    /**
     * ONE DEPARTMENT'S FOUR CELLS.
     *
     * Ground with no area on it answers `notMine` throughout, the same as a
     * department nothing on whose ground runs this module: in both, nobody was
     * ever asked, so neither an empty figure nor a nought would be honest.
     *
     * @param list<FigurePeriod> $run
     *
     * @return array<string, MatrixCell>
     */
    private function cellsFor(PatrolTopicGround $ground, FigurePeriod $period, array $run): array
    {
        if ($ground->isUnrun()) {
            return self::notMineCells();
        }

        $patrols = [];
        $distance = [];
        $observations = [];
        $coverage = [];
        foreach ($run as $past) {
            $tally = $ground->measured($past) ? $this->figures->tally($ground->areas, $past->from, $past->until) : null;

            $patrols[] = null === $tally ? null : (float) $tally->patrols;
            $distance[] = $tally?->distanceKm;
            $observations[] = null === $tally ? null : (float) $tally->observations;
            $coverage[] = $ground->measured($past) ? $this->figures->coverage($ground->within, $past->from, $past->until) : null;
        }

        $previous = $period->previous();
        $now = $this->figures->tally($ground->areas, $period->from, $period->until);
        $was = $this->figures->tally($ground->areas, $previous->from, $previous->until);
        $share = $this->figures->coverage($ground->within, $period->from, $period->until);
        $wasShare = $this->figures->coverage($ground->within, $previous->from, $previous->until);

        return [
            'patrols.patrols' => new MatrixCell((float) $now->patrols, (float) ($now->patrols - $was->patrols), $patrols),
            'patrols.distance' => new MatrixCell($now->distanceKm, $now->distanceKm - $was->distanceKm, $distance),
            'patrols.coverage' => new MatrixCell(
                $share,
                null === $share || null === $wasShare ? null : $share - $wasShare,
                $coverage,
            ),
            'patrols.observations' => new MatrixCell((float) $now->observations, (float) ($now->observations - $was->observations), $observations),
        ];
    }

    /**
     * FOUR COLUMNS NOBODY PUT TO THIS DEPARTMENT — a row of dashes.
     *
     * Not an empty figure and never a nought: the department leads with this
     * module and nothing on its ground is running it, so it did not fail to
     * answer, it was not asked.
     *
     * @return array<string, MatrixCell>
     */
    private static function notMineCells(): array
    {
        $cells = [];
        foreach (self::columns() as $column) {
            $cells[$column->key] = MatrixCell::notMine();
        }

        return $cells;
    }

    /**
     * ONE LINE PER DEPARTMENT, over the chart's run — the comparison the design
     * asks for, and part of why a topic owns its own charts: only this module
     * knows that its departments differ by how much ground each reads.
     *
     * @param list<FigurePeriod> $run
     * @param list<string>       $labels
     */
    private function distanceByDepartment(PerformanceScope $scope, array $run, array $labels): TopicChart
    {
        $series = [];
        $reads = [];

        foreach ($this->answering($scope) as $entry) {
            $slice = PatrolTopicSlice::of($scope->areaUuid, $entry->areaUuid);
            \assert(null !== $slice);

            $ground = $this->groundOf($slice->areaUuid, $entry->runningSince[$this->slug] ?? null);
            if ($ground->isUnrun()) {
                continue;
            }

            $points = [];
            foreach ($run as $past) {
                $points[] = $ground->measured($past)
                    ? $this->figures->tally($ground->areas, $past->from, $past->until)->distanceKm
                    : null;
            }

            $name = $entry->name;
            $series[] = new ChartSeries($name, $points);
            $reads[] = \sprintf('%s reads %d area%s', $name, \count($ground->areas), 1 === \count($ground->areas) ? '' : 's');
        }

        return new TopicChart(
            key: 'patrols.distance_by_department',
            title: 'Distance, by department, per period',
            kind: ChartKind::Line,
            labels: $labels,
            series: $series,
            unit: 'km',
            caption: implode(' · ', $reads),
        );
    }

    /**
     * THE SHARE OF THE GROUND WALKED, PERIOD BY PERIOD.
     *
     * NO TARGET LINE IS DRAWN, because nothing declares one. A target is what
     * somebody committed to; this module records no commitment, and inventing a
     * round number to draw a line against would be the chart asserting a
     * promise nobody made.
     *
     * @param list<FigurePeriod> $run
     * @param list<string>       $labels
     */
    private function coverageOverTime(PerformanceScope $scope, array $run, array $labels): TopicChart
    {
        $ground = $this->groundOf($scope->areaUuid, $this->runningSince($this->answering($scope)));

        $points = [];
        foreach ($run as $past) {
            $points[] = !$ground->isUnrun() && $ground->measured($past)
                ? $this->figures->coverage($ground->within, $past->from, $past->until)
                : null;
        }

        return new TopicChart(
            key: 'patrols.coverage',
            title: 'Coverage over time',
            kind: ChartKind::Line,
            labels: $labels,
            series: [new ChartSeries('Coverage', $points)],
            unit: '%',
            caption: 'No target is declared for coverage, so none is drawn.',
        );
    }

    /**
     * THE DEPARTMENTS THIS PAGE HOLDS — asked of the host, in one read.
     *
     * Who the departments are lives in the team bundle and whether a module is
     * switched on lives in the registry's ledger; joining them is the host's
     * job, done once, which is exactly what
     * {@see DepartmentDirectoryInterface} publishes. This module does not
     * depend on either of those packages' entities and no longer reads them.
     *
     * THE ROWS ARE THE DEPARTMENTS THAT ATTACH THIS MODULE, and a department
     * that attaches it where nothing on its ground runs it is a ROW OF DASHES
     * rather than no row: that it leads with Patrols and is not running them
     * anywhere is a fact a director acts on, and a page that silently dropped
     * the row would hide it. {@see DepartmentEntry::canAnswerFor()} is what
     * separates the two, and it decides the cells, not the row set.
     *
     * A department that attaches nothing of this module's is a different case
     * and is genuinely not a row: the topic is not about it at all.
     *
     * @return list<DepartmentEntry>
     */
    private function rowsIn(PerformanceScope $scope): array
    {
        return array_values(array_filter(
            $this->directory->forScope($scope)->entries,
            fn (DepartmentEntry $entry): bool => $entry->attaches($this->slug),
        ));
    }

    /**
     * THE GROUND OF ONE SLICE — the areas it covers, by name, and the instant
     * this module started recording over them.
     *
     * The areas come from the area module, whose records this module's own
     * patrols already point at. WHETHER THE MODULE IS RUNNING does not: that
     * is the registry's ledger, and it arrives already joined and already
     * scoped as a department entry's `runningSince`, so nothing here reads the
     * ledger by hand.
     *
     * RUNNING SINCE NOBODY KNOWS WHEN DATES NO HOLES. The contract answers the
     * epoch for a row written before the day was recorded — the module is
     * running, the day is simply unknown — so it is read as "no lower bound"
     * rather than as a date every period is after.
     */
    private function groundOf(?string $areaUuid, ?\DateTimeImmutable $runningSince): PatrolTopicGround
    {
        if (null !== $areaUuid && !Uuid::isValid($areaUuid)) {
            return new PatrolTopicGround([]);
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AreaOfInterest::class, 'a')
            ->orderBy('a.name', 'ASC');

        if (null !== $areaUuid) {
            $query->andWhere('a.uuid = :area')->setParameter('area', Uuid::fromString($areaUuid));
        }

        /** @var list<AreaOfInterest> $areas */
        $areas = $query->getQuery()->getResult();

        $dated = null !== $runningSince && $runningSince->getTimestamp() > 0 ? $runningSince : null;

        return new PatrolTopicGround($areas, null === $areaUuid ? null : ($areas[0] ?? null), $dated);
    }

    /**
     * THE ROWS A FIGURE IS ACTUALLY DRAWN ACROSS — the ones this module can be
     * asked about.
     *
     * A row of dashes is still a row, but it contributes no line to a chart
     * and no department to the headline's "across N", because it has nothing
     * to contribute.
     *
     * @return list<DepartmentEntry>
     */
    private function answering(PerformanceScope $scope): array
    {
        return $this->directory->forScope($scope)->answeringFor($this->slug);
    }

    /**
     * THE EARLIEST ANY OF THESE DEPARTMENTS COULD HAVE BEEN ASKED — what dates
     * the holes in a figure about the whole page rather than about one row.
     *
     * @param list<DepartmentEntry> $entries
     */
    private function runningSince(array $entries): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($entries as $entry) {
            $since = $entry->runningSince[$this->slug] ?? null;
            if (null === $since) {
                continue;
            }

            $earliest = null === $earliest || $since < $earliest ? $since : $earliest;
        }

        return $earliest;
    }

    /**
     * FIVE FIGURES THAT SAY THEY HAVE NOTHING, for a scope where no department
     * can be asked about this module. A row of none where the page draws five
     * is a different page, and a reader cannot tell a missing topic from a
     * quiet month.
     *
     * @return list<TopicKpi>
     */
    private function nothingRunsHere(PerformanceScope $scope): array
    {
        $why = \sprintf('no department of %s is asked about the %s module', mb_strtolower($scope->label), $this->name);

        return [
            new TopicKpi('patrols.patrols', 'Patrols', null, caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.distance', 'Distance', null, 'km', caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.coverage', 'Coverage', null, '%', caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.observations', 'Observations', null, caption: $why, polarity: ColumnPolarity::Up),
        ];
    }

    /**
     * What the coverage share is OF — the buffer is part of what it MEANS, so
     * it is printed with it, and a roll-up says which surface it is a share of,
     * because a reader who is not told will assume a mean of the areas.
     */
    private function coverageCaption(PatrolTopicGround $ground): string
    {
        $buffer = rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.');

        return \sprintf(
            'within %s km of a track%s',
            $buffer,
            null === $ground->within && \count($ground->areas) > 1 ? ', as one share of those boundaries combined' : '',
        );
    }

    /**
     * THE RUN A FIGURE IS DRAWN OVER — so many periods ending at this one,
     * oldest first, each the same length as the one the page asked for.
     *
     * Stepped back through {@see FigurePeriod::previous()} rather than assumed
     * to be months, so a page reading quarters gets quarters.
     *
     * @return list<FigurePeriod>
     */
    private static function run(FigurePeriod $period, int $count): array
    {
        $run = [$period];
        for ($step = 1; $step < $count; ++$step) {
            array_unshift($run, $run[0]->previous());
        }

        return $run;
    }

    private static function plainly(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, '.', ',');
    }
}
