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

namespace Uhifadhi\Patrol\Tests\Unit\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Facts\PatrolFactPeriod;

#[CoversClass(PatrolFactPeriod::class)]
final class PatrolFactPeriodTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function windows(): iterable
    {
        yield 'a calendar month is itself' => ['2026-03-01', '2026-04-01', '2026-03'];
        yield 'a calendar quarter is itself' => ['2026-07-01', '2026-10-01', '2026-Q3'];
        yield 'a calendar year is itself' => ['2026-01-01', '2027-01-01', '2026'];
        yield 'thirty rolling days are the month holding their last day' => ['2026-08-27', '2026-09-26', '2026-09'];
        yield 'ninety rolling days are the quarter holding their last day' => ['2026-06-28', '2026-09-26', '2026-Q3'];
        yield 'a rolling year is the year holding its last day' => ['2025-09-26', '2026-09-26', '2026'];
        yield 'a window ending at midnight ends the day before' => ['2026-03-05', '2026-04-01', '2026-03'];
    }

    #[DataProvider('windows')]
    public function testAWindowIsAnsweredByTheLedgerPeriodThatMatchesIt(string $from, string $until, string $key): void
    {
        self::assertSame($key, PatrolFactPeriod::answering(new \DateTimeImmutable($from), new \DateTimeImmutable($until))->key);
    }
}
