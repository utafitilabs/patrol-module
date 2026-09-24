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

namespace Uhifadhi\Patrol\Model;

/**
 * WHAT THE PATROLS MODULE HAS TO SAY AT ORGANIZATION SCOPE, at one moment.
 *
 * IT IS THE AREA READING ADDED UP, NEVER A SECOND AGGREGATE. Every field here
 * is the per-area answer the module already publishes on `/areas/{uuid}`,
 * taken over the areas a {@see \Uhifadhi\Contracts\Shell\Scope} names — the
 * rule the organization dashboard's contract is built on. A figure measured a
 * second way would be a second answer to one question, with nothing to say
 * which of them was right.
 *
 * ABSENT IS NOT ZERO, throughout, and at this scope the distinction gets a
 * field of its own: `areasWithRegister` is how many areas have ever opened a
 * patrol. Nought of them means the module has measured nothing, which is a
 * different statement from an organization that walked nowhere this week.
 */
final readonly class PatrolOrgReading
{
    /**
     * @param list<PatrolOutRow> $out               the patrols open right now, longest out first
     * @param float|null         $walkedTodayKm     null where nothing that closed today recorded a distance
     * @param int                $thisWeek          patrols counted since monday, by the module's own counting rule
     * @param int                $areasWithRegister how many areas in scope have ever opened a patrol
     * @param int                $areasInScope      how many areas the scope names at all
     * @param string|null        $dashboardUrl      the module's own page for these rows, where ONE page answers for them
     */
    public function __construct(
        public array $out,
        public ?float $walkedTodayKm,
        public int $thisWeek,
        public int $areasWithRegister,
        public int $areasInScope,
        public ?string $dashboardUrl,
    ) {
    }

    /** Whether this module has measured anything at all here. */
    public function measured(): bool
    {
        return $this->areasWithRegister > 0;
    }

    /**
     * The patrols open right now, BOUNDED — a dashboard cell is a reading, not
     * an archive, and its height may not grow with the day.
     *
     * @return list<PatrolOutRow>
     */
    public function shown(int $rows): array
    {
        return \array_slice($this->out, 0, $rows);
    }
}
