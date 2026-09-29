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

namespace Uhifadhi\Patrol\Devkit;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Uhifadhi\Patrol\Service\GeoService;

/**
 * A MONTH OF PLAUSIBLE PATROLLING, AS DATA — invented shifts, the routes they
 * walked, and the notes they came back with. It writes nothing and knows nothing
 * about a database: {@see PatrolContentProvider} takes what this describes and
 * files it through the doors a person uses.
 *
 * THAT SPLIT IS THE POINT. The generator is where a seed may be as elaborate as
 * it likes — it invents geometry, weights the busy gates and spreads the dates
 * so five charts fill — and it is precisely the place that must not be able to
 * store anything, because content written past the product's own rules is
 * content shaped in ways the product cannot produce.
 *
 * EVERYTHING HERE IS FICTION: invented ranger names, generic post names,
 * invented routes, and no real place is named or located.
 *
 * The history is spatially realistic, because a coverage map of patrols all
 * looping around one centroid is a lie about how patrolling works:
 *
 *  - the caller hands in sample points spread across the area, and the posts are
 *    picked from them by farthest-point sampling, so the five fictional stations
 *    sit genuinely far apart and every recorded patrol STARTS at its own post;
 *  - each type moves the way that type moves — foot patrols make short
 *    meandering loops home, vehicles run long smooth routes between posts,
 *    drones transit out and sweep a distant sector in straight legs — and
 *    durations follow the profile's speed;
 *  - a step that would leave the area is refused: the route turns instead, so
 *    tracks stay inside the boundary the caller sampled.
 *
 * A RECORDED PATROL IS DESCRIBED AS A GPX DOCUMENT, not as a track column,
 * because that is what the module's ingest path reads. Every fifth-of-a-shift
 * point carries its own time, so the times, the distance and the gap count the
 * stored patrol ends up with are all read off the file rather than asserted by
 * this class — which is the same thing that happens to a ranger's own upload.
 *
 * DETERMINISTIC. One fixed seed, so the same area always yields the same month.
 */
final class PatrolSeedMonth
{
    /** How many patrols a seed month holds. */
    public const int PATROLS = 12;

    /** Fixed seed: the same area always produces the same seed history. */
    public const int RANDOM_SEED = 20260823;

    /**
     * Slots a shift's lead is drawn from. This class never sees a person: it
     * draws a slot, and the caller resolves that slot against whatever roster
     * the installation has — so the seed decides who led which shift, and a
     * roster of another size still gets a whole month.
     *
     * The count is divisible by every roster size up to ten, so taking it
     * modulo a roster leaves everybody on it equally likely to lead.
     */
    public const int LEAD_SLOTS = 2520;

    private const int MIN_TRACK_POINTS = 30;
    private const int MAX_TRACK_POINTS = 120;

    /** Kilometres in a degree of latitude — good enough for seed dead reckoning. */
    private const float KM_PER_DEGREE = 111.195;

    /**
     * Turns tried, in radians, when a step would leave the area.
     *
     * @var list<float>
     */
    private const array TURNS = [\M_PI_2, -\M_PI_2, \M_PI, 2.356194, -2.356194];

    /** @var list<string> */
    private const array PROFILES = ['foot', 'vehicle', 'drone'];

    /**
     * How a deployment's own type vocabulary maps onto a movement profile: the
     * words are installation config ("walk", "boat", "ndege"…), the physics are
     * not.
     *
     * @var array<string, list<string>>
     */
    private const array PROFILE_KEYWORDS = [
        'foot' => ['foot', 'walk', 'hike', 'ground', 'ranger'],
        'vehicle' => ['vehicle', 'car', 'truck', 'bike', 'moto', 'horse', 'boat', 'vessel', 'river', 'lake'],
        'drone' => ['drone', 'uav', 'air', 'aerial', 'flight', 'plane', 'fly'],
    ];

    /** @var non-empty-list<string> */
    public const array STATIONS = ['North Gate', 'River Post', 'South Gate', 'Ridge Camp', 'Lake Post'];

    /**
     * How busy each post is, relative to the others — the reason "patrols by
     * station" reads as a real ranking rather than five identical bars. A busy
     * gate runs several times the patrols a quiet outpost does; the weights are
     * by station INDEX, and anything beyond this list falls back to the lightest.
     *
     * @var list<int>
     */
    private const array STATION_WEIGHTS = [9, 6, 4, 2, 1];

