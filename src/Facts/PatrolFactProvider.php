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

use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;
use Uhifadhi\Contracts\Facts\FigureDefinition;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolCorridorService;

/**
 * THE PATROL FIGURES THE WORKER FILES ON THE FACTS LEDGER — coverage, and
 * how long since a patrol entered each zone — so no page measures them.
 *
 * Every figure here reads a growing set: every track of a month, or every
 * track ever, against every zone. A page that measured them took seconds on
 * one long patrol; the core's schedule asks this provider instead, hourly,
 * and a page reads one stored row per figure with the time it was computed.
 *
 * WHAT IS FILED, per zone (`FactSubject::ZONE`, the zone's uuid) and per area
 * (`FactSubject::AREA`, the area's uuid):
 *
 * | key | subject | what | additive |
 * |---|---|---|---|
 * | `patrols.zone_coverage` | zone | % of the zone within its patrols' own type widths of a complete track | no |
 * | `patrols.zone_coverage_uniform` | zone | % of the zone within the module's one width of a complete track | no |
 * | `patrols.zone_patrols` | zone | complete patrols whose track entered the zone | yes |
 * | `patrols.zone_distance_km` | zone | kilometres those tracks ran inside the zone | yes |
 * | `patrols.zone_entered_ever` | zone | 1 when a complete track has ever entered the zone, 0 when none ever has | no |
 * | `patrols.zone_last_entered_at` | zone | when the last such patrol started, as a Unix time | no |
 * | `patrols.zone_last_patrol` | zone | that patrol's id | no |
 * | `patrols.area_coverage_uniform` | area | % of the area within the module's one width of a complete track | no |
 *
 * A share is filed as the number a plate prints — 54.0 for 54 % — like every
 * share this module publishes. NULL IS UNKNOWN, as the ledger defines it: an
 * area that recorded no track in the period has no coverage share to state,
 * and a zone in it has none either. A zone no track ever entered is not
 * unknown — it is `zone_entered_ever` 0, filed, and it has no last entry.
 *
 * "EVER" MEANS BEFORE THE PERIOD ENDS: for the month open now that is every
 * track so far; for a closed month, every track before its end — so a
 * closed month's figure stays what it was on its last day.
 *
 * IT BUFFERS BEFORE IT MEASURES. A patrol completed while no worker ran has no
 * stored corridor yet; the first thing a run does is buffer the period's
 * complete patrols still lacking one, so the hourly schedule is the safety
 * net for the message the completion sent. It writes no fact itself — the
 * core files what this returns — only the module's own corridor rows.
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Contracts/Facts/FactProviderInterface.php
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Contracts/docs/module-development.md — "Facts a module computes on a schedule"
 */
