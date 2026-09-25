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

namespace Uhifadhi\Patrol\MessageHandler;

use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Contracts\Facts\RecomputeFacts;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;
use Uhifadhi\Patrol\Message\BufferPatrolCorridor;
use Uhifadhi\Patrol\Service\PatrolCorridorService;

/**
 * THE WORKER'S SIDE OF A SETTLED PATROL: buffer its track into the stored
 * corridor, then ask the core to file this module's figures again for the
 * months the patrol touched. The figures are never computed here — the
 * registry's own writer does that on the same worker, for this module alone,
 * and a month that had already closed is included, so a patrol uploaded late
 * is counted without an operator's rebuild.
 *
 * Registered by hand with the message it handles:
 *   "If autoconfiguration is disabled, manually register handlers using the
 *    messenger.message_handler tag with the handles attribute"
 *   — https://symfony.com/doc/current/messenger.html#manually-configuring-handlers
 *
 * @see RecomputeFacts the core's contract for a module's own recompute
 */
final readonly class BufferPatrolCorridorHandler
{
    public function __construct(
        private PatrolCorridorService $corridors,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(BufferPatrolCorridor $message): void
    {
        if (!$this->corridors->buffer($message->patrolId)) {
            return;
        }

        $months = $this->corridors->monthsOf($message->patrolId);
        if ([] === $months) {
            return;
        }

        $this->bus->dispatch(new RecomputeFacts(PatrolFactProvider::SLUG, $months));
    }
}
