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

use Uhifadhi\Contracts\Atlas\PlatePalette;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Model\PatrolDashboard;
use Uhifadhi\Patrol\Model\PatrolFilter;

/**
 * Computes the dashboard's data contract from plain entities. Pure — "now" is
 * always injected (never the clock), so every number is unit-tested.
 *
 * ## Discarded patrols
 *
 * A discarded patrol reaches this service and leaves it in exactly one place:
 * `PatrolDashboard::$patrols`, the list the log table and the feed render. It is
 * absent from EVERY figure — the month count, the month distance, the type
 * counts, the station ranking, the five-week series, the total, the last-patrol
 * line — and from the coverage map's payload, because a track drawn on the
 * coverage map is a claim about ground covered.
 *
 * The split is deliberate, and the two halves say different things. A discard
 * means "this effort did not happen as recorded", so counting it would report
 * kilometres nobody walked. But a ranger who uploaded a patrol and then
 * discarded it must still be able to FIND it — a record that vanishes from
 * every screen is indistinguishable from one the sync lost. So it stays in the
 * lists, subdued and pilled, and nowhere else.
 *
 * {@see \Uhifadhi\Patrol\Enum\PatrolStatusEnum::countsTowardsStatistics()}
 * is the one predicate all of that goes through.
 */
final class PatrolDashboardService
{
    /**
     * A PATROL TYPE IS A CATEGORY, and the house has nine of them.
     *
     * The module names a type's POSITION in the area's declared order and
     * nothing else; the host resolves that position to a hue — `--cat-n` in
     * the page and `--cat-p-n` on imagery, which are deliberately two
     * different colours. A tenth type wraps to the first, which is the
     * caller's to do and is done here.
     */
    public const int CATEGORIES = 9;

    /**
     * PL·03's buffer, in metres: the design's KPI is "% of area within 2 km of a
     * track", so the distance is part of the widget's meaning, not a knob — the
     * caption on the plate states it, and a deployment that changed it silently
     * would be printing a different number under the same words.
     */
    public const float COVERAGE_BUFFER_M = 2000.0;

    private const int WEEKS = 5;
    private const int CALENDAR_CELLS = 42;

