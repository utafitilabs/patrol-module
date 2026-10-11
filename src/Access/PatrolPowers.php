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
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Security\PatrolWriteVoter;

/**
 * THE MODULE'S POWERS OVER SOMEBODY ELSE'S RECORD. A patrol is evidence of who
 * was where, so the Permissions page shows who may change one they did not
 * record:
 *
 *  - adding to or completing another ranger's patrol — its track,
 *    observations, events, flights, photographs and completion, every write
 *    the handset makes — asked of the colleague's most recently recorded
 *    patrol: one row, however many they have led;
 *  - correcting an observation on it, the signed amendment the web appends,
 *    asked as its route asks: `patrols.manage` of the area that patrol ran in.
 *
 * @see PatrolWriteVoter the question it asks
 */
final readonly class PatrolPowers implements PowerSourceInterface
{
    public const string WRITE_ANOTHER_RANGERS_PATROL = 'add-to-or-complete-another-rangers-patrol';
    public const string AMEND_ANOTHER_RANGERS_OBSERVATION = 'correct-an-observation-on-another-rangers-patrol';

    /** The pair the amendment route asks of the area. */
    private const string MANAGE = PatrolConcerns::PATROLS.'.'.Verb::Manage->value;

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

        yield new Power(
            self::AMEND_ANOTHER_RANGERS_OBSERVATION,
            PowerGroup::OthersRecords,
            'correct an observation on another ranger’s patrol',
            [self::MANAGE],
            [PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea],
        );
    }

    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed
    {
        if (!$target instanceof UserInterface) {
            return null;
        }

        $latest = $this->patrols->findOneBy(['lead' => $target], ['id' => 'DESC']);

        return self::AMEND_ANOTHER_RANGERS_OBSERVATION === $power->key ? $latest?->getArea() : $latest;
    }
}
