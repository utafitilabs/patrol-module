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
 * THE OVERVIEW'S STRIP AT ORGANIZATION SCOPE — the area readings added up.
 *
 * Null where nothing was measured, never zero: an organization whose areas
 * closed no patrol with a distance this week walked no known distance.
 */
final readonly class PatrolOrgFacts
{
    public function __construct(
        /** The areas in scope running the module. */
        public int $areas,
        /** Of them, the ones that have ever opened a patrol. */
        public int $reporting,
        public int $outNow,
        /** How many areas the patrols out now are spread over. */
        public int $outAreas,
        public int $finishedToday,
        public int $thisWeek,
        public ?float $weekDistanceKm,
    ) {
    }
}