    /**
     * The share of patrols landing in the CURRENT calendar month, as a percent.
     * The rest spread back over the weeks before it, so a five-week chart fills
     * across ~6 weeks while the map, log, register and calendar — all scoped to
     * the month on screen — stay rich.
     */
    private const int THIS_MONTH_PERCENT = 62;

    /** How far back the pre-month spread reaches — ~6 weeks. */
    private const int SPREAD_DAYS = 40;

    /** @var non-empty-list<string> */
    private const array RANGERS = [
        'Neema Kileo', 'Baraka Mushi', 'Asha Ndosi', 'Juma Wema',
        'Zawadi Massawe', 'Hamisi Lyimo', 'Rehema Sway', 'Tumaini Kessy',
    ];

    /** @var non-empty-list<string> */
    private const array OBSERVATION_NOTES = [
        'Fresh tracks crossing the path, heading west.',
        'Boundary marker leaning, needs resetting.',
        'Cut branches piled beside the trail.',
        'Group of grazers moving along the ridge.',
        'Culvert blocked after the rains.',
        'Old fire scar, no active burning.',
        'Snare wire found and removed.',
        'Water point dry, livestock signs around it.',
    ];

    /** @var non-empty-list<string> */
    private const array PATROL_NOTES = [
        'Routine round, nothing out of place.',
        'Quiet shift; radio check on the hour.',
        'Slow going after the rain.',
        'Met the neighbouring team at the boundary.',
    ];

    /** What a hand-written patrol says about itself, because its route is not recorded. */
    private const string SKETCH_NOTE = 'Written up from the duty log — route not recorded.';

    /**
     * The kinds and sub-categories a seed area's observation taxonomy starts
     * with. Small on purpose: the taxonomy screen's whole point is that an area
     * writes its own, and a seed that arrived with forty rows would teach the
     * opposite.
     *
     * @var array<string, non-empty-list<string>>
     */
    public const array TAXONOMY = [
        'Wildlife' => ['Large mammal', 'Carcass', 'Bird of prey'],
        'Human sign' => ['Snare', 'Cut timber', 'Livestock incursion'],
        'Infrastructure' => ['Boundary marker', 'Water point', 'Track / culvert'],
    ];

    /** @var non-empty-list<array{name: string, lon: float, lat: float}> */
    private array $stations;

    private readonly Randomizer $randomizer;

    /**
     * @param list<list<array{0: float, 1: float}>>     $rings      the area's rings (outer and holes alike);
     *                                                              empty means "everywhere is inside"
     * @param non-empty-list<array{0: float, 1: float}> $samples    points spread across the area, from
     *                                                              which the posts are chosen
     * @param non-empty-list<string>                    $types      the deployment's patrol-type keys
     * @param non-empty-list<string>                    $categories the deployment's observation-category keys
     */
    public function __construct(
        private readonly GeoService $geo,
        private readonly array $rings,
        private readonly array $samples,
        private readonly array $types,
        private readonly array $categories,
        private readonly \DateTimeImmutable $now,
        private readonly int $count = self::PATROLS,
    ) {
        $this->randomizer = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $this->stations = $this->chooseStations($samples);
    }

    /**
     * The fictional posts this month was patrolled from, in the order they were
     * chosen — the busiest first, which is what the weights are indexed by.
     *
     * @return non-empty-list<array{name: string, lon: float, lat: float}>
     */
    public function stations(): array
    {
        return $this->stations;
    }

    /**
     * The month, patrol by patrol.
     *
     * A `gpx` document means the shift was recorded and the ingest path reads
     * its times, distance and route out of the file. `null` means it was written
     * up by hand afterwards, and then `startedAt`, `endedAt` and `distanceKm`
     * are the team's own account of it — which is exactly the difference between
     * the module's two write paths.
     *
     * @return list<array{
     *     gpx: ?string,
     *     type: string,
     *     station: string,
     *     leadSlot: int,
     *     team: string,
     *     note: ?string,
     *     startedAt: \DateTimeImmutable,
     *     endedAt: \DateTimeImmutable,
     *     distanceKm: float,
     *     observations: list<array{
     *         category: string,
     *         note: string,
     *         position: array{0: float, 1: float},
     *         loggedAt: \DateTimeImmutable,
     *         photos: int,
     *     }>,
     * }>
     */
    public function patrols(): array
    {
        $patrols = [];
        for ($i = 0; $i < $this->count; ++$i) {
            // Every fourth patrol is hand-entered: real rosters are never all GPS.
            $patrols[] = 3 === $i % 4 ? $this->sketchedPatrol() : $this->recordedPatrol();
        }

        return $patrols;
    }

