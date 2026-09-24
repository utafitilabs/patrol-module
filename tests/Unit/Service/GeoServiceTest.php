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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Service\GeoService;

/**
 * The position helpers behind the observation rows and the observation meta
 * plate. Expectations are computed, not guessed: a value's total arc-seconds
 * are round(|value| * 3600), split as degrees = seconds / 3600, minutes =
 * (seconds % 3600) / 60, seconds = the remainder — e.g. 3.195° * 3600 =
 * 11 502" = 3° 11' 42", and 29.530556° * 3600 = 106 310.002" → 106 310" =
 * 29° 31' 50".
 */
final class GeoServiceTest extends TestCase
{
    /** @return iterable<string, array{float, float, string}> */
    public static function positions(): iterable
    {
        yield 'southern + western hemisphere' => [-29.530556, -3.195, '3°11\'42"S 29°31\'50"W'];
        yield 'northern + eastern hemisphere' => [70.25, 12.5, '12°30\'00"N 70°15\'00"E'];
        yield 'null island counts as N/E' => [0.0, 0.0, '0°00\'00"N 0°00\'00"E'];
        yield 'rounds up to whole seconds' => [179.999999, -89.5, '89°30\'00"S 180°00\'00"E'];
    }

    #[DataProvider('positions')]
    public function testItPrintsAPositionAsDegreesMinutesSecondsLatitudeFirst(float $lon, float $lat, string $expected): void
    {
        self::assertSame($expected, new GeoService()->formatDms($lon, $lat));
    }

    public function testItReadsTheCoordinatePairOutOfAGeoJsonPoint(): void
    {
        self::assertSame(
            [-29.530556, -3.195],
            new GeoService()->coordinates('{"type":"Point","coordinates":[-29.530556,-3.195]}'),
        );
    }

    public function testItRefusesGeometryThatIsNotAPoint(): void
    {
        $this->expectException(\LogicException::class);

        new GeoService()->coordinates('{"type":"LineString","coordinates":[[1,2],[3,4]]}');
    }

    /** A pair a map could have produced becomes a Point, longitude first. */
    public function testItWritesALatitudeAndLongitudeAsAGeoJsonPoint(): void
    {
        self::assertSame(
            '{"type":"Point","coordinates":[-29.44,-3.19]}',
            new GeoService()->pointGeoJson(-3.19, -29.44),
        );
    }

    /**
     * OFF THE WORLD IS NOT A COORDINATE, and it is refused rather than clamped: a
     * station filed at the pole would read as placed.
     *
     * @return iterable<string, array{float, float}>
     */
    public static function offTheWorld(): iterable
    {
        yield 'past the north pole' => [90.5, -29.0];
        yield 'past the south pole' => [-91.0, -29.0];
        yield 'past the antimeridian' => [-3.0, 180.5];
        yield 'the other way past it' => [-3.0, -181.0];
    }

    #[DataProvider('offTheWorld')]
    public function testItRefusesAPairThatIsNotAPlaceOnEarth(float $lat, float $lon): void
    {
        self::assertNull(new GeoService()->pointGeoJson($lat, $lon));
    }

    /**
     * THE MIDDLE OF A GEOMETRY'S BOX, however deeply the geometry nests its pairs —
     * which is what lets a picker open on an area without knowing whether the
     * boundary came back as a Polygon or a MultiPolygon.
     *
     * @return iterable<string, array{string, array{0: float, 1: float}|null}>
     */
    public static function geometries(): iterable
    {
        yield 'a point is its own middle' => ['{"type":"Point","coordinates":[-29.0,-3.0]}', [-29.0, -3.0]];
        yield 'a polygon ring' => [
            '{"type":"Polygon","coordinates":[[[12.0,-6.0],[14.0,-6.0],[14.0,-4.0],[12.0,-4.0],[12.0,-6.0]]]}',
            [13.0, -5.0],
        ];
        yield 'two polygons' => [
            '{"type":"MultiPolygon","coordinates":[[[[0.0,0.0],[2.0,0.0],[2.0,2.0],[0.0,0.0]]],'
            .'[[[8.0,8.0],[10.0,8.0],[10.0,10.0],[8.0,8.0]]]]}',
            [5.0, 5.0],
        ];
        yield 'text carrying no geometry' => ['{"type":"Polygon"}', null];
        yield 'not json at all' => ['a boundary, honestly', null];
    }

    /**
     * @param array{0: float, 1: float}|null $expected
     */
    #[DataProvider('geometries')]
    public function testItFindsTheMiddleOfAGeometrysBox(string $geoJson, ?array $expected): void
    {
        self::assertSame($expected, new GeoService()->centre($geoJson));
    }
}
