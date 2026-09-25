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
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * PL·03 ROLLED UP ACROSS EVERY AREA, against real PostGIS: the ground the stored corridors cover in
 * the areas that recorded a track, over those areas' boundaries added together.
 *
 * The fixture squares are the ~11.1 km × 11.1 km ≈ 123 km² square the area-wide test uses, so a
 * track straight across one sweeps a 4 km band ≈ 44 km² — about a third of it.
 */
final class PatrolCorridorRollUpCoverageTest extends IntegrationTestCase
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

    public function testOneAreaRollsUpToThatAreasOwnShare(): void
    {
        $area = $this->makeArea();
        $this->makeTrackedPatrol($area, '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');

        $rolledUp = $this->rollUp();
        $areaWide = $this->areaCoverage($area);

        self::assertNotNull($rolledUp);
        self::assertNotNull($areaWide);
        self::assertEqualsWithDelta($areaWide, $rolledUp, 0.0001);
    }

    public function testTwoAreasFoldIntoOneShareOfBothSurfaces(): void
    {
        $thin = $this->makeArea();
        $blanketed = $this->makeArea(lonWest: -29.0);

        // One band in the first square; a blanket of three overlapping bands in the second.
        $this->makeTrackedPatrol($thin, '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        foreach (['-2.98', '-2.95', '-2.92'] as $index => $lat) {
            $this->makeTrackedPatrol($blanketed, \sprintf('{"type":"LineString","coordinates":[[-29.2,%1$s],[-28.7,%1$s]]}', $lat), \sprintf('2026-03-1%dT06:00:00Z', $index));
        }

        $here = $this->areaCoverage($thin);
        $there = $this->areaCoverage($blanketed);
        $everywhere = $this->rollUp();

        self::assertNotNull($here);
        self::assertNotNull($there);
        self::assertNotNull($everywhere);
        // Not the sum of two shares and not their mean: one ratio over both squares' surfaces, so
        // it lies strictly between the thin one and the blanketed one.
        self::assertGreaterThan($here, $everywhere);
        self::assertLessThan($there, $everywhere);
    }

    public function testAnAreaWithNoTrackInTheWindowIsInNeitherSum(): void
    {
        $walked = $this->makeArea();
        $untouched = $this->makeArea(lonWest: -29.0);
        $this->makeTrackedPatrol($walked, '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        $this->makeTrackedPatrol($untouched, null);

        $areaWide = $this->areaCoverage($walked);
        self::assertNotNull($areaWide);
        self::assertEqualsWithDelta($areaWide, $this->rollUp(), 0.0001);
    }

    public function testAnAreaWithNoBoundaryIsInNeitherSum(): void
    {
        $bounded = $this->makeArea();
        $boundless = $this->makeArea(withBoundary: false, lonWest: -29.0);
        $this->makeTrackedPatrol($bounded, '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}');
        $this->makeTrackedPatrol($boundless, '{"type":"LineString","coordinates":[[-29.0,-2.95],[-28.9,-2.95]]}');

        $areaWide = $this->areaCoverage($bounded);
        self::assertNotNull($areaWide);
        self::assertEqualsWithDelta($areaWide, $this->rollUp(), 0.0001);
    }

    public function testDiscardedAndStillRecordingTracksAreNotCoverage(): void
    {
        $area = $this->makeArea();
        $this->makeTrackedPatrol($area, '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}')->discard('Testing');
        $this->makeTrackedPatrol($area, '{"type":"LineString","coordinates":[[-29.95,-3.0],[-29.95,-2.9]]}')->setStatus(PatrolStatusEnum::Recording);
        $this->em->flush();

        self::assertNull($this->rollUp());
    }

    public function testNothingRecordedInTheWindowHasNothingToMeasure(): void
    {
        $area = $this->makeArea();
        $this->makeTrackedPatrol($area, '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}', '2026-02-27T06:00:00Z');
        $this->makeTrackedPatrol($area, '{"type":"LineString","coordinates":[[-30.0,-2.96],[-29.9,-2.96]]}', '2026-04-02T06:00:00Z');
        $this->makeTrackedPatrol($area, null);

        self::assertNull($this->rollUp());
    }

    private function rollUp(): ?float
    {
        $this->bufferCorridors();

        return $this->corridors()->fractionAcrossAreas($this->monthStart, $this->nextMonth);
    }

    private function areaCoverage(AreaOfInterest $area): ?float
    {
        $this->bufferCorridors();

        return $this->corridors()->fractionWithin($area, $this->monthStart, $this->nextMonth);
    }

    /** A ~11.1 km square: 0.1° wide from $lonWest, lat −3.0 to −2.9. */
    private function makeArea(bool $withBoundary = true, float $lonWest = -30.0): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName(\sprintf('Square at %.1f', $lonWest));
        if ($withBoundary) {
            $area->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[%1$.1f,-3.0],[%2$.1f,-3.0],[%2$.1f,-2.9],[%1$.1f,-2.9],[%1$.1f,-3.0]]]]}',
                $lonWest,
                $lonWest + 0.1,
            ));
        }
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function makeTrackedPatrol(AreaOfInterest $area, ?string $track, string $startedAt = '2026-03-10T06:00:00Z'): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }
}
