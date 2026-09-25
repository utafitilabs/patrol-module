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

namespace Uhifadhi\Patrol\Overview;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayer;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayerProviderInterface;
use Uhifadhi\Contracts\Atlas\PlatePalette;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;

/**
 * PATROLS' THREE LAYERS ON THE HOST'S OPERATIONAL PLATE, each with its legend.
 *
 * One plate, many owners: the host draws the map and this module owns the lines
 * on it that are patrol effort. The legend is grouped by contributor, which is
 * the only way a person can tell why a layer vanished — so all three ship a
 * legend entry every render, including on a morning when they have nothing to
 * draw. A legend that appears and disappears with the data is a legend nobody
 * can rely on.
 *
 * WHAT IS ON IS OPERATIONAL, WHAT IS OFF IS ANALYSIS. Today's tracks and the
 * live ones are what an area manager is shown at 07:00; the 2 km coverage buffer
 * is the shape behind PL·03's percentage and is one click away with its legend,
 * exactly where it was.
 *
 * A COLOUR IS DATA, so it is stated once and is the same in light and dark. The
 * per-type track swatches are {@see PatrolDashboardService::typeSwatches()} — the
 * one map the module's own coverage plate, legend, charts and calendar all read,
 * so a foot patrol is the same green here as it is on the module's own screen.
 * The legend's swatch is the first of them, exactly as the design's is.
 *
 * A RECORDING PATROL'S TRACK IS NULL until it closes, so the live layer is drawn
 * from its points: the trail they make, and the last one as the head of the line.
 * That is not a shortcut — it is the only honest geometry a patrol still out has.
 */
