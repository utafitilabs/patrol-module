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

use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Message\BufferPatrolCorridor;

/**
 * WHAT A REQUEST DOES WHEN A PATROL SETTLES: it asks the worker to buffer the
 * track, and does not buffer it itself.
 *
 * Called after the flush that settled the patrol — a completion from the
 * handset, a patrol recorded from a GPX file, a late track batch for a patrol
 * already complete — so the id exists and the worker reads what was written.
 * A patrol with no track, or one that does not count, sends nothing: it has
 * no ground to cover.
 *
 * @see https://symfony.com/doc/current/messenger.html#dispatching-the-message
 */
final readonly class PatrolCorridorQueue
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function settled(Patrol $patrol): void
    {
        $id = $patrol->getId();
        if (null === $id || null === $patrol->getTrack() || PatrolStatusEnum::Complete !== $patrol->getStatus()) {
            return;
        }

        $this->bus->dispatch(new BufferPatrolCorridor($id));
    }
}
