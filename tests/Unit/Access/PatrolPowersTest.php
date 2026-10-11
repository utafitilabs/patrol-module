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
 * THE MODULE'S POWERS OVER SOMEBODY ELSE'S RECORD, as the Permissions page
 * reads them: adding to or completing another ranger's patrol, asked with the
 * question every handset write asks, and correcting an observation on it.
 */
final class PatrolPowersTest extends TestCase
{
    public function testItDeclaresOnePowerOverAnotherRangersPatrol(): void
    {
        $power = $this->powerKeyed(PatrolPowers::WRITE_ANOTHER_RANGERS_PATROL);

        self::assertSame(PowerGroup::OthersRecords, $power->group);
        self::assertSame('add to or complete another ranger’s patrol', $power->label);
        self::assertSame([PatrolWriteVoter::WRITE], $power->questions);
        self::assertSame([PowerTarget::AColleague], $power->targets);
    }

    /**
     * A CORRECTION IS SIGNED ONTO SOMEBODY ELSE'S RECORD. Whoever holds
     * `patrols.manage` at the area appends one to an observation another
     * ranger made, so the Permissions page lists who may, asked of the area
     * the colleague patrols, for a colleague and for somebody beyond the
     * asker's area alike.
     */
    public function testItDeclaresThePowerToAmendAnotherRangersObservation(): void
    {
        $power = $this->powerKeyed(PatrolPowers::AMEND_ANOTHER_RANGERS_OBSERVATION);

        self::assertSame(PowerGroup::OthersRecords, $power->group);
        self::assertSame('correct an observation on another ranger’s patrol', $power->label);
        self::assertSame(['patrols.manage'], $power->questions);
        self::assertSame([PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea], $power->targets);
    }

    public function testItDeclaresNoOtherPower(): void
    {
        self::assertCount(2, [...$this->source()->powers()]);
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

    private function powerKeyed(string $key): Power
    {
        foreach ($this->source()->powers() as $power) {
            if ($key === $power->key) {
                return $power;
            }
        }

        self::fail('no power keyed '.$key);
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
