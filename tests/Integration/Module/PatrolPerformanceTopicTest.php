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

namespace Uhifadhi\Patrol\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Module\PatrolPerformanceTopic;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolFigureService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE TOPIC THE PATROLS MODULE PUBLISHES, against a real database.
 *
 * Every assertion here is about the four things the contract holds a topic to:
 * five figures whatever the month, rows only for the departments that read the
 * module, the scope obeyed rather than assumed, and the three absences kept
 * apart.
 */
final class PatrolPerformanceTopicTest extends IntegrationTestCase
{
    use StoredCoverage;

    private const string NOW = '2026-08-20 09:00:00';

    /**
     * FOUR, AND ALWAYS FOUR — ruled 2026-09-21, pinned by KEY and by LABEL in
     * the design's order. The count alone would pass on a row of four
     * different figures, and the labels are what a reader recognises the
     * topic by.
     */
    public function testFourFiguresAreAlwaysPublished(): void
    {
        $this->world();

        $kpis = $this->topic()->kpis(PerformanceScope::organization(), self::period());

        self::assertSame(
            ['patrols.patrols', 'patrols.distance', 'patrols.coverage', 'patrols.observations'],
            self::keys($kpis),
        );

        self::assertSame(
            ['Patrols', 'Distance', 'Coverage', 'Observations'],
            array_map(static fn (TopicKpi $kpi): string => $kpi->label, $kpis),
        );
    }

    /**
     * AND `Out right now` IS NOT ONE OF THEM. It is the one figure of the
     * five that is not a reading of the PERIOD at all — it is a reading of
     * this instant, which is a different question from how a department did
     * over a month, and it is answered on the patrol dashboard where somebody
     * is watching. The four-to-a-row ruling dropped it from the topic.
     */
    public function testOutRightNowIsNoLongerAHeadlineFigure(): void
    {
        $this->world();

        self::assertNotContains(
            'patrols.out_now',
            self::keys($this->topic()->kpis(PerformanceScope::organization(), self::period())),
        );
    }

    public function testTheFourFiguresAreTheOrganizationsOwn(): void
    {
        $this->world();

        $figures = self::figures($this->topic()->kpis(PerformanceScope::organization(), self::period()));

        // Five patrols of 112 km in the first area, one of 7 km in the second.
        self::assertSame(6.0, $figures['patrols.patrols']);
        self::assertSame(119.0, $figures['patrols.distance']);
        self::assertSame(3.0, $figures['patrols.observations']);
    }

    public function testAScopeNoDepartmentIsAskedInStillPublishesFour(): void
    {
        $world = $this->world();
        $elsewhere = $this->area('Unserved reserve', -31.6, -1.3);
        $this->em->flush();

        $kpis = $this->topic()->kpis(PerformanceScope::area((string) $elsewhere->getUuidString(), 'Unserved reserve'), self::period());

        self::assertCount(4, $kpis);
        foreach ($kpis as $kpi) {
            self::assertNull($kpi->value, \sprintf('%s is not a nought where no area runs the module.', $kpi->key));
            self::assertStringContainsString('is asked about the Patrols module', $kpi->caption);
        }
        self::assertNotSame([], $world);
    }

    public function testOnlyTheDepartmentsThatReadTheModuleAreRows(): void
    {
        $world = $this->world();

        // A department that attaches nothing of this module's is no row at all.
        $this->department('Tourism');
        $this->em->flush();

        self::assertSame(
            ['Ecology', 'Protection Service'],
            self::names($this->topic()->matrix(PerformanceScope::organization(), self::period())->rows),
        );
        self::assertNotSame([], $world);
    }

    public function testEachRowReadsTheGroundItsOwnScopeCovers(): void
    {
        $this->world();

        $rows = self::rows($this->topic()->matrix(PerformanceScope::organization(), self::period()));

        // Ecology is org-wide: both areas, six patrols of 119 km.
        self::assertSame('Org-wide', $rows['Ecology']->band);
        self::assertSame(6.0, $rows['Ecology']->cells['patrols.patrols']->value);
        self::assertSame(119.0, $rows['Ecology']->cells['patrols.distance']->value);

        // Protection Service is confined to the first area: five patrols of 112 km.
        self::assertSame('Example reserve', $rows['Protection Service']->band);
        self::assertSame(5.0, $rows['Protection Service']->cells['patrols.patrols']->value);
        self::assertSame(112.0, $rows['Protection Service']->cells['patrols.distance']->value);
    }

