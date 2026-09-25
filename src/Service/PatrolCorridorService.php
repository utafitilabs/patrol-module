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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;

/**
 * BUFFERING PATROL TRACKS INTO STORED CORRIDORS — for one patrol when it
 * settles, and in batches for the ones that still lack one.
 *
 * THE WIDTHS ARE THE ONES COVERAGE HAS ALWAYS BEEN MEASURED AT: a track at its
 * type's own width, the module's {@see PatrolDashboardService::COVERAGE_BUFFER_M}
 * where the type sets none, and a second shape at that module width for
 * every patrol. A width changed later leaves stale corridors behind, which
 * {@see self::catchUp()} finds by comparing the widths stored beside them.
 *
 * RUNS IN THE WORKER AND ON THE CONSOLE, never in a web request: a long track
 * is the cost this exists to pay once.
 *
 * "NOW" IS THE CLOCK'S, the framework's `clock` service, so a test fixes it.
 *
 * @see https://symfony.com/doc/current/components/clock.html
 */
final readonly class PatrolCorridorService
{
    /** How many patrols are buffered between two clears of the entity manager. */
    public const int BATCH = 100;

    public function __construct(
        private PatrolCorridorRepository $corridors,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @return bool whether the patrol has a corridor now */
    public function buffer(int $patrolId): bool
    {
        return $this->corridors->buffer($patrolId, self::width(), self::width(), $this->clock->now());
    }

    /**
     * EVERY COMPLETE PATROL WHOSE CORRIDOR IS MISSING OR STALE, buffered in
     * batches, oldest id first; with `$all`, every complete patrol with a
     * track. A window narrows it to the patrols that started in it.
     *
     * The entity manager is cleared after every batch, so a rebuild over years
     * of patrols holds one batch in memory, not the history.
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/batch-processing.html
     *
     * @param (callable(int): void)|null $progress told how many patrols each batch buffered
     *
     * @return int how many patrols were buffered
     */
    public function catchUp(
        bool $all = false,
        int $batchSize = self::BATCH,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $until = null,
        ?callable $progress = null,
    ): int {
        $buffered = 0;
        $after = 0;

        while (true) {
            $ids = $this->corridors->findPatrolIdsToBuffer(self::width(), self::width(), $after, $batchSize, $all, $from, $until);
            if ([] === $ids) {
                break;
            }

            $done = 0;
            foreach ($ids as $id) {
                if ($this->buffer($id)) {
                    ++$done;
                }
            }

            $buffered += $done;
            $after = $ids[\count($ids) - 1];
            $this->entityManager->clear();

            if (null !== $progress) {
                $progress($done);
            }

            if (\count($ids) < $batchSize) {
                break;
            }
        }

        return $buffered;
    }

    /** The module's one coverage width, in whole metres. */
    public static function width(): int
    {
        return (int) PatrolDashboardService::COVERAGE_BUFFER_M;
    }
}
