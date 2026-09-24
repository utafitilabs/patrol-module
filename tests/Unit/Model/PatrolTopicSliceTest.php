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

namespace Uhifadhi\Patrol\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Model\PatrolTopicSlice;

/**
 * THE SLICING RULE, ON ITS OWN — no kernel and no database, because the
 * intersection of two scopes is arithmetic and a test of it should fail in
 * milliseconds where somebody would edit it.
 */
final class PatrolTopicSliceTest extends TestCase
{
    private const string KILIMANI = '0198f0a0-0000-7000-8000-0000000000a1';
    private const string OLKEJU = '0198f0a0-0000-7000-8000-0000000000a2';

    public function testTheOrganizationsPageReadsEveryAreaForAnOrganizationWideDepartment(): void
    {
        $slice = PatrolTopicSlice::of(null, null);

        self::assertNotNull($slice);
        self::assertNull($slice->areaUuid);
        self::assertTrue($slice->isRollUp());
    }

    public function testTheOrganizationsPageReadsOneAreaForADepartmentConfinedToIt(): void
    {
        $slice = PatrolTopicSlice::of(null, self::KILIMANI);

        self::assertNotNull($slice);
        self::assertSame(self::KILIMANI, $slice->areaUuid);
        self::assertFalse($slice->isRollUp());
    }

    public function testAnAreasPageNarrowsAnOrganizationWideDepartmentToThatArea(): void
    {
        $slice = PatrolTopicSlice::of(self::KILIMANI, null);

        self::assertNotNull($slice);
        self::assertSame(self::KILIMANI, $slice->areaUuid, 'An org-wide department reads this area too, but the page is about this area.');
    }

    public function testAnAreasPageReadsTheAreaForItsOwnDepartment(): void
    {
        $slice = PatrolTopicSlice::of(self::KILIMANI, self::KILIMANI);

        self::assertNotNull($slice);
        self::assertSame(self::KILIMANI, $slice->areaUuid);
    }

    public function testADepartmentConfinedElsewhereIsNoRowOfThisPage(): void
    {
        self::assertNull(
            PatrolTopicSlice::of(self::KILIMANI, self::OLKEJU),
            'A department of another area is not a row with no figures — it is not a row.',
        );
    }
}
