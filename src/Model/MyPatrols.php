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

use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;

/**
 * ONE PERSON'S PATROLS, READ ONCE for their own dashboard (#19): every figure
 * and list the module's six cards print, measured at one moment so no two of
 * them disagree about what "this week" was.
 *
 * TWO COUNTS OF THE MONTH, and they differ on purpose. `monthPatrols` is every
 * patrol of the month the person has not withdrawn — the one still out
 * included, because it is theirs and it is this month's. `monthCounted` is
 * the patrols whose distance is in `monthKm`, which a patrol still out is not
 * yet: its distance is provisional until the handset completes it
 * ({@see \Uhifadhi\Patrol\Enum\PatrolStatusEnum::countsTowardsStatistics()}).
 */
final readonly class MyPatrols
{
    /** How tall the day with the most walking stands, in the design's pixels. */
    public const float TALLEST_BAR = 82.5;

    /**
     * @param list<array{label: string, km: float, height: float}> $week               Monday to Sunday: the day, the kilometres, the bar's height
     * @param list<Patrol>                                         $latestPatrols      newest first
     * @param list<Observation>                                    $latestObservations newest first
     */
    public function __construct(
        public UserInterface $person,
        public float $monthKm,
        public float $weekKm,
        public int $monthPatrols,
        public int $monthCounted,
        public int $outNow,
        public float $lastMonthKm,
        public int $lastMonthCounted,
        public ?Patrol $longest,
        public int $monthObservations,
        public int $monthObservationsWithPhotos,
        public array $week,
        public array $latestPatrols,
        public array $latestObservations,
    ) {
    }
}
