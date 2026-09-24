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

namespace Uhifadhi\Patrol\Model;

use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\PatrolType;

/**
 * ONE SUBMISSION OF THE ENTRY FLOW — everything the page posted, resolved to
 * this area's own records, and nothing else.
 *
 * It exists so the controller stays what a controller is: it authorises, reads
 * the request into this, and hands it over. Every rule about what makes a
 * patrol — that a start is required, that an end must follow it, that a track
 * outranks a typed distance — belongs to
 * {@see \Uhifadhi\Patrol\Service\PatrolRecordingService} and is asserted there,
 * where it can be exercised without a browser.
 *
 * THE TRACK IS A KEY, NOT BYTES. The file went to the storage on the way in,
 * through the platform's upload component; what the form carries back is the
 * key the component was given. Whether that key is one this draft actually holds
 * is the service's question, asked against the draft's own rows.
 */
final readonly class LoggedPatrol
{
    /**
     * @param ?string                 $trackKey     the evidence key PL·01 reported, or null for a
     *                                              patrol nobody tracked
     * @param list<LoggedObservation> $observations PL·03's records, in the order the page drew them
     */
    public function __construct(
        public PatrolType $type,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt = null,
        public ?Station $station = null,
        public ?UserInterface $lead = null,
        public ?string $team = null,
        public ?string $note = null,
        public ?float $distanceKm = null,
        public ?string $trackKey = null,
        public array $observations = [],
    ) {
    }
}
