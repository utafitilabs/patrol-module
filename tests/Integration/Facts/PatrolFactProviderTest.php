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

namespace Uhifadhi\Patrol\Tests\Integration\Facts;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FigureDefinition;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE PATROL FACTS, FILED THE WAY THE SCHEDULE FILES THEM — through the
 * core's own rebuild, on the real PostGIS test database — and read back from
 * the ledger as a page reads them.
 *
 * The fixture area is the ~11.1 km square the other coverage tests use, split
 * into a NORTH and a SOUTH half so a track can enter one and miss the other.
 */
final class PatrolFactProviderTest extends IntegrationTestCase
{
    use StoredCoverage;

    private const string MARCH = '2026-03';
    private const string ACROSS_NORTH = '{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}';

    private AreaOfInterest $area;
    private Zone $north;
    private Zone $south;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $this->area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($this->area);
        $this->north = $this->zone('North', -2.95, -2.9);
        $this->south = $this->zone('South', -3.0, -2.95);
        $this->em->flush();
    }

    public function testItIsTaggedForTheCoresScheduleAndDeclaresEachFigureOnce(): void
    {
        $provider = static::getContainer()->get('test_public.'.PatrolFactProvider::class);
        self::assertInstanceOf(FactProviderInterface::class, $provider);
        self::assertSame('patrols', $provider->moduleSlug());

        $declared = [];
        foreach ($provider->figures() as $figure) {
            $declared[$figure->key] = [$figure->subjectKind, $figure->additive];
        }

        self::assertSame([
            PatrolFactProvider::ZONE_COVERAGE => [FactSubject::ZONE, false],
            PatrolFactProvider::ZONE_COVERAGE_UNIFORM => [FactSubject::ZONE, false],
            PatrolFactProvider::ZONE_PATROLS => [FactSubject::ZONE, true],
            PatrolFactProvider::ZONE_DISTANCE_KM => [FactSubject::ZONE, true],
            PatrolFactProvider::ZONE_ENTERED_EVER => [FactSubject::ZONE, false],
            PatrolFactProvider::ZONE_LAST_ENTERED_AT => [FactSubject::ZONE, false],
            PatrolFactProvider::ZONE_LAST_PATROL => [FactSubject::ZONE, false],
            PatrolFactProvider::AREA_COVERAGE_UNIFORM => [FactSubject::AREA, false],
        ], $declared);
        self::assertContainsOnlyInstancesOf(FigureDefinition::class, $provider->figures());
    }

    public function testAMonthsFiguresAreFiledPerZoneAndPerArea(): void
    {
        Vocabulary::type($this->em, $this->area, 'walk')->setCoverageBufferM(150);
        $patrol = $this->patrol('2026-03-10T06:00:00Z', self::ACROSS_NORTH);
        $this->fileFacts(new \DateTimeImmutable('2026-03-15'));

        $north = $this->zoneFacts($this->north);
        $south = $this->zoneFacts($this->south);

        self::assertSame(1.0, $north[PatrolFactProvider::ZONE_PATROLS]->value);
        self::assertEqualsWithDelta(11.1, (float) $north[PatrolFactProvider::ZONE_DISTANCE_KM]->value, 0.2);
        self::assertSame(1.0, $north[PatrolFactProvider::ZONE_ENTERED_EVER]->value);
        self::assertSame((float) $patrol->getId(), $north[PatrolFactProvider::ZONE_LAST_PATROL]->value);
        self::assertSame((float) new \DateTimeImmutable('2026-03-10T06:00:00Z')->getTimestamp(), $north[PatrolFactProvider::ZONE_LAST_ENTERED_AT]->value);

        // A share is filed in points; the walk's own 150 m covers less than 2 km.
        $typed = (float) $north[PatrolFactProvider::ZONE_COVERAGE]->value;
        $uniform = (float) $north[PatrolFactProvider::ZONE_COVERAGE_UNIFORM]->value;
        self::assertGreaterThan(0.0, $typed);
        self::assertLessThan($uniform, $typed);
        self::assertLessThanOrEqual(100.0, $uniform);

        self::assertSame(0.0, $south[PatrolFactProvider::ZONE_PATROLS]->value);
        self::assertSame(0.0, $south[PatrolFactProvider::ZONE_ENTERED_EVER]->value, 'A zone no track ever entered is filed as never, not left unknown.');
        self::assertArrayNotHasKey(PatrolFactProvider::ZONE_LAST_ENTERED_AT, $south);
        self::assertArrayNotHasKey(PatrolFactProvider::ZONE_LAST_PATROL, $south);

        $area = $this->facts()->latest(FactSubject::AREA, (string) $this->area->getUuidString(), PatrolFactProvider::AREA_COVERAGE_UNIFORM, self::MARCH);
        self::assertNotNull($area);
        self::assertGreaterThan(0.0, (float) $area->value);
        self::assertLessThan(100.0, (float) $area->value);
    }

    /** An area that recorded no track has no share to state — unknown, never a nought. */
    public function testAMonthWithNoTrackFilesUnknownCoverageAndNoughtEntries(): void
    {
        $this->fileFacts(new \DateTimeImmutable('2026-03-15'));

        $north = $this->zoneFacts($this->north);

        self::assertFalse($north[PatrolFactProvider::ZONE_COVERAGE]->isKnown());
        self::assertFalse($north[PatrolFactProvider::ZONE_COVERAGE_UNIFORM]->isKnown());
        self::assertSame(0.0, $north[PatrolFactProvider::ZONE_PATROLS]->value);
        self::assertSame(0.0, $north[PatrolFactProvider::ZONE_ENTERED_EVER]->value);
        self::assertFalse($this->facts()->latest(FactSubject::AREA, (string) $this->area->getUuidString(), PatrolFactProvider::AREA_COVERAGE_UNIFORM, self::MARCH)?->isKnown() ?? true);
    }

    /**
     * THE HOURLY RUN IS THE SAFETY NET. A patrol completed while no worker ran
     * has no corridor; the run buffers it before it measures, so the month's
     * coverage counts it.
     */
    public function testARunBuffersTheCompletePatrolsNoWorkerBuffered(): void
    {
        $this->patrol('2026-03-10T06:00:00Z', self::ACROSS_NORTH);
        self::assertSame(0, $this->corridorCount());

        $this->fileFacts(new \DateTimeImmutable('2026-03-15'));

        self::assertSame(1, $this->corridorCount());
        self::assertTrue($this->zoneFacts($this->north)[PatrolFactProvider::ZONE_COVERAGE]->isKnown());
    }

    /** A closed month keeps its last day's answer: a later patrol is not its last entry. */
    public function testAClosedMonthsLastEntryIsTheLastBeforeItEnded(): void
    {
        $march = $this->patrol('2026-03-10T06:00:00Z', self::ACROSS_NORTH);
        $this->patrol('2026-04-02T06:00:00Z', self::ACROSS_NORTH);

        $this->fileFacts(new \DateTimeImmutable('2026-03-15'));

        self::assertSame((float) $march->getId(), $this->zoneFacts($this->north)[PatrolFactProvider::ZONE_LAST_PATROL]->value);
    }

    /** A discarded track is no evidence, for coverage or for entry. */
    public function testADiscardedPatrolIsNotEvidence(): void
    {
        $this->patrol('2026-03-10T06:00:00Z', self::ACROSS_NORTH, PatrolStatusEnum::Discarded);

        $this->fileFacts(new \DateTimeImmutable('2026-03-15'));

        $north = $this->zoneFacts($this->north);
        self::assertSame(0.0, $north[PatrolFactProvider::ZONE_ENTERED_EVER]->value);
        self::assertFalse($north[PatrolFactProvider::ZONE_COVERAGE_UNIFORM]->isKnown());
    }

    /** Coverage of a quarter is not three months added: it is asked for and filed on its own. */
    public function testAQuartersCoverageIsFiledAsItsOwnPeriod(): void
    {
        $this->patrol('2026-03-10T06:00:00Z', self::ACROSS_NORTH);

        $this->fileFacts(new \DateTimeImmutable('2026-03-15'));

        $quarter = $this->facts()->latest(FactSubject::ZONE, (string) $this->north->getUuidString(), PatrolFactProvider::ZONE_COVERAGE_UNIFORM, '2026-Q1');
        self::assertNotNull($quarter);
        self::assertEqualsWithDelta((float) $this->zoneFacts($this->north)[PatrolFactProvider::ZONE_COVERAGE_UNIFORM]->value, (float) $quarter->value, 0.0001);
    }

    /** @return array<string, Fact> */
    private function zoneFacts(Zone $zone): array
    {
        return $this->facts()->batch(FactSubject::ZONE, [(string) $zone->getUuidString()], PatrolFactProvider::ZONE_FIGURES, self::MARCH)[(string) $zone->getUuidString()] ?? [];
    }

    private function corridorCount(): int
    {
        return self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor'));
    }

    private function zone(string $name, float $southLat, float $northLat): Zone
    {
        $zone = new Zone()
            ->setName($name)
            ->setArea($this->area)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[-30.0,%1$s],[-29.9,%1$s],[-29.9,%2$s],[-30.0,%2$s],[-30.0,%1$s]]]]}',
                $southLat,
                $northLat,
            ));
        $this->em->persist($zone);

        return $zone;
    }

    private function patrol(string $startedAt, string $track, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setStatus($status)
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    private static function whole(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
