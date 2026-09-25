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

namespace Uhifadhi\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Message\BufferPatrolCorridor;
use Uhifadhi\Patrol\Service\PatrolCorridorQueue;

/**
 * A SETTLED PATROL IS HANDED TO THE WORKER, and only one that has ground to
 * cover: a complete patrol with a track. The request buffers nothing.
 */
#[CoversClass(PatrolCorridorQueue::class)]
final class PatrolCorridorQueueTest extends TestCase
{
    private const string TRACK = '{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}';

    public function testACompletePatrolWithATrackIsSentToTheWorker(): void
    {
        $bus = self::bus();
        new PatrolCorridorQueue($bus)->settled(self::patrol(41, PatrolStatusEnum::Complete, self::TRACK));

        self::assertCount(1, $bus->sent);
        self::assertInstanceOf(BufferPatrolCorridor::class, $bus->sent[0]);
        self::assertInstanceOf(AsyncMessageInterface::class, $bus->sent[0], 'the core\'s marker routes it to the queue');
        self::assertSame(41, $bus->sent[0]->patrolId);
    }

    public function testAPatrolWithNoTrackHasNothingToBuffer(): void
    {
        $bus = self::bus();
        new PatrolCorridorQueue($bus)->settled(self::patrol(41, PatrolStatusEnum::Complete, null));

        self::assertSame([], $bus->sent);
    }

    public function testAPatrolThatDoesNotCountIsNotBuffered(): void
    {
        $bus = self::bus();
        $queue = new PatrolCorridorQueue($bus);
        $queue->settled(self::patrol(41, PatrolStatusEnum::Recording, self::TRACK));
        $queue->settled(self::patrol(42, PatrolStatusEnum::Discarded, self::TRACK));

        self::assertSame([], $bus->sent);
    }

    public function testAPatrolNeverSavedHasNoIdToSend(): void
    {
        $bus = self::bus();
        new PatrolCorridorQueue($bus)->settled(self::patrol(null, PatrolStatusEnum::Complete, self::TRACK));

        self::assertSame([], $bus->sent);
    }

    private static function patrol(?int $id, PatrolStatusEnum $status, ?string $track): Patrol
    {
        $area = new AreaOfInterest()->setName('Example square');
        $patrol = new Patrol($area, new PatrolType($area, 'walk', 'Walking round'))->setStatus($status)->setTrack($track);
        if (null !== $id) {
            new \ReflectionProperty(Patrol::class, 'id')->setValue($patrol, $id);
        }

        return $patrol;
    }

    /** @return MessageBusInterface&object{sent: list<object>} */
    private static function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };
    }
}
