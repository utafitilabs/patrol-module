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

namespace Uhifadhi\Patrol\Tests\Integration\Org;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Model\PatrolOrgReading;
use Uhifadhi\Patrol\Service\PatrolOrgOverviewService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE ORGANIZATION'S READING IS THE AREAS' READINGS, ADDED UP.
 *
 * That is the rule the whole organization dashboard rests on, and it is the
 * only thing worth proving about this service: it counts nothing itself, so
 * what the suite has to hold it to is that its answer is position for
 * position the answer the per-area cards already give — not two numbers that
 * happen to agree on one fixture.
 *
 * One saturday, two areas: two patrols out in the first and one in the
 * second, three closed today between them, and a third area that has never
 * opened a patrol at all.
 */
final class PatrolOrgReadingTest extends IntegrationTestCase
{
    private const string NOW = '2026-03-21T11:42:00+00:00';

    private AreaOfInterest $north;
    private AreaOfInterest $south;
    private AreaOfInterest $untouched;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = $this->anArea('Northern Reserve');
        $this->south = $this->anArea('Southern Reserve');
        // Registered, gazetted, and running nothing — the state most areas in a
        // new installation are in.
        $this->untouched = $this->anArea('Western Reserve');

        // Out right now, longest first: north opened at 07:10 and 08:40, south
        // at 10:05.
        $this->aPatrol($this->north, 'walk', '2026-03-21T07:10:00+00:00', status: PatrolStatusEnum::Recording);
        $this->aPatrol($this->north, 'walk', '2026-03-21T08:40:00+00:00', status: PatrolStatusEnum::Recording);
        $this->aPatrol($this->south, 'boat', '2026-03-21T10:05:00+00:00', status: PatrolStatusEnum::Recording);

        // Closed today, with a measured distance each.
        $this->aPatrol($this->north, 'walk', '2026-03-21T05:00:00+00:00', '2026-03-21T09:00:00+00:00', distanceKm: 61.0);
        $this->aPatrol($this->south, 'boat', '2026-03-21T05:30:00+00:00', '2026-03-21T09:30:00+00:00', distanceKm: 35.0);

        // Earlier in the same week, and the week before it.
        $this->aPatrol($this->north, 'walk', '2026-03-16T07:00:00+00:00', '2026-03-16T11:00:00+00:00', distanceKm: 12.0);
        $this->aPatrol($this->north, 'walk', '2026-03-12T07:00:00+00:00', '2026-03-12T11:00:00+00:00', distanceKm: 12.0);
    }

    /** Every patrol out in any area is on the organization's reading, once. */
    public function testItHoldsEveryAreasLivePatrols(): void
    {
        self::assertCount(3, $this->reading()->out);
    }

    /**
     * POSITION FOR POSITION THE AREAS' OWN ANSWER — the property the dashboard
     * depends on, asserted against the per-area service rather than against a
     * number written into this test.
     */
    public function testItIsTheAreasOwnReadingsConcatenated(): void
    {
        $overview = $this->overview();
        $now = new \DateTimeImmutable(self::NOW);

        $refs = [];
        foreach ([$this->north, $this->south, $this->untouched] as $area) {
            foreach ($overview->out($area, $now) as $row) {
                $refs[] = $row['patrol']->getRef();
            }
        }
        sort($refs);

        $wide = array_map(static fn (object $row): string => $row->ref, $this->reading()->out);
        sort($wide);

        self::assertSame($refs, $wide);
    }

    /** Longest out first, across areas: which area a row is in is a column, not an ordering. */
    public function testTheLongestOutComesFirst(): void
    {
        $started = array_map(
            static fn (object $row): ?string => $row->startedAt?->format('H:i'),
            $this->reading()->out,
        );

        self::assertSame(['07:10', '08:40', '10:05'], $started);
    }

    /** And each row names the area it is out in. */
    public function testEachRowNamesItsArea(): void
    {
        self::assertSame(
            ['Northern Reserve', 'Northern Reserve', 'Southern Reserve'],
            array_map(static fn (object $row): string => $row->areaName, $this->reading()->out),
        );
    }

    /** The day's walking is the areas' days added up. */
    public function testWalkedTodayIsTheAreasDaysAddedUp(): void
    {
        self::assertSame(96.0, $this->reading()->walkedTodayKm);
    }

    /**
     * THE WEEK IS COUNTED BY THE MODULE'S OWN RULE. Monday's and this
     * morning's closed patrols count; a patrol still recording has not
     * finished happening, and last week's is not this week.
     */
    public function testTheWeekIsMondayToNow(): void
    {
        self::assertSame(3, $this->reading()->thisWeek);
    }

    /** An area that has never opened a patrol is in scope and has no register. */
    public function testAnAreaThatHasNeverPatrolledIsCountedAsScopeAndNotAsAReading(): void
    {
        $reading = $this->reading();

        self::assertSame(3, $reading->areasInScope);
        self::assertSame(2, $reading->areasWithRegister);
        self::assertTrue($reading->measured());
    }

    /** No page answers for three areas at once, so no door is offered. */
    public function testThereIsNoDoorWhereMoreThanOneAreaPatrols(): void
    {
        self::assertNull($this->reading()->dashboardUrl);
    }

    /** One area patrolling has one page, and the cell opens it. */
    public function testOneAreaPatrollingGetsItsOwnDoor(): void
    {
        $reading = $this->reading(Scope::area((string) $this->south->getUuidString(), 'Southern Reserve'));

        self::assertNotNull($reading->dashboardUrl);
        self::assertStringContainsString((string) $this->south->getUuidString(), $reading->dashboardUrl);
    }

    /** A scope naming one area reads that area and nothing next door. */
    public function testAnAreaScopeStopsAtItsOwnBoundary(): void
    {
        $reading = $this->reading(Scope::area((string) $this->north->getUuidString(), 'Northern Reserve'));

        self::assertCount(2, $reading->out);
        self::assertSame(61.0, $reading->walkedTodayKm);
        self::assertSame(1, $reading->areasInScope);
    }

    /** An installation with no area at all has measured nothing — which is not nought. */
    public function testAnUnmeasuredInstallationSaysSo(): void
    {
        $reading = $this->reading(Scope::area('00000000-0000-4000-8000-000000000000', 'Nowhere'));

        self::assertFalse($reading->measured());
        self::assertSame(0, $reading->areasInScope);
        self::assertNull($reading->walkedTodayKm);
    }

    private function reading(?Scope $scope = null): PatrolOrgReading
    {
        $service = static::getContainer()->get('test_public.'.PatrolOrgOverviewService::class);
        \assert($service instanceof PatrolOrgOverviewService);

        return $service->forScope($scope ?? Scope::organization(), new \DateTimeImmutable(self::NOW));
    }

    private function overview(): PatrolOverviewService
    {
        $service = static::getContainer()->get('test_public.'.PatrolOverviewService::class);
        \assert($service instanceof PatrolOverviewService);

        return $service;
    }

    private function anArea(string $name): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName($name);
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function aPatrol(
        AreaOfInterest $area,
        string $type,
        string $startedAt,
        ?string $endedAt = null,
        ?float $distanceKm = null,
        PatrolStatusEnum $status = PatrolStatusEnum::Complete,
    ): Patrol {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, $type))
            ->setStatus($status)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setEndedAt(null === $endedAt ? null : new \DateTimeImmutable($endedAt))
            ->setDistanceKm($distanceKm);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }
}
