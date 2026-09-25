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

namespace Uhifadhi\Patrol\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * `patrol:coverage:rebuild` — the backfill: complete patrols with no stored
 * corridor, or a stale one, buffered in batches; a second run finds nothing.
 */
final class CoverageRebuildCommandTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $this->area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($this->area);
        $this->em->flush();
    }

    public function testItBuffersEveryCompletePatrolLackingACorridorInBatches(): void
    {
        foreach (['-2.91', '-2.93', '-2.95'] as $lat) {
            $this->patrol(\sprintf('{"type":"LineString","coordinates":[[-30.0,%1$s],[-29.9,%1$s]]}', $lat));
        }
        // Nothing to buffer: no track, or a patrol that does not count.
        $this->patrol(null);
        $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.97],[-29.9,-2.97]]}', PatrolStatusEnum::Discarded);
        $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.98],[-29.9,-2.98]]}', PatrolStatusEnum::Recording);

        $tester = $this->rebuild(['--batch-size' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('2 patrols buffered', $output);
        self::assertStringContainsString('1 patrol buffered', $output);
        self::assertStringContainsString('3 patrols buffered.', $output);
        self::assertSame(3, $this->corridors());
    }

    public function testASecondRunFindsNothingAndAllBuffersEverythingAgain(): void
    {
        $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.91],[-29.9,-2.91]]}');
        $this->rebuild();

        self::assertStringContainsString('0 patrols buffered.', $this->rebuild()->getDisplay());
        self::assertStringContainsString('1 patrol buffered.', $this->rebuild(['--all' => true])->getDisplay());
    }

    /** A type whose width changed leaves stale corridors, and the rebuild finds them by the width it stored. */
    public function testAWidthChangeMakesTheTypesCorridorsStale(): void
    {
        $this->patrol('{"type":"LineString","coordinates":[[-30.0,-2.91],[-29.9,-2.91]]}');
        $this->rebuild();

        $this->em->getConnection()->executeStatement('UPDATE patrol_type SET coverage_buffer_m = 400');

        self::assertStringContainsString('1 patrol buffered.', $this->rebuild()->getDisplay());
        self::assertSame(400, self::whole($this->em->getConnection()->fetchOne('SELECT width_m FROM patrol_corridor')));
    }

    public function testABatchSizeThatIsNotAWholeNumberIsRefused(): void
    {
        $tester = $this->rebuild(['--batch-size' => '0']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--batch-size is a whole number', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function rebuild(array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('patrol:coverage:rebuild'));
        $tester->execute($input);

        return $tester;
    }

    private function corridors(): int
    {
        return self::whole($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM patrol_corridor'));
    }

    private function patrol(?string $track, PatrolStatusEnum $status = PatrolStatusEnum::Complete): void
    {
        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable('2026-03-10T06:00:00Z'))
            ->setStatus($status)
            ->setTrack($track));
        $this->em->flush();
    }

    private static function whole(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
