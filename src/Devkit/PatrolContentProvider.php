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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository as AreaStationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\StationService as AreaStationService;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Exception\TaxonomyConflictException;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\Api\ObservationSyncService;
use Uhifadhi\Patrol\Service\Api\PhotoSyncService;
use Uhifadhi\Patrol\Service\GeoService;
use Uhifadhi\Patrol\Service\PatrolRecordingService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Service\TrackIngestService;

/**
 * A MONTH OF PATROLLING TO LOOK AT — the sample month {@see PatrolDemoMonth}
 * describes, filed into the area the installation already has, so a developer's
 * first dashboard is a populated one.
 *
 * IT GOES THROUGH THE SAME SERVICES THE SCREENS AND THE HANDSET DO, and that is
 * the whole discipline of it. Every recorded patrol is ingested by
 * {@see TrackIngestService} from a GPX document, exactly as an upload is; every
 * hand-written one is filed by {@see PatrolRecordingService}, exactly as the log
 * screen files it; the notes go through {@see ObservationSyncService} and the
 * photographs through {@see PhotoSyncService}, which is the field app's own
 * path; and the area's observation taxonomy is written by
 * {@see TaxonomyAdminService}, the service behind the admin screen. Nothing
 * below constructs a record or persists one.
 *
 * SOMEBODY LED EVERY SHIFT, AND THEY ARE ONE OF THE SYNTHETIC DEMO PEOPLE the
 * team slice seeds — which is what `dependsOn()` buys. The sample month draws a
 * lead SLOT per shift from its own seed and this resolves it against the roster
 * the installation has, so a re-seeded demo credits the same people again. The
 * lead is the first name on the team line: they are on the record as the lead,
 * and the team string names who else was out.
 *
 * WHAT THAT DISCIPLINE COSTS, SAID PLAINLY. The sample month describes two
 * things this module has no way to write:
 *
 *   THE GAPS in a track. A recorded patrol's gap count is whatever its own
 *   timestamps imply, because that is what ingest measures; the sample month
 *   cannot assert one.
 *   AMENDMENTS. Nothing here corrects an observation, because a correction is
 *   signed and inventing a signature onto an evidence trail is the one thing an
 *   evidence trail exists to prevent.
 *
 * IT SEEDS ONCE. An area that already holds patrols is left exactly as it is:
 * re-running a demo seeder is a developer repeating a command, not an
 * instruction to double the month.
 *
 * IT IS COLLECTED, NOT RUN. devkit installs through `require-dev`; in a
 * production build nothing collects this and it is an ordinary service nobody
 * ever asks anything of.
 *
 * THE ENTITY MANAGER IS HERE TO READ. Which area exists, who has an account, and
 * where inside that boundary a route may run are questions only the installation
 * and PostGIS can answer.
 *
 * @see ContentProviderInterface
 */