    public function testAnAreasPageDropsADepartmentConfinedSomewhereElse(): void
    {
        $world = $this->world();
        $second = $world['second'];

        $confined = $this->department('Second Protection', $second);
        $confined->attachModule($world['module']);
        $this->em->flush();

        $here = self::names($this->topic()->matrix(self::scopeOf($world['area']), self::period())->rows);

        self::assertContains('Second Protection', self::names($this->topic()->matrix(PerformanceScope::organization(), self::period())->rows));
        self::assertNotContains('Second Protection', $here, 'A department of another area is not a row of this area\'s page.');
        self::assertSame(['Ecology', 'Protection Service'], $here);
    }

    public function testAnAreasPageNarrowsEveryFigureToThatArea(): void
    {
        $world = $this->world();

        $organization = self::figures($this->topic()->kpis(PerformanceScope::organization(), self::period()));
        $area = self::figures($this->topic()->kpis(self::scopeOf($world['area']), self::period()));

        self::assertSame(6.0, $organization['patrols.patrols']);
        self::assertSame(5.0, $area['patrols.patrols'], 'The second area\'s patrol is not this area\'s.');
        self::assertSame(112.0, $area['patrols.distance']);
    }

    public function testAnAreasPageNarrowsAnOrganizationWideDepartmentsRowToo(): void
    {
        $world = $this->world();

        $rows = self::rows($this->topic()->matrix(self::scopeOf($world['area']), self::period()));

        self::assertArrayHasKey('Ecology', $rows);
        self::assertSame(5.0, $rows['Ecology']->cells['patrols.patrols']->value, 'An org-wide department reads this area on this area\'s page.');
    }

    public function testEveryPeriodBeforeTheModuleWasInstalledIsAHoleAndNeverANought(): void
    {
        $this->world();

        $patrols = self::kpi($this->topic()->kpis(PerformanceScope::organization(), self::period()), 'patrols.patrols');

        // Six months ending August; the module was installed over the first
        // area in June, so March, April and May are periods nobody recorded.
        self::assertCount(PatrolPerformanceTopic::PERIODS, $patrols->history);
        self::assertSame([null, null, null], \array_slice($patrols->history, 0, 3));
        self::assertSame(0.0, $patrols->history[3], 'June was measured and recorded nothing, which is a nought.');
        self::assertSame(6.0, $patrols->history[5]);
        self::assertTrue($patrols->hasHistory());
    }

    public function testAHoleInAHistoryIsNotTheSameAsAnUnmeasuredShare(): void
    {
        $this->world();

        $coverage = self::kpi($this->topic()->kpis(PerformanceScope::organization(), self::period()), 'patrols.coverage');

        self::assertNull($coverage->value, 'No track was recorded, so the share is unknown rather than nought.');
        self::assertFalse($coverage->isKnown());
        self::assertSame([null, null, null, null, null, null], $coverage->history);
    }

    public function testADepartmentWhoseGroundRunsNothingIsARowOfDashes(): void
    {
        $world = $this->world();

        // It leads with Patrols, and no area it reads is running them.
        $unserved = $this->area('Unserved reserve', -31.6, -1.3);
        $orphan = $this->department('Orphan', $unserved);
        $orphan->attachModule($world['module']);
        $this->em->flush();

        $rows = self::rows($this->topic()->matrix(PerformanceScope::organization(), self::period()));

        self::assertArrayHasKey('Orphan', $rows, 'Attaching a module and running it nowhere is a fact the page states, not one it hides.');
        foreach ($rows['Orphan']->cells as $key => $cell) {
            self::assertTrue($cell->notMine, \sprintf('Nobody put %s to this department.', $key));
            self::assertFalse($cell->isKnown());
            self::assertNull($cell->value);
            self::assertNull($cell->delta);
        }
    }

