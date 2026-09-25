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
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * The three readings the AREA OVERVIEW asks of patrols and the module's own
 * screens never did: who is out, what closed today, and — the one that needed
 * new geometry — where nobody has been.
 *
 * PL·A3 IS ABOUT ABSENCE, and absence is measured from the last track that
 * ENTERED a zone, not from the last patrol that named one: a patrol carries a
 * free-text station and no zone at all, so the only honest answer comes from
 * ST_Intersects against the host's zone polygons. The worker asks these
 * questions and files the answers; the coverage is read from stored corridors.
 *
 * The fixture area is the same ~0.1° square PatrolRepositoryCoverageTest uses
 * (lon −30.0 to −29.9, lat −3.0 to −2.9, ≈ 123 km²), split into a NORTH and a SOUTH
 * half so a track can enter one and miss the other.
 */
final class PatrolRepositoryOverviewTest extends IntegrationTestCase
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

    private function repository(): PatrolRepository
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return $repository;
    }

    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function makeZone(AreaOfInterest $area, string $name, float $southLat, float $northLat): Zone
    {
        $zone = new Zone()
            ->setName($name)
            ->setArea($area)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[-30.0,%1$s],[-29.9,%1$s],[-29.9,%2$s],[-30.0,%2$s],[-30.0,%1$s]]]]}',
                $southLat,
                $northLat,
            ));
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    private function makePatrol(AreaOfInterest $area, string $startedAt, ?string $track, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setStatus($status)
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    // ---- who is out -------------------------------------------------------

    public function testOnlyRecordingPatrolsAreOutRightNow(): void
    {
        $area = $this->makeArea();
        $out = $this->makePatrol($area, '2026-03-22T06:00:00Z', null, PatrolStatusEnum::Recording);
        $this->makePatrol($area, '2026-03-22T05:00:00Z', null, PatrolStatusEnum::Complete);
        $this->makePatrol($area, '2026-03-22T04:00:00Z', null, PatrolStatusEnum::Discarded);

        $recording = $this->repository()->findByAreaRecording($area);

        self::assertSame([$out->getId()], array_map(static fn (Patrol $p): ?int => $p->getId(), $recording));
    }

    public function testAPatrolOutInAnotherAreaIsNotOutInThisOne(): void
    {
        $area = $this->makeArea();
        $elsewhere = $this->makeArea();
        $this->makePatrol($elsewhere, '2026-03-22T06:00:00Z', null, PatrolStatusEnum::Recording);

        self::assertSame([], $this->repository()->findByAreaRecording($area));
    }

    // ---- what closed today ------------------------------------------------

    public function testClosedTodayIsMeasuredByWhenAPatrolENDED(): void
    {
        $area = $this->makeArea();
        // Opened before midnight, closed this morning: the day's work, by the
        // only column that says when it was closed.
        $overnight = $this->makePatrol($area, '2026-03-21T20:00:00Z', null);
        $overnight->setEndedAt(new \DateTimeImmutable('2026-03-22T04:00:00Z'));
        // Opened today and still open: not closed today.
        $stillOut = $this->makePatrol($area, '2026-03-22T06:00:00Z', null, PatrolStatusEnum::Recording);
        $stillOut->setEndedAt(null);
        // Closed yesterday.
        $yesterday = $this->makePatrol($area, '2026-03-21T06:00:00Z', null);
        $yesterday->setEndedAt(new \DateTimeImmutable('2026-03-21T15:00:00Z'));
        $this->em->flush();

        $closed = $this->repository()->findByAreaEndedBetween(
            $area,
            new \DateTimeImmutable('2026-03-22T00:00:00Z'),
            new \DateTimeImmutable('2026-03-23T00:00:00Z'),
        );

        self::assertSame([$overnight->getId()], array_map(static fn (Patrol $p): ?int => $p->getId(), $closed));
    }

    // ---- where nobody has been -------------------------------------------

    public function testAZoneNoTrackEverEnteredHasNoLastEntry(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);
        $south = $this->makeZone($area, 'South', -3.0, -2.95);
        // A track along the far north edge: it enters North and misses South.
        $patrol = $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');

        $last = $this->repository()->zoneLastEntriesBefore($this->nextMonth);

        self::assertCount(2, $last);
        self::assertNull($last[(string) $south->getUuidString()]);
        $entry = $last[(string) $north->getUuidString()];
        self::assertNotNull($entry);
        self::assertSame($patrol->getId(), $entry['patrolId']);
        self::assertSame('2026-03-10', $entry['enteredAt']->format('Y-m-d'));
    }

    public function testTheMostRECENTTrackToEnterAZoneIsTheOneReported(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makePatrol($area, '2026-03-05T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $latest = $this->makePatrol($area, '2026-03-18T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.93],[-29.9,-2.93]]}');

        $entry = $this->repository()->zoneLastEntriesBefore($this->nextMonth)[(string) $north->getUuidString()];

        self::assertNotNull($entry);
        self::assertSame($latest->getId(), $entry['patrolId']);
        self::assertSame('2026-03-18', $entry['enteredAt']->format('Y-m-d'));
    }

    /** "Ever" ends where the period does: a closed month keeps the answer of its last day. */
    public function testALaterTrackIsNotTheLastEntryOfAnEarlierPeriod(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);
        $march = $this->makePatrol($area, '2026-03-05T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->makePatrol($area, '2026-04-18T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.93],[-29.9,-2.93]]}');

        self::assertSame($march->getId(), $this->repository()->zoneLastEntriesBefore($this->nextMonth)[(string) $north->getUuidString()]['patrolId'] ?? null);
    }

    public function testADiscardedTrackNeverEnteredAnything(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}', PatrolStatusEnum::Discarded);

        // A discard says the effort did not happen as recorded, so it cannot be
        // the evidence that somebody was there.
        self::assertNull($this->repository()->zoneLastEntriesBefore($this->nextMonth)[(string) $north->getUuidString()]);
        self::assertSame(['patrols' => 0, 'metres' => 0.0], $this->repository()->zoneEntriesBetween($this->monthStart, $this->nextMonth)[(string) $north->getUuidString()]);
    }

    public function testEntriesCountTheTracksThatCrossedIntoAZoneAndTheirLengthInside(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);
        $south = $this->makeZone($area, 'South', -3.0, -2.95);
        // Two tracks across North, each ≈ 11.1 km inside it; none reaches South.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->makePatrol($area, '2026-03-11T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.93],[-29.9,-2.93]]}');

        $entries = $this->repository()->zoneEntriesBetween($this->monthStart, $this->nextMonth);

        self::assertSame(2, $entries[(string) $north->getUuidString()]['patrols']);
        self::assertEqualsWithDelta(22200.0, $entries[(string) $north->getUuidString()]['metres'], 400.0);
        self::assertSame(0, $entries[(string) $south->getUuidString()]['patrols']);
    }

    public function testEachZoneIsCoveredAgainstItsOwnSurface(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);
        $south = $this->makeZone($area, 'South', -3.0, -2.95);
        // A walk counts 150 m either side as covered; the module's width is 2 km.
        Vocabulary::type($this->em, $area, 'walk')->setCoverageBufferM(150);
        // Straight across the middle of the NORTH half only (≈ 5.5 km tall), so
        // its 4 km band covers most of North and only clips into South.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.925],[-29.9,-2.925]]}');
        $this->bufferCorridors();

        $zones = $this->corridors()->coverageBetween($this->monthStart, $this->nextMonth)['zones'];

        $northShare = $zones[(string) $north->getUuidString()]['uniform'];
        $southShare = $zones[(string) $south->getUuidString()]['uniform'];
        self::assertNotNull($northShare);
        self::assertNotNull($southShare);
        // The band sits over North and reaches only a little way into South.
        self::assertGreaterThan(0.6, $northShare);
        self::assertLessThan($northShare, $southShare);
        // The walk's own 150 m corridor covers far less of North than 2 km does.
        self::assertLessThan($northShare, (float) $zones[(string) $north->getUuidString()]['typed']);
    }

    public function testAZoneInAnAreaWithNoTrackIsUnmeasuredAndTheAreaToo(): void
    {
        $area = $this->makeArea();
        $north = $this->makeZone($area, 'North', -2.95, -2.9);

        $coverage = $this->corridors()->coverageBetween($this->monthStart, $this->nextMonth);

        self::assertNull($coverage['zones'][(string) $north->getUuidString()]['uniform']);
        self::assertNull($coverage['zones'][(string) $north->getUuidString()]['typed']);
        self::assertArrayHasKey((string) $area->getUuidString(), $coverage['areas']);
        self::assertNull($coverage['areas'][(string) $area->getUuidString()]);
    }

    public function testAnotherAreasTracksDoNotCoverThisAreasZones(): void
    {
        $area = $this->makeArea();
        $elsewhere = $this->makeArea();
        $zone = $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makePatrol($elsewhere, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->bufferCorridors();

        self::assertNull($this->corridors()->coverageBetween($this->monthStart, $this->nextMonth)['zones'][(string) $zone->getUuidString()]['uniform']);
        self::assertNull($this->repository()->zoneLastEntriesBefore($this->nextMonth)[(string) $zone->getUuidString()]);
    }

    // ---- the coverage buffer, as geometry ---------------------------------

    public function testTheCoverageBufferIsReturnedAsGeoJsonClippedToTheBoundary(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-30.2,-2.95],[-29.7,-2.95]]}');
        $this->bufferCorridors();

        $geoJson = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth);

        self::assertNotNull($geoJson);
        /** @var array{type?: string, coordinates?: array<mixed>} $decoded */
        $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains($decoded['type'] ?? null, ['Polygon', 'MultiPolygon']);
        // The track runs well past both edges; the buffer must be clipped to the
        // boundary rather than spilling outside the area it describes.
        self::assertStringNotContainsString('-30.2', json_encode($decoded['coordinates'] ?? [], \JSON_THROW_ON_ERROR));
    }

    public function testAMonthWithNoTracksBuffersNothingRatherThanEmptyGeometry(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', null);
        self::assertSame(0, $this->bufferCorridors());

        self::assertNull($this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth));
    }

    /**
     * A REAL TRACK IS A DENSE TRACE, and buffering one produces a shape whose
     * outline wanders vertex by vertex around every recorded fix. Unsimplified,
     * a month of those reaches the page as a body of coordinates a screen cannot
     * resolve, so the shape is simplified for transport before it travels.
     */
    public function testTheCoverageBufferIsSimplifiedForTransport(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', self::denseTrack());
        $this->bufferCorridors();

        $simplified = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth);
        $whole = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth, simplify: false);

        self::assertNotNull($simplified);
        self::assertNotNull($whole);
        self::assertLessThan(\strlen($whole), \strlen($simplified));
    }

    /**
     * SIMPLIFYING MUST NOT COST THE GEOMETRY. The tolerance is below what a
     * plate at area zoom can draw, so the shape that travels still covers the
     * ground the shape that was measured covered — the same claim the KPI
     * beside it makes.
     */
    public function testTheSimplifiedBufferStillCoversTheSameGround(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', self::denseTrack());
        $this->bufferCorridors();

        $simplified = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth);
        $whole = $this->corridors()->coveredGeoJson($area, $this->monthStart, $this->nextMonth, simplify: false);

        self::assertNotNull($simplified);
        self::assertNotNull($whole);
        /** @var array{type?: string} $decoded */
        $decoded = json_decode($simplified, true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains($decoded['type'] ?? null, ['Polygon', 'MultiPolygon']);
        // Within half a percent of the measured area, on a deliberately tight
        // wander — a difference no reader of a whole-area plate could see, and
        // none the KPI beside it would round differently.
        self::assertEqualsWithDelta(1.0, self::areaOf($simplified) / self::areaOf($whole), 0.005);
    }

    /** A dense trace, as a handset records one: many fixes a few metres apart. */
    private static function denseTrack(): string
    {
        $points = [];
        for ($i = 0; $i <= 400; ++$i) {
            // A gentle wander, so the buffered outline carries real detail
            // rather than being one straight corridor.
            $points[] = [-30.05 + ($i * 0.00025), -2.95 + (sin($i / 9) * 0.0004)];
        }

        return json_encode(['type' => 'LineString', 'coordinates' => $points], \JSON_THROW_ON_ERROR);
    }

    /** The planar area of a GeoJSON shape, for comparing one against another. */
    private static function areaOf(string $geoJson): float
    {
        $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $rings = $decoded['coordinates'] ?? [];
        self::assertIsArray($rings);

        return self::shoelace($rings);
    }

    /**
     * The absolute shoelace area of every innermost ring found, summed — enough
     * to compare two forms of the same shape without a second geometry library.
     *
     * @param array<mixed> $node
     */
    private static function shoelace(array $node): float
    {
        // A ring is a list of [x, y] pairs; anything deeper is a wrapper.
        if (\is_array($node[0] ?? null) && !\is_array($node[0][0] ?? null)) {
            $area = 0.0;
            $count = \count($node);
            for ($i = 0; $i < $count; ++$i) {
                /** @var array{0: float, 1: float} $a */
                $a = $node[$i];
                /** @var array{0: float, 1: float} $b */
                $b = $node[($i + 1) % $count];
                $area += ($a[0] * $b[1]) - ($b[0] * $a[1]);
            }

            return abs($area) / 2;
        }

        $total = 0.0;
        foreach ($node as $child) {
            if (\is_array($child)) {
                $total += self::shoelace($child);
            }
        }

        return $total;
    }
}
