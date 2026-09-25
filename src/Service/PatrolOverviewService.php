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

namespace Uhifadhi\Patrol\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\TrackPointRepository;

/**
 * WHAT PATROLS TELLS THE AREA OVERVIEW, READ ONCE.
 *
 * The module contributes to `/areas/{uuid}` through five separate contribution points — a
 * widget contributor, a now-tile provider, an attention provider, a map-layer
 * provider and a pulse provider — and four of them are asking about the same
 * morning. This is the one place that morning is measured, so the strip's "3
 * out" and the card's three rows and the plate's three live tracks are the same
 * three patrols rather than three answers taken a query apart.
 *
 * THE OVERVIEW'S WINDOW IS THE DAY, NOT THE MONTH. The module's own dashboard is
 * a month: 142 patrols, 2,214 km, the calendar, the five-week series. Nothing
 * here reconciles with that and nothing should — an area manager at 07:00 is
 * asking who is out and how today is going, and a card that answered with the
 * month would be answering a question nobody asked. The one month figure that
 * appears (in PL·A2's closing line) is there precisely to say so.
 *
 * PURE OF THE CLOCK: `$now` is handed in everywhere, never read, so every one of
 * these cards is testable at a fixed moment.
 *
 * ABSENT IS NOT ZERO, throughout. A distance nobody recorded is null and prints
 * as an em dash; it is never 0 km, which would claim the module measured the
 * day's walking and found none.
 */