    public function testADepartmentThatAttachesNothingOfThisModulesIsNoRow(): void
    {
        $this->world();
        $this->department('Tourism');
        $this->em->flush();

        self::assertNotContains(
            'Tourism',
            self::names($this->topic()->matrix(PerformanceScope::organization(), self::period())->rows),
            'The topic is not about a department that does not lead with this module.',
        );
    }

    public function testARowOfDashesContributesNoLineToAChart(): void
    {
        $world = $this->world();

        $unserved = $this->area('Unserved reserve', -31.6, -1.3);
        $this->department('Orphan', $unserved)->attachModule($world['module']);
        $this->em->flush();

        $distance = $this->topic()->charts(PerformanceScope::organization(), self::period())[0];

        self::assertSame(['Ecology', 'Protection Service'], array_map(
            static fn (ChartSeries $series): string => $series->label,
            $distance->series,
        ), 'A department with nothing to answer has nothing to draw.');
    }

    public function testEveryRowCarriesTheTwoLettersEverySurfaceDrawsItBy(): void
    {
        $this->world();

        $rows = self::rows($this->topic()->matrix(PerformanceScope::organization(), self::period()));

        self::assertSame('EC', $rows['Ecology']->mark);
        self::assertSame('PS', $rows['Protection Service']->mark);
    }

    public function testTheMatrixColumnsCarryTheirPolarityIntoTheAnswer(): void
    {
        $this->world();

        $matrix = $this->topic()->matrix(PerformanceScope::organization(), self::period());

        self::assertEquals(PatrolPerformanceTopic::columns(), $matrix->columns);
        self::assertFalse($matrix->isEmpty());
    }

    public function testTheTopicNamesTheModuleTheHostOrdersItBy(): void
    {
        $topic = $this->topic();

        self::assertSame('patrols', $topic->moduleSlug());
        self::assertSame('patrols', $topic->key());
        self::assertSame('Patrols', $topic->title());
    }

    /**
     * THE TAG, END TO END. A provider nobody collected is the classic silent
     * failure of this platform's seams — the class is perfect, the page simply
     * never mentions the module — so the assertion goes through the host's own
     * collector rather than through the container's service list.
     */
    public function testTheHostCollectsThisTopicUnderTheModulesOwnSlug(): void
    {
        $this->world();

        $topics = static::getContainer()->get('test_public.'.PerformanceTopics::class);
        \assert($topics instanceof PerformanceTopics);

        $collected = $topics->byKey('patrols', PerformanceScope::organization(), self::period());

        self::assertInstanceOf(PatrolPerformanceTopic::class, $collected);
        self::assertSame(PatrolModuleProvider::SLUG, $collected->moduleSlug(), 'A topic is ordered and switched off by the module\'s own slug.');
    }

    public function testTwoChartsAreDrawnOverTheSameRunAndNeitherInventsATarget(): void
    {
        $this->world();

        $charts = $this->topic()->charts(PerformanceScope::organization(), self::period());

        self::assertCount(2, $charts);
        foreach ($charts as $chart) {
            self::assertCount(PatrolPerformanceTopic::CHART_PERIODS, $chart->labels);
            self::assertNull($chart->target, 'Nothing declares a target, so no line is drawn.');
            foreach ($chart->series as $series) {
                self::assertCount(PatrolPerformanceTopic::CHART_PERIODS, $series->points, 'One point per label, holes kept.');
            }
        }

        self::assertSame(['Ecology', 'Protection Service'], array_map(
            static fn (ChartSeries $series): string => $series->label,
            $charts[0]->series,
        ));
    }

