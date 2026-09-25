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

namespace Uhifadhi\Patrol\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;

/**
 * A TRACK IS BUFFERED ONCE, BY THE WORKER, AND NEVER ON A READ.
 *
 * Buffering a long track in geography is the cost that held a page at the
 * execution limit on one long patrol. The rule this holds: the only ST_Buffer
 * in the module is the one that writes a patrol's stored corridor
 * ({@see PatrolCorridorRepository::buffer()}); every other query unions the
 * stored shapes. And the one query that unions them per area and clips the
 * union per zone computes each union once — a WITH query marked MATERIALIZED
 * (https://www.postgresql.org/docs/current/queries-with.html, "CTE
 * Materialization"), which the planner may otherwise fold into the join and
 * recompute per zone row.
 */
#[CoversNothing]
final class TracksAreBufferedOnceTest extends TestCase
{
    public function testTheOnlyBufferInTheModuleIsTheOneThatStoresACorridor(): void
    {
        $src = \dirname(__DIR__, 3).'/src/';
        $buffering = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $path = substr($file->getPathname(), \strlen($src));
            // The demo content shrinks an AREA's boundary to seed points inside
            // it, on the console, in development; it buffers no track.
            if ('php' !== $file->getExtension() || str_starts_with($path, 'Devkit/')) {
                continue;
            }
            $count = substr_count((string) file_get_contents($file->getPathname()), 'ST_Buffer(');
            if ($count > 0) {
                $buffering[$path] = $count;
            }
        }

        // Two calls in one statement: the type's width and the module's.
        self::assertSame(['Repository/PatrolCorridorRepository.php' => 2], $buffering);

        $source = (string) file_get_contents((string) new \ReflectionClass(PatrolCorridorRepository::class)->getFileName());
        $buffer = substr($source, (int) strpos($source, 'public function buffer('), (int) strpos($source, 'public function findPatrolIdsToBuffer(') - (int) strpos($source, 'public function buffer('));
        self::assertSame(2, substr_count($buffer, 'ST_Buffer('), 'both buffers are in the statement that writes the corridor');
    }

    public function testTheUnionClippedPerZoneIsComputedOncePerArea(): void
    {
        $source = (string) file_get_contents((string) new \ReflectionClass(PatrolCorridorRepository::class)->getFileName());

        preg_match_all('/(\w+) AS (MATERIALIZED )?\(\s*SELECT[^()]*?ST_Union\(/s', $source, $found, \PREG_SET_ORDER);

        self::assertCount(1, $found, 'the one WITH query that unions corridors');
        self::assertSame('MATERIALIZED ', $found[0][2] ?? '', 'a WITH query that unions corridors is computed once, not once per zone');
    }
}
