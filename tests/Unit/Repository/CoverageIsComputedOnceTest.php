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
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * THE COVERAGE IS COMPUTED ONCE PER QUERY. Every WITH query that unions the
 * buffered tracks is joined to many rows — one per zone — and the planner
 * folds a once-referenced WITH query into that join unless told not to,
 * recomputing the union per row. The word that tells it is MATERIALIZED
 * (https://www.postgresql.org/docs/current/queries-with.html, "CTE
 * Materialization"), and this holds it on every such query in the repository:
 * a long patrol over a zoned area is a page that answers in under a second,
 * not one that dies at the execution limit.
 */
#[CoversNothing]
final class CoverageIsComputedOnceTest extends TestCase
{
    public function testEveryUnionOfBufferedTracksIsAMaterializedWithQuery(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(PatrolRepository::class))->getFileName());

        preg_match_all('/(\w+) AS (MATERIALIZED )?\(\s*SELECT[^()]*?ST_Union\(ST_Buffer\(/s', $source, $found, \PREG_SET_ORDER);

        self::assertNotEmpty($found, 'the repository unions buffered tracks somewhere');
        $unmaterialized = [];
        foreach ($found as $match) {
            if ('' === ($match[2] ?? '')) {
                $unmaterialized[] = $match[1];
            }
        }
        self::assertSame([], $unmaterialized, 'a WITH query that unions buffered tracks is computed once, not once per zone');
        self::assertCount(2, $found, 'the two coverage queries the repository holds');
    }
}