    /**
     * ONE ORGANIZATION: two areas that run Patrols, an org-wide Ecology and a
     * Protection Service confined to the first, five counted patrols of 112 km
     * and three observations in the first area, one of 7 km in the second, and
     * one patrol still out.
     *
     * @return array{area: AreaOfInterest, second: AreaOfInterest, module: Module, ecology: Department, protection: Department}
     */
    private function world(): array
    {
        $area = $this->area('Example reserve', -29.6, -3.3);
        $second = $this->area('Second reserve', -30.6, -2.3);

        $module = new Module()->setSlug('patrols')->setName('Patrols');
        $this->em->persist($module);

        // Installed in June, so the three periods before it are holes.
        $this->install($module, $area, '2026-06-10 08:00:00');
        $this->install($module, $second, '2026-06-10 08:00:00');

        $ecology = $this->department('Ecology');
        $ecology->attachModule($module);
        $protection = $this->department('Protection Service', $area);
        $protection->attachModule($module);

        foreach ([10.0, 12.0, 20.0, 30.0, 40.0] as $km) {
            $patrol = $this->patrol($area, $km);
            if (12.0 === $km) {
                $this->em->persist(new Observation($patrol, 'sighting'));
            }

            if (30.0 === $km) {
                $this->em->persist(new Observation($patrol, 'sighting'));
                $this->em->persist(new Observation($patrol, 'sighting'));
            }
        }

        $this->patrol($second, 7.0);

        // One still out, which counts towards nothing but "out right now".
        $this->patrol($area, 99.0, '2026-08-19 07:00:00')
            ->setStatus(PatrolStatusEnum::Recording);

        $this->em->flush();

        return ['area' => $area, 'second' => $second, 'module' => $module, 'ecology' => $ecology, 'protection' => $protection];
    }

    private function area(string $name, float $west, float $south): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName($name)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[%1$F,%2$F],[%3$F,%2$F],[%3$F,%4$F],[%1$F,%4$F],[%1$F,%2$F]]]]}',
                $west,
                $south,
                $west + 0.2,
                $south + 0.2,
            ));
        $this->em->persist($area);

        return $area;
    }

    private function install(Module $module, AreaOfInterest $area, string $at): AreaModule
    {
        $installed = new AreaModule()->setModule($module)
            ->setArea($area)
            ->setActive(true)
            ->setInstalledAt(new \DateTimeImmutable($at));
        $this->em->persist($installed);

        return $installed;
    }

    private function department(string $name, ?AreaOfInterest $area = null): Department
    {
        $department = new Department()->setName($name);
        if (null !== $area) {
            $department->setArea($area);
        }

        $this->em->persist($department);

        return $department;
    }

    private function patrol(AreaOfInterest $area, float $km, string $startedAt = '2026-08-05 07:00:00'): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setDistanceKm($km)
            ->setStartedAt(new \DateTimeImmutable($startedAt));
        $this->em->persist($patrol);

        return $patrol;
    }

    private function topic(): PatrolPerformanceTopic
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        $directory = static::getContainer()->get('test_public.'.DepartmentDirectoryInterface::class);
        \assert($directory instanceof DepartmentDirectoryInterface);

        // The patrols buffered, as the worker would have by now.
        $this->em->flush();
        $this->bufferCorridors();

        return new PatrolPerformanceTopic($this->em, $directory, new PatrolFigureService($repository, $this->corridors(), $this->facts()), 'patrols', 'Patrols');
    }

    private static function period(): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable(self::NOW));
    }

    private static function scopeOf(AreaOfInterest $area): PerformanceScope
    {
        return PerformanceScope::area((string) $area->getUuidString(), (string) $area->getName());
    }

    /**
     * @param list<TopicKpi> $kpis
     *
     * @return list<string>
     */
    private static function keys(array $kpis): array
    {
        return array_map(static fn (TopicKpi $kpi): string => $kpi->key, $kpis);
    }

    /**
     * @param list<TopicKpi> $kpis
     *
     * @return array<string, float|null>
     */
    private static function figures(array $kpis): array
    {
        $figures = [];
        foreach ($kpis as $kpi) {
            $figures[$kpi->key] = $kpi->value;
        }

        return $figures;
    }

    /** @param list<TopicKpi> $kpis */
    private static function kpi(array $kpis, string $key): TopicKpi
    {
        foreach ($kpis as $kpi) {
            if ($kpi->key === $key) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No "%s" figure was published.', $key));
    }

    /** @return array<string, MatrixRow> */
    private static function rows(TopicMatrix $matrix): array
    {
        $rows = [];
        foreach ($matrix->rows as $row) {
            $rows[$row->departmentName] = $row;
        }

        return $rows;
    }

    /**
     * @param list<MatrixRow> $rows
     *
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static fn (MatrixRow $row): string => $row->departmentName, $rows);
    }
}
