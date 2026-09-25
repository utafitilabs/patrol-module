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

namespace Uhifadhi\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Contracts\Atlas\PlatePalette;
use Uhifadhi\Patrol\Service\PatrolMapService;

/**
 * THE MODULE'S PLATES, STATED IN PHP. Patrol writes no map JavaScript: it says
 * what is on a map and the atlas draws it, with the platform's imagery, chrome,
 * legend and fullscreen.
 *
 * The geometry arrives as the text the geometry columns hold. Anything that
 * will not parse is simply not drawn — a bad track is a plate without that
 * track, never a screen that fails.
 */
final class PatrolMapServiceTest extends TestCase
{
    private const string BOUNDARY = '{"type":"Polygon","coordinates":[[[-29.5,-3.2],[-29.4,-3.2],[-29.4,-3.1],[-29.5,-3.1],[-29.5,-3.2]]]}';
    private const string TRACK = '{"type":"LineString","coordinates":[[-29.48,-3.18],[-29.46,-3.16],[-29.44,-3.14]]}';
    private const string POINT = '{"type":"Point","coordinates":[-29.47,-3.17]}';
    private const string ZONE = '{"type":"Polygon","coordinates":[[[-29.5,-3.2],[-29.45,-3.2],[-29.45,-3.1],[-29.5,-3.1],[-29.5,-3.2]]]}';
    private const string BUFFER = '{"type":"MultiPolygon","coordinates":[[[[-29.49,-3.19],[-29.43,-3.19],[-29.43,-3.13],[-29.49,-3.13],[-29.49,-3.19]]]]}';

    public function testEachPatrolTypeIsItsOwnLayerInTheDeploymentsColour(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors());