final readonly class PatrolContentProvider implements ContentProviderInterface
{
    /** Cells per side of the sampling grid laid over the area's bounding box. */
    private const int GRID_DIVISIONS = 6;

    /** How many seeded points the ST_GeneratePoints fallback scatters. */
    private const int FALLBACK_SAMPLES = 24;

    /** Fewer usable grid cells than this and the fallback sampler takes over. */
    private const int MIN_SAMPLES = 4;

    /** Kept clear of the boundary, as a fraction of the area's narrow side. */
    private const float INTERIOR_MARGIN = 0.05;

    /** Used when the area has no boundary geometry to sample at all. */
    private const float FALLBACK_LON = 0.0;
    private const float FALLBACK_LAT = 0.0;
    private const float FALLBACK_SPREAD_DEG = 0.08;

    private const int PHOTO_WIDTH = 480;
    private const int PHOTO_HEIGHT = 360;

    /**
     * Earthy fills for the invented photographs — enough variety that a files
     * grid or an observation's photo strip does not read as one repeated tile.
     *
     * @var non-empty-list<array{0: int, 1: int, 2: int}>
     */
    private const array PHOTO_FILLS = [
        [78, 92, 63], [122, 108, 74], [64, 84, 96], [96, 76, 60],
        [70, 96, 82], [110, 96, 88], [88, 100, 70], [60, 72, 84],
    ];

    /**
     * The interior geometry the demo is allowed to use: the boundary eroded by
     * INTERIOR_MARGIN of its narrow side, so tracks keep clear of the edge. An
     * area too narrow to erode keeps its own outline. Every sampling query below
     * builds on this common table expression.
     */
    private const string INTERIOR_CTE = <<<'SQL'
        WITH raw AS (
            SELECT ST_MakeValid(geom) AS geom FROM area_of_interest WHERE id = :id AND geom IS NOT NULL
        ), sized AS (
            SELECT geom,
                   GREATEST(ST_XMax(geom) - ST_XMin(geom), ST_YMax(geom) - ST_YMin(geom)) AS span,
                   LEAST(ST_XMax(geom) - ST_XMin(geom), ST_YMax(geom) - ST_YMin(geom)) AS narrow
            FROM raw
        ), interior AS (
            SELECT CASE
                       WHEN ST_IsEmpty(ST_Buffer(geom, -narrow * %1$F)) THEN geom
                       ELSE ST_Buffer(geom, -narrow * %1$F)
                   END AS geom,
                   span
            FROM sized
        )
        SQL;

    /**
     * @param array<string, array{label: string}> $types      the deployment's patrol.types vocabulary
     * @param array<string, array{label: string}> $categories the deployment's patrol.observation_categories vocabulary
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolRepository $patrols,
        private ObservationRepository $observations,
        private GeoService $geo,
        private TrackIngestService $ingest,
        private PatrolRecordingService $recording,
        private ObservationSyncService $observationSync,
        private PhotoSyncService $photoSync,
        private TaxonomyAdminService $taxonomy,
        private PatrolVocabularyService $vocabulary,
        private AreaStationService $areaStations,
        private AreaStationRepository $areaStationRepository,
        private array $types,
        private array $categories,
    ) {
    }

    public function key(): string
    {
        return 'patrol';
    }

    public function label(): string
    {
        return 'Patrols';
    }

    public function description(): string
    {
        return 'A month of patrolling in the first area: tracks walked, driven and flown, the notes logged en route, and the photographs that came back with them.';
    }

    /**
     * The people. A patrol is LED by somebody and its observations are recorded
     * BY somebody, and a register whose every shift was led by nobody says
     * nothing about who is doing the work — and credits nobody the hours.
     *
     * Areas are not named here, deliberately: nothing installed ships area demo
     * content, and devkit refuses an edge to a key no provider declares. The
     * area is taken from whatever the installation has.
     *
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return ['team'];
    }

    public function load(): void
    {
        $area = $this->firstArea();
        if (null === $area) {
            // An installation with no area is one with nowhere to patrol, and
            // that is a state rather than a failure.
            return;
        }

        if ($this->patrols->count(['area' => $area]) > 0) {
            return;
        }

        $this->seedTaxonomy($area);

        $month = new PatrolDemoMonth(
            $this->geo,
            $this->boundaryRings($area),
            $this->samplePoints($area),
            $this->configuredWords($this->types, 'foot'),
            $this->configuredWords($this->categories, 'wildlife'),
            new \DateTimeImmutable(),
        );

        // THE DEMO'S WORDS BECOME THE AREA'S WORDS, seeded once: the
        // installation's configured types, and the posts this month's patrols
        // set out from. Both arrive ACTIVE — they are the area's vocabulary,
        // not strays off a handset — and a second run finds them already there.
        $this->vocabulary->seedTypes($area);
        // The posts are the AREA's stations (core AreaBundle), recorded through
        // the area's own service where the demo's month names one the area
        // does not keep yet; a second run finds them by name.
        $stations = [];
        $known = [];
        foreach ($this->areaStationRepository->findByArea($area) as $existing) {
            $known[mb_strtolower(trim((string) $existing->getName()))] = $existing;
        }
        foreach ($month->stations() as $post) {
            $stations[$post['name']] = $known[mb_strtolower(trim($post['name']))]
                ?? $this->areaStations->add($area, $post['name'], (float) $post['lon'], (float) $post['lat']);
        }

        $roster = $this->roster();
        $recorder = $roster[0] ?? null;
        $photographs = $this->photoVariants();

        try {
            foreach ($month->patrols() as $plan) {
                $lead = [] === $roster ? null : $roster[$plan['leadSlot'] % \count($roster)];

                $patrol = null === $plan['gpx']
                    ? $this->recording->record(
                        $area,
                        $this->vocabulary->resolveType($area, $plan['type']),
                        $plan['startedAt'],
                        $plan['endedAt'],
                        $stations[$plan['station']] ?? null,
                        $lead,
                        $plan['team'],
                        $plan['note'],
                        $plan['distanceKm'],
                    )
                    : $this->ingest->ingest(
                        $plan['gpx'],
                        $area,
                        $this->vocabulary->resolveType($area, $plan['type']),
                        PatrolSourceEnum::Gpx,
                        $stations[$plan['station']] ?? null,
                        $lead,
                        $plan['team'],
                        $plan['note'],
                    );

                if (null !== $recorder && [] !== $plan['observations']) {
                    $this->logObservations($patrol, $plan['observations'], $recorder, $photographs);
                }
            }
        } finally {
            // The stored evidence keeps the bytes; the source files do not.
            foreach ($photographs as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * The notes a shift came back with, appended through the field app's own
     * endpoint service — so a seeded observation is validated, positioned and
     * counted exactly as a synced one is.
     *
     * @param list<array{category: string, note: string, position: array{0: float, 1: float}, loggedAt: \DateTimeImmutable, photos: int}> $plans
     * @param list<string>                                                                                                                $photographs
     */
    private function logObservations(Patrol $patrol, array $plans, UserInterface $recorder, array $photographs): void
    {
        $rows = [];
        foreach ($plans as $plan) {
            $rows[] = [
                'clientUuid' => Uuid::v7()->toRfc4122(),
                'category' => $plan['category'],
                'note' => $plan['note'],
                'loggedAt' => $plan['loggedAt']->format(\DateTimeInterface::ATOM),
                'photoCount' => [] === $photographs ? 0 : $plan['photos'],
                'position' => [
                    'lon' => $plan['position'][0],
                    'lat' => $plan['position'][1],
                    'accuracyM' => 8.0,
                ],
            ];
        }

        [$accepted] = $this->observationSync->append($patrol, ['observations' => $rows], $recorder);

        if ([] === $photographs) {
            return;
        }

        foreach ($accepted as $index => $clientUuid) {
            $observation = $this->observations->findOneByClientUuid(Uuid::fromString($clientUuid));
            if (!$observation instanceof Observation) {
                continue;
            }

            $this->attachPhotographs($observation, $plans[$index]['photos'], $plans[$index], $photographs);
        }
    }

    /**
     * @param array{category: string, note: string, position: array{0: float, 1: float}, loggedAt: \DateTimeImmutable, photos: int} $plan
     * @param non-empty-list<string>                                                                                                $photographs
     */
    private function attachPhotographs(Observation $observation, int $wanted, array $plan, array $photographs): void
    {
        for ($n = 0; $n < $wanted; ++$n) {
            $source = $photographs[(($observation->getId() ?? 0) + $n) % \count($photographs)];

            $this->photoSync->store(
                $observation,
                Uuid::v7(),
                new UploadedFile($source, basename($source), 'image/jpeg', null, true),
                $plan['loggedAt'],
                json_encode(
                    ['type' => 'Point', 'coordinates' => [$plan['position'][0], $plan['position'][1]]],
                    \JSON_THROW_ON_ERROR,
                ),
                8.0,
            );
        }
    }

    /**
     * A small starting taxonomy for the area, written one call at a time through
     * the service the admin screen calls. A label the area already has is left
     * alone rather than duplicated — the service refuses it, and a refusal here
     * means the demo is running a second time.
     */
    private function seedTaxonomy(AreaOfInterest $area): void
    {
        foreach (PatrolDemoMonth::TAXONOMY as $kindLabel => $subcategories) {
            try {
                $kind = $this->taxonomy->createKind($area, $kindLabel);
            } catch (TaxonomyConflictException) {
                continue;
            }

            foreach ($subcategories as $label) {
                try {
                    $this->taxonomy->createSubcategory($kind, $label);
                } catch (TaxonomyConflictException) {
                    // A wire-code the area already spends. Nothing to do.
                }
            }
        }
    }

    /**
     * The area to patrol — the first the installation has.
     */
    private function firstArea(): ?AreaOfInterest
    {
        $areas = $this->entityManager->getRepository(AreaOfInterest::class)->findBy([], ['id' => 'ASC'], 1);

        return $areas[0] ?? null;
    }

    /**
     * The people the installation already has accounts for, oldest first — the
     * demo team where the team slice has run, which it has, because this is
     * seeded after it. This creates nobody: accounts belong to whoever owns
     * them, and an installation with none seeds a month nobody is credited for.
     *
     * @return list<UserInterface>
     */
    private function roster(): array
    {
        /** @var list<UserInterface> $users */
        $users = $this->entityManager->getRepository(UserInterface::class)->findBy([], ['id' => 'ASC']);

        return $users;
    }

    /**
     * The deployment's own words, or a single fallback where it configured none
     * — a demo with no vocabulary to draw on is still a demo.
     *
     * @param array<string, array{label: string}> $configured
     *
     * @return non-empty-list<string>
     */
    private function configuredWords(array $configured, string $fallback): array
    {
        $keys = array_keys($configured);

        return [] !== $keys ? $keys : [$fallback];
    }

    /**
     * Points spread over the area, sampled by PostGIS: one interior point per
     * cell of a square grid clipped to the boundary. Deterministic by
     * construction; shapes yielding too few cells fall back to seeded
     * ST_GeneratePoints, which is deterministic through its seed argument.
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function samplePoints(AreaOfInterest $area): array
    {
        $grid = $this->fetchPoints($area, $this->interior().\sprintf(
            ', grid AS (
                 SELECT (ST_SquareGrid(interior.span / %d, interior.geom)).geom AS cell, interior.geom AS shape
                 FROM interior
             ), spot AS (
                 SELECT ST_PointOnSurface(ST_CollectionExtract(ST_Intersection(cell, shape), 3)) AS p
                 FROM grid WHERE ST_Intersects(cell, shape)
             )
             SELECT ST_X(p) AS lon, ST_Y(p) AS lat FROM spot
             WHERE p IS NOT NULL AND NOT ST_IsEmpty(p)
             ORDER BY ST_Y(p), ST_X(p)',
            self::GRID_DIVISIONS,
        ));
        if (\count($grid) >= self::MIN_SAMPLES) {
            return $grid;
        }

        $scattered = $this->fetchPoints($area, $this->interior().\sprintf(
            ' SELECT ST_X(p) AS lon, ST_Y(p) AS lat
             FROM (SELECT (ST_Dump(ST_GeneratePoints(interior.geom, %d, %d))).geom AS p FROM interior) d
             ORDER BY ST_Y(p), ST_X(p)',
            self::FALLBACK_SAMPLES,
            PatrolDemoMonth::RANDOM_SEED,
        ));
        if ([] !== $scattered) {
            return $scattered;
        }

        // No boundary at all: a neutral ring, so the demo still has distinct posts.
        [$lon, $lat] = $this->centroid($area);
        $ring = [];
        foreach (array_keys(PatrolDemoMonth::STATIONS) as $index) {
            $angle = 2 * \M_PI * $index / \count(PatrolDemoMonth::STATIONS);
            $ring[] = [$lon + self::FALLBACK_SPREAD_DEG * sin($angle), $lat + self::FALLBACK_SPREAD_DEG * cos($angle)];
        }

        return $ring;
    }

    /**
     * The rings of that same interior geometry, read once as GeoJSON so every
     * step of a generated route can be tested in PHP instead of a query per
     * vertex.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function boundaryRings(AreaOfInterest $area): array
    {
        $geoJson = $this->entityManager->getConnection()->fetchOne(
            $this->interior().' SELECT ST_AsGeoJSON(geom) FROM interior',
            ['id' => $area->getId()],
        );
        if (!\is_string($geoJson)) {
            return [];
        }

        $decoded = json_decode($geoJson, true);
        $coordinates = \is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        if (!\is_array($coordinates)) {
            return [];
        }

        $polygons = 'MultiPolygon' === ($decoded['type'] ?? null) ? $coordinates : [$coordinates];
        $rings = [];
        foreach ($polygons as $polygon) {
            if (!\is_array($polygon)) {
                continue;
            }
            foreach ($polygon as $ring) {
                if (!\is_array($ring)) {
                    continue;
                }
                $vertices = [];
                foreach ($ring as $pair) {
                    if (\is_array($pair) && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null)) {
                        $vertices[] = [(float) $pair[0], (float) $pair[1]];
                    }
                }
                if (\count($vertices) > 3) {
                    $rings[] = $vertices;
                }
            }
        }

        return $rings;
    }

    private function interior(): string
    {
        return \sprintf(self::INTERIOR_CTE, self::INTERIOR_MARGIN);
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function fetchPoints(AreaOfInterest $area, string $sql): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, ['id' => $area->getId()]);

        $points = [];
        foreach ($rows as $row) {
            if (is_numeric($row['lon'] ?? null) && is_numeric($row['lat'] ?? null)) {
                $points[] = [(float) $row['lon'], (float) $row['lat']];
            }
        }

        return $points;
    }

    /**
     * The area's centroid, straight from PostGIS — this module stores boundaries
     * as geometry, so the database computes their middle.
     *
     * @return array{0: float, 1: float}
     */
    private function centroid(AreaOfInterest $area): array
    {
        $geoJson = $this->entityManager->getConnection()->fetchOne(
            'SELECT ST_AsGeoJSON(ST_Centroid(geom)) FROM area_of_interest WHERE id = :id AND geom IS NOT NULL',
            ['id' => $area->getId()],
        );

        return \is_string($geoJson) ? $this->geo->coordinates($geoJson) : [self::FALLBACK_LON, self::FALLBACK_LAT];
    }

    /**
     * A handful of small JPEGs drawn to temp files, once, so each attachment is
     * stored from a real image. Empty where GD is unavailable — the demo then
     * seeds patrols and observations without photographs rather than failing on
     * a machine with no image extension.
     *
     * @return list<string>
     */
    private function photoVariants(): array
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            return [];
        }

        $paths = [];
        foreach (self::PHOTO_FILLS as [$r, $g, $b]) {
            $image = imagecreatetruecolor(self::PHOTO_WIDTH, self::PHOTO_HEIGHT);
            $fill = imagecolorallocate($image, $r, $g, $b);
            // A lighter horizon band, so a tile reads as a photograph rather
            // than a swatch.
            $band = imagecolorallocate($image, min(255, $r + 26), min(255, $g + 26), min(255, $b + 26));
            if (false !== $fill) {
                imagefilledrectangle($image, 0, 0, self::PHOTO_WIDTH, self::PHOTO_HEIGHT, $fill);
            }
            if (false !== $band) {
                imagefilledrectangle($image, 0, (int) (self::PHOTO_HEIGHT * 0.62), self::PHOTO_WIDTH, self::PHOTO_HEIGHT, $band);
            }
            $path = (string) tempnam(sys_get_temp_dir(), 'patrol_demo_photo_');
            imagejpeg($image, $path, 82);
            $paths[] = $path;
        }

        return $paths;
    }
}