    /**
     * @return array{
     *     gpx: ?string, type: string, station: string, leadSlot: int, team: string, note: ?string,
     *     startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable, distanceKm: float,
     *     observations: list<array{category: string, note: string, position: array{0: float, 1: float}, loggedAt: \DateTimeImmutable, photos: int}>,
     * }
     */
    private function recordedPatrol(): array
    {
        // A WEIGHTED post, not a round robin: real areas have busy gates and
        // quiet outposts, so per-station counts must read as a ranking.
        $station = $this->stations[$this->pickStationIndex()];
        $type = $this->pick($this->types);
        $profile = $this->profileFor($type);

        $points = match ($profile) {
            'vehicle' => $this->vehicleRoute($station),
            'drone' => $this->droneSweep($station),
            default => $this->footLoop($station),
        };
        $distanceKm = $this->trackDistanceKm($points);

        $startedAt = $this->startOfDuty();
        $speedKmh = match ($profile) {
            'vehicle' => $this->randomizer->getFloat(20.0, 30.0),
            'drone' => $this->randomizer->getFloat(40.0, 60.0),
            default => $this->randomizer->getFloat(3.5, 4.8),
        };
        $endedAt = $startedAt->modify(\sprintf('+%d seconds', max(600, (int) round($distanceKm / $speedKmh * 3600))));

        return [
            'gpx' => $this->gpx($points, $startedAt, $endedAt),
            'type' => $type,
            'station' => $station['name'],
            'leadSlot' => $this->leadSlot(),
            'team' => $this->team(),
            'note' => 0 === $this->randomizer->getInt(0, 2) ? $this->pick(self::PATROL_NOTES) : null,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'distanceKm' => round($distanceKm, 2),
            'observations' => $this->observations($points, $startedAt, $endedAt),
        ];
    }

    /**
     * A patrol written up from the duty log: distance and times are the team's
     * own estimate and there is NO document, so nothing downstream can mistake
     * it for a measured shift.
     *
     * @return array{
     *     gpx: ?string, type: string, station: string, leadSlot: int, team: string, note: ?string,
     *     startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable, distanceKm: float,
     *     observations: list<array{category: string, note: string, position: array{0: float, 1: float}, loggedAt: \DateTimeImmutable, photos: int}>,
     * }
     */
    private function sketchedPatrol(): array
    {
        $startedAt = $this->startOfDuty();

        return [
            'gpx' => null,
            'type' => $this->pick($this->types),
            'station' => $this->stations[$this->pickStationIndex()]['name'],
            'leadSlot' => $this->leadSlot(),
            'team' => $this->team(),
            'note' => self::SKETCH_NOTE,
            'startedAt' => $startedAt,
            'endedAt' => $startedAt->modify(\sprintf('+%d minutes', $this->randomizer->getInt(70, 260))),
            'distanceKm' => round($this->randomizer->getFloat(2.0, 11.0), 1),
            'observations' => [],
        ];
    }

