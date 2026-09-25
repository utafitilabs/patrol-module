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

use Uhifadhi\Patrol\Message\BufferPatrolCorridor;
use Uhifadhi\Patrol\Service\PatrolCorridorService;

/**
 * THE WORKER'S SIDE OF A SETTLED PATROL: its track, buffered and kept.
 *
 * Registered with the `messenger.message_handler` tag and its `handles`
 * attribute, by hand, because a reusable bundle is not autoconfigured:
 *
 *   "If autoconfiguration is disabled, manually register handlers using the
 *    messenger.message_handler tag"
 *   — https://symfony.com/doc/current/messenger.html#manually-configuring-handlers
 *
 * @see vendor/symfony/messenger/DependencyInjection/MessengerPass.php — the tag's `handles` attribute names the message class
 */
final readonly class BufferPatrolCorridorHandler
{
    public function __construct(
        private PatrolCorridorService $corridors,
    ) {
    }

    public function __invoke(BufferPatrolCorridor $message): void
    {
        $this->corridors->buffer($message->patrolId);
    }
}
