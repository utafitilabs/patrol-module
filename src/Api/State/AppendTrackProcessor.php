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

namespace Uhifadhi\Patrol\Api\State;

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Patrol\Api\ContractResponse;
use Uhifadhi\Patrol\Api\PatrolApiContext;
use Uhifadhi\Patrol\Service\Api\TrackBatchService;

/**
 * `POST /api/patrols/{uuid}/track` — API-CONTRACT.md §5.
 */
final class AppendTrackProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly TrackBatchService $track,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $uuid = $this->api->uriUuid($uriVariables);
        $this->api->requireRecorder($this->api->findPatrol($uuid)?->getArea());

        $patrol = $this->api->patrol($uuid);
        $this->api->requireWriter($patrol);

        [$accepted, $duplicate] = $this->track->append($patrol, $this->api->body());

        return ContractResponse::ack($accepted, $duplicate);
    }
}