    /**
     * The notes logged en route, at points on the track and at the times the
     * shift passed them.
     *
     * @param non-empty-list<array{0: float, 1: float}> $points
     *
     * @return list<array{category: string, note: string, position: array{0: float, 1: float}, loggedAt: \DateTimeImmutable, photos: int}>
     */
    private function observations(array $points, \DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt): array
    {
        $span = max(1, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        $last = max(1, \count($points) - 1);
        $observations = [];

        for ($n = 0, $wanted = $this->randomizer->getInt(0, 4); $n < $wanted; ++$n) {
            $at = $this->randomizer->getInt(0, \count($points) - 1);
            $observations[] = [
                'category' => $this->pick($this->categories),
                'note' => $this->pick(self::OBSERVATION_NOTES),
                'position' => $points[$at],
                'loggedAt' => $startedAt->modify(\sprintf('+%d seconds', (int) round($span * $at / $last))),
                // Weighted toward one or two — some observations carry none, a
                // few carry three, like a real evidence set.
                'photos' => [0, 1, 1, 2, 2, 3][$this->randomizer->getInt(0, 5)],
            ];
        }

        return $observations;
    }

    /**
     * The route as a GPX 1.1 document, every point stamped with the time the
     * shift passed it — so the ingest path reads the same facts off this that it
     * reads off a ranger's upload.
     *
     * @param non-empty-list<array{0: float, 1: float}> $points
     */
    private function gpx(array $points, \DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt): string
    {
        $span = max(1, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        $last = max(1, \count($points) - 1);

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('gpx');
        $xml->writeAttribute('version', '1.1');
        $xml->writeAttribute('creator', 'uhifadhi/patrol-module seed');
        $xml->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');
        $xml->startElement('trk');
        $xml->startElement('trkseg');
        foreach ($points as $index => [$lon, $lat]) {
            $xml->startElement('trkpt');
            $xml->writeAttribute('lat', \sprintf('%.6F', $lat));
            $xml->writeAttribute('lon', \sprintf('%.6F', $lon));
            $xml->writeElement(
                'time',
                $startedAt->modify(\sprintf('+%d seconds', (int) round($span * $index / $last)))
                    ->format(\DateTimeInterface::ATOM),
            );
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Foot patrol: a short meandering round near its post that turns for home
     * halfway through, so it starts and ends at the station.
     *
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function footLoop(array $station): array
    {
        $points = $this->clampPoints($this->randomizer->getInt(40, 110));
        $stepKm = $this->randomizer->getFloat(4.0, 12.0) / ($points - 1);
        $heading = $this->randomizer->getFloat(0.0, 2 * \M_PI);
        $lon = $station['lon'];
        $lat = $station['lat'];

        $track = [[round($lon, 6), round($lat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            $heading += $this->randomizer->getFloat(-0.55, 0.55);
            if ($i > intdiv($points, 2)) {
                // Homeward half: lean on the bearing back to the post.
                $heading = self::towards($heading, $this->bearing($lon, $lat, $station['lon'], $station['lat']), 0.3);
            }
            [$lon, $lat, $heading] = $this->stepInside($lon, $lat, $heading, $stepKm);
            $track[] = [round($lon, 6), round($lat, 6)];
        }

        return $track;
    }

    /**
     * Vehicle patrol: a long, smooth run that leaves its post for another, so
     * vehicle effort links the area up instead of circling one place.
     *
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function vehicleRoute(array $station): array
    {
        $points = $this->clampPoints($this->randomizer->getInt(60, 120));
        $stepKm = $this->randomizer->getFloat(20.0, 60.0) / ($points - 1);
        $lon = $station['lon'];
        $lat = $station['lat'];
        $destination = $this->otherStation($station);
        $heading = $this->bearing($lon, $lat, $destination['lon'], $destination['lat']);

        $track = [[round($lon, 6), round($lat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            if ($this->geo->haversineKm($lat, $lon, $destination['lat'], $destination['lon']) < 2 * $stepKm) {
                // Arrived: carry on to the next post rather than stopping short.
                $destination = $this->otherStation($destination);
            }
            // Enough drift to follow a road, enough pull to arrive: a vehicle
            // route bends, it does not wander like a foot round.
            $heading = self::towards($heading, $this->bearing($lon, $lat, $destination['lon'], $destination['lat']), 0.14)
                + $this->randomizer->getFloat(-0.3, 0.3);
            [$lon, $lat, $heading] = $this->stepInside($lon, $lat, $heading, $stepKm);
            $track[] = [round($lon, 6), round($lat, 6)];
        }

        return $track;
    }

    /**
     * Drone flight: a straight transit from the launch post out to a sector,
     * then a lawnmower sweep of it — fast, straight lines, often far from any
     * station.
     *
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function droneSweep(array $station): array
    {
        $points = $this->clampPoints($this->randomizer->getInt(30, 90));
        $stepKm = $this->randomizer->getFloat(12.0, 35.0) / ($points - 1);
        $lon = $station['lon'];
        $lat = $station['lat'];
        $sector = $this->samples[$this->randomizer->getInt(0, \count($this->samples) - 1)];
        $heading = $this->bearing($lon, $lat, $sector[0], $sector[1]);
        $transit = intdiv($points, 3);
        $legSteps = max(3, intdiv($points - $transit, 5));
        $sweep = $heading + \M_PI_2;

        $track = [[round($lon, 6), round($lat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            if ($i <= $transit) {
                $heading = $this->bearing($lon, $lat, $sector[0], $sector[1]);
            } else {
                // Legs there and back, with one short shift onto the next line.
                $step = ($i - $transit - 1) % ($legSteps + 1);
                $leg = intdiv($i - $transit - 1, $legSteps + 1);
                $heading = $step === $legSteps
                    ? $sweep + \M_PI_2
                    : $sweep + (0 === $leg % 2 ? 0.0 : \M_PI);
            }
            [$lon, $lat, $heading] = $this->stepInside($lon, $lat, $heading, $stepKm);
            $track[] = [round($lon, 6), round($lat, 6)];
        }

        return $track;
    }

    /**
     * One step along a heading — refused if it would leave the area: the route
     * turns (a quarter, a half, three quarters) until it finds a way that stays
     * in, and holds position if nothing does.
     *
     * @return array{0: float, 1: float, 2: float} lon, lat and the heading taken
     */
    private function stepInside(float $lon, float $lat, float $heading, float $stepKm): array
    {
        foreach ([0.0, ...self::TURNS] as $turn) {
            $candidate = $heading + $turn;
            $nextLat = $lat + $stepKm / self::KM_PER_DEGREE * cos($candidate);
            $nextLon = $lon + $stepKm / (self::KM_PER_DEGREE * max(0.2, cos(deg2rad($lat)))) * sin($candidate);
            if ($this->inside($nextLon, $nextLat)) {
                return [$nextLon, $nextLat, $candidate];
            }
        }

        return [$lon, $lat, $heading + \M_PI];
    }

    /** Nudge a heading towards another one the short way round. */
    private static function towards(float $heading, float $target, float $weight): float
    {
        return $heading + $weight * atan2(sin($target - $heading), cos($target - $heading));
    }

    /** Compass bearing in radians (0 = north), on the flat — seed scale. */
    private function bearing(float $lon, float $lat, float $toLon, float $toLat): float
    {
        return atan2(($toLon - $lon) * max(0.2, cos(deg2rad($lat))), $toLat - $lat);
    }

    /**
     * Even-odd ray casting against the sampled rings. Holes are rings too, so an
     * odd number of crossings means inside the land and outside the holes.
     */
    private function inside(float $lon, float $lat): bool
    {
        if ([] === $this->rings) {
            return true;
        }

        $crossings = 0;
        foreach ($this->rings as $ring) {
            for ($i = 0, $n = \count($ring), $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $ring[$i];
                [$xj, $yj] = $ring[$j];
                if (($yi > $lat) !== ($yj > $lat)
                    && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi
                ) {
                    ++$crossings;
                }
            }
        }

        return 1 === $crossings % 2;
    }

    /**
     * The fictional posts, pinned to well-separated sample points by greedy
     * farthest-point sampling: take the first point, then always the candidate
     * furthest from everything chosen so far.
     *
     * @param non-empty-list<array{0: float, 1: float}> $samples
     *
     * @return non-empty-list<array{name: string, lon: float, lat: float}>
     */
    private function chooseStations(array $samples): array
    {
        $chosen = [$samples[0]];
        $rest = \array_slice($samples, 1);
        while (\count($chosen) < \count(self::STATIONS) && [] !== $rest) {
            $bestIndex = 0;
            $bestDistance = -1.0;
            foreach ($rest as $index => [$lon, $lat]) {
                $nearest = \PHP_FLOAT_MAX;
                foreach ($chosen as [$takenLon, $takenLat]) {
                    $nearest = min($nearest, $this->geo->haversineKm($lat, $lon, $takenLat, $takenLon));
                }
                if ($nearest > $bestDistance) {
                    $bestDistance = $nearest;
                    $bestIndex = $index;
                }
            }
            $chosen[] = $rest[$bestIndex];
            unset($rest[$bestIndex]);
            $rest = array_values($rest);
        }

        $stations = [];
        foreach ($chosen as $index => [$lon, $lat]) {
            $stations[] = ['name' => self::STATIONS[$index], 'lon' => $lon, 'lat' => $lat];
        }

        return $stations;
    }

    /**
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return array{name: string, lon: float, lat: float}
     */
    private function otherStation(array $station): array
    {
        $others = array_values(array_filter(
            $this->stations,
            static fn (array $candidate): bool => $candidate['name'] !== $station['name'],
        ));

        return [] === $others ? $station : $others[$this->randomizer->getInt(0, \count($others) - 1)];
    }

    /**
     * Duty starts spread over the last ~6 weeks, at field hours — but WEIGHTED
     * towards the current month, so two different widgets both read right.
     *
     * A five-week "patrols per week" chart runs back four weeks before the
     * current one, so a seed penned entirely into the current month leaves its
     * earliest bars empty for the first weeks of a month. Most of a share
     * ({@see self::THIS_MONTH_PERCENT}) still lands in the current month, keeping
     * the map, log, register and calendar rich, while the rest spread back so
     * every chart week fills. A slot landing after "now" is pulled back inside
     * today.
     */
    private function startOfDuty(): \DateTimeImmutable
    {
        $dayOfMonth = (int) $this->now->format('j');

        if ($this->randomizer->getInt(1, 100) <= self::THIS_MONTH_PERCENT) {
            $monthStart = $this->now->modify('first day of this month')->setTime(0, 0);
            $start = $monthStart->modify(\sprintf('+%d days', $this->randomizer->getInt(1, $dayOfMonth) - 1));
        } else {
            // Before the month began, back to ~6 weeks ago. The floor is the day
            // the current month started, so this branch never lands inside it.
            $daysBack = $this->randomizer->getInt($dayOfMonth, self::SPREAD_DAYS);
            $start = $this->now->modify(\sprintf('-%d days', $daysBack))->setTime(0, 0);
        }

        $start = $start->setTime($this->randomizer->getInt(5, 14), 5 * $this->randomizer->getInt(0, 11));

        // Never in the future — today's slot may land after "now".
        return $start > $this->now ? $this->now->modify('-1 hour') : $start;
    }

    /**
     * A station index drawn from {@see self::STATION_WEIGHTS}: busier posts come
     * up more often, so the seed's per-station counts vary the way a real
     * roster's do.
     */
    private function pickStationIndex(): int
    {
        $count = \count($this->stations);
        $cumulative = [];
        $total = 0;
        for ($i = 0; $i < $count; ++$i) {
            $total += self::STATION_WEIGHTS[$i] ?? 1;
            $cumulative[$i] = $total;
        }

        $roll = $this->randomizer->getInt(1, max(1, $total));
        foreach ($cumulative as $index => $ceiling) {
            if ($roll <= $ceiling) {
                return $index;
            }
        }

        return $count - 1;
    }

    /** @param list<array{0: float, 1: float}> $points */
    private function trackDistanceKm(array $points): float
    {
        $km = 0.0;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $km += $this->geo->haversineKm($points[$i - 1][1], $points[$i - 1][0], $points[$i][1], $points[$i][0]);
        }

        return $km;
    }

    /**
     * WHO LED THE SHIFT, AS A SLOT — {@see self::LEAD_SLOTS}. The caller reads
     * it against the people the installation has, because a generator that
     * invented a lead would invent a person nothing can credit hours to.
     */
    private function leadSlot(): int
    {
        return $this->randomizer->getInt(0, self::LEAD_SLOTS - 1);
    }

    /**
     * WHO ELSE WAS OUT. The lead is drawn separately and stands first on the
     * team line, so this names the rest of the crew and never the lead.
     */
    private function team(): string
    {
        /** @var list<string> $roster */
        $roster = $this->randomizer->shuffleArray(self::RANGERS);

        return implode(', ', \array_slice($roster, 0, $this->randomizer->getInt(2, 4)));
    }

    private function clampPoints(int $points): int
    {
        return max(self::MIN_TRACK_POINTS, min(self::MAX_TRACK_POINTS, $points));
    }

    /** The movement profile a deployment's own type word implies. */
    private function profileFor(string $type): string
    {
        $word = strtolower($type);
        foreach (self::PROFILE_KEYWORDS as $profile => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($word, $keyword)) {
                    return $profile;
                }
            }
        }

        // Unknown vocabulary still gets a stable profile, so a deployment naming
        // its types in another language keeps a varied seed.
        return self::PROFILES[crc32($word) % \count(self::PROFILES)];
    }

    /**
     * @param non-empty-list<string> $candidates
     */
    private function pick(array $candidates): string
    {
        return $candidates[$this->randomizer->getInt(0, \count($candidates) - 1)];
    }
}
