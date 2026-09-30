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
use Uhifadhi\Patrol\Service\Api\FlightSyncService;

/**
 * `POST /api/patrols/{uuid}/flights` — API-CONTRACT.md §7, the drone branch.
 */
final class AppendFlightsProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly FlightSyncService $flights,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $uuid = $this->api->uriUuid($uriVariables);
        $this->api->requireRecorder($this->api->findPatrol($uuid)?->getArea());

        $patrol = $this->api->patrol($uuid);
        $this->api->requireWriter($patrol);

        [$accepted, $duplicate] = $this->flights->append($patrol, $this->api->body());

        return ContractResponse::ack($accepted, $duplicate);
    }
}
