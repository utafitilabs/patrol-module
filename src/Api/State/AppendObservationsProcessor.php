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
use Uhifadhi\Patrol\Service\Api\ObservationSyncService;

/**
 * `POST /api/patrols/{uuid}/observations` — API-CONTRACT.md §6.
 */
final class AppendObservationsProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly ObservationSyncService $observations,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $uuid = $this->api->uriUuid($uriVariables);
        $recorder = $this->api->requireRecorder($this->api->findPatrol($uuid)?->getArea());

        $patrol = $this->api->patrol($uuid);
        $this->api->requireWriter($patrol);

        [$accepted, $duplicate] = $this->observations->append($patrol, $this->api->body(), $recorder);

        return ContractResponse::ack($accepted, $duplicate);
    }
}
