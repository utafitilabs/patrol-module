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

namespace Uhifadhi\Patrol\Tests\Unit\Access;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerSourceInterface;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Patrol\Access\PatrolPowers;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Security\PatrolWriteVoter;

/**
 * THE MODULE'S ONE POWER OVER SOMEBODY ELSE'S RECORD, as the Permissions page
 * reads it: adding to or completing another ranger's patrol, asked with the
 * question every handset write asks.
 */
final class PatrolPowersTest extends TestCase
{
    public function testItDeclaresOnePowerOverAnotherRangersPatrol(): void
    {
        $powers = [...$this->source()->powers()];

        self::assertCount(1, $powers);
        $power = $powers[0];
        self::assertInstanceOf(Power::class, $power);
        self::assertSame('add-to-or-complete-another-rangers-patrol', $power->key);
        self::assertSame(PowerGroup::OthersRecords, $power->group);
        self::assertSame('add to or complete another ranger’s patrol', $power->label);
        self::assertSame([PatrolWriteVoter::WRITE], $power->questions);
        self::assertSame([PowerTarget::AColleague], $power->targets);
    }

    /**
     * Only a person has patrols. Asked about nothing in particular, or about
     * something that is not a person, there is no patrol to ask about, and
     * the repository is not asked either.
     */
    public function testAQuestionAboutSomethingOtherThanAPersonHasNoSubject(): void
    {
        $source = $this->source();
        $power = [...$source->powers()][0];

        self::assertNull($source->subject($power, PatrolWriteVoter::WRITE, PowerTarget::AColleague, null));
        self::assertNull($source->subject($power, PatrolWriteVoter::WRITE, PowerTarget::AColleague, new \stdClass()));
    }

    public function testItIsAPowerSource(): void
    {
        self::assertInstanceOf(PowerSourceInterface::class, $this->source());
    }

    private function source(): PatrolPowers
    {
        // A registry nobody may ask: the repository resolves itself lazily,
        // so a question that reached it would fail this test.
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method(self::anything());

        return new PatrolPowers(new PatrolRepository($registry));
    }
}
