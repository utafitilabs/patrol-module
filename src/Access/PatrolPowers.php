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

namespace Uhifadhi\Patrol\Access;

use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerSourceInterface;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Security\PatrolWriteVoter;

/**
 * THE MODULE'S ONE POWER OVER SOMEBODY ELSE'S RECORD: adding to or completing
 * another ranger's patrol — its track, observations, events, flights,
 * photographs and completion, every write the handset makes. A patrol is
 * evidence of who was where, so the Permissions page shows who may write to
 * one they did not record.
 *
 * It is asked of the colleague's most recently recorded patrol: one row,
 * however many they have led.
 *
 * @see PatrolWriteVoter the question it asks
 */
final readonly class PatrolPowers implements PowerSourceInterface
{
    public const string WRITE_ANOTHER_RANGERS_PATROL = 'add-to-or-complete-another-rangers-patrol';

    public function __construct(
        private PatrolRepository $patrols,
    ) {
    }

    public function powers(): iterable
    {
        yield new Power(
            self::WRITE_ANOTHER_RANGERS_PATROL,
            PowerGroup::OthersRecords,
            'add to or complete another ranger’s patrol',
            [PatrolWriteVoter::WRITE],
            [PowerTarget::AColleague],
        );
    }

    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed
    {
        if (!$target instanceof UserInterface) {
            return null;
        }

        return $this->patrols->findOneBy(['lead' => $target], ['id' => 'DESC']);
    }
}
