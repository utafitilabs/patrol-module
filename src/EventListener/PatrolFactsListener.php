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

namespace Uhifadhi\Patrol\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\RecomputeFacts;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;

/**
 * A CHANGE TO WHAT THE FIGURES COUNT ASKS THE CORE TO FILE ITS MONTHS AGAIN.
 *
 * The core's schedule refiles only the periods still open, and a closed month
 * keeps what it last filed. A complete patrol recorded late, discarded,
 * deleted, moved, or given an observation after its month closed changes that
 * month's figures, so the flush that wrote the change asks for the month —
 * {@see RecomputeFacts}, which the worker answers.
 *
 * A PATROL STILL RECORDING ASKS FOR NOTHING: it counts towards no figure, and
 * its track arrives a point at a time, so asking on every point would flood
 * the queue with requests for figures it does not touch.
 *
 * The months are collected while the flush is computed and sent once it has
 * been written, so a flush that fails asks for nothing.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html#onflush
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/events.html#postflush
 */
final class PatrolFactsListener
{
    /** @var array<string, true> month keys asked for by the flush under way */
    private array $months = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $work = $event->getObjectManager()->getUnitOfWork();

        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof Patrol && self::counts($entity->getStatus())) {
                $this->ask($entity->getStartedAt(), $entity->getEndedAt());
            }
            if ($entity instanceof Observation && self::counts($entity->getPatrol()->getStatus())) {
                $this->ask($entity->getPatrol()->getStartedAt(), $entity->getPatrol()->getEndedAt());
            }
        }

        foreach ($work->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Patrol) {
                continue;
            }

            $changes = $work->getEntityChangeSet($entity);
            // THE STATUS BEFORE THE CHANGE, which the change set may carry as
            // the column's raw value rather than the enum.
            $was = $changes['status'][0] ?? $entity->getStatus();
            $was = \is_string($was) ? PatrolStatusEnum::tryFrom($was) : $was;
            if (!$was instanceof PatrolStatusEnum || (!self::counts($was) && !self::counts($entity->getStatus()))) {
                continue;
            }

            $this->ask($entity->getStartedAt(), $entity->getEndedAt());

            $start = $changes['startedAt'][0] ?? null;
            $end = $changes['endedAt'][0] ?? null;
            if ($start instanceof \DateTimeImmutable || $end instanceof \DateTimeImmutable) {
                $this->ask(
                    $start instanceof \DateTimeImmutable ? $start : $entity->getStartedAt(),
                    $end instanceof \DateTimeImmutable ? $end : $entity->getEndedAt(),
                );
            }
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ([] === $this->months) {
            return;
        }

        $months = array_keys($this->months);
        $this->months = [];

        $this->bus->dispatch(new RecomputeFacts(PatrolFactProvider::SLUG, $months));
    }

    /** The months a patrol's track falls in, from the one it started in to the one it ended in. */
    private function ask(?\DateTimeImmutable $from, ?\DateTimeImmutable $until): void
    {
        if (null === $from) {
            return;
        }

        foreach (FactPeriod::monthsBetween($from, max($from, $until ?? $from)) as $month) {
            $this->months[$month->key] = true;
        }
    }

    private static function counts(PatrolStatusEnum $status): bool
    {
        return $status->countsTowardsStatistics();
    }
}
