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

namespace Uhifadhi\Patrol\Tests\Unit\Devkit;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Devkit\PatrolSeedMonth;
use Uhifadhi\Patrol\Service\GeoService;

/**
 * The sample month adds up — and stays inside the boundary it was handed.
 *
 * This is the half of the seed that may invent freely, so it is the half that
 * has to be checked arithmetically: {@see \Uhifadhi\Patrol\Tests\Integration\Devkit\PatrolContentProviderTest}
 * then proves the same table survives being written through the product's own
 * doors.
 */
final class PatrolSeedMonthTest extends TestCase
{
    public function testAMonthIsTwelveShiftsAndAQuarterOfThemWereWrittenUpByHand(): void
    {
        $patrols = $this->month()->patrols();

        self::assertCount(PatrolSeedMonth::PATROLS, $patrols);

        $sketched = array_filter($patrols, static fn (array $patrol): bool => null === $patrol['gpx']);
        self::assertCount(3, $sketched, 'A roster that was all GPS would be a lie about how patrolling is recorded.');
    }

    /** A sketch has no route to describe, so it describes none — and says so. */
    public function testAHandWrittenShiftCarriesNoDocumentAndNoObservations(): void
    {
        foreach ($this->month()->patrols() as $patrol) {
            if (null !== $patrol['gpx']) {
                continue;
            }

            self::assertSame([], $patrol['observations']);
            self::assertGreaterThan($patrol['startedAt'], $patrol['endedAt']);
            self::assertGreaterThan(0.0, $patrol['distanceKm']);
        }
    }

    /**
     * THE DOCUMENT IS WHAT INGEST READS, so it has to be a document: well-formed
     * GPX, with a time on every point — which is where the stored patrol's span,
     * distance and gap count come from.
     */
    public function testEveryRecordedShiftIsAWellFormedTimestampedGpxDocument(): void
    {
        foreach ($this->month()->patrols() as $patrol) {
            if (null === $patrol['gpx']) {
                continue;
            }

            $xml = simplexml_load_string($patrol['gpx']);
            self::assertNotFalse($xml, 'A seed track that will not parse is a seed that seeds nothing.');

            $points = $xml->trk->trkseg->trkpt;
            self::assertGreaterThan(1, $points->count());
            foreach ($points as $point) {
                self::assertNotSame('', (string) $point->time);
            }
        }
    }

    /** A route that wandered out of the area would draw a coverage map nobody could trust. */
    public function testNoRouteLeavesTheAreaItWasSampledFrom(): void
    {
        foreach ($this->month()->patrols() as $patrol) {
            if (null === $patrol['gpx']) {
                continue;
            }

            $xml = simplexml_load_string($patrol['gpx']);
            self::assertNotFalse($xml);
            foreach ($xml->trk->trkseg->trkpt as $point) {
                $lon = (float) $point['lon'];
                $lat = (float) $point['lat'];
                self::assertGreaterThanOrEqual(-30.0, $lon);
                self::assertLessThanOrEqual(-29.0, $lon);
                self::assertGreaterThanOrEqual(-3.6, $lat);
                self::assertLessThanOrEqual(-2.8, $lat);
            }
        }
    }

    /** Busy gates and quiet outposts: five posts, and they do not sit on top of each other. */
    public function testThePostsAreSpreadAcrossTheAreaRatherThanBunched(): void
    {
        $stations = $this->month()->stations();

        self::assertCount(\count(PatrolSeedMonth::STATIONS), $stations);
        self::assertSame(
            \count($stations),
            \count(array_unique(array_map(static fn (array $s): string => $s['lon'].'/'.$s['lat'], $stations))),
        );
    }

    /** One seed, one month: two runs over the same area describe the same shifts. */
    public function testTheSameAreaAlwaysDescribesTheSameMonth(): void
    {
        self::assertEquals($this->month()->patrols(), $this->month()->patrols());
    }

    /**
     * AN AREA WITH POSTS OF ITS OWN IS PATROLLED FROM THEM. The month adds no
     * post the area does not keep: it takes the area's, busiest first, and
     * every shift sets out from one of them.
     */
    public function testAnAreaWithPostsOfItsOwnIsPatrolledFromThem(): void
    {
        $posts = [
            ['name' => 'Eastgate Post', 'lon' => -29.8, 'lat' => -3.4],
            ['name' => 'Fig Tree Ranger Post', 'lon' => -29.3, 'lat' => -3.4],
            ['name' => 'Ridge Outpost', 'lon' => -29.5, 'lat' => -3.0],
        ];
        $month = new PatrolSeedMonth(
            new GeoService(),
            [[[-30.0, -3.6], [-29.0, -3.6], [-29.0, -2.8], [-30.0, -2.8], [-30.0, -3.6]]],
            [[-29.9, -3.5], [-29.2, -3.5], [-29.9, -2.9], [-29.2, -2.9], [-29.5, -3.2]],
            ['foot', 'vehicle', 'drone'],
            ['wildlife', 'sign', 'infrastructure'],
            new \DateTimeImmutable('2026-08-22 17:00:00'),
            posts: $posts,
        );

        self::assertSame($posts, $month->stations());
        foreach ($month->patrols() as $patrol) {
            self::assertContains($patrol['station'], array_column($posts, 'name'), 'every shift sets out from one of the area\'s own posts');
        }
    }

    private function month(): PatrolSeedMonth
    {
        return new PatrolSeedMonth(
            new GeoService(),
            [[[-30.0, -3.6], [-29.0, -3.6], [-29.0, -2.8], [-30.0, -2.8], [-30.0, -3.6]]],
            [[-29.9, -3.5], [-29.2, -3.5], [-29.9, -2.9], [-29.2, -2.9], [-29.5, -3.2]],
            ['foot', 'vehicle', 'drone'],
            ['wildlife', 'sign', 'infrastructure'],
            new \DateTimeImmutable('2026-08-22 17:00:00'),
        );
    }
}
