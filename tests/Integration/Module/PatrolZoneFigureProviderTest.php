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
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneRef;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolZoneFigureProvider;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * WHAT PATROLS PUBLISHES FOR EACH ZONE OF AN AREA, measured against real
 * PostGIS because every figure here is a set operation and nothing smaller
 * than the database can answer one honestly.
 *
 * The fixture is a ~0.1° square (lon −30.0 to −29.9, lat −3.0 to −2.9) with a
 * NORTH strip (lat −2.92 to −2.90) and a SOUTH strip (lat −3.00 to −2.98),
 * leaving roughly six kilometres of unzoned middle between them — far enough
 * that a track walked down the middle spills into neither at the two-kilometre
 * fallback width.
 */
final class PatrolZoneFigureProviderTest extends IntegrationTestCase
{
    use StoredCoverage;

    private const string NORTH = 'North';
    private const string SOUTH = 'South';

    /** A line that enters both strips: straight down the square's middle. */
    private const string CROSSING = '{"type":"LineString","coordinates":[[-29.95,-2.91],[-29.95,-2.99]]}';

    /** A line in the unzoned middle, more than the fallback buffer from either strip. */
    private const string BETWEEN = '{"type":"LineString","coordinates":[[-29.96,-2.95],[-29.94,-2.95]]}';

    public function testAZoneCountsThePatrolsWhoseTrackEnteredIt(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->zone($area, self::SOUTH, -3.00, -2.98);
        $this->patrol($area, self::CROSSING);
        $this->patrol($area, self::BETWEEN);
        $this->em->flush();

        $figures = $this->figuresFor($area);

        self::assertSame(1.0, $figures[self::NORTH]['patrols']);
        self::assertSame(1.0, $figures[self::SOUTH]['patrols'], 'One track entered both strips, and it is one patrol in each.');
    }

    public function testDistanceIsHowFarTheTrackRanInsideTheZone(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->zone($area, self::SOUTH, -3.00, -2.98);
        $this->patrol($area, self::CROSSING);
        $this->em->flush();

        $figures = $this->figuresFor($area);

        // The crossing line runs a hundredth of a degree of latitude — 1.106 km —
        // inside each strip, and the rest of its length inside neither.
        self::assertEqualsWithDelta(1.106, $figures[self::NORTH]['distance'], 0.02);
        self::assertEqualsWithDelta(1.106, $figures[self::SOUTH]['distance'], 0.02);
    }

    public function testCoveredIsTheShareOfTheZoneTheBufferedTracksLieOver(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->zone($area, self::SOUTH, -3.00, -2.98);
        $this->patrol($area, self::CROSSING);
        $this->em->flush();

        $covered = $this->kpi($area, self::NORTH, ZoneFigureProviderInterface::COVERED);

        self::assertTrue($covered->isKnown());
        self::assertTrue($covered->isShare(), 'Covered moves in points, so it carries the share unit.');
        self::assertGreaterThan(0.0, (float) $covered->value);
        self::assertLessThan(100.0, (float) $covered->value, 'One track two kilometres wide cannot cover an eleven-kilometre strip.');
    }

    public function testAPatrolThatEnteredNeitherZoneIsCountedInNeither(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->zone($area, self::SOUTH, -3.00, -2.98);
        $this->patrol($area, self::BETWEEN);
        $this->em->flush();

        $figures = $this->figuresFor($area);

        self::assertSame(0.0, $figures[self::NORTH]['patrols']);
        self::assertSame(0.0, $figures[self::NORTH]['distance']);
        self::assertSame(0.0, $figures[self::SOUTH]['patrols']);
    }

    public function testADiscardedPatrolProvesNothingAboutTheGroundItCrossed(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING)->setStatus(PatrolStatusEnum::Discarded);
        $this->em->flush();