final readonly class PatrolMapLayers implements MapLayerProviderInterface
{
    /** The legend's own name for this module's group of layers. */
    public const string GROUP = 'Patrols';

    public function __construct(
        private PatrolOverviewService $overview,
        private PatrolRepository $patrols,
        // THE AREA'S OWN WORDS — the same list the module's own map is drawn
        // from, so a track is the same colour on both.
        private PatrolTypeRepository $types,
        private PatrolCorridorRepository $corridors,
    ) {
    }

    public function moduleSlug(): string
    {
        return PatrolOverviewContributor::SLUG;
    }

    public function mapLayersFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $colors = PatrolDashboardService::typeSwatches($this->types->findVocabularyByArea($area));
        $accent = PlatePalette::ACCENT;

        return [
            $this->live($area, $now, $colors, $accent),
            $this->closedToday($area, $now, $colors),
            $this->buffer($area, $now, $accent),
        ];
    }

    /**
     * OUT RIGHT NOW — one trail per open patrol and one ring at its head.
     *
     * The ring is a separate feature rather than a property of the line because
     * it is a different fact: the line is where somebody has been, the ring is
     * where they are. A patrol whose ping is stale carries that on the ring, so
     * the plate raises the same alarm the strip does without the host having to
     * know what a ping is.
     *
     * @param array<string, string> $colors
     */
    private function live(AreaOfInterest $area, \DateTimeImmutable $now, array $colors, string $accent): MapLayer
    {
        $features = [];
        $open = $this->overview->out($area, $now);
        foreach ($open as $row) {
            $patrol = $row['patrol'];
            $properties = [
                'ref' => $patrol->getRef(),
                'station' => $patrol->getStation(),
                'type' => $patrol->getType(),
                'typeLabel' => $patrol->getTypeLabel(),
                'color' => $colors[$patrol->getType()] ?? $accent,
                'stale' => $row['stale'],
                'lastPing' => $row['pingLabel'],
                'url' => $row['url'],
            ];

            if (null !== $row['line']) {
                $features[] = $this->feature($row['line'], [...$properties, 'kind' => 'trail']);
            }
            if (null !== $row['point']) {
                $features[] = $this->feature($row['point'], [...$properties, 'kind' => 'ping']);
            }
        }

        return new MapLayer(
            'patrols.live',
            PatrolOverviewContributor::SLUG,
            self::GROUP,
            'Out right now',
            $accent,
            $this->collection($features),
            MapLayer::STYLE_LINE,
            \count($open),
            live: true,
        );
    }

    /**
     * CLOSED TODAY, QUIETED. Yesterday's work is context for today's, so it is
     * drawn and drawn back: the layer's own colour is the quiet grey the design
     * gives it, and each track still carries its type colour for the plate to
     * fade rather than replace.
     *
     * Only COMPLETE patrols, and only the ones that recorded a route: a
     * hand-logged patrol has no geometry and is left out entirely rather than
     * drawn as a guess (docs/design-decisions.md §4).
     *
     * @param array<string, string> $colors
     */
    private function closedToday(AreaOfInterest $area, \DateTimeImmutable $now, array $colors): MapLayer
    {
        $dayStart = $now->setTime(0, 0);
        $closed = $this->patrols->findByAreaEndedBetween($area, $dayStart, $dayStart->modify('+1 day'));

        $features = [];
        $drawn = 0;
        foreach ($closed as $patrol) {
            if (!$patrol->getStatus()->countsTowardsStatistics()) {
                continue;
            }
            ++$drawn;
            $track = $patrol->getTrack();
            if (null === $track || !$patrol->hasRecordedTrack()) {
                continue;
            }
            $features[] = $this->feature($track, [
                'kind' => 'track',
                'ref' => $patrol->getRef(),
                'station' => $patrol->getStation(),
                'type' => $patrol->getType(),
                'typeLabel' => $patrol->getTypeLabel(),
                'color' => $colors[$patrol->getType()] ?? PlatePalette::ACCENT,
                'url' => $this->overview->patrolUrl($area, $patrol),
            ]);
        }

        return new MapLayer(
            'patrols.today',
            PatrolOverviewContributor::SLUG,
            self::GROUP,
            'Closed today',
            // Context, not the subject: a closed patrol is not what this
            // morning is about, and colouring it as loudly as a live one
            // would say it was.
            PlatePalette::DIM,
            $this->collection($features),
            MapLayer::STYLE_LINE,
            $drawn,
        );
    }

    /**
     * THE 2 KM COVERAGE BUFFER — the shape PL·03's percentage is measured from,
     * off by default.
     *
     * Off because it is analysis rather than this morning: it answers "is this
     * area being covered", which is a question for a report, not for 07:00. It
     * is not deleted — it is one click away in the legend, which is the whole
     * demotion made visible.
     *
     * It carries no count. A share of a surface is not a number of things, and
     * printing "· 63" beside it would invite reading it as sixty-three of
     * something.
     */
    private function buffer(AreaOfInterest $area, \DateTimeImmutable $now, string $accent): MapLayer
    {
        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);
        $geometry = $this->corridors->coveredGeoJson($area, $monthStart, $nextMonth);

        return new MapLayer(
            'patrols.buffer',
            PatrolOverviewContributor::SLUG,
            self::GROUP,
            \sprintf('%s km coverage buffer', rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1), '0'), '.')),
            $accent,
            $this->collection(null === $geometry ? [] : [$this->feature($geometry, ['kind' => 'buffer'])]),
            MapLayer::STYLE_FILL,
            on: false,
        );
    }

    /**
     * One GeoJSON feature from the geometry text a PostGIS column stores.
     *
     * The geometry travels as the module's own columns hold it — postgis-bundle
     * reads and writes GeoJSON text — so nothing here re-projects, rounds or
     * re-encodes a coordinate. Malformed text draws nothing rather than throwing
     * the whole plate away: one unreadable track must not cost an area manager
     * the map.
     *
     * @param array<string, mixed> $properties
     *
     * @return array{type: string, geometry: array<mixed>|null, properties: array<string, mixed>}
     */
    private function feature(string $geometry, array $properties): array
    {
        $decoded = json_decode($geometry, true);

        return [
            'type' => 'Feature',
            'geometry' => \is_array($decoded) ? $decoded : null,
            'properties' => $properties,
        ];
    }

    /**
     * @param list<array{type: string, geometry: array<mixed>|null, properties: array<string, mixed>}> $features
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => array_values(array_filter(
            $features,
            static fn (array $feature): bool => null !== $feature['geometry'],
        ))];
    }
}
