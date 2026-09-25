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

namespace Uhifadhi\Patrol\Tests\Integration\Overview;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\TrackBatch;
use Uhifadhi\Patrol\Entity\TrackPoint;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * ONE MORNING, BUILT ONCE, read by every test of the module's five overview
 * contributions.
 *
 * The saturday the design describes, in the test kernel's own synthetic
 * vocabulary (walk / boat, never a client's words): two patrols out — one
 * pinging, one silent for over two hours — three closed since midnight, an
 * observation logged, and two zones of which one has not been entered for a
 * fortnight.
 *
 * SHARED BECAUSE THE POINT IS THAT THEY AGREE. The strip, the live card, the
 * attention list and the map plate all describe this same morning, and a fixture
 * per test would let four of them be right about four different days.
 */
abstract class PatrolOverviewTestCase extends IntegrationTestCase
{
    use StoredCoverage;

    protected const string NOW = '2026-03-21T11:42:00+00:00';

    /**
     * The posts a test's station key stands for, in the module's own demo
     * vocabulary. A key with no post of its own becomes a station named after
     * itself, which is all a test that only counts stations needs.
     *
     * @var array<string, string>
     */
    private const array STATIONS = [
        'river' => 'River Post',
        'ridge' => 'Ridge Camp',
        'lake' => 'Lake Post',
    ];

    protected AreaOfInterest $area;

    /** @var array<string, Patrol> */
    protected array $patrols = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $this->area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($this->area);
        $this->em->flush();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * WHAT THE WORKER DOES BETWEEN A PATROL AND A PAGE: the tracks buffered and
     * the month's patrol facts filed. The zone figures a page reads are these,
     * so a test that reads them runs the worker first — and one that does not
     * reads the page as it is before the worker has run.
     */
    protected function runWorker(): void
    {
        $this->em->flush();
        $this->fileFacts($this->now());

        $area = $this->em->find(AreaOfInterest::class, $this->area->getId());
        \assert($area instanceof AreaOfInterest);
        $this->area = $area;
    }

    protected function makeUser(string $first, string $last): User
    {
        $user = new User()->setPassword('x')
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function makeZone(string $name, float $southLat, float $northLat): Zone
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
        $this->em->flush();

        return $zone;
    }

    protected function makePatrol(string $key, string $type, ?string $startedAt, ?string $endedAt = null, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, $type))
            ->setStatus($status)
            ->setStationRecord(Vocabulary::station($this->em, $this->area, self::STATIONS[$key] ?? ucfirst($key)))
            ->setStartedAt(null === $startedAt ? null : new \DateTimeImmutable($startedAt))
            ->setEndedAt(null === $endedAt ? null : new \DateTimeImmutable($endedAt));
        $this->em->persist($patrol);
        $this->em->flush();

        return $this->patrols[$key] = $patrol;
    }

    /** @param list<array{string, float, float}> $points [recordedAt, lon, lat] */
    protected function ping(Patrol $patrol, array $points): void
    {
        $batch = new TrackBatch($patrol, 'batch-'.uniqid());
        $this->em->persist($batch);
        foreach ($points as [$at, $lon, $lat]) {
            $this->em->persist(new TrackPoint(
                $patrol,
                $batch,
                \sprintf('{"type":"Point","coordinates":[%s,%s]}', $lon, $lat),
                new \DateTimeImmutable($at),
            ));
        }
        $this->em->flush();
    }

    protected function makeObservation(Patrol $patrol, string $loggedAt, ?string $position = null, string $note = 'Fence line down'): Observation
    {
        $observation = new Observation($patrol, 'maintenance')
            ->setNote($note)
            ->setPosition($position)
            ->setLoggedAt(new \DateTimeImmutable($loggedAt));
        $this->em->persist($observation);
        $this->em->flush();

        return $observation;
    }
}
