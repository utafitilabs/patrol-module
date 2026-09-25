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

namespace Uhifadhi\Patrol\Message;

use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * BUFFER THIS PATROL'S TRACK — sent when a patrol settles, handled by the
 * worker.
 *
 * A completion is an event and is recorded at once; buffering the track is
 * work that grows with the track, so the request that completed the patrol
 * only sends this and answers. The core's queue marker routes it to the
 * installation's `async` transport, so it needs no routing line of its own:
 *
 *   "route all messages that extend this example base class or interface"
 *   — https://symfony.com/doc/current/messenger.html#routing-messages-to-a-transport
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Contracts/Queue/AsyncMessageInterface.php
 *
 * IT CARRIES THE ID AND NOTHING ELSE: the handler reads the patrol as it is
 * when the worker gets to it, so a track that grew in the meantime is
 * buffered whole. A message whose patrol is gone buffers nothing.
 *
 * WITHOUT A WORKER the message waits on the queue, and the hourly facts run
 * buffers every complete patrol still lacking a corridor before it measures
 * — {@see \Uhifadhi\Patrol\Facts\PatrolFactProvider::compute()}.
 */
final readonly class BufferPatrolCorridor implements AsyncMessageInterface
{
    public function __construct(
        public int $patrolId,
    ) {
    }
}
