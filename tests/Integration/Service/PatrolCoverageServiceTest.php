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

namespace Uhifadhi\Patrol\Tests\Integration\Service;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Service\PatrolCoverageService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE COVERED GROUND, KEPT FOR THE DAY.
 *
 * The union of a month's buffered tracks is a real piece of work — measured at
 * ~145 ms on a seeded month of 142 dense traces — and it is asked for on every
 * load of the dashboard and of the widget library. It changes only when a new
 * track arrives, which on a month already recorded is rare and never urgent, so
 * it is held per area, per month, per DAY: the key rolls at midnight, and a
 * shape can never outlive the day it was measured on.
 *
 * An installation with no cache still gets the answer, measured every time. The
 * cache makes it cheaper, never possible.
 */
final class PatrolCoverageServiceTest extends IntegrationTestCase
{
    use StoredCoverage;

    private const string TRACK = '{"type":"LineString","coordinates":[[-29.98,-2.95],[-29.94,-2.95]]}';
    private const string ELSEWHERE = '{"type":"LineString","coordinates":[[-29.98,-2.92],[-29.94,-2.92]]}';

    private \DateTimeImmutable $monthStart;
    private \DateTimeImmutable $nextMonth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monthStart = new \DateTimeImmutable('2026-03-01T00:00:00Z');
        $this->nextMonth = new \DateTimeImmutable('2026-04-01T00:00:00Z');
    }

    public function testTheShapeIsMeasuredOncePerAreaMonthAndDay(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, self::TRACK);
        $service = $this->coverageService($cache = new ArrayAdapter());

        $first = $service->bufferFor($area, $this->monthStart, $this->nextMonth, new \DateTimeImmutable('2026-03-22T09:00:00Z'));

        // A second track lands, and the same day still answers with the shape it
        // measured this morning — which is the whole point of holding it.
        $this->makePatrol($area, self::ELSEWHERE);
        $again = $service->bufferFor($area, $this->monthStart, $this->nextMonth, new \DateTimeImmutable('2026-03-22T17:00:00Z'));

        self::assertNotNull($first);
        self::assertSame($first, $again);
        self::assertCount(1, $cache->getValues());
    }

    /** The key rolls at midnight, so a new day measures the month again. */
    public function testTheNextDayMeasuresItAgain(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, self::TRACK);
        $service = $this->coverageService(new ArrayAdapter());

        $today = $service->bufferFor($area, $this->monthStart, $this->nextMonth, new \DateTimeImmutable('2026-03-22T09:00:00Z'));
        $this->makePatrol($area, self::ELSEWHERE);
        $tomorrow = $service->bufferFor($area, $this->monthStart, $this->nextMonth, new \DateTimeImmutable('2026-03-23T09:00:00Z'));

        self::assertNotSame($today, $tomorrow);
    }

    /** One area's coverage is never another's, whatever the day. */
    public function testTwoAreasAreHeldApart(): void
    {
        $here = $this->makeArea();
        $there = $this->makeArea();
        $this->makePatrol($here, self::TRACK);
        $service = $this->coverageService(new ArrayAdapter());
        $now = new \DateTimeImmutable('2026-03-22T09:00:00Z');

        self::assertNotNull($service->bufferFor($here, $this->monthStart, $this->nextMonth, $now));
        self::assertNull($service->bufferFor($there, $this->monthStart, $this->nextMonth, $now));
    }

    /** Two months of the same area are two different questions. */
    public function testTwoMonthsAreHeldApart(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, self::TRACK);
        $service = $this->coverageService(new ArrayAdapter());
        $now = new \DateTimeImmutable('2026-03-22T09:00:00Z');

        self::assertNotNull($service->bufferFor($area, $this->monthStart, $this->nextMonth, $now));
        self::assertNull($service->bufferFor(
            $area,
            new \DateTimeImmutable('2026-02-01T00:00:00Z'),
            new \DateTimeImmutable('2026-03-01T00:00:00Z'),
            $now,
        ));
    }

    /**
     * AN INSTALLATION WITH NO CACHE STILL GETS THE ANSWER. The cache is a
     * saving, never a requirement — a host that wired none must not have a
     * coverage layer that silently draws nothing.
     */
    public function testWithoutACacheTheShapeIsSimplyMeasuredEveryTime(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, self::TRACK);
        $service = $this->coverageService(null);
        $now = new \DateTimeImmutable('2026-03-22T09:00:00Z');

        $first = $service->bufferFor($area, $this->monthStart, $this->nextMonth, $now);
        $this->makePatrol($area, self::ELSEWHERE);

        self::assertNotNull($first);
        self::assertNotSame($first, $service->bufferFor($area, $this->monthStart, $this->nextMonth, $now));
    }

    /**
     * PL·03 IS READ, NOT MEASURED: the month's share as the worker filed it,
     * with its time — and nothing at all before the worker has run.
     */
    public function testTheMonthShareIsTheFiledFactAndNothingBeforeTheWorkerRuns(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, self::TRACK);
        $service = $this->coverageService(null);

        self::assertNull($service->monthShare($area, $this->monthStart));

        $this->fileFacts($this->monthStart);
        $area = $this->em->find(AreaOfInterest::class, $area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $area);
        $share = $service->monthShare($area, $this->monthStart);

        self::assertNotNull($share);
        self::assertTrue($share->isKnown());
        // A 4 km band across 0.04° of an 11 km square, clipped: a share in points.
        self::assertGreaterThan(5.0, (float) $share->value);
        self::assertLessThan(40.0, (float) $share->value);
    }

    private function coverageService(?ArrayAdapter $cache): PatrolCoverageService
    {
        return new PatrolCoverageService($this->corridors(), $this->facts(), $cache);
    }

    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /** A complete patrol, buffered as the worker buffers it when it settles. */
    private function makePatrol(AreaOfInterest $area, string $track): void
    {
        $this->em->persist($patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable('2026-03-10T06:00:00Z'))
            ->setStatus(PatrolStatusEnum::Complete)
            ->setTrack($track));
        $this->em->flush();
        self::assertTrue($this->bufferPatrol($patrol));
    }
}
