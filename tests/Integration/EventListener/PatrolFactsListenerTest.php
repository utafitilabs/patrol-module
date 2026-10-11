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

namespace Uhifadhi\Patrol\Tests\Integration\EventListener;

use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Facts\RecomputeFacts;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\EventListener\PatrolFactsListener;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * A CHANGE TO WHAT THE FIGURES COUNT ASKS THE CORE TO FILE THEM AGAIN.
 *
 * The schedule refiles only the periods still open; a closed month keeps what
 * it last filed. So a patrol discarded, deleted, recorded late or given an
 * observation after its month closed would leave that month's figures wrong
 * for good — unless the change itself asks for the month again.
 *
 * A patrol still recording counts towards nothing, and its track arrives a
 * point at a time: it asks for nothing until it settles.
 */
final class PatrolFactsListenerTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $this->em->persist($this->area);
        $this->em->flush();
    }

    public function testACompletePatrolRecordedAsksForItsMonth(): void
    {
        $this->patrol('2026-03-10 06:00:00');

        self::assertSame([['2026-03']], $this->asked());
    }

    public function testDiscardingAPatrolAsksForItsMonth(): void
    {
        $patrol = $this->patrol('2026-03-10 06:00:00');
        $this->clearQueue();

        $patrol->setStatus(PatrolStatusEnum::Discarded);
        $this->em->flush();

        self::assertSame([['2026-03']], $this->asked());
    }

    public function testDeletingAPatrolAsksForItsMonth(): void
    {
        $patrol = $this->patrol('2026-03-10 06:00:00');
        $this->clearQueue();

        $this->em->remove($patrol);
        $this->em->flush();

        self::assertSame([['2026-03']], $this->asked());
    }

    public function testAnObservationOnACompletePatrolAsksForItsMonth(): void
    {
        $patrol = $this->patrol('2026-03-10 06:00:00');
        $this->clearQueue();

        $this->em->persist(new Observation($patrol, 'sighting'));
        $this->em->flush();

        self::assertSame([['2026-03']], $this->asked());
    }

    /** Moved to another month, both months change: each is asked for. */
    public function testMovingAPatrolsStartAsksForBothMonths(): void
    {
        $patrol = $this->patrol('2026-03-10 06:00:00');
        $this->clearQueue();

        $patrol->setStartedAt(new \DateTimeImmutable('2026-05-02 06:00:00'));
        $this->em->flush();

        self::assertSame([['2026-03', '2026-05']], $this->asked());
    }

    public function testAPatrolStillRecordingAsksForNothing(): void
    {
        $patrol = $this->patrol('2026-03-10 06:00:00', PatrolStatusEnum::Recording);
        $patrol->setDistanceKm(4.0);
        $this->em->persist(new Observation($patrol, 'sighting'));
        $this->em->flush();

        self::assertSame([], $this->asked());
    }

    public function testItIsTheModulesListener(): void
    {
        self::assertInstanceOf(PatrolFactsListener::class, static::getContainer()->get('test_public.'.PatrolFactsListener::class));
    }

    private function patrol(string $startedAt, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setStatus($status);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    /** @return list<list<string>> the months each queued recompute of this module asks for */
    private function asked(): array
    {
        $asked = [];
        foreach ($this->async()->all() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RecomputeFacts && 'patrols' === $message->moduleSlug) {
                $keys = $message->monthKeys;
                sort($keys);
                $asked[] = $keys;
            }
        }

        return $asked;
    }

    private function clearQueue(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
    }

    private function async(): ListableReceiverInterface
    {
        $transport = static::getContainer()->get('test_public.messenger.transport.async');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        return $transport;
    }
}