        self::assertTrue($this->provider()->figuresFor($this->request($area))->isEmpty());
    }

    public function testAnAreaWithNoPatrolsPublishesNothingRatherThanZeroes(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->zone($area, self::SOUTH, -3.00, -2.98);
        $this->em->flush();

        $answer = $this->provider()->figuresFor($this->request($area));

        self::assertTrue($answer->isEmpty());
        self::assertSame([], $answer->forZone($this->zoneUuid($area, self::NORTH)));
    }

    public function testAskedAboutNoZonesAtAllTheProviderMeasuresNothing(): void
    {
        $period = self::period();

        $answer = $this->provider()->figuresFor(new ZoneFigureRequest([], $period));

        self::assertTrue($answer->isEmpty());
        self::assertSame($period, $answer->period);
    }

    public function testEachTrackIsBufferedAtItsOwnTypesWidth(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->zone($area, self::SOUTH, -3.00, -2.98);

        $narrow = Vocabulary::type($this->em, $area, 'walk')->setCoverageBufferM(100);
        $wide = Vocabulary::type($this->em, $area, 'boat')->setCoverageBufferM(2000);
        $this->patrol($area, '{"type":"LineString","coordinates":[[-29.96,-2.91],[-29.94,-2.91]]}', $narrow);
        $this->patrol($area, '{"type":"LineString","coordinates":[[-29.96,-2.99],[-29.94,-2.99]]}', $wide);
        $this->em->flush();

        $figures = $this->figuresFor($area);

        self::assertLessThan(
            $figures[self::SOUTH][ZoneFigureProviderInterface::COVERED],
            $figures[self::NORTH][ZoneFigureProviderInterface::COVERED],
            'The same line covers less ground when its type says a hundred metres than when it says two thousand.',
        );
    }

    public function testTheAnswerStatesThePeriodItMeasuredAndNamesTheModule(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING);
        $this->em->flush();

        $period = self::period();
        $answer = $this->provider()->figuresFor($this->request($area, $period));

        self::assertSame($period, $answer->period, 'The month asked for is the month measured.');
        self::assertSame('patrols', $this->provider()->moduleSlug());

        foreach ($answer->forZone($this->zoneUuid($area, self::NORTH)) as $kpi) {
            self::assertSame('patrols', $kpi->moduleSlug);
            self::assertSame('Patrols', $kpi->moduleName);
            self::assertNull($kpi->areaName, 'A zone figure is nobody\'s share of a roll-up.');
        }
    }

    public function testAZoneCarriesOneFigurePerKeyInOneOrder(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING);
        $this->em->flush();

        $keys = array_map(
            static fn (DepartmentKpi $kpi): string => $kpi->key,
            $this->provider()->figuresFor($this->request($area))->forZone($this->zoneUuid($area, self::NORTH)),
        );

        self::assertSame(['patrols', 'distance', ZoneFigureProviderInterface::COVERED], $keys);
    }

    public function testAPatrolOutsideTheWindowSaysNothingAboutTheMonth(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING, null, '2026-02-10 07:00:00');
        $this->em->flush();

        self::assertTrue($this->provider()->figuresFor($this->request($area))->isEmpty());
    }

    /**
     * BEFORE THE WORKER HAS RUN, a zone reads as not computed yet — its plates
     * carry no value, never a nought, and say when the figure will come.
     */
    public function testAZoneTheWorkerHasNotMeasuredSaysSoRatherThanZero(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING);
        $this->em->flush();

        $plates = $this->reader()->figuresFor($this->request($area))->forZone($this->zoneUuid($area, self::NORTH));

        self::assertCount(3, $plates);
        foreach ($plates as $kpi) {
            self::assertFalse($kpi->isKnown());
            self::assertStringEndsWith(PatrolZoneFigureProvider::NOT_COMPUTED, $kpi->caption);
            self::assertNull($kpi->asOf);
        }
    }

    /** A filed figure of a month still open carries the time it is true as of. */
    public function testAFiledFigureCarriesTheTimeItIsTrueAsOf(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING, null, (new \DateTimeImmutable('first day of this month 07:00'))->format('Y-m-d H:i:s'));
        $this->em->flush();
        $now = new \DateTimeImmutable();
        $this->fileFacts($now);

        $plates = $this->reader()->figuresFor($this->request($area, FigurePeriod::month($now)))->forZone($this->zoneUuid($area, self::NORTH));

        self::assertNotSame([], $plates);
        foreach ($plates as $kpi) {
            self::assertNotNull($kpi->asOf, $kpi->key.' is a figure of the month open now, so it says when it was computed.');
        }
    }

    /**
     * A ROLLING WINDOW is answered by the calendar period of about its length
     * that holds its last day, and the answer says which.
     */
    public function testARollingWindowIsAnsweredByTheCalendarPeriodThatHoldsItsEnd(): void
    {
        $area = $this->area();
        $this->zone($area, self::NORTH, -2.92, -2.90);
        $this->patrol($area, self::CROSSING);
        $this->em->flush();
        $this->fileFacts(self::period()->from);

        $rolling = new FigurePeriod(new \DateTimeImmutable('2026-01-01 00:00:00'), new \DateTimeImmutable('2026-03-31 00:00:00'), 'the last 89 days');
        $answer = $this->reader()->figuresFor($this->request($area, $rolling));

        self::assertEquals(new \DateTimeImmutable('2026-01-01 00:00:00'), $answer->period->from);
        self::assertEquals(new \DateTimeImmutable('2026-04-01 00:00:00'), $answer->period->until);
    }

    /**
     * Every zone's figures as name => key => value — the shape the assertions read.
     *
     * @return array<string, array<string, float|null>>
     */
    private function figuresFor(AreaOfInterest $area): array
    {
        $answer = $this->provider()->figuresFor($this->request($area));

        $byName = [];
        foreach ($this->zones($area) as $name => $zone) {
            $byName[$name] = [];
            foreach ($answer->forZone((string) $zone->getUuidString()) as $kpi) {
                $byName[$name][$kpi->key] = $kpi->value;
            }
        }

        return $byName;
    }

    private function kpi(AreaOfInterest $area, string $zoneName, string $key): DepartmentKpi
    {
        foreach ($this->provider()->figuresFor($this->request($area))->forZone($this->zoneUuid($area, $zoneName)) as $kpi) {
            if ($kpi->key === $key) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No "%s" figure was published for %s.', $key, $zoneName));
    }

    private function request(AreaOfInterest $area, ?FigurePeriod $period = null): ZoneFigureRequest
    {
        $refs = [];
        foreach ($this->zones($area) as $name => $zone) {
            $refs[] = new ZoneRef((string) $zone->getUuidString(), (string) $area->getUuidString(), $name);
        }

        return new ZoneFigureRequest($refs, $period ?? self::period());
    }

    private function zoneUuid(AreaOfInterest $area, string $name): string
    {
        return (string) $this->zones($area)[$name]->getUuidString();
    }

    /** @return array<string, Zone> */
    private function zones(AreaOfInterest $area): array
    {
        $zones = [];
        foreach ($this->em->getRepository(Zone::class)->findBy(['area' => $area], ['name' => 'ASC']) as $zone) {
            $zones[(string) $zone->getName()] = $zone;
        }

        return $zones;
    }

    /**
     * THE PROVIDER AFTER THE WORKER HAS RUN: the month's facts filed through
     * the core's own rebuild, then the provider that reads them.
     */
    private function provider(): PatrolZoneFigureProvider
    {
        $this->fileFacts(self::period()->from);

        return $this->reader();
    }

    /** The provider as a page meets it, whether or not the worker has run. */
    private function reader(): PatrolZoneFigureProvider
    {
        return new PatrolZoneFigureProvider($this->facts(), 'patrols', 'Patrols');
    }

    private function area(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName('Example square')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function zone(AreaOfInterest $area, string $name, float $southLat, float $northLat): Zone
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

    private function patrol(
        AreaOfInterest $area,
        string $track,
        ?PatrolType $type = null,
        string $startedAt = '2026-03-10 07:00:00',
    ): Patrol {
        $patrol = new Patrol($area, $type ?? Vocabulary::type($this->em, $area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        $this->em->persist($patrol);

        return $patrol;
    }

    private static function period(): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable('2026-03-15 09:00:00'));
    }
}
