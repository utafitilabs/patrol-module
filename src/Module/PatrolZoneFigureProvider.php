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

namespace Uhifadhi\Patrol\Module;

use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactPeriodKind;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneFigures;
use Uhifadhi\Contracts\Kpi\ZoneRef;
use Uhifadhi\Patrol\Facts\PatrolFactPeriod;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * WHAT THE PATROLS MODULE RECORDED OVER EACH ZONE OF AN AREA — three figures per
 * zone, READ FROM THE FACTS LEDGER, where the worker filed them.
 *
 * A zone is the area module's ground and this module's records are the module's;
 * the seam is where the two meet, so nothing here names a zone, stores one, or
 * decides who may read it. The zone arrives as a ref and the answer is keyed by
 * the zone's published uuid.
 *
 * NOTHING IS MEASURED HERE. The figures are sets over a month of tracks, and a
 * request never computes over a growing set: {@see PatrolFactProvider} files
 * them on the core's schedule, and this reads one batch of rows. Each plate
 * carries the time its figure is true as of ({@see DepartmentKpi::$asOf}).
 *
 * WHY THE COUNT AND THE SHARE DISAGREE ABOUT WHICH PATROLS MATTER, and it is not
 * an inconsistency: a patrol is "in" a zone when its TRACK entered the ring, but
 * ground is covered by every track within its own type's width of it — a round
 * walked along the fence covers the ring's edge without ever crossing it.
 *
 * THE PERIOD ANSWERED is the ledger period that matches the one asked
 * ({@see PatrolFactPeriod::answering()}), handed back with the answer, so a
 * surface that asked about a rolling window prints the calendar period it got.
 *
 * NOTHING IS A ZERO THAT WAS NOT MEASURED. A zone no track entered, in a period
 * the area recorded no track at all, is left out, as the seam asks. A zone the
 * worker has not measured yet gets its plates with no value and the words that
 * say why, never a nought.
 */
final class PatrolZoneFigureProvider implements ZoneFigureProviderInterface
{
    /** The empty state of a figure nobody has computed yet: when it will be. */
    public const string NOT_COMPUTED = 'not computed yet · runs hourly';

    private const array READS = [
        PatrolFactProvider::ZONE_PATROLS,
        PatrolFactProvider::ZONE_DISTANCE_KM,
        PatrolFactProvider::ZONE_COVERAGE,
    ];

    public function __construct(
        private readonly FactReaderInterface $facts,
        /** The slug this module is registered under in the registry's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function figuresFor(ZoneFigureRequest $request): ZoneFigures
    {
        $period = PatrolFactPeriod::answering($request->period->from, $request->period->until);
        $answered = self::figurePeriod($period, $request->period);

        if ($request->isEmpty()) {
            return ZoneFigures::none($answered);
        }

        $read = $this->facts->batch(FactSubject::ZONE, $request->zoneUuids(), self::READS, $period->key);

        $byZone = [];
        foreach ($request->zones as $zone) {
            $facts = $read[$zone->zoneUuid] ?? [];

            if ([] === $facts) {
                $byZone[$zone->zoneUuid] = $this->plates($zone, null, null, null);
                continue;
            }

            $patrols = $facts[PatrolFactProvider::ZONE_PATROLS] ?? null;
            $coverage = $facts[PatrolFactProvider::ZONE_COVERAGE] ?? null;

            // Nothing entered the ring AND nothing was measured over it: the
            // period recorded no track in this area, which is unknown and not naught.
            if (0.0 === $patrols?->value && null === $coverage?->value) {
                continue;
            }

            $byZone[$zone->zoneUuid] = $this->plates($zone, $patrols, $facts[PatrolFactProvider::ZONE_DISTANCE_KM] ?? null, $coverage);
        }

        return new ZoneFigures($byZone, $answered);
    }

    /**
     * One zone's three plates, in the order a surface draws them.
     *
     * The share is carried as the number a plate prints — 54.0 for 54 % — because
     * {@see DepartmentKpi::display()} formats the value it is handed and
     * {@see DepartmentKpi::delta()} moves a share in points.
     *
     * `previous` and the sparkline are left empty on purpose: the seam asks about
     * ONE period for a whole set of zones, and a card that draws no move needs no
     * second read.
     *
     * @return list<DepartmentKpi>
     */
    private function plates(ZoneRef $zone, ?Fact $patrols, ?Fact $distance, ?Fact $coverage): array
    {
        $caption = \sprintf('%s module · %s', $this->name, $zone->name);

        return [
            new DepartmentKpi('patrols', 'Patrols logged', $this->slug, $this->name, $patrols?->value, '', null, [], self::caption($caption, 'every track that entered the zone', $patrols), asOf: self::asOf($patrols)),
            new DepartmentKpi('distance', 'Distance patrolled', $this->slug, $this->name, $distance?->value, 'km', null, [], self::caption($caption, 'measured inside the zone only', $distance), asOf: self::asOf($distance)),
            new DepartmentKpi(
                self::COVERED,
                'Covered',
                $this->slug,
                $this->name,
                $coverage?->value,
                DepartmentKpi::SHARE,
                null,
                [],
                // The width is part of what the share MEANS, so it is printed with it:
                // each track counts as covering its own type's width, and a type that
                // sets none falls back on the module's figure.
                self::caption($caption, \sprintf(
                    'each track at its type\'s own width, %s km where a type sets none',
                    rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.'),
                ), $coverage),
                asOf: self::asOf($coverage),
            ),
        ];
    }

    /** The plate's provenance line; for a figure nobody has computed, when it will be. */
    private static function caption(string $caption, string $what, ?Fact $fact): string
    {
        return \sprintf('%s · %s', $caption, null === $fact ? self::NOT_COMPUTED : $what);
    }

    /** When the figure is true as of — none for a final one, which is its period's figure. */
    private static function asOf(?Fact $fact): ?\DateTimeImmutable
    {
        return null === $fact || $fact->isFinal() ? null : $fact->asOf;
    }

    /** The period answered, in the words a caption prints — the one asked where they agree. */
    private static function figurePeriod(FactPeriod $period, FigurePeriod $asked): FigurePeriod
    {
        if ($period->from == $asked->from && $period->until == $asked->until) {
            return $asked;
        }

        return match ($period->kind) {
            FactPeriodKind::Month => FigurePeriod::month($period->from),
            FactPeriodKind::Quarter => FigurePeriod::quarter($period->from),
            FactPeriodKind::Year => FigurePeriod::year($period->from),
        };
    }
}
