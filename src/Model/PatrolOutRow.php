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

/**
 * ONE PATROL THAT IS OUT, READ ACROSS AREAS.
 *
 * The organization's reading of "who is out" is the areas' readings
 * concatenated, and a row from one area has to be tellable from a row from
 * another — so the area it happened in is a FIELD here where the per-area
 * card has no need of one. Everything else is the same fact the area card
 * states, taken from the same measurement.
 *
 * NULL IS NOT ZERO IN ANY FIELD. A patrol with no start time has been out for
 * an unknown length of time; one that has never pinged has not reported at
 * all; one whose record names no team size did not go out alone.
 */
final readonly class PatrolOutRow
{
    public function __construct(
        /** The patrol's reference, as every other patrol surface prints it. */
        public string $ref,
        /** The area it is out in — the column the organization's reading adds. */
        public string $areaName,
        /** When it opened, or null where the record does not say. */
        public ?\DateTimeImmutable $startedAt,
        /** The area's own word for this kind of round, in the area's own vocabulary. */
        public string $kindLabel,
        /** How many rangers the record names, or null where it names none. */
        public ?int $rangers,
        /** How long since the handset last said anything, already in words. */
        public ?string $pingLabel,
        /** Whether that silence has run past the module's own threshold. */
        public bool $stale,
        /** This patrol's own page. */
        public string $url,
    ) {
    }
}
