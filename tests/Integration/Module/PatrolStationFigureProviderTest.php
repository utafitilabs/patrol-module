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
use Uhifadhi\Bundle\AreaBundle\Entity\Station as AreaStation;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureRequest;
use Uhifadhi\Contracts\Kpi\StationRef;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolStationFigureProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * WHAT PATROLS PUBLISHES FOR EACH STATION OF AN AREA, measured against real
 * PostGIS because one of the two ways a patrol is attributed to a post is a
 * distance between two points and nothing smaller than the database answers
 * that honestly.
 *
 * The fixture is the same ~0.1° square the zone test uses (lon −30.0 to −29.9,
 * lat −3.0 to −2.9) with two posts far enough apart that neither sits inside
 * the other's three-hundred-metre circle.
 */
final class PatrolStationFigureProviderTest extends IntegrationTestCase
{
    private const string GATE = 'Gate One';
    private const string CAMP = 'Camp Two';

    /** Where the gate stands, and where a track that set off from it begins. */
    private const float GATE_LON = -29.95;
    private const float GATE_LAT = -2.95;

    /** The camp, some four kilometres away. */
    private const float CAMP_LON = -29.91;
    private const float CAMP_LAT = -2.95;

    public function testAPostCountsTheCompletePatrolsWhoseStationNameIsIts(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->station($area, self::CAMP, self::CAMP_LON, self::CAMP_LAT);
        $this->patrolFrom($area, self::GATE);
        $this->patrolFrom($area, self::GATE);
        $this->patrolFrom($area, self::CAMP);
        $this->em->flush();

        $headlines = $this->headlines($area);

        self::assertSame(2.0, $headlines[self::GATE]);
        self::assertSame(1.0, $headlines[self::CAMP]);
    }

    /** A patrol that carries only a word — no id — still counts for the post of that name. */
    public function testTheNameIsMatchedWithoutRegardToCaseOrSurroundingSpace(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $wordOnly = $this->patrolFrom($area, null);
        $wordOnly->setStationWord('  gate ONE ');
        $this->em->flush();

        self::assertSame(1.0, $this->headlines($area)[self::GATE]);
    }

    public function testAPatrolThatNamedNoStationIsCountedAtThePostItsTrackBeganBeside(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->station($area, self::CAMP, self::CAMP_LON, self::CAMP_LAT);
        // Some two hundred metres north of the gate, inside the three hundred.
        $this->patrolFrom($area, null, self::track(self::GATE_LON, self::GATE_LAT + 0.0018));
        $this->em->flush();

        $headlines = $this->headlines($area);

        self::assertSame(1.0, $headlines[self::GATE]);
        self::assertSame(0.0, $headlines[self::CAMP], 'A post four kilometres away launched nothing.');
    }

    public function testATrackThatBeganFarFromEveryPostIsCountedAtNone(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->patrolFrom($area, null, self::track(-29.98, -2.93));
        $this->em->flush();

        self::assertSame(0.0, $this->headlines($area)[self::GATE]);
    }

    public function testThePostNamedOnThePatrolWinsOverThePostItStartedBeside(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->station($area, self::CAMP, self::CAMP_LON, self::CAMP_LAT);
        // Filed against the camp, and its first fix is on the gate's doorstep.
        $this->patrolFrom($area, self::CAMP, self::track(self::GATE_LON, self::GATE_LAT));
        $this->em->flush();

        $headlines = $this->headlines($area);

        self::assertSame(0.0, $headlines[self::GATE]);
        self::assertSame(1.0, $headlines[self::CAMP]);
    }

    public function testTheCaptionSaysHowFarThosePatrolsWent(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->patrolFrom($area, self::GATE)->setDistanceKm(7.5);
        $this->patrolFrom($area, self::GATE)->setDistanceKm(4.5);
        $this->em->flush();

        self::assertSame('out of here · 12 km', $this->headline($area, self::GATE)->caption);
    }

    public function testAPostPublishesTheOneHeadlineKeyAndNoUrl(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->patrolFrom($area, self::GATE);
        $this->em->flush();

        $figures = $this->provider()->figuresFor($this->request($area))->forStation($this->stationUuid($area, self::GATE));

        self::assertCount(1, $figures, 'A dock draws one row per module, so a module publishes one figure.');
        self::assertSame(StationFigureProviderInterface::HEADLINE, $figures[0]->key);
        self::assertSame('patrols', $figures[0]->moduleSlug);
        self::assertSame('Patrols', $figures[0]->moduleName);
        self::assertSame('', $figures[0]->unit, 'The headline is a count of patrols, not a distance.');
        self::assertNull($figures[0]->areaName, 'A station figure is nobody\'s share of a roll-up.');
        self::assertStringNotContainsString('/', $figures[0]->caption, 'The core resolves the dock\'s link from the slug.');
    }

    public function testTheAnswerStatesThePeriodItMeasuredAndNamesTheModule(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->patrolFrom($area, self::GATE);
        $this->em->flush();

        $period = self::period();
        $answer = $this->provider()->figuresFor($this->request($area, $period));

        self::assertSame($period, $answer->period, 'The month asked for is the month measured.');
        self::assertSame('patrols', $this->provider()->moduleSlug());
    }

