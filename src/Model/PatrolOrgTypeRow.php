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
 * ONE PATROL TYPE ACROSS THE ORGANIZATION — the word on the left of the
 * matrix, and every area's answer for it on the right.
 *
 * A TYPE IS THE AREA'S, so the same key may sit on different bases and
 * buffers in two areas; this row keeps every area's own values rather than
 * picking one.
 */
final readonly class PatrolOrgTypeRow
{
    /**
     * @param list<PatrolOrgTypeCell> $cells one per area in scope, in the declared order
     */
    public function __construct(
        public string $key,
        public string $label,
        /** Its place among the organization's types, which the dot is drawn from. */
        public int $position,
        public array $cells,
    ) {
    }

    public function thisMonth(): int
    {
        return array_sum(array_map(static fn (PatrolOrgTypeCell $cell): int => $cell->thisMonth, $this->cells));
    }

    /** The areas that run the type: keep it and have not retired it. */
    public function areasRunning(): int
    {
        return \count(array_filter($this->cells, static fn (PatrolOrgTypeCell $cell): bool => $cell->kept && $cell->active));
    }

    /** The areas that keep the word but have retired it. */
    public function retiredIn(): int
    {
        return \count(array_filter($this->cells, static fn (PatrolOrgTypeCell $cell): bool => $cell->kept && !$cell->active));
    }

    public function isRetired(): bool
    {
        return 0 === $this->areasRunning();
    }

    public function baseNotSetSomewhere(): bool
    {
        return [] !== array_filter($this->cells, static fn (PatrolOrgTypeCell $cell): bool => $cell->baseNotSet());
    }

    /**
     * The bases the areas keeping it run it on, each named once.
     *
     * @return list<string>
     */
    public function baseLabels(): array
    {
        $labels = [];
        foreach ($this->cells as $cell) {
            if (null !== $cell->base) {
                $labels[$cell->base->value] = $cell->base->label();
            }
        }

        return array_values($labels);
    }
}
