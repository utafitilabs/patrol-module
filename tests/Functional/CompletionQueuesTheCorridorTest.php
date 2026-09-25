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

namespace Uhifadhi\Patrol\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Message\BufferPatrolCorridor;

/**
 * A COMPLETION IS AN EVENT, RECORDED AT ONCE; THE TRACK IS THE WORKER'S.
 *
 * `POST /api/patrols/{uuid}/complete` settles the patrol and queues one
 * message — it buffers nothing itself, so the handset's answer does not wait
 * on a long track. The worker (`messenger:consume async`) buffers it. A track
 * batch that lands after completion queues it again.
 */
final class CompletionQueuesTheCorridorTest extends FieldSyncTestCase
{
    public function testTheCompletionQueuesTheBufferAndTheWorkerDoesIt(): void
    {
        $patrolUuid = $this->recordedPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);
        self::assertResponseIsSuccessful();

        $patrol = $this->em->getRepository(Patrol::class)->findOneBy(['clientUuid' => $patrolUuid]);
        self::assertInstanceOf(Patrol::class, $patrol);

        self::assertSame([(int) $patrol->getId()], $this->queuedPatrolIds());
        self::assertSame(0, $this->corridors(), 'the request that completed the patrol buffered nothing');

        // The worker, as an installation runs it, until the queue is empty —
        // it holds the core's own work too.
        $worker = $this->console('messenger:consume', ['receivers' => ['async'], '--limit' => $this->queued(), '--time-limit' => 20]);
        self::assertSame(0, $worker->getStatusCode(), $worker->getDisplay());

        self::assertSame(1, $this->corridors());
        self::assertSame([], $this->queuedPatrolIds());
    }

    public function testARecordingPatrolsTrackIsNotQueued(): void
    {
        $this->recordedPatrol();

        self::assertSame([], $this->queuedPatrolIds());
    }

    public function testATrackBatchAfterCompletionQueuesTheBufferAgain(): void
    {
        $patrolUuid = $this->recordedPatrol();
        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);
        self::assertResponseIsSuccessful();

        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:1",
            'points' => [
                ['lat' => -3.2040, 'lon' => -29.5360, 'recordedAt' => '2026-08-23T06:50:17Z', 'accuracyM' => 5.0],
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertCount(2, $this->queuedPatrolIds());
    }

    /** A patrol created and given a track, as the handset does, still recording. */
    private function recordedPatrol(): string
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();
        self::assertResponseStatusCodeSame(201);

        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:0",
            'points' => [
                ['lat' => -3.2014, 'lon' => -29.5377, 'recordedAt' => '2026-08-23T06:44:17Z', 'accuracyM' => 4.0],
                ['lat' => -3.2020, 'lon' => -29.5370, 'recordedAt' => '2026-08-23T06:45:17Z', 'accuracyM' => 5.0],
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $patrolUuid;
    }

    /** @return list<int> the patrols whose buffer is waiting on the queue */
    private function queuedPatrolIds(): array
    {
        $transport = static::getContainer()->get('test_public.messenger.transport.async');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        $ids = [];
        foreach ($transport->all() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof BufferPatrolCorridor) {
                $ids[] = $message->patrolId;
            }
        }

        return $ids;
    }

    private function queued(): int
    {
        $transport = static::getContainer()->get('test_public.messenger.transport.async');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        return \count(iterator_to_array($transport->all(), false));
    }

    private function corridors(): int
    {
        return self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor'));
    }

    /** @param array<string, mixed> $input */
    private function console(string $name, array $input): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find($name));
        $tester->execute($input);

        return $tester;
    }

    private static function whole(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