final readonly class PatrolFactProvider implements FactProviderInterface
{
    public const string ZONE_COVERAGE = 'patrols.zone_coverage';
    public const string ZONE_COVERAGE_UNIFORM = 'patrols.zone_coverage_uniform';
    public const string ZONE_PATROLS = 'patrols.zone_patrols';
    public const string ZONE_DISTANCE_KM = 'patrols.zone_distance_km';
    public const string ZONE_ENTERED_EVER = 'patrols.zone_entered_ever';
    public const string ZONE_LAST_ENTERED_AT = 'patrols.zone_last_entered_at';
    public const string ZONE_LAST_PATROL = 'patrols.zone_last_patrol';
    public const string AREA_COVERAGE_UNIFORM = 'patrols.area_coverage_uniform';

    /** Every zone figure, for a page that reads a zone's whole set in one batch. */
    public const array ZONE_FIGURES = [
        self::ZONE_COVERAGE,
        self::ZONE_COVERAGE_UNIFORM,
        self::ZONE_PATROLS,
        self::ZONE_DISTANCE_KM,
        self::ZONE_ENTERED_EVER,
        self::ZONE_LAST_ENTERED_AT,
        self::ZONE_LAST_PATROL,
    ];

    public function __construct(
        private PatrolCorridorService $buffering,
        private PatrolCorridorRepository $corridors,
        private PatrolRepository $patrols,
        private string $slug = 'patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function figures(): array
    {
        return [
            new FigureDefinition(self::ZONE_COVERAGE, FactSubject::ZONE, additive: false),
            new FigureDefinition(self::ZONE_COVERAGE_UNIFORM, FactSubject::ZONE, additive: false),
            new FigureDefinition(self::ZONE_PATROLS, FactSubject::ZONE, additive: true),
            new FigureDefinition(self::ZONE_DISTANCE_KM, FactSubject::ZONE, additive: true),
            new FigureDefinition(self::ZONE_ENTERED_EVER, FactSubject::ZONE, additive: false),
            new FigureDefinition(self::ZONE_LAST_ENTERED_AT, FactSubject::ZONE, additive: false),
            new FigureDefinition(self::ZONE_LAST_PATROL, FactSubject::ZONE, additive: false),
            new FigureDefinition(self::AREA_COVERAGE_UNIFORM, FactSubject::AREA, additive: false),
        ];
    }

    public function compute(FactRequest $request): iterable
    {
        $from = $request->period->from;
        $until = $request->period->until;

        // THE SAFETY NET: the period's complete patrols that no worker buffered.
        $this->buffering->catchUp(from: $from, until: $until);

        if ($request->asks(self::ZONE_COVERAGE) || $request->asks(self::ZONE_COVERAGE_UNIFORM) || $request->asks(self::AREA_COVERAGE_UNIFORM)) {
            $coverage = $this->corridors->coverageBetween($from, $until);

            foreach ($coverage['zones'] as $zone => $shares) {
                if (!$request->covers($zone)) {
                    continue;
                }
                if ($request->asks(self::ZONE_COVERAGE)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_COVERAGE, self::points($shares['typed']));
                }
                if ($request->asks(self::ZONE_COVERAGE_UNIFORM)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_COVERAGE_UNIFORM, self::points($shares['uniform']));
                }
            }

            if ($request->asks(self::AREA_COVERAGE_UNIFORM)) {
                foreach ($coverage['areas'] as $area => $share) {
                    if ($request->covers($area)) {
                        yield new FactValue(FactSubject::AREA, $area, self::AREA_COVERAGE_UNIFORM, self::points($share));
                    }
                }
            }
        }

        if ($request->asks(self::ZONE_PATROLS) || $request->asks(self::ZONE_DISTANCE_KM)) {
            foreach ($this->patrols->zoneEntriesBetween($from, $until) as $zone => $entries) {
                if (!$request->covers($zone)) {
                    continue;
                }
                if ($request->asks(self::ZONE_PATROLS)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_PATROLS, (float) $entries['patrols']);
                }
                if ($request->asks(self::ZONE_DISTANCE_KM)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_DISTANCE_KM, $entries['metres'] / 1000.0);
                }
            }
        }

        if ($request->asks(self::ZONE_ENTERED_EVER) || $request->asks(self::ZONE_LAST_ENTERED_AT) || $request->asks(self::ZONE_LAST_PATROL)) {
            foreach ($this->patrols->zoneLastEntriesBefore($until) as $zone => $last) {
                if (!$request->covers($zone)) {
                    continue;
                }
                if ($request->asks(self::ZONE_ENTERED_EVER)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_ENTERED_EVER, null === $last ? 0.0 : 1.0);
                }
                // A zone never entered has no last entry: nothing is filed,
                // and `zone_entered_ever` 0 is what says so.
                if (null === $last) {
                    continue;
                }
                if ($request->asks(self::ZONE_LAST_ENTERED_AT)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_LAST_ENTERED_AT, (float) $last['enteredAt']->getTimestamp());
                }
                if ($request->asks(self::ZONE_LAST_PATROL)) {
                    yield new FactValue(FactSubject::ZONE, $zone, self::ZONE_LAST_PATROL, (float) $last['patrolId']);
                }
            }
        }
    }

    /** A fraction of 1 as the points a plate prints; unknown stays unknown. */
    private static function points(?float $fraction): ?float
    {
        return null === $fraction ? null : $fraction * 100.0;
    }
}
