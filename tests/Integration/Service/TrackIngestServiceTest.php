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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Service\TrackIngestService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

final class TrackIngestServiceTest extends IntegrationTestCase
{
    private function gpx(): string
    {
        $xml = file_get_contents(\dirname(__DIR__, 2).'/Fixtures/gpx/short_track.gpx');
        \assert(false !== $xml);

        return $xml;
    }

    private function ingest(): TrackIngestService
    {
        $service = $this->service(TrackIngestService::class);
        \assert($service instanceof TrackIngestService);

        return $service;
    }

    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Example reserve')->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    public function testIngestsAGpxFileIntoAStoredPatrol(): void
    {
        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Alex')->setLastName('Example');
        $this->em->persist($lead);
        $area = $this->makeArea();

        $patrol = $this->ingest()->ingest(
            $this->gpx(),
            $area,
            type: Vocabulary::type($this->em, $area, 'walk'),
            station: Vocabulary::station($this->em, $area, 'North post'),
            lead: $lead,
            team: 'B. Example, C. Example',
        );

        // Reload from the database — assertions are about what was stored.
        $this->em->clear();
        $stored = $this->em->find(Patrol::class, $patrol->getId());
        self::assertInstanceOf(Patrol::class, $stored);

        self::assertSame('walk', $stored->getType());
        self::assertSame('North post', $stored->getStation());
        self::assertSame(PatrolSourceEnum::Gpx, $stored->getSource());
        self::assertSame(4, $stored->getPointCount());
        self::assertSame(1, $stored->getGapCount());
        self::assertNotNull($stored->getDistanceKm());
        self::assertEqualsWithDelta(0.5293, $stored->getDistanceKm(), 0.001);
        self::assertEquals(new \DateTimeImmutable('2026-03-01T06:00:00Z'), $stored->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-03-01T06:25:00Z'), $stored->getEndedAt());
        self::assertNotNull($stored->getCreatedAt());
        self::assertSame('lead@example.test', $stored->getLead()?->getEmail());

        // The track round-trips through PostGIS as a GeoJSON LineString.
        $trackJson = $stored->getTrack();
        self::assertNotNull($trackJson);
        /** @var array{type: string, coordinates: list<list<float>>} $geo */
        $geo = json_decode($trackJson, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('LineString', $geo['type']);
        self::assertCount(4, $geo['coordinates']);
        self::assertEqualsWithDelta([-30.0, -1.0], $geo['coordinates'][0], 1e-9);
    }

    /** A patrol recorded from a file is settled: the worker is asked to buffer its track, and nothing is buffered here. */
    public function testARecordedPatrolIsQueuedForTheWorkerNotBufferedHere(): void
    {
        $area = $this->makeArea();
        $patrol = $this->ingest()->ingest($this->gpx(), $area, type: Vocabulary::type($this->em, $area, 'walk'));

        $transport = static::getContainer()->get('test_public.messenger.transport.async');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface::class, $transport);
        $queued = [];
        foreach ($transport->all() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof \Uhifadhi\Patrol\Message\BufferPatrolCorridor) {
                $queued[] = $message->patrolId;
            }
        }

        self::assertSame([(int) $patrol->getId()], $queued);
        self::assertSame(0, self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor')));
    }

    public function testObservationsPersistWithTheirPatrol(): void
    {
        $area = $this->makeArea();
        $patrol = $this->ingest()->ingest($this->gpx(), $area, type: Vocabulary::type($this->em, $area, 'boat'));

        $observation = new Observation($patrol, 'maintenance');
        $observation->setNote('Jetty ladder broken.')
            ->setPosition('{"type":"Point","coordinates":[-30.001,-1.0005]}')
            ->setLoggedAt(new \DateTimeImmutable('2026-03-01T06:07:00Z'));
        $this->em->persist($observation);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->find(Patrol::class, $patrol->getId());
        self::assertInstanceOf(Patrol::class, $stored);
        self::assertCount(1, $stored->getObservations());
        $first = $stored->getObservations()->first();
        self::assertInstanceOf(Observation::class, $first);
        self::assertSame('maintenance', $first->getCategory());
        self::assertSame('Jetty ladder broken.', $first->getNote());
        $position = $first->getPosition();
        self::assertNotNull($position);
        /** @var array{type: string, coordinates: list<float>} $point */
        $point = json_decode($position, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Point', $point['type']);
        self::assertEqualsWithDelta([-30.001, -1.0005], $point['coordinates'], 1e-9);
    }

    private static function whole(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
