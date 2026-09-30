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
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Service\Api\PatrolUpsertService;

/**
 * `POST /api/patrols` — API-CONTRACT.md §4.
 */
final class CreatePatrolProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PatrolUpsertService $upsert,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        // THE GROUND FIRST, so the gate is asked about the area the phone
        // named rather than about no area at all. An id this server never
        // issued resolves to null, the gate still refuses a caller who may
        // not record, and the upsert then answers the 422 it owns.
        $body = $this->api->body();
        $recorder = $this->api->requireRecorder($this->api->findArea(Payload::string($body, 'areaId') ?? ''));

        // A RE-SEND IS ONLY THE SENDER'S: somebody else's patrol id is not a
        // way in to it, nor a way to learn it exists beyond the refusal.
        $existing = $this->api->findPatrol(Payload::string($body, 'clientUuid') ?? '');
        if (null !== $existing) {
            $this->api->requireWriter($existing);
        }

        [$patrol, $duplicate] = $this->upsert->upsert($body, $recorder);

        // 201 the first time, 200 on a re-send — the contract draws that line
        // and the app reads it.
        return ContractResponse::patrol($patrol, $duplicate);
    }
}