final readonly class PatrolOverviewService
{
    /**
     * How long a patrol may go without pinging before the overview calls it out
     * rather than quietly averaging it away — the design's own threshold, said
     * on the card in its `.use` line so the number on screen and the number in
     * code are the same number.
     */
    public const int PING_STALE_AFTER_SECONDS = 5400;

    /**
     * How many days a zone may go unentered before it is worth somebody's week,
     * and before it is worth their day.
     *
     * Stated here rather than configured, for the same reason PL·03's 2 km
     * buffer is: the threshold is part of what the card MEANS, and a deployment
     * that quietly moved it would be printing a different claim under the same
     * words. They are days rather than a "patrol frequency target" because this
     * module records no plan — there is nothing to be behind.
     */
    public const int ZONE_GAP_SOON_DAYS = 7;
    public const int ZONE_GAP_NOW_DAYS = 14;

    /** How many rows PL·A4 shows. A queue is a page of work, not an archive. */
    public const int OBSERVATION_ROWS = 6;

    /**
     * @param array<string, array{label: string}> $categories the deployment's patrol.observation_categories map
     */
    public function __construct(
        private PatrolRepository $patrols,
        private TrackPointRepository $trackPoints,
        private ObservationRepository $observations,
        private UrlGeneratorInterface $router,
        // THE AREA'S OWN PATROL TYPES — the Patrol types section's list.
        private PatrolTypeRepository $types,
        private array $categories,
        // THE FACTS LEDGER, where the worker files the zone figures PL·A3 reads.
        private FactReaderInterface $facts,
        // THE AREA'S ZONES, from the core's own repository, by name.
        private ZoneRepository $zones,
    ) {
    }

    /**
     * PL·A1 — the patrols that have opened and not closed, longest out first,
     * each with the last thing its handset said.
     *
     * `outSeconds` and `pingSeconds` are null where the record does not say: a
     * patrol with no start time has been out for an unknown length of time, and
     * one that has never pinged has not reported at all. Neither is zero.
     *
     * STALE MEANS SILENT FOR TOO LONG, and a patrol that has never pinged is
     * only silent once it has been out long enough to have said something. A
     * patrol opened four minutes ago with no points yet is not a patrol in
     * trouble; it is a patrol whose first batch has not arrived.
     *
     * `line` and `point` are the trail and its head, GeoJSON straight from the
     * points, because a RECORDING patrol's `track` is null until it closes.
     *
     * @return list<array{patrol: Patrol, url: string, outSeconds: int|null, outLabel: string|null, lastPingAt: \DateTimeImmutable|null, pingSeconds: int|null, pingLabel: string|null, stale: bool, line: string|null, point: string|null}>
     */
    public function out(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $patrols = $this->patrols->findByAreaRecording($area);
        $ids = [];
        foreach ($patrols as $patrol) {
            $id = $patrol->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }
        $trails = $this->trackPoints->trailsForPatrols($ids);

        $rows = [];
        foreach ($patrols as $patrol) {
            $trail = $trails[(int) $patrol->getId()] ?? null;
            $startedAt = $patrol->getStartedAt();
            $outSeconds = null === $startedAt ? null : max(0, $now->getTimestamp() - $startedAt->getTimestamp());
            $pingSeconds = null === $trail ? null : max(0, $now->getTimestamp() - $trail['lastAt']->getTimestamp());

            $rows[] = [
                'patrol' => $patrol,
                'url' => $this->patrolUrl($area, $patrol),
                'outSeconds' => $outSeconds,
                'outLabel' => null === $outSeconds ? null : self::ageLabel($outSeconds),
                'lastPingAt' => $trail['lastAt'] ?? null,
                'pingSeconds' => $pingSeconds,
                'pingLabel' => null === $pingSeconds ? null : self::ageLabel($pingSeconds),
                'stale' => null === $pingSeconds
                    ? (null !== $outSeconds && $outSeconds >= self::PING_STALE_AFTER_SECONDS)
                    : $pingSeconds >= self::PING_STALE_AFTER_SECONDS,
                'line' => $trail['line'] ?? null,
                'point' => $trail['lastPoint'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * PL·A1's closing line — how many of the handsets that are out are actually
     * reporting, and which one to raise.
     *
     * A HANDSET REPORTING IS A PATROL PINGING. The module registers no devices
     * and holds no roster, so "2 of 3" is counted over the patrols that are out
     * rather than over a fleet: it is the same fact the rows above it state, said
     * once as a total. The one named is the longest-silent, because that is the
     * one somebody has to call.
     *
     * @param list<array{patrol: Patrol, url: string, outSeconds: int|null, outLabel: string|null, lastPingAt: \DateTimeImmutable|null, pingSeconds: int|null, pingLabel: string|null, stale: bool, line: string|null, point: string|null}> $out
     *
     * @return array{reporting: int, total: int, worst: array{patrol: Patrol, url: string, outSeconds: int|null, outLabel: string|null, lastPingAt: \DateTimeImmutable|null, pingSeconds: int|null, pingLabel: string|null, stale: bool, line: string|null, point: string|null}|null}
     */
    public function handsets(array $out): array
    {
        $silent = array_values(array_filter($out, static fn (array $row): bool => $row['stale']));
        // Longest silence first. A patrol that has never pinged is the longest
        // silence there is, so it sorts ahead of any measured one.
        usort($silent, static fn (array $a, array $b): int => ($b['pingSeconds'] ?? \PHP_INT_MAX) <=> ($a['pingSeconds'] ?? \PHP_INT_MAX));

        return [
            'reporting' => \count($out) - \count($silent),
            'total' => \count($out),
            'worst' => $silent[0] ?? null,
        ];
    }

    /**
     * PL·A2 — today only, against the same weekday a week ago.
     *
     * THE SAME WEEKDAY, not yesterday: patrolling has a weekly shape, and a
     * saturday compared with a friday would report the shape of the week as a
     * change in effort. The two windows are the same length and the same
     * weekday, so the difference is about the day rather than about the calendar.
     *
     * `distanceKm` is null where nothing closed today carries a distance —
     * hand-logged patrols record no route — because 0 km would claim the day's
     * walking was measured and came to nothing.
     *
     * @return array{day: \DateTimeImmutable, lastWeek: \DateTimeImmutable, closed: int, closedLastWeek: int, distanceKm: float|null, distanceKmLastWeek: float|null, typeCounts: array<string, int>, stations: list<string>, observations: int, stillOut: int, stillOutRefs: list<string>, monthCount: int, monthDistanceKm: float|null}
     */
    public function today(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $dayStart = $now->setTime(0, 0);
        $lastWeekStart = $dayStart->modify('-7 days');

        $closed = $this->counted($this->patrols->findByAreaEndedBetween($area, $dayStart, $dayStart->modify('+1 day')));
        $lastWeek = $this->counted($this->patrols->findByAreaEndedBetween($area, $lastWeekStart, $lastWeekStart->modify('+1 day')));

        // Types are the AREA's vocabulary, so every type it keeps gets a count —
        // including the ones that did nothing today. Here a 0 is a real
        // measurement ("no drone flew"), not a stand-in for an unknown.
        $typeCounts = array_fill_keys(array_keys($this->types->findVocabularyByArea($area)), 0);
        $stations = [];
        foreach ($closed as $patrol) {
            $typeCounts[$patrol->getType()] = ($typeCounts[$patrol->getType()] ?? 0) + 1;
            $station = $patrol->getStation();
            if (null !== $station && '' !== $station && !\in_array($station, $stations, true)) {
                $stations[] = $station;
            }
        }

        $out = $this->patrols->findByAreaRecording($area);
        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);
        $month = $this->counted($this->patrols->findByAreaStartedBetween($area, $monthStart, $nextMonth));

        return [
            'day' => $dayStart,
            'lastWeek' => $lastWeekStart,
            'closed' => \count($closed),
            'closedLastWeek' => \count($lastWeek),
            'distanceKm' => $this->distance($closed),
            'distanceKmLastWeek' => $this->distance($lastWeek),
            'typeCounts' => $typeCounts,
            'stations' => $stations,
            'observations' => \count($this->observations->findByAreaLoggedBetween($area, $dayStart, $dayStart->modify('+1 day'))),
            'stillOut' => \count($out),
            'stillOutRefs' => array_map(static fn (Patrol $p): string => $p->getRef(), $out),
            'monthCount' => \count($month),
            'monthDistanceKm' => $this->distance($month),
        ];
    }

    /**
     * WHETHER THIS AREA PATROLS AT ALL.
     *
     * The one question that separates a zero the overview may print from an
     * absence it must not dress up as one: an area with a register and a quiet
     * morning walked 0 km, and an area that has never opened a patrol did not
     * walk 0 km — it has nothing to say. Asked only where the day came back
     * empty, so a busy morning never pays for it.
     */
    public function hasRegister(AreaOfInterest $area): bool
    {
        return $this->patrols->areaHasAnyPatrol($area);
    }

    /**
     * PL·A3 — every zone of the area by how long since a track entered it, worst
     * first, with the share of each lying within the module's one coverage
     * width this month — READ FROM THE FACTS LEDGER, never measured here.
     *
     * The worker files, per zone, whether a complete track has ever entered it,
     * when the last one did and which patrol it was, and the month's covered
     * share ({@see PatrolFactProvider}); a page reads one batch of rows and
     * counts the days from the stored instant itself, so "days since" moves with
     * the clock between two runs.
     *
     * THREE STATES, NEVER CONFLATED:
     *  - `never`: the worker looked and no track has ever entered the zone —
     *    the worst gap there is, sorted first;
     *  - a last entry: `daysSince` whole calendar days before `$now`;
     *  - `computed` false: no fact filed for the zone yet — the page says
     *    "not computed yet · runs hourly", sorts it last, and raises no
     *    attention about it, because nothing is known about it.
     *
     * `asOf` is the fact the card's stamp prints ({@see shell_as_of()}): the
     * area's share where it is filed, otherwise the first zone fact read.
     *
     * @return array{zones: list<array{zone: string, uuid: string, computed: bool, never: bool, lastPatrol: Patrol|null, lastEnteredAt: \DateTimeImmutable|null, daysSince: int|null, coverageFraction: float|null}>, areaCoverageFraction: float|null, asOf: Fact|null, bufferKm: float}
     */
    public function gaps(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $buffer = PatrolDashboardService::COVERAGE_BUFFER_M;
        $month = FactPeriod::month($now)->key;
        $zones = $this->zones->zonesFor($area);

        $uuids = array_values(array_filter(array_map(static fn (Zone $zone): ?string => $zone->getUuidString(), $zones)));
        $read = [] === $uuids ? [] : $this->facts->batch(FactSubject::ZONE, $uuids, [
            PatrolFactProvider::ZONE_ENTERED_EVER,
            PatrolFactProvider::ZONE_LAST_ENTERED_AT,
            PatrolFactProvider::ZONE_LAST_PATROL,
            PatrolFactProvider::ZONE_COVERAGE_UNIFORM,
        ], $month);

        $areaUuid = $area->getUuidString();
        $areaCoverage = null === $areaUuid ? null : $this->facts->latest(FactSubject::AREA, $areaUuid, PatrolFactProvider::AREA_COVERAGE_UNIFORM, $month);

        $lastPatrolIds = [];
        foreach ($read as $facts) {
            $id = $facts[PatrolFactProvider::ZONE_LAST_PATROL]->value ?? null;
            if (null !== $id && 1.0 === ($facts[PatrolFactProvider::ZONE_ENTERED_EVER]->value ?? null)) {
                $lastPatrolIds[] = (int) $id;
            }
        }
        /** @var array<int, Patrol> $lastPatrols */
        $lastPatrols = [];
        foreach ([] === $lastPatrolIds ? [] : $this->patrols->findBy(['id' => $lastPatrolIds]) as $patrol) {
            $lastPatrols[(int) $patrol->getId()] = $patrol;
        }

        $asOf = $areaCoverage;
        $rows = [];
        foreach ($zones as $zone) {
            $uuid = (string) $zone->getUuidString();
            $facts = $read[$uuid] ?? [];
            $ever = $facts[PatrolFactProvider::ZONE_ENTERED_EVER] ?? null;
            $coverage = $facts[PatrolFactProvider::ZONE_COVERAGE_UNIFORM] ?? null;
            $asOf ??= $ever ?? $coverage;

            $entered = 1.0 === $ever?->value;
            $at = $entered ? ($facts[PatrolFactProvider::ZONE_LAST_ENTERED_AT]->value ?? null) : null;
            $enteredAt = null === $at ? null : new \DateTimeImmutable('@'.(int) $at)->setTimezone($now->getTimezone());
            $patrolId = $entered ? ($facts[PatrolFactProvider::ZONE_LAST_PATROL]->value ?? null) : null;

            $rows[] = [
                'zone' => (string) $zone->getName(),
                'uuid' => $uuid,
                'computed' => null !== $ever,
                'never' => null !== $ever && !$entered,
                'lastPatrol' => null === $patrolId ? null : ($lastPatrols[(int) $patrolId] ?? null),
                'lastEnteredAt' => $enteredAt,
                // Whole days between the two calendar dates, so a patrol that
                // entered at 23:50 last night reads as "1 d" this morning rather
                // than as "0 d" for another eight hours.
                'daysSince' => null === $enteredAt ? null : max(0, (int) $enteredAt->setTime(0, 0)->diff($now->setTime(0, 0))->format('%r%a')),
                'coverageFraction' => null === $coverage?->value ? null : $coverage->value / 100.0,
            ];
        }

        // Worst first: never entered, then the longest since, then what the
        // worker has not measured yet; by name within each.
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $row): int => !$row['computed'] ? 2 : ($row['never'] ? 0 : 1);

            return [$rank($a), $a['lastEnteredAt']?->getTimestamp() ?? 0, $a['zone']] <=> [$rank($b), $b['lastEnteredAt']?->getTimestamp() ?? 0, $b['zone']];
        });

        return [
            'zones' => $rows,
            'areaCoverageFraction' => null === $areaCoverage?->value ? null : $areaCoverage->value / 100.0,
            'asOf' => $asOf,
            'bufferKm' => $buffer / 1000,
        ];
    }

    /**
     * PL·A4 — the area's recent observations, OLDEST FIRST, each with its real
     * age, the patrol it was logged on, and the host zone its point fell in.
     *
     * WHAT THIS CARD CANNOT SAY, and says so instead of guessing: whether an
     * observation has been FILED as an incident. The incidents module records
     * that on its own side (`Incident::sourceRecordUuid`) and this module holds
     * no flag for it. So there is no "unfiled" count here and no unfiled filter:
     * either would be a number this module has no evidence for, and showing
     * every observation as unfiled would be worse than showing none — it would
     * be a false queue somebody would work through.
     *
     * Oldest first among the recent page, because within a handful of things
     * nobody has looked at, the one that has waited longest is the one to open.
     *
     * @return array{rows: list<array{observation: Observation, url: string, patrolUrl: string, category: string, zone: string|null, ageSeconds: int|null, ageLabel: string|null}>, monthCount: int}
     */
    public function observations(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $recent = $this->observations->findByAreaLatestFirst($area, self::OBSERVATION_ROWS);
        $zones = $this->observations->zoneNamesFor($recent);

        $rows = [];
        foreach (array_reverse($recent) as $observation) {
            $loggedAt = $observation->getLoggedAt();
            $patrol = $observation->getPatrol();
            $rows[] = [
                'observation' => $observation,
                'url' => $this->router->generate('patrol_observation_show', [
                    'uuid' => $area->getUuidString(),
                    'patrol' => $patrol->getUuid()->toRfc4122(),
                    'observation' => $observation->getUuid()->toRfc4122(),
                ]),
                'patrolUrl' => $this->patrolUrl($area, $patrol),
                'category' => $this->categories[$observation->getCategory()]['label'] ?? $observation->getCategory(),
                'zone' => $zones[(int) $observation->getId()] ?? null,
                'ageSeconds' => $ageSeconds = null === $loggedAt ? null : max(0, $now->getTimestamp() - $loggedAt->getTimestamp()),
                'ageLabel' => null === $ageSeconds ? null : self::ageLabel($ageSeconds),
            ];
        }

        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);

        return [
            'rows' => $rows,
            'monthCount' => \count($this->observations->findByAreaLoggedBetween($area, $monthStart, $nextMonth)),
        ];
    }

    /**
     * THE LIVE READING THE PATROLS DASHBOARD BINDS — the same four facts the area
     * overview draws, measured here once so a preset's "Out right now", "Where
     * nobody has been" and "Observations awaiting action" widgets can never
     * disagree with the overview's own plates about the same morning.
     *
     * Returned as a context bag the dashboard and the widget library both merge
     * into their template variables: the direction widgets are off by default, so
     * a plain dashboard shows none of them, but the library previews every widget
     * and a preset may turn any of them on — so the reading must always be there.
     *
     * @return array{out: list<array<string, mixed>>, handsets: array{reporting: int, total: int, worst: array<string, mixed>|null}, gaps: array{zones: list<array<string, mixed>>, areaCoverageFraction: float|null, asOf: Fact|null, bufferKm: float}, observations: array{rows: list<array<string, mixed>>, monthCount: int}}
     */
    public function dashboardReading(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $out = $this->out($area, $now);

        return [
            'out' => $out,
            'handsets' => $this->handsets($out),
            'gaps' => $this->gaps($area, $now),
            'observations' => $this->observations($area, $now),
        ];
    }

    /**
     * HOW LONG, THE WAY THE DESIGN SAYS IT: "12 min", "6 h 20", "9 d".
     *
     * One function rather than a Twig filter beside it, because the same age is
     * printed on the card, in the strip's alarm and on the attention row — and
     * the host prints the attention row's age from a string this module hands it
     * ({@see \Uhifadhi\Bundle\AreaBundle\Overview\AttentionItem::$ageLabel}). Two formatters would
     * eventually disagree about the same patrol on the same screen.
     */
    public static function ageLabel(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        if ($minutes < 60) {
            return \sprintf('%d min', $minutes);
        }
        if ($minutes < 1440) {
            return \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
        }

        return \sprintf('%d d', intdiv($minutes, 1440));
    }

    /** The module's own page for one patrol — where every row on every card goes. */
    public function patrolUrl(AreaOfInterest $area, Patrol $patrol): string
    {
        return $this->router->generate('patrol_show', [
            'uuid' => $area->getUuidString(),
            'patrol' => $patrol->getUuid()->toRfc4122(),
        ]);
    }

    /** The module's own dashboard — where a card that is a summary of many goes. */
    public function dashboardUrl(AreaOfInterest $area): string
    {
        return $this->router->generate('patrol_dashboard', ['uuid' => $area->getUuidString()]);
    }

    /**
     * The one predicate every "does this count" decision in this module goes
     * through: a discarded patrol says the effort did not happen as recorded,
     * and a recording one has not finished happening.
     *
     * @param list<Patrol> $patrols
     *
     * @return list<Patrol>
     */
    private function counted(array $patrols): array
    {
        return array_values(array_filter(
            $patrols,
            static fn (Patrol $patrol): bool => $patrol->getStatus()->countsTowardsStatistics(),
        ));
    }

    /**
     * Kilometres actually recorded — null, not 0.0, where none of them recorded
     * any. A day of hand-logged patrols walked a distance nobody measured.
     *
     * @param list<Patrol> $patrols
     */
    private function distance(array $patrols): ?float
    {
        $total = null;
        foreach ($patrols as $patrol) {
            $km = $patrol->getDistanceKm();
            if (null !== $km) {
                $total = ($total ?? 0.0) + $km;
            }
        }

        return $total;
    }
}
