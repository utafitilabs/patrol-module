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
use Uhifadhi\Patrol\Service\Api\PatrolCompletionService;

/**
 * `POST /api/patrols/{uuid}/complete` — API-CONTRACT.md §9. The call that turns
 * a pile of accepted parts into a record the module will draw.
 */
final class CompletePatrolProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PatrolCompletionService $completion,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $uuid = $this->api->uriUuid($uriVariables);
        $this->api->requireRecorder($this->api->findPatrol($uuid)?->getArea());

        $patrol = $this->api->patrol($uuid);
        $this->api->requireWriter($patrol);

        // The body is optional here — empty for an ordinary complete, and
        // carrying the discard when the ranger threw the patrol away instead.
        [$completed, $alreadySettled] = $this->completion->complete($patrol, $this->api->body());

        // 200 either way: a repeated complete is success, and `duplicate`
        // carries the difference.
        return ContractResponse::completed($completed, $alreadySettled);
    }
}