    /**
     * The half-open window every "this month" figure is scoped to (PL·01's
     * count, PL·02's distance sum, PL·03's coverage and the station ranking).
     *
     * Public because the coverage KPI is the one month figure that CANNOT be
     * computed from the loaded entities — it is a set operation the worker
     * files on the facts ledger per month
     * ({@see \Uhifadhi\Patrol\Facts\PatrolFactProvider::AREA_COVERAGE_UNIFORM}) —
     * so its caller must read exactly the month this service counts in. Decided here, in the one place that defines "this month", never
     * re-derived at the call site.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, untilExclusive]
     */
    public static function monthRange(\DateTimeImmutable $now): array
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);

        return [$monthStart, $monthStart->modify('+1 month')];
    }

    /**
     * WHICH CATEGORY EACH PATROL TYPE IS — its position in the area's declared
     * order, 1 to 9. Computed once per request and handed to the templates, so
     * the dashboard, the widget library and the map can never put the same type
     * in two different categories.
     *
     * A template writes it as `data-cat`, and the shell resolves the hue: this
     * module decides which types there are and in what order, and nothing at
     * all about what a colour is.
     *
     * @param array<string, array{label: string}> $types the deployment's patrol.types map
     *
     * @return array<string, int>
     */
    public static function typePositions(array $types): array
    {
        $positions = [];
        foreach (array_keys($types) as $index => $key) {
            $positions[$key] = ($index % self::CATEGORIES) + 1;
        }

        return $positions;
    }

    /**
     * THE SAME ANSWER FOR A PLATE — the token each type's position resolves to
     * on imagery, which is not the token the same category wears in the page.
     * Every layer, legend row and marker this module draws names one of these
     * and never a colour.
     *
     * @param array<string, array{label: string}> $types
     *
     * @return array<string, string>
     */
    public static function typeSwatches(array $types): array
    {
        return array_map(
            static fn (int $position): string => PlatePalette::category($position),
            self::typePositions($types),
        );
    }

    /**
     * What patrol draws on the coverage map (PL·05), in one JSON-safe bag: one
     * entry per patrol that actually RECORDED a route, and the stations they set
     * out from. The area's own ground — boundary and zones — is the area's
     * answer (`AreaMapPayload::forArea()`) and is handed to the plate beside
     * this. Built here rather than in Twig so its shape is unit-tested, and so
     * the dashboard and the widget library can never hand their maps different
     * data.
     *
     * A track travels as the GeoJSON text the geometry column stores
     * (postgis-bundle types) and is decoded where the plate is built.
     *
     * A hand-logged patrol has no geometry (docs/design-decisions.md §4): it is
     * left out entirely rather than drawn as a guess, and its absence must not
     * leave a hole in the list (the entries are appended, never keyed).
     *
     * Stations are free strings with no coordinates of their own
     * (docs/design-decisions.md §1), so the design's labelled station markers
     * are placed at the best evidence there is: the FIRST recorded point of a
     * patrol that set out from that station. A station whose patrols were all
     * hand-logged therefore gets no marker — an invented position would be worse
     * than none.
     *
     * The zone each track is drawn as belonging to (item: zone spatial-join) is
     * NOT stored on the patrol — a patrol names a station and no zone
     * (docs/design-decisions.md §1). It is computed live by a PostGIS spatial
     * join in the controller ({@see \Uhifadhi\Patrol\Repository\PatrolRepository::zonesForPatrols()})
     * and handed in as an id→name map, so the ZONE filter drives the map exactly
     * the way the station filter does — client-side, over data the payload carries.
     * A track whose start falls in no zone carries the empty string.
     *
     * @param array<string, array{label: string}> $types       the deployment's patrol.types map
     * @param array<string, string>               $patrolZones patrol uuid → zone name, the live spatial join; absent uuids are unzoned
     *
     * @return array{patrols: list<array{uuid: string, ref: string, type: string, station: string, zone: string, color: string, track: string}>, stations: list<array{name: string, lon: float, lat: float}>}
     */
    public function coveragePayload(PatrolDashboard $dashboard, array $types, array $patrolZones = []): array
    {
        $swatches = self::typeSwatches($types);

        $tracks = [];
        /** @var array<string, array{name: string, lon: float, lat: float}> $stations */
        $stations = [];
        foreach ($dashboard->patrols as $patrol) {
            $uuid = $patrol->getUuid()->toRfc4122();
            $track = $patrol->getTrack();
            // Only a COMPLETE patrol is drawn here. This payload is what the
            // coverage map (PL·05) and the tracks plate (PL·08) render, and a
            // line on a coverage map is read as ground covered — which is
            // exactly what a discard withdraws, and exactly what a track still
            // arriving has not established yet. A discard stays in the lists
            // beside the map, simply not in the picture of coverage; a
            // recording patrol reaches neither, having never got past
            // isPresentable() in build().
            if (null === $track || '' === $track || !$patrol->getStatus()->countsTowardsStatistics()) {
                continue;
            }
            // The KEY drives the filter (it is what a saved filter holds); the
            // LABEL is what the marker is drawn with.
            $station = $patrol->getStationKey() ?? '';
            $tracks[] = [
                'uuid' => $uuid,
                'ref' => $patrol->getRef(),
                'type' => $patrol->getType(),
                'station' => $station,
                'zone' => $patrolZones[$uuid] ?? '',
                'color' => $swatches[$patrol->getType()] ?? PlatePalette::ACCENT,
                'track' => $track,
            ];

            if ('' === $station || isset($stations[$station])) {
                continue;
            }
            $record = $patrol->getStationRecord();
            // A station that says where it stands is drawn there; otherwise the
            // first fix of a patrol that set out from it, which is the best
            // evidence there is and beats an invented coordinate.
            $start = self::pointOf($record?->getPoint()) ?? self::firstPoint($track);
            if (null !== $start) {
                $stations[$station] = ['name' => $record?->getName() ?? $station, 'lon' => $start[0], 'lat' => $start[1]];
            }
        }

        return ['patrols' => $tracks, 'stations' => array_values($stations)];
    }

    /**
     * A GeoJSON Point as [lon, lat]; null for anything else or for nothing.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function pointOf(?string $point): ?array
    {
        if (null === $point || '' === $point) {
            return null;
        }
        $decoded = json_decode($point, true);
        $coordinates = \is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        if (!\is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
            return null;
        }

        return [(float) $coordinates[0], (float) $coordinates[1]];
    }

    /**
     * The first vertex of a GeoJSON (Multi)LineString as [lon, lat]; null for
     * anything else, so a malformed column never becomes a marker.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function firstPoint(string $lineString): ?array
    {
        $decoded = json_decode($lineString, true);
        if (!\is_array($decoded) || !\is_array($decoded['coordinates'] ?? null)) {
            return null;
        }
        $point = $decoded['coordinates'][0] ?? null;
        // A MultiLineString nests one level deeper.
        if (\is_array($point) && \is_array($point[0] ?? null)) {
            $point = $point[0];
        }
        if (!\is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
            return null;
        }

        return [(float) $point[0], (float) $point[1]];
    }

    /**
     * The half-open window the dashboard must LOAD to draw one month — wider than
     * the month itself, because two of the month's own widgets reach past its
     * edges: the calendar grid draws the neighbouring months' leading and
     * trailing days ({@see self::calendarRange()}), and the five-week chart runs
     * back four weeks before the month's reference week ({@see self::weeklySeries()}).
     * The controller queries exactly this window and hands the rows to
     * {@see self::build()}, which buckets each widget to its own sub-window — so
     * the map and log show the month, the calendar shows its dimmed neighbours,
     * and the chart still fills.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, untilExclusive]
     */
    public static function loadRange(\DateTimeImmutable $month, \DateTimeImmutable $now): array
    {
        [$monthStart, $nextMonth] = self::monthRange($month);
        [$gridStart, $gridUntil] = self::calendarRange($month);
        $anchor = self::weeklyAnchor($month, $now);
        $weekStart = $anchor->modify('monday this week')->setTime(0, 0)->modify(\sprintf('-%d weeks', self::WEEKS - 1));
        $weekUntil = $anchor->modify('monday this week')->setTime(0, 0)->modify('+1 week');

        $from = min($monthStart, $gridStart, $weekStart);
        $until = max($nextMonth, $gridUntil, $weekUntil);

        return [$from, $until];
    }

    /**
     * The instant the five-week chart ends on for the month on screen: "now" when
     * the month contains it (the live current month), else the month's last day —
     * a past month reads the five weeks up to its own close, not up to today.
     */
    private static function weeklyAnchor(\DateTimeImmutable $month, \DateTimeImmutable $now): \DateTimeImmutable
    {
        [$monthStart, $nextMonth] = self::monthRange($month);
        if ($now >= $monthStart && $now < $nextMonth) {
            return $now;
        }

        return $nextMonth->modify('-1 day');
    }

    /**
     * @param list<Patrol>                        $patrols     latest first, the LOAD window ({@see self::loadRange()})
     * @param array<string, array{label: string}> $types       the deployment's patrol.types map
     * @param Fact|null                           $coverage    PL·03, read by the caller from the facts ledger for the month on screen (see {@see self::monthRange()}); null where the worker has not computed it yet
     * @param PatrolFilter|null                   $filter      the one filter the whole screen reads — type, station, zone and month; null is the month containing $now, narrowed by nothing
     * @param array<string, string>               $patrolZones patrol uuid → zone name (the live spatial join); the ZONE filter's menu and its predicate
     */
    public function build(array $patrols, array $types, \DateTimeImmutable $now, ?Fact $coverage = null, ?PatrolFilter $filter = null, array $patrolZones = []): PatrolDashboard
    {
        $filter ??= new PatrolFilter($now->modify('first day of this month')->setTime(0, 0));

        // The map, the log and the charts all read ONE month — the MONTH filter's
        // choice, defaulting to the month containing "now". The window every "this
        // month" figure is scoped to.
        [$monthStart, $nextMonth] = $filter->window();

        $monthCount = 0;
        $monthDistanceKm = 0.0;
        /** @var array<string, int> $monthTypeCounts */
        $monthTypeCounts = [];
        /** @var array<string, int> $typeCounts */
        $typeCounts = array_fill_keys(array_keys($types), 0);
        /** @var array<string, int> $stationCounts */
        $stationCounts = [];
        /** @var array<string, string> $stationLabels */
        $stationLabels = [];
        // Patrol-hours per lead this month — the "Effort by ranger" widget. Keyed
        // by the lead entity so two patrols by the same person add up, and holding
        // the entity so the template formats the name the one way it formats every
        // name (the lead_name macro), never a second spelling computed here.
        /** @var array<int, array{lead: \Uhifadhi\Contracts\Entity\UserInterface, hours: float}> $effort */
        $effort = [];
        $totalCount = 0;
        $lastPatrol = null;

        // TWO SETS, and the difference between them is the whole status model.
        //
        // The PRESENTED set is what the log, the feed and the calendar draw:
        // every patrol whose recording has finished, which includes a discarded
        // one (shown subdued — see the discard design) and excludes one that is
        // still arriving. A caller hands us whatever the repository found; the
        // decision about what may be drawn is made here, once.
        $presented = array_values(array_filter(
            $patrols,
            static fn (Patrol $patrol): bool => $patrol->getStatus()->isPresentable(),
        ));

        // THE FILTER MENUS ARE THE MONTH'S, not the narrowed view's: a station
        // you chose must not be the only station the menu still offers, or the
        // filter is a door that locks behind you. Read before the narrowing,
        // therefore — the counts below are read after it.
        $menuStations = self::pairsPresent($presented, $monthStart, $nextMonth, static fn (Patrol $patrol): array => [$patrol->getStationKey() ?? '', $patrol->getStation() ?? '']);
        $menuZones = self::namesPresent($presented, $monthStart, $nextMonth, static fn (Patrol $patrol): string => $patrolZones[$patrol->getUuid()->toRfc4122()] ?? '');

        // ONE FILTER DRIVES EVERYTHING, and this is where it does it: the map,
        // the log, the KPIs, the charts and the calendar are all readings of the
        // set below, so they cannot be answering different questions.
        $presented = array_values(array_filter(
            $presented,
            static fn (Patrol $patrol): bool => $filter->matches(
                $patrol->getType(),
                $patrol->getStationKey() ?? '',
                $patrolZones[$patrol->getUuid()->toRfc4122()] ?? '',
            ),
        ));

        // THE MONTH'S presented patrols — the map, the log and the feed read the
        // month on screen, not all of history, which would leave the MONTH
        // filter a dead indicator. The calendar and the five-week chart
        // still read the wider PRESENTED/COUNTED sets below, because they draw
        // past the month's edges by design.
        $presentedMonth = array_values(array_filter(
            $presented,
            static function (Patrol $patrol) use ($monthStart, $nextMonth): bool {
                $started = $patrol->getStartedAt();

                return null !== $started && $started >= $monthStart && $started < $nextMonth;
            },
        ));

        // The COUNTED set is stricter again: nothing below this line may see a
        // discarded patrol or a half-arrived one. The filter happens ONCE rather
        // than as a condition repeated in the tallies where one could be missed.
        $counted = array_values(array_filter(
            $presented,
            static fn (Patrol $patrol): bool => $patrol->getStatus()->countsTowardsStatistics(),
        ));

        foreach ($counted as $patrol) {
            $started = $patrol->getStartedAt();
            if (null === $started || $started < $monthStart || $started >= $nextMonth) {
                continue;
            }
            // Every figure below is the MONTH's: the filter chips, the last-patrol
            // line and the totals all describe the month the rest of the screen
            // shows, so they can never disagree with the map beside them.
            ++$totalCount;
            $typeCounts[$patrol->getType()] = ($typeCounts[$patrol->getType()] ?? 0) + 1;
            if (null === $lastPatrol || $started > $lastPatrol->getStartedAt()) {
                $lastPatrol = $patrol;
            }
            ++$monthCount;
            $monthDistanceKm += $patrol->getDistanceKm() ?? 0.0;
            $monthTypeCounts[$patrol->getType()] = ($monthTypeCounts[$patrol->getType()] ?? 0) + 1;
            $stationKey = $patrol->getStationKey();
            if (null !== $stationKey && '' !== $stationKey) {
                $stationCounts[$stationKey] = ($stationCounts[$stationKey] ?? 0) + 1;
                $stationLabels[$stationKey] = $patrol->getStation() ?? $stationKey;
            }

            // Hours on the track, credited to the committed lead. A patrol with no
            // lead has no ranger to credit, and one still open (no end) has no
            // measured duration — neither is 0 h, both are simply absent from the
            // effort chart. The design's "a shared patrol counts once for each
            // lead" awaits a multi-lead field; the record commits to one lead.
            $lead = $patrol->getLead();
            $ended = $patrol->getEndedAt();
            if (null !== $lead && null !== $ended) {
                $key = spl_object_id($lead);
                $effort[$key] ??= ['lead' => $lead, 'hours' => 0.0];
                $effort[$key]['hours'] += max(0.0, ($ended->getTimestamp() - $started->getTimestamp()) / 3600);
            }
        }

        arsort($stationCounts);
        $stationSeries = [];
        foreach ($stationCounts as $station => $count) {
            $stationSeries[] = ['station' => $station, 'label' => $stationLabels[$station] ?? $station, 'count' => $count];
        }

        // Ranked by hours, most first — the design's descending bars.
        $effortSeries = array_values($effort);
        usort($effortSeries, static fn (array $a, array $b): int => $b['hours'] <=> $a['hours']);

        return new PatrolDashboard(
            patrols: $presentedMonth,
            monthCount: $monthCount,
            monthDistanceKm: $monthDistanceKm,
            monthTypeCounts: $monthTypeCounts,
            coverage: $coverage,
            typeCounts: $typeCounts,
            totalCount: $totalCount,
            lastPatrol: $lastPatrol,
            // The five weeks up to the month's reference week — anchored to "now"
            // for the live month, to the month's close for a past one.
            weeklySeries: $this->weeklySeries($counted, $types, self::weeklyAnchor($filter->month, $now)),
            stationSeries: $stationSeries,
            effortSeries: $effortSeries,
            // The MENUS, which list the whole month so every choice stays
            // reachable — unlike the counts beside them, which are the narrowed
            // view's.
            stations: $menuStations,
            // The zones the month's patrols set out in, sorted — the ZONE filter's
            // menu. Computed by a live PostGIS spatial join, never a stored field.
            zones: $menuZones,
            // The calendar shows the month on screen; ‹ › then fetches any other
            // month through the same method (PatrolCalendarController).
            calendar: $this->calendarFor($presented, $filter->month, $now),
        );
    }

    /**
     * The distinct key → label pairs a month's presented patrols carry on one
     * axis, sorted by label for a filter menu. The KEY is what the filter
     * carries in the query string; the label is what the menu prints.
     *
     * @param list<Patrol>                                  $patrols the presented patrols over the LOAD window
     * @param \Closure(Patrol): array{0: string, 1: string} $pair    the axis to read, as [key, label]
     *
     * @return array<string, string>
     */
    private static function pairsPresent(array $patrols, \DateTimeImmutable $monthStart, \DateTimeImmutable $nextMonth, \Closure $pair): array
    {
        $pairs = [];
        foreach ($patrols as $patrol) {
            $started = $patrol->getStartedAt();
            if (null === $started || $started < $monthStart || $started >= $nextMonth) {
                continue;
            }
            [$key, $label] = $pair($patrol);
            if ('' !== $key) {
                $pairs[$key] = '' !== $label ? $label : $key;
            }
        }
        asort($pairs);

        return $pairs;
    }

    /**
     * The distinct names a month's presented patrols carry on one axis, sorted
     * for a filter menu. A patrol with no station, or whose start fell in no
     * zone (or which was hand-logged, so had no track to place), carries the
     * empty string and contributes nothing — the menu then says "no … yet"
     * rather than offering a nameless option.
     *
     * @param list<Patrol>             $patrols the presented patrols over the LOAD window
     * @param \Closure(Patrol): string $name    the axis to read
     *
     * @return list<string>
     */
    private static function namesPresent(array $patrols, \DateTimeImmutable $monthStart, \DateTimeImmutable $nextMonth, \Closure $name): array
    {
        $names = [];
        foreach ($patrols as $patrol) {
            $started = $patrol->getStartedAt();
            if (null === $started || $started < $monthStart || $started >= $nextMonth) {
                continue;
            }
            $value = $name($patrol);
            if ('' !== $value) {
                $names[$value] = true;
            }
        }
        $sorted = array_keys($names);
        sort($sorted);

        return $sorted;
    }

    /**
     * @param list<Patrol>                        $patrols
     * @param array<string, array{label: string}> $types
     *
     * @return list<array{label: string, counts: array<string, int>}>
     */
    private function weeklySeries(array $patrols, array $types, \DateTimeImmutable $anchor): array
    {
        // Five Monday-start weeks, oldest first, the anchor's week last (the
        // current week for the live month, the month's closing week for a past
        // one — see self::weeklyAnchor()).
        $thisWeekStart = $anchor->modify('monday this week')->setTime(0, 0);
        $weeks = [];
        for ($i = self::WEEKS - 1; $i >= 0; --$i) {
            $weeks[] = $thisWeekStart->modify(\sprintf('-%d weeks', $i));
        }

        $series = [];
        foreach ($weeks as $index => $weekStart) {
            $weekEnd = $weekStart->modify('+1 week');
            $counts = array_fill_keys(array_keys($types), 0);
            foreach ($patrols as $patrol) {
                $started = $patrol->getStartedAt();
                if (null !== $started && $started >= $weekStart && $started < $weekEnd) {
                    $counts[$patrol->getType()] = ($counts[$patrol->getType()] ?? 0) + 1;
                }
            }
            $series[] = ['label' => 'W'.($index + 1), 'counts' => $counts];
        }

        return $series;
    }

    /**
     * The days a month's calendar grid covers: its first cell and the day AFTER
     * its last, so a caller can ask the repository for exactly the patrols the
     * grid can show — including the neighbouring months' dimmed leading and
     * trailing days, which the design still draws pills on.
     *
     * Public because the fragment endpoint (PatrolCalendarController) queries
     * one month at a time: the grid's shape is decided HERE, in the one place
     * that lays the cells out, never re-derived at the call site.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, untilExclusive]
     */
    public static function calendarRange(\DateTimeImmutable $month): array
    {
        // A Monday-start 6×7 grid always fits a month wherever it begins.
        $gridStart = $month->modify('first day of this month')->setTime(0, 0)->modify('monday this week');

        return [$gridStart, $gridStart->modify(\sprintf('+%d days', self::CALENDAR_CELLS))];
    }

    /**
     * The calendar grid for ANY month — what the ‹ › navigation asks for. Pure:
     * the patrols are handed in (queried with {@see self::calendarRange()}), the
     * month is the month on screen and "now" is the clock, which decides only
     * which cell is ringed as today. A month that holds no patrols is a full
     * grid of empty days, never a missing widget.
     *
     * DISCARDED patrols DO get a pill, drawn subdued. The grid is a list of what
     * happened on which day, not a figure about it — and a ranger looking for
     * the patrol they discarded on the 12th should find it on the 12th. It is
     * counted in nothing.
     *
     * @param list<Patrol> $patrols
     *
     * @return list<array{date: \DateTimeImmutable, patrols: list<Patrol>, today: bool, outside: bool}>
     */
    public function calendarFor(array $patrols, \DateTimeImmutable $month, \DateTimeImmutable $now): array
    {
        $monthStart = $month->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        [$gridStart] = self::calendarRange($month);
        $today = $now->format('Y-m-d');

        /** @var array<string, list<Patrol>> $byDay */
        $byDay = [];
        foreach ($patrols as $patrol) {
            // Filtered HERE as well as in build(), because the calendar has a
            // SECOND door: PatrolCalendarController fetches any other month
            // straight from the repository and renders the same grid. A rule
            // enforced only on the dashboard's path would hold in august and
            // quietly fail the moment somebody clicked ‹.
            if (!$patrol->getStatus()->isPresentable()) {
                continue;
            }
            $started = $patrol->getStartedAt();
            if (null !== $started) {
                $byDay[$started->format('Y-m-d')][] = $patrol;
            }
        }

        $cells = [];
        for ($i = 0; $i < self::CALENDAR_CELLS; ++$i) {
            $date = $gridStart->modify(\sprintf('+%d days', $i));
            $cells[] = [
                'date' => $date,
                'patrols' => $byDay[$date->format('Y-m-d')] ?? [],
                'today' => $date->format('Y-m-d') === $today,
                'outside' => $date < $monthStart || $date >= $nextMonth,
            ];
        }

        return $cells;
    }
}
