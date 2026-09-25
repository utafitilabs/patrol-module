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

namespace Uhifadhi\Patrol\Tests\Integration\Repository;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * PL·03 "Coverage · 2 km buffer" against real PostGIS: the share of the area's
 * surface lying within 2 km of any track recorded this month, read from the
 * STORED CORRIDORS — each complete patrol's track buffered once, then unioned.
 *
 * The fixture area is a ~0.1° square straddling the equator-ish latitude −3,
 * so its geodesic extent is roughly 11.1 km × 11.1 km ≈ 123 km². A track drawn
 * straight across its middle therefore sweeps a 4 km band ≈ 44 km² — about a
 * third of the square. The assertions are deliberately loose bounds around that
 * hand-computable figure, not a golden number: the point is that the query
 * measures on the spheroid (buffering in geography metres), not in degrees.
 */
final class PatrolCorridorCoverageTest extends IntegrationTestCase
{
    use StoredCoverage;

    private \DateTimeImmutable $monthStart;
    private \DateTimeImmutable $nextMonth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monthStart = new \DateTimeImmutable('2026-03-01T00:00:00Z');
        $this->nextMonth = new \DateTimeImmutable('2026-04-01T00:00:00Z');
    }

    /** A ~11.1 km square: lon −30.0 to −29.9, lat −3.0 to −2.9. */
    private function makeArea(bool $withBoundary = true): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        if ($withBoundary) {
            $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        }
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function makePatrol(AreaOfInterest $area, string $startedAt, ?string $track): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    private function coverage(AreaOfInterest $area): ?float
    {
        $this->bufferCorridors();

        return $this->corridors()->fractionWithin($area, $this->monthStart, $this->nextMonth);
    }

    /**
     * A TYPE'S OWN COVERAGE BUFFER IS WHAT ITS TRACKS ARE BUFFERED BY.
     *
     * The ground a month covered is not one distance around every track: how wide a
     * patrol's track counts as covered is a property of the TYPE — a walk sees a
     * hundred and fifty metres either side, a flight sees four hundred — so the
     * shape is each type's width around its own tracks, unioned.
     *
     * The default is what a type carrying none falls back on, which is every type
     * in every area that has not chosen, and so is what the shape has always been.
     */
    public function testATypesOwnBufferIsWhatItsTracksAreDrawnWith(): void
    {
        $area = $this->makeArea();
        $narrow = Vocabulary::type($this->em, $area, 'walk');
        $narrow->setCoverageBufferM(150);
        $patrol = $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        $patrol->setPatrolType($narrow);
        $this->em->flush();

        $this->bufferCorridors();
        $tight = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth, false);
        self::assertIsString($tight);

        // The same track with no width of its own is buffered by the module's own
        // distance, which is more than thirteen times as wide — so the two shapes
        // cannot be confused for one another. (Buffering cleared the entity
        // manager, as a batch does, so the type is read again.)
        $narrow = $this->em->find(PatrolType::class, $narrow->getId());
        self::assertInstanceOf(PatrolType::class, $narrow);
        $narrow->setCoverageBufferM(null);
        $this->em->flush();
        $this->em->clear();

        // The corridor buffered at 150 m is STALE now, and the rebuild finds it
        // by the width stored beside it.
        self::assertSame(1, $this->corridorService()->catchUp());
        $wide = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth, false);
        self::assertIsString($wide);
        self::assertNotSame($tight, $wide);
        self::assertGreaterThan(
            $this->areaOf($tight) * 5,
            $this->areaOf($wide),
            'the module\'s 2 km buffer covers many times the ground a 150 m one does',
        );
    }

    private function corridorService(): \Uhifadhi\Patrol\Service\PatrolCorridorService
    {
        $service = static::getContainer()->get('test_public.'.\Uhifadhi\Patrol\Service\PatrolCorridorService::class);
        \assert($service instanceof \Uhifadhi\Patrol\Service\PatrolCorridorService);

        return $service;
    }

    /** The square degrees a GeoJSON polygon covers — enough to tell two apart. */
    private function areaOf(string $geoJson): float
    {
        $area = $this->em->getConnection()->fetchOne(
            'SELECT ST_Area(ST_GeomFromGeoJSON(:geometry))',
            ['geometry' => $geoJson],
        );

        self::assertIsNumeric($area);

        return (float) $area;
    }

    public function testATrackAcrossTheSquareCoversAboutAThird(): void
    {
        $area = $this->makeArea();
        // Straight across the middle, west edge to east edge.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');

        $fraction = $this->coverage($area);

        self::assertNotNull($fraction);
        // ≈ 44 km² of 123 km². Loose bounds — the band is 4 km of an 11 km square.
        self::assertGreaterThan(0.25, $fraction);
        self::assertLessThan(0.50, $fraction);
    }

    public function testTwoTracksAreUnionedRatherThanSummed(): void
    {
        $area = $this->makeArea();
        // Two lines 0.005° (~550 m) apart: their 2 km buffers overlap heavily,
        // so the union must be far less than twice a single track's coverage.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        $this->makePatrol($area, '2026-03-11T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.945],[-29.9,-2.945]]}');

        $fraction = $this->coverage($area);

        self::assertNotNull($fraction);
        self::assertGreaterThan(0.25, $fraction);
        self::assertLessThan(0.60, $fraction);
    }

    public function testTracksSweepingTheWholeSquareAreClippedToTheBoundary(): void
    {
        $area = $this->makeArea();
        // Three lines ~3.3 km apart, each running well past both edges: their
        // 4 km bands overlap into one blanket over the square. The buffer spills
        // far outside the boundary, so a fraction above 1 would prove the
        // intersection is not being clipped.
        foreach (['-2.98', '-2.95', '-2.92'] as $index => $lat) {
            $this->makePatrol(
                $area,
                \sprintf('2026-03-1%dT06:00:00Z', $index),
                \sprintf('{"type":"LineString","coordinates":[[-30.2,%1$s],[-29.7,%1$s]]}', $lat),
            );
        }

        $fraction = $this->coverage($area);

        self::assertNotNull($fraction);
        self::assertGreaterThan(0.90, $fraction);
        self::assertLessThanOrEqual(1.0, $fraction);
    }

    public function testNoPatrolsAtAllIsTheEmptyState(): void
    {
        self::assertNull($this->coverage($this->makeArea()));
    }

    public function testAManualPatrolWithNoTrackContributesNothing(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', null);

        self::assertNull($this->coverage($area));
    }

    public function testATrackFromAnotherMonthIsOutsideTheWindow(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-02-27T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        $this->makePatrol($area, '2026-04-02T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.96],[-29.9,-2.96]]}');

        self::assertNull($this->coverage($area));
    }

    public function testAnotherAreasTracksAreNotCounted(): void
    {
        $area = $this->makeArea();
        $other = $this->makeArea();
        $this->makePatrol($other, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');

        self::assertNull($this->coverage($area));
    }

    /**
     * AN AREA CAN EXIST BEFORE ITS BOUNDARY DOES. AreaBundle makes
     * `area_of_interest.geom` nullable — an area is gazetted and named before a
     * boundary is imported for it — so a boundaryless area is a real, persistable
     * shape again, not a constraint violation.
     *
     * The coverage query answers null for one: a track can be recorded there,
     * but with no boundary there is nothing for that track to be a share OF. The
     * `a.geom IS NOT NULL` branch in {@see \Uhifadhi\Patrol\Repository\PatrolCorridorRepository::fractionWithin()}
     * is the honest one, and this proves it fires — a real COMPLETE track across
     * where the boundary would be still measures null, not zero and not an error.
     */
    public function testAnAreaWithNoBoundaryMeasuresNullCoverage(): void
    {
        $area = $this->makeArea(withBoundary: false);
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');

        self::assertNull($this->coverage($area));
    }

    /**
     * A DISCARDED patrol's track is not coverage. The two patrols here draw the
     * same line across the same square, so the only difference between "a third
     * of the area" and "nothing measured" is the discard itself.
     */
    public function testADiscardedTracksGroundIsNotCounted(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}')
            ->discard('Started by mistake');
        $this->em->flush();

        // Null, not 0.0: with the discard removed there is no track in the
        // window at all, and unmeasured is not the same fact as zero.
        self::assertNull($this->coverage($area));
    }

    /** And it subtracts only itself — a real patrol beside it still measures. */
    public function testADiscardedTrackDoesNotSuppressARealOne(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        $this->makePatrol($area, '2026-03-11T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-3.0],[-29.95,-2.9]]}')
            ->discard('Testing');
        $this->em->flush();

        $withDiscardExcluded = $this->coverage($area);
        self::assertNotNull($withDiscardExcluded);

        // The surviving track alone sweeps roughly a third of the square. Were
        // the discarded perpendicular track counted, the union would be a cross
        // and the share markedly larger.
        self::assertGreaterThan(0.25, $withDiscardExcluded);
        self::assertLessThan(0.50, $withDiscardExcluded);
    }
}
