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

namespace Uhifadhi\Patrol\Tests\Integration\MessageHandler;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Message\BufferPatrolCorridor;
use Uhifadhi\Patrol\MessageHandler\BufferPatrolCorridorHandler;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE WORKER'S SIDE: a settled patrol's track, buffered at its type's width
 * and at the module's, and kept.
 */
final class BufferPatrolCorridorHandlerTest extends IntegrationTestCase
{
    public function testThePatrolsTrackIsBufferedAtBothWidthsAndKept(): void
    {
        $patrol = $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}', 150);

        $this->handler()(new BufferPatrolCorridor((int) $patrol->getId()));

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT width_m, uniform_width_m, point_count, ST_Area(geom::geography) AS typed, ST_Area(uniform_geom::geography) AS uniform FROM patrol_corridor WHERE patrol_id = :id',
            ['id' => $patrol->getId()],
        );
        self::assertIsArray($row);
        self::assertSame(150, $row['width_m']);
        self::assertSame(2000, $row['uniform_width_m']);
        self::assertSame(3, $row['point_count']);
        // ≈ 11.1 km long and unclipped: a band and its two round ends —
        // 3.3 + 0.07 km² at 150 m either side, 44.4 + 12.6 km² at 2 km.
        self::assertEqualsWithDelta(3.4e6, self::number($row['typed']), 0.3e6);
        self::assertEqualsWithDelta(57.0e6, self::number($row['uniform']), 3.0e6);
    }

    public function testASecondMessageReplacesTheCorridorRatherThanAddingOne(): void
    {
        $patrol = $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}', 150);

        $this->handler()(new BufferPatrolCorridor((int) $patrol->getId()));
        $this->handler()(new BufferPatrolCorridor((int) $patrol->getId()));

        self::assertSame(1, self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor')));
    }

    public function testAPatrolWhoseTrackIsGoneKeepsNoCorridor(): void
    {
        $patrol = $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.95],[-29.9,-2.95]]}', null);
        $this->handler()(new BufferPatrolCorridor((int) $patrol->getId()));

        $patrol->setTrack(null);
        $this->em->flush();
        $this->handler()(new BufferPatrolCorridor((int) $patrol->getId()));

        self::assertSame(0, self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor')));
    }

    public function testAMessageForAPatrolThatIsGoneBuffersNothing(): void
    {
        $this->handler()(new BufferPatrolCorridor(987654));

        self::assertSame(0, self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor')));
    }

    private function handler(): BufferPatrolCorridorHandler
    {
        $handler = static::getContainer()->get('test_public.'.BufferPatrolCorridorHandler::class);
        \assert($handler instanceof BufferPatrolCorridorHandler);

        return $handler;
    }

    private function patrol(string $track, ?int $width): Patrol
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $this->em->persist($area);
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk')->setCoverageBufferM($width))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable('2026-03-10T06:00:00Z'))
            ->setStatus(PatrolStatusEnum::Complete)
            ->setPointCount(3)
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    private static function whole(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function number(mixed $value): float
    {
        self::assertIsNumeric($value);

        return (float) $value;
    }
}
