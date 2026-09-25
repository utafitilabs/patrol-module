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
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolDepartmentKpiProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolFigureService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * A department reads the Patrols module BY SCOPE: every patrol recorded in its area, or across the
 * organization when it has none — whoever led the patrol, whether they hold a position, and
 * whichever department that position is filed under.
 */
final class PatrolDepartmentKpiProviderTest extends IntegrationTestCase
{
    use StoredCoverage;

    private const string NOW = '2026-08-20 09:00:00';

    public function testEveryPatrolInTheAreaCountsWhicheverDepartmentItsLeadIsSeatedIn(): void
    {
        $world = $this->world();

        $ecology = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()));

        // Two patrols led from Ecology, three from Protection: all five are the area's.
        self::assertSame(5.0, $ecology['patrols']);
        self::assertSame(112.0, $ecology['distance']);
        self::assertSame(3.0, $ecology['observations']);
    }

    public function testAPatrolLedBySomebodyWithNoPositionCountsForTheArea(): void
    {
        $world = $this->world();

        $unseated = $this->unseated('Neema', 'Mollel');
        $this->em->persist(new Observation($this->patrol($world['area'], $unseated, 8.0), 'sighting')->setRecordedBy($unseated));
        $this->patrol($world['area'], null, 4.0);
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()));

        self::assertSame(7.0, $figures['patrols']);
        self::assertSame(124.0, $figures['distance']);
        self::assertSame(4.0, $figures['observations']);
    }

    public function testAPatrolLedFromAnotherDepartmentCountsForADepartmentWithNoPeopleOfItsOwn(): void
    {
        $world = $this->world();
        $tourism = $this->department('Tourism');
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($tourism, $world['area']), self::now()));

        self::assertSame(5.0, $figures['patrols']);
        self::assertSame(112.0, $figures['distance']);
        self::assertSame(3.0, $figures['observations']);
    }

    public function testTwoDepartmentsScopedToTheSameAreaReadIdenticalFigures(): void
    {
        $world = $this->world();
        $this->tracked($world['area'], $world['analyst'], '{"type":"LineString","coordinates":[[-29.6,-3.25],[-29.4,-3.25]]}');
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.6,-3.15],[-29.4,-3.15]]}');
        $this->patrol($world['area'], $world['ranger'], 9.0, '2026-07-12 07:00:00');
        $this->em->flush();

        $ecology = $this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now());
        $protection = $this->provider()->kpisFor(self::ref($world['protection'], $world['area']), self::now());

        self::assertNotSame([], $ecology);
        self::assertNotNull(self::kpi($ecology, 'coverage')->value);
        self::assertEquals($ecology, $protection);
    }

    public function testAnOrganizationWideDepartmentSumsEveryArea(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->patrol($second, $world['analyst'], 7.0);
        $this->em->persist(new Observation($this->patrol($second, null, 3.0), 'sighting'));
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($world['protection']), self::now()));

        // 5 + 2 patrols, 112 + 10 km, 3 + 1 observations.
        self::assertSame(7.0, $figures['patrols']);
        self::assertSame(122.0, $figures['distance']);
        self::assertSame(4.0, $figures['observations']);
    }

    public function testAnOrganizationWideDepartmentReadsOneShareOfEveryBoundaryWalked(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->tracked($world['area'], $world['analyst'], '{"type":"LineString","coordinates":[[-29.6,-3.2],[-29.4,-3.2]]}');
        $this->tracked($second, null, '{"type":"LineString","coordinates":[[-30.6,-2.2],[-30.4,-2.2]]}');
        $this->em->flush();

        $coverage = self::kpi($this->provider()->kpisFor(self::ref($world['ecology']), self::now()), 'coverage');

        $rolledUp = $this->corridors()->fractionAcrossAreas(...PatrolDashboardService::monthRange(self::now()));

        self::assertNotNull($rolledUp);
        self::assertNotNull($coverage->value);
        self::assertEqualsWithDelta($rolledUp * 100.0, $coverage->value, 0.0001);
    }

    public function testAnAreaWithNoPatrolsInEitherWindowReportsNothing(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->em->flush();

        self::assertSame([], $this->provider()->kpisFor(self::ref($world['ecology'], $second), self::now()));
    }

    public function testAScopeWhosePatrolsAreOlderThanBothWindowsReportsNothing(): void
    {
        $area = $this->secondArea();
        $this->patrol($area, null, 7.0, '2026-06-10 07:00:00');
        $ecology = $this->department('Ecology');
        $this->em->flush();

        self::assertSame([], $this->provider()->kpisFor(self::ref($ecology), self::now()));
        self::assertSame([], $this->provider()->kpisFor(self::ref($ecology, $area), self::now()));
    }

    public function testAPatrolOnlyInThePreviousWindowStillReports(): void
    {
        $area = $this->secondArea();
        $this->patrol($area, null, 7.0, '2026-07-10 07:00:00');
        $ecology = $this->department('Ecology');
        $this->em->flush();

        $patrols = self::kpi($this->provider()->kpisFor(self::ref($ecology, $area), self::now()), 'patrols');

        self::assertSame(0.0, $patrols->value);
        self::assertSame(1.0, $patrols->previous);
    }

    public function testADiscardedPatrolAndItsObservationsCountForNobody(): void
    {
        $world = $this->world();

        $thrownAway = $this->patrol($world['area'], $world['ranger'], 500.0)->discard('Started by mistake');
        $this->em->persist(new Observation($thrownAway, 'sighting')->setRecordedBy($world['analyst']));
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()));

        self::assertSame(5.0, $figures['patrols'], 'The discarded patrol is not a sixth.');
        self::assertSame(112.0, $figures['distance'], 'Nor are its 500 km.');
        self::assertSame(3.0, $figures['observations'], 'Nor is the observation logged on it.');
    }

    public function testAPatrolStillRecordingCountsForNobodyYet(): void
    {
        $world = $this->world();

        $stillArriving = $this->patrol($world['area'], $world['ranger'], 500.0)
            ->setStatus(PatrolStatusEnum::Recording);
        $this->em->persist(new Observation($stillArriving, 'sighting')->setRecordedBy($world['analyst']));
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()));

        self::assertSame(5.0, $figures['patrols'], 'A patrol still arriving is not a sixth.');
        self::assertSame(112.0, $figures['distance'], 'Nor is the distance it has reached so far.');
        self::assertSame(3.0, $figures['observations'], 'Nor is the observation logged on it.');
    }

    public function testAnAreaScopedDepartmentReadsThatAreasFiguresAlone(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->patrol($second, $world['analyst'], 7.0);
        $this->em->flush();

        $here = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()));
        self::assertSame(5.0, $here['patrols']);
        self::assertSame(112.0, $here['distance']);

        $elsewhere = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $second), self::now()));
        self::assertSame(1.0, $elsewhere['patrols']);
        self::assertSame(7.0, $elsewhere['distance']);
    }

    public function testTheMonthOverMonthComparisonIsLastMonthsSameScope(): void
    {
        $world = $this->world();

        $this->patrol($world['area'], $world['analyst'], 5.0, '2026-07-04 07:00:00');
        $this->patrol($world['area'], null, 6.0, '2026-07-19 07:00:00');
        $this->em->flush();

        $patrols = self::kpi($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()), 'patrols');

        self::assertSame(5.0, $patrols->value);
        self::assertSame(2.0, $patrols->previous);
        self::assertSame(150.0, $patrols->delta());
        self::assertSame('good', $patrols->direction());
    }

    public function testTheFiguresCarrySixMonthsOfTheScopeForTheSparkline(): void
    {
        $world = $this->world();

        $patrols = self::kpi($this->provider()->kpisFor(self::ref($world['ecology']), self::now()), 'patrols');

        self::assertCount(6, $patrols->spark);
        self::assertSame(5.0, $patrols->spark[5]);
        self::assertNotSame('', $patrols->sparkPoints());
    }

    public function testCoverageIsTheAreasOwnShareWhoeverWalkedIt(): void
    {
        $world = $this->world();

        $this->tracked($world['area'], $this->unseated('Neema', 'Mollel'), '{"type":"LineString","coordinates":[[-29.6,-3.25],[-29.4,-3.25]]}');
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.6,-3.15],[-29.4,-3.15]]}');
        $this->em->flush();

        $coverage = self::kpi($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()), 'coverage');

        self::assertSame(DepartmentKpi::SHARE, $coverage->unit);
        self::assertTrue($coverage->isShare());

        $areaWide = $this->areaWideCoverage($world['area']);
        self::assertNotNull($areaWide);
        self::assertNotNull($coverage->value);
        self::assertEqualsWithDelta($areaWide * 100.0, $coverage->value, 0.0001);
    }

    public function testCoverageWithNoRecordedTrackIsUnknownRatherThanZero(): void
    {
        $world = $this->world();

        $coverage = self::kpi($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()), 'coverage');

        self::assertNull($coverage->value);
        self::assertFalse($coverage->isKnown());
        self::assertSame("\u{2014}", $coverage->display());
    }

    public function testTheCaptionNamesTheScopesPatrolsAndNotTheDepartment(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->patrol($second, null, 7.0);
        $this->em->flush();

        $area = self::kpi($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()), 'patrols');
        self::assertSame('Patrols module · every patrol recorded in Example reserve', $area->caption);

        $organization = self::kpi($this->provider()->kpisFor(self::ref($world['ecology']), self::now()), 'patrols');
        self::assertSame('Patrols module · every patrol recorded across the organization: Example reserve, Second reserve', $organization->caption);
    }

    public function testFourFiguresAreReportedOnceWhateverTheScope(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->patrol($second, $world['analyst'], 7.0);
        $this->em->flush();

        self::assertSame(
            ['patrols', 'distance', 'observations', 'coverage'],
            self::keys($this->provider()->kpisFor(self::ref($world['ecology']), self::now())),
            'An organization-wide department reads one roll-up.',
        );
        self::assertSame(
            ['patrols', 'distance', 'observations', 'coverage'],
            self::keys($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now())),
            'An area-scoped department reads one set too.',
        );
    }

    public function testEveryFigureNamesTheModuleTheHostAskedFor(): void
    {
        $world = $this->world();

        foreach ($this->provider()->kpisFor(self::ref($world['ecology']), self::now()) as $kpi) {
            self::assertSame('patrols', $kpi->moduleSlug);
            self::assertSame('Patrols', $kpi->moduleName);
        }
        self::assertSame('patrols', $this->provider()->moduleSlug());
    }

    /**
     * A department as the CONTRACT hands it over — id, name and uuid, never the
     * entity.
     *
     * This is the shape the core's KPI contract takes, and the reason it
     * takes it: departments belong to TeamBundle and nothing publishes
     * a contract for one, so a signature typed against team's class would make every
     * module that reports a figure hard-require team. Whoever holds the
     * department resolves it to a ref — here, the test playing the surface that
     * renders a performance page.
     *
     * An area handed in confines the department to it; without one the ref is
     * organization-wide and the figures roll up across every area.
     */
    private static function ref(Department $department, ?AreaOfInterest $area = null): DepartmentRef
    {
        return new DepartmentRef(
            (int) $department->getId(),
            (string) $department->getName(),
            $department->getUuid()?->toRfc4122(),
            $area?->getUuid()?->toRfc4122(),
        );
    }

    /**
     * One area, two departments, five patrols this month (112 km) and three observations.
     *
     * @return array{area: AreaOfInterest, ecology: Department, protection: Department, analyst: User, ranger: User}
     */
    private function world(): array
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName('Example reserve')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-29.6,-3.3],[-29.4,-3.3],[-29.4,-3.1],[-29.6,-3.1],[-29.6,-3.3]]]]}');
        $this->em->persist($area);

        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        $analyst = $this->user('Grace', 'Shirima', $this->position('Analyst'), $ecology);
        $ranger = $this->user('Juma', 'Kileo', $this->position('Ranger'), $protection);

        // Ecology's two.
        $this->patrol($area, $analyst, 10.0);
        $ecologySecond = $this->patrol($area, $analyst, 12.0);
        // Protection's three.
        $this->patrol($area, $ranger, 20.0);
        $protectionSecond = $this->patrol($area, $ranger, 30.0);
        $this->patrol($area, $ranger, 40.0);

        // Two of Ecology's analyst's — one of them logged during a Protection-led patrol.
        $this->em->persist(new Observation($ecologySecond, 'sighting')->setRecordedBy($analyst));
        $this->em->persist(new Observation($protectionSecond, 'sighting')->setRecordedBy($analyst));
        $this->em->persist(new Observation($protectionSecond, 'sighting')->setRecordedBy($ranger));

        $this->em->flush();

        return ['area' => $area, 'ecology' => $ecology, 'protection' => $protection, 'analyst' => $analyst, 'ranger' => $ranger];
    }

    /** A second boundary next door, so a scope has something to exclude. */
    private function secondArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName('Second reserve')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.6,-2.3],[-30.4,-2.3],[-30.4,-2.1],[-30.6,-2.1],[-30.6,-2.3]]]]}');
        $this->em->persist($area);

        return $area;
    }

    /** A patrol that actually recorded a route — the only kind coverage can be measured from. */
    private function tracked(AreaOfInterest $area, ?User $lead, string $track): Patrol
    {
        return $this->patrol($area, $lead, 0.0)->setTrack($track);
    }

    /** The area's own PL·03, straight from the repository. */
    private function areaWideCoverage(AreaOfInterest $area): ?float
    {
        $this->em->flush();
        $this->bufferCorridors();

        return $this->corridors()->fractionWithin($area, ...PatrolDashboardService::monthRange(self::now()));
    }

    private function provider(): PatrolDepartmentKpiProvider
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        // The patrols buffered, as the worker would have by now.
        $this->em->flush();
        $this->bufferCorridors();

        return new PatrolDepartmentKpiProvider(new PatrolFigureService($repository, $this->corridors(), $this->facts()), $this->em, 'patrols', 'Patrols');
    }

    private function department(string $name): Department
    {
        $department = new Department()->setName($name);
        $this->em->persist($department);

        return $department;
    }

    /**
     * A POSITION CARRIES NO DEPARTMENT. Its name is unique across the whole
     * organization, and which department somebody works for is one of the two
     * dimensions of where they are PLACED — see {@see self::user()}.
     */
    private function position(string $name): Position
    {
        $position = new Position()->setName($name);
        $this->em->persist($position);

        return $position;
    }

    /**
     * Somebody holding no position and placed nowhere — the figures still
     * count their patrols, because a department's figures follow the SCOPE
     * and never the recorder.
     */
    private function unseated(string $first, string $last): User
    {
        $user = new User()->setPassword('x')
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Somebody holding a position, placed across the organization and against
     * one named department. The placement is what makes them a member of it,
     * and it is written on the person rather than on the position.
     */
    private function user(string $first, string $last, Position $position, Department $department): User
    {
        $placement = new Placement()->acrossTheOrganization()->inDepartments([$department]);
        $this->em->persist($placement);

        $user = new User()->setPassword('x')
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last)
            ->setPosition($position)
            ->setPlacement($placement);
        $this->em->persist($user);

        return $user;
    }

    private function patrol(AreaOfInterest $area, ?User $lead, float $km, string $startedAt = '2026-08-05 07:00:00'): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setLead($lead)
            ->setDistanceKm($km)
            ->setStartedAt(new \DateTimeImmutable($startedAt));
        $this->em->persist($patrol);

        return $patrol;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * The department's figures as key => value.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return array<string, float>
     */
    private static function figures(array $kpis): array
    {
        $figures = [];
        foreach ($kpis as $kpi) {
            $figures[$kpi->key] = (float) $kpi->value;
        }

        return $figures;
    }

    /**
     * Every key reported, in order — the assertion that catches a repeated set.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return list<string>
     */
    private static function keys(array $kpis): array
    {
        return array_map(static fn (DepartmentKpi $kpi): string => $kpi->key, $kpis);
    }

    /** @param list<DepartmentKpi> $kpis */
    private static function kpi(array $kpis, string $key): DepartmentKpi
    {
        foreach ($kpis as $kpi) {
            if ($kpi->key === $key) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No "%s" figure was reported.', $key));
    }
}