    public function testADiscardedPatrolLeftNoPostAtAll(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->patrolFrom($area, self::GATE)->setStatus(PatrolStatusEnum::Discarded);
        $this->em->flush();

        self::assertTrue($this->provider()->figuresFor($this->request($area))->isEmpty());
    }

    public function testAPatrolOutsideTheWindowSaysNothingAboutTheMonth(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->patrolFrom($area, self::GATE, null, '2026-02-10 07:00:00');
        $this->em->flush();

        self::assertTrue($this->provider()->figuresFor($this->request($area))->isEmpty());
    }

    public function testAnAreaThatRecordedNoPatrolPublishesNothingRatherThanZeroes(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->station($area, self::CAMP, self::CAMP_LON, self::CAMP_LAT);
        $this->em->flush();

        $answer = $this->provider()->figuresFor($this->request($area));

        self::assertTrue($answer->isEmpty());
        self::assertSame([], $answer->forStation($this->stationUuid($area, self::GATE)));
    }

    public function testAPostThatLaunchedNothingInAMonthOthersPatrolledReadsZero(): void
    {
        $area = $this->area();
        $this->station($area, self::GATE, self::GATE_LON, self::GATE_LAT);
        $this->station($area, self::CAMP, self::CAMP_LON, self::CAMP_LAT);
        $this->patrolFrom($area, self::GATE);
        $this->em->flush();

        $camp = $this->headline($area, self::CAMP);

        self::assertSame(0.0, $camp->value, 'The month was measured here and nothing went out of it.');
        self::assertSame('out of here · 0 km', $camp->caption);
    }

    public function testAskedAboutNoStationsAtAllTheProviderMeasuresNothing(): void
    {
        $period = self::period();

        $answer = $this->provider()->figuresFor(new StationFigureRequest([], $period));

        self::assertTrue($answer->isEmpty());
        self::assertSame($period, $answer->period);
    }

    /**
     * Each post's headline value by name — the shape most assertions read. A
     * post the module had nothing to say about is absent, not zero.
     *
     * @return array<string, float|null>
     */
    private function headlines(AreaOfInterest $area): array
    {
        $answer = $this->provider()->figuresFor($this->request($area));

        $byName = [];
        foreach ($this->stations($area) as $name => $station) {
            foreach ($answer->forStation((string) $station->getUuidString()) as $kpi) {
                $byName[$name] = $kpi->value;
            }
        }

        return $byName;
    }

    private function headline(AreaOfInterest $area, string $name): DepartmentKpi
    {
        foreach ($this->provider()->figuresFor($this->request($area))->forStation($this->stationUuid($area, $name)) as $kpi) {
            if (StationFigureProviderInterface::HEADLINE === $kpi->key) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No headline figure was published for %s.', $name));
    }

    private function request(AreaOfInterest $area, ?FigurePeriod $period = null): StationFigureRequest
    {
        $refs = [];
        foreach ($this->stations($area) as $name => $station) {
            $refs[] = new StationRef((string) $station->getUuidString(), (string) $area->getUuidString(), $name);
        }

        return new StationFigureRequest($refs, $period ?? self::period());
    }

    private function stationUuid(AreaOfInterest $area, string $name): string
    {
        return (string) $this->stations($area)[$name]->getUuidString();
    }

    /** @return array<string, AreaStation> */
    private function stations(AreaOfInterest $area): array
    {
        $stations = [];
        foreach ($this->em->getRepository(AreaStation::class)->findBy(['area' => $area], ['name' => 'ASC']) as $station) {
            $stations[(string) $station->getName()] = $station;
        }

        return $stations;
    }

    private function provider(): PatrolStationFigureProvider
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return new PatrolStationFigureProvider($repository, 'patrols', 'Patrols');
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

    private function station(AreaOfInterest $area, string $name, float $lon, float $lat): AreaStation
    {
        $station = new AreaStation()
            ->setName($name)
            ->setArea($area)
            ->setPoint(\sprintf('{"type":"Point","coordinates":[%s,%s]}', $lon, $lat));
        $this->em->persist($station);
        $this->em->flush();

        return $station;
    }

    private function patrolFrom(
        AreaOfInterest $area,
        ?string $stationLabel,
        ?string $track = null,
        string $startedAt = '2026-03-10 07:00:00',
    ): Patrol {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        // The post the test made, by name — the area's record; a name the area
        // does not keep stays a word on the patrol, as a stale handset leaves it.
        $post = null;
        foreach ($this->em->getRepository(AreaStation::class)->findBy(['area' => $area]) as $candidate) {
            if (null !== $stationLabel && mb_strtolower(trim((string) $candidate->getName())) === mb_strtolower(trim($stationLabel))) {
                $post = $candidate;
            }
        }
        null !== $post ? $patrol->setStationRecord($post) : $patrol->setStationWord($stationLabel);
        $this->em->persist($patrol);

        return $patrol;
    }

    /** A short line running east from where it begins. */
    private static function track(float $lon, float $lat): string
    {
        return \sprintf('{"type":"LineString","coordinates":[[%1$s,%2$s],[%3$s,%2$s]]}', $lon, $lat, $lon + 0.01);
    }

    private static function period(): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable('2026-03-15 09:00:00'));
    }
}
