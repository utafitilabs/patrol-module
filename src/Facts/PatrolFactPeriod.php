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

namespace Uhifadhi\Patrol\Facts;

use Uhifadhi\Contracts\Facts\FactPeriod;

/**
 * WHICH LEDGER PERIOD ANSWERS A WINDOW A PAGE ASKED ABOUT.
 *
 * The ledger files calendar months, quarters and years
 * ({@see FactPeriod}); a surface asks about a window. A window that IS a
 * calendar month, quarter or year is answered by that period exactly. Any
 * other window — a rolling ninety days — is answered by the calendar period
 * of about its length that holds its last instant, and the caller says so:
 * a seam that hands its period back prints the period it was given.
 *
 * About its length is the house's own reading of a span, the one
 * {@see \Uhifadhi\Contracts\Kpi\FigurePeriod::shortLabel()} names a period by:
 * over two hundred days is a year, over forty-five a quarter, otherwise a
 * month.
 */
final class PatrolFactPeriod
{
    private function __construct()
    {
    }

    public static function answering(\DateTimeImmutable $from, \DateTimeImmutable $until): FactPeriod
    {
        foreach ([FactPeriod::month($from), FactPeriod::quarter($from), FactPeriod::year($from)] as $period) {
            if ($period->from == $from && $period->until == $until) {
                return $period;
            }
        }

        $last = $until->modify('-1 second');
        $days = (int) $from->diff($until)->days;

        return match (true) {
            $days > 200 => FactPeriod::year($last),
            $days > 45 => FactPeriod::quarter($last),
            default => FactPeriod::month($last),
        };
    }
}