        $layers = $map->toArray()['layers'];
        self::assertSame(
            [Ground::ZONES_LAYER_ID, PatrolMapService::COVERAGE_LAYER, 'patrol.tracks.foot', 'patrol.tracks.vehicle', 'patrol.endpoints', 'patrol.stations'],
            array_column($layers, 'id'),
        );
        self::assertSame(PlatePalette::category(1), $layers[2]['swatch']);
        self::assertSame(PlatePalette::category(2), $layers[3]['swatch']);
        self::assertSame('line', $layers[2]['shape']);
    }

    /**
     * A TYPE ROW IS A SWITCH. The map's own type filtering is the legend now,
     * so a row states the type's label and how many of its tracks are drawn.
     */
    public function testATypeRowSwitchesItsTracksAndCountsThem(): void
    {
        $row = self::legendRow(self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors()), 'foot');

        self::assertSame('patrol.tracks.foot', $row->layerId);
        self::assertSame(2, $row->count);
        self::assertSame(PatrolMapService::PATROLS_GROUP, $row->group);
    }

    /**
     * A type the deployment configured but nobody patrolled keeps its row, and
     * the row says zero: a legend that comes and goes with the data is a legend
     * nobody can read.
     */
    public function testATypeWithNoTracksKeepsItsRowAndSaysZero(): void
    {
        $map = self::map()->coverage(
            self::ground(),
            ['patrols' => [], 'stations' => []],
            self::types(),
            self::colors(),
        );

        $row = self::legendRow($map, 'vehicle');
        self::assertSame(0, $row->count);
        self::assertFalse($row->visible);
    }

    /**
     * Every track carries the patrol it belongs to, so the plate can colour a
     * feature individually and a reader can tell one route from another.
     */
    public function testATrackFeatureCarriesItsPatrolsReferenceAndColour(): void
    {
        $features = self::features(self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors()), 2);

        self::assertSame(
            ['ref' => 'PT-0001', 'color' => PlatePalette::category(1), PatrolMapService::TOOLTIP_PROPERTY => 'PT-0001 · foot'],
            \is_array($features[0]) ? $features[0]['properties'] : null,
        );
    }

    /**
     * A TRACK NAMES ITSELF ON HOVER — "ref · type", in the words the legend row
     * beside it uses, so pointing at a line answers "which patrol is this?"
     * without opening anything.
     *
     * Stated as a PROPERTY NAME the plate reads on hover, never as the permanent
     * `label` halo: a plate carrying a hundred routes would otherwise be a field
     * of labels with a map somewhere behind it.
     */
    public function testATrackNamesItselfAndItsTypeOnHover(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors());

        $layers = $map->toArray()['layers'];
        self::assertSame(PatrolMapService::TOOLTIP_PROPERTY, $layers[2]['tooltip']);

        $properties = self::properties($map, 2, 0);
        self::assertSame('PT-0001 · foot', $properties[PatrolMapService::TOOLTIP_PROPERTY] ?? null);
        self::assertArrayNotHasKey('label', $properties);
    }

    /**
     * A LOG ROW BESIDE THE MAP SPOTLIGHTS ITS TRACK, and the layer says which
     * property names a feature so a row can address it. No module JavaScript:
     * the row wears data-atlas-highlight and the plate does the rest.
     */
    public function testATrackLayerNamesThePropertyARowSpotlightsItBy(): void
    {
        $layers = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors())->toArray()['layers'];

        self::assertSame('ref', $layers[2]['featureId']);
        self::assertSame('ref', $layers[3]['featureId']);
    }

    /**
     * PL·03 IS A NUMBER AND A SHAPE. The KPI states the share of the area within
     * 2 km of a track; this is that same set operation DRAWN, so a reader can
     * see where the covered ground is rather than only how much of it there was.
     *
     * It lies UNDER everything — a quiet fill beneath the routes that made it —
     * as a z-index rather than an ordering, so it stays underneath however many
     * layers a deployment's types add above it.
     */
    public function testTheCoverageBufferIsDrawnUnderTheTracksWithItsOwnLegendRow(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors(), self::BUFFER);

        $layers = $map->toArray()['layers'];
        self::assertSame(PatrolMapService::COVERAGE_LAYER, $layers[1]['id']);
        self::assertSame('fill', $layers[1]['shape']);
        $style = $layers[1]['style'];
        self::assertIsArray($style);
        self::assertSame(PatrolMapService::COVERAGE_Z_INDEX, $style['zIndex'] ?? null);
        self::assertCount(1, self::features($map, 1));

        $row = self::legendRow($map, PatrolMapService::COVERAGE_LABEL);
        self::assertSame(PatrolMapService::COVERAGE_LAYER, $row->layerId);
        self::assertNull($row->count);
        self::assertTrue($row->visible);
    }

    /**
     * THE ROW SAYS THE DISTANCE THE SHAPE WAS ACTUALLY MEASURED AT.
     *
     * A type now carries its own coverage buffer, so the ground a month covered is
     * not one distance around every track: it is each type's width around its own.
     * A row that went on saying "2 km" would be a legend a reader cannot rely on —
     * which is the one thing the legend contract is for — so where the types in
     * play disagree the row says the range instead, and where they agree it says
     * the one number.
     */
    public function testTheCoverageRowNamesTheWidthsTheTypesActuallyCarry(): void
    {
        $narrow = self::map()->coverage(
            self::ground(),
            self::coveragePayload(),
            ['foot' => ['label' => 'Foot', 'bufferM' => 150], 'vehicle' => ['label' => 'Vehicle', 'bufferM' => 150]],
            self::colors(),
            self::BUFFER,
        );
        self::assertSame('150 m coverage buffer', self::legendRow($narrow, '150 m coverage buffer')->label);

        $mixed = self::map()->coverage(
            self::ground(),
            self::coveragePayload(),
            ['foot' => ['label' => 'Foot', 'bufferM' => 150], 'vehicle' => ['label' => 'Vehicle', 'bufferM' => 400]],
            self::colors(),
            self::BUFFER,
        );
        self::assertSame('150–400 m coverage buffer', self::legendRow($mixed, '150–400 m coverage buffer')->label);
    }

    /**
     * A TYPE THAT CARRIES NO BUFFER FALLS BACK ON THE MODULE'S, and the row then
     * says the module's distance — which is what every area reads today, and what
     * the design's own row says.
     */
    public function testATypeWithNoBufferOfItsOwnKeepsTheModulesDistanceInTheRow(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors(), self::BUFFER);

        self::assertSame(PatrolMapService::COVERAGE_LABEL, self::legendRow($map, PatrolMapService::COVERAGE_LABEL)->label);
    }

    /**
     * A month in which nothing was recorded has no covered ground, and the
     * honest form of that is a layer with nothing in it — never a missing legend
     * row, which would leave a reader unable to tell "none" from "not measured".
     */
    public function testAMonthWithNoRecordedTrackStillShipsTheCoverageRow(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors(), null);

        self::assertSame(PatrolMapService::COVERAGE_LAYER, $map->toArray()['layers'][1]['id']);
        self::assertSame([], self::features($map, 1));
        self::assertFalse(self::legendRow($map, PatrolMapService::COVERAGE_LABEL)->visible);
    }

    /** The design's ● start and ○ end, as points the plate draws in the track's colour. */
    public function testEveryTrackContributesItsStartAndItsEnd(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors());

        $ends = self::features($map, 4);

        self::assertCount(6, $ends);
        self::assertSame(
            ['type' => 'Point', 'coordinates' => [-29.48, -3.18]],
            \is_array($ends[0]) ? $ends[0]['geometry'] : null,
        );
    }

    /**
     * A station has no coordinates of its own, so it is drawn where its patrols
     * set out — and it wears its name, which the plate draws as a halo label.
     */
    public function testAStationIsDrawnWhereItsPatrolsSetOutAndWearsItsName(): void
    {
        $features = self::features(self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors()), 5);

        self::assertCount(1, $features);
        self::assertSame(['label' => 'North gate'], \is_array($features[0]) ? $features[0]['properties'] : null);
    }

    public function testTheBoundaryIsDrawnWithItsScrimAndSwitchableFromTheLegend(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors());

        $boundary = $map->toArray()['boundary'];
        self::assertIsArray($boundary);
        self::assertTrue($boundary['scrim']);
        self::assertSame(AtlasMap::BOUNDARY_LAYER_ID, self::legendRow($map, 'Boundary')->layerId);
    }

    public function testATrackThatWillNotParseIsSimplyNotDrawn(): void
    {
        $map = self::map()->coverage(
            self::ground('not json'),
            ['patrols' => [['uuid' => 'u', 'ref' => 'PT-0003', 'type' => 'foot', 'station' => '', 'zone' => '', 'color' => PlatePalette::category(1), 'track' => '{']], 'stations' => []],
            self::types(),
            self::colors(),
        );

        self::assertNull($map->toArray()['boundary']);
        self::assertSame([], self::features($map, 2));
    }

    /**
     * THE AREA'S ZONES ARE THE ATLAS GROUND'S: the first layer, under every
     * track, with a row that counts them under "The area" — the boundary row
     * above it, the stations row after it.
     */
    public function testTheCoveragePlateStandsOnTheAreasGround(): void
    {
        $map = self::map()->coverage(self::ground(), self::coveragePayload(), self::types(), self::colors());

        $zones = $map->toArray()['layers'][0];
        self::assertSame(Ground::ZONES_LAYER_ID, $zones['id']);
        self::assertSame(PlatePalette::DIM, $zones['swatch']);
        self::assertSame('line', $zones['shape']);
        self::assertCount(2, self::features($map, 0));

        $area = array_values(array_filter($map->legend(), static fn (LegendItem $row): bool => Ground::GROUP === $row->group));
        self::assertSame(
            [['Boundary', null], ['Zones', 2], ['stations', 1]],
            array_map(static fn (LegendItem $row): array => [$row->label, $row->count], $area),
        );
        self::assertSame(Ground::GROUP, $map->legend()[0]->group);
    }

    public function testAnAreaWithNoZonesStillStatesZonesNought(): void
    {
        $map = self::map()->coverage(['boundary' => self::BOUNDARY, 'zones' => []], self::coveragePayload(), self::types(), self::colors());

        $row = self::legendRow($map, 'Zones');
        self::assertSame(0, $row->count);
        self::assertFalse($row->visible);
    }

    /* ---- the detail plate ------------------------------------------------ */

    public function testTheTrackPlateStandsOnTheAreasGround(): void
    {
        $map = self::map()->track(self::ground(), ['track' => self::TRACK]);

        self::assertSame(2, self::legendRow($map, 'Zones')->count);
        self::assertSame(Ground::GROUP, self::legendRow($map, 'Zones')->group);
        self::assertSame(Ground::GROUP, self::legendRow($map, 'Boundary')->group);
    }

    /**
     * THE STATION PICKER KEEPS ITS OWN QUIET BOUNDARY: a plate for placing one
     * point is not a plate about the area's zones.
     */
    public function testTheStationPickerDrawsNoZones(): void
    {
        $map = self::map()->stationPoint(self::BOUNDARY, [], -3.15, -29.45, 'North gate');

        self::assertNotContains(Ground::ZONES_LAYER_ID, array_column($map->toArray()['layers'], 'id'));
        $boundary = $map->toArray()['boundary'];
        self::assertIsArray($boundary);
        self::assertFalse($boundary['scrim']);
    }

    public function testTheDetailPlateDrawsTheTrackAndItsEnds(): void
    {
        $map = self::map()->track(self::ground(), ['track' => self::TRACK, 'color' => PlatePalette::category(1)]);

        self::assertSame([Ground::ZONES_LAYER_ID, 'patrol.track', 'patrol.endpoints'], array_column($map->toArray()['layers'], 'id'));
        self::assertCount(2, self::features($map, 2));
    }

    /**
     * A DETAIL PLATE OPENS DEEP INSIDE THE AREA, so the scrim starts off: dimming
     * "outside" darkens imagery with no edge in frame to explain it. The control
     * is still built, so it can be switched on.
     */
    public function testTheDetailPlatesScrimStartsOff(): void
    {
        $map = self::map()->track(self::ground(), ['track' => self::TRACK]);

        $boundary = $map->toArray()['boundary'];
        self::assertIsArray($boundary);
        self::assertFalse($boundary['scrim']);
    }

    /**
     * An observation is a marker, not a layer feature, because it opens its own
     * page: the link rides in the window the marker opens.
     */
    public function testEveryPositionedObservationIsAMarkerThatOpensItsPage(): void
    {
        $map = self::map()->track(self::ground(), [
            'track' => self::TRACK,
            'observations' => [
                ['n' => 1, 'position' => self::POINT, 'category' => 'snare', 'url' => '/patrols/x/observations/1', 'current' => false],
                ['n' => 2, 'position' => null, 'category' => 'tracks', 'url' => null, 'current' => false],
            ],
        ]);

        $markers = $map->toUxMap()->toArray()['markers'];
        self::assertIsArray($markers);
        // The observation with no fix holds its number in the list and is not drawn.
        self::assertCount(1, $markers);
        self::assertIsArray($markers[0]);
        $window = $markers[0]['infoWindow'];
        self::assertIsArray($window);
        self::assertIsString($window['content']);
        self::assertStringContainsString('/patrols/x/observations/1', $window['content']);
    }

    /**
     * ON THE OBSERVATION SCREEN THE TRACK IS CONTEXT, not the subject, so it is
     * drawn back and its ends are not drawn at all.
     */
    public function testTheObservationScreenDrawsTheTrackBack(): void
    {
        $map = self::map()->track(self::ground(), [
            'track' => self::TRACK,
            'observation' => ['n' => 3, 'position' => self::POINT, 'category' => 'snare'],
        ]);

        self::assertSame([Ground::ZONES_LAYER_ID, 'patrol.track'], array_column($map->toArray()['layers'], 'id'));
    }

    public function testAHandLoggedPatrolIsAPlateWithNoTrack(): void
    {
        $map = self::map()->track(self::ground(), ['track' => null]);

        self::assertSame([], self::features($map, 1));
        self::assertNotNull($map->toArray()['boundary']);
    }

    /**
     * One feature's properties, as the plate will read them.
     *
     * @return array<string, mixed>
     */
    private static function properties(AtlasMap $map, int $layer, int $feature): array
    {
        $found = self::features($map, $layer)[$feature] ?? null;
        self::assertIsArray($found);
        $properties = $found['properties'] ?? null;
        self::assertIsArray($properties);

        $named = [];
        foreach ($properties as $name => $value) {
            self::assertIsString($name);
            $named[$name] = $value;
        }

        return $named;
    }

    /**
     * @return list<mixed>
     */
    private static function features(AtlasMap $map, int $index): array
    {
        $collection = $map->toArray()['layers'][$index]['features'];

        self::assertIsArray($collection);
        self::assertIsList($collection['features'] ?? null);

        return $collection['features'];
    }

    private static function legendRow(AtlasMap $map, string $label): LegendItem
    {
        foreach ($map->legend() as $item) {
            if ($item->label === $label) {
                return $item;
            }
        }

        self::fail(\sprintf('The legend has no row labelled "%s".', $label));
    }

    /**
     * @return array{patrols: list<array{uuid: string, ref: string, type: string, station: string, zone: string, color: string, track: string}>, stations: list<array{name: string, lon: float, lat: float}>}
     */
    private static function coveragePayload(): array
    {
        return [
            'patrols' => [
                ['uuid' => 'a', 'ref' => 'PT-0001', 'type' => 'foot', 'station' => 'North gate', 'zone' => '', 'color' => PlatePalette::category(1), 'track' => self::TRACK],
                ['uuid' => 'b', 'ref' => 'PT-0002', 'type' => 'foot', 'station' => 'North gate', 'zone' => '', 'color' => PlatePalette::category(1), 'track' => self::TRACK],
                ['uuid' => 'c', 'ref' => 'PT-0003', 'type' => 'vehicle', 'station' => '', 'zone' => '', 'color' => PlatePalette::category(2), 'track' => self::TRACK],
            ],
            'stations' => [['name' => 'North gate', 'lon' => -29.48, 'lat' => -3.18]],
        ];
    }

    /**
     * @return array<string, array{label: string}>
     */
    private static function types(): array
    {
        return ['foot' => ['label' => 'Foot'], 'vehicle' => ['label' => 'Vehicle']];
    }

    /**
     * The two types' PLATE TOKENS — what their positions in the area's
     * declared order resolve to. A test that handed hexes would be testing a
     * module that decides colours, which this one deliberately does not.
     *
     * @return array<string, string>
     */
    private static function colors(): array
    {
        return ['foot' => PlatePalette::category(1), 'vehicle' => PlatePalette::category(2)];
    }

    /**
     * The area's answer, as `AreaMapPayload::forArea()` gives it.
     *
     * @return array{boundary: string|null, zones: list<array{name: string|null, geom: string|null}>}
     */
    private static function ground(?string $boundary = self::BOUNDARY): array
    {
        return [
            'boundary' => $boundary,
            'zones' => [['name' => 'North block', 'geom' => self::ZONE], ['name' => 'South block', 'geom' => self::ZONE]],
        ];
    }

    private static function map(): PatrolMapService
    {
        return new PatrolMapService(new MapBuilder());
    }
}
