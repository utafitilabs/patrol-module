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
 * THE ORGANIZATION'S LIST, ANSWERED — every row the filter leaves across the
 * areas in scope, and the counts beside every option, each the areas' own
 * counts added.
 */
final readonly class PatrolOrgList
{
    /**
     * @param list<PatrolOrgRow>                                  $rows          every matching row, newest first, unpaged
     * @param array<string, string>                               $typeLabels    type key → the label the first area to keep it gives it
     * @param array<string, int>                                  $typeCounts    type key → the rows that type would leave
     * @param array<string, string>                               $stations      station key → name, the stations of the areas in scope
     * @param array<string, int>                                  $stationCounts station key → the rows that station would leave
     * @param list<array{uuid: string, name: string, count: int}> $areaCounts    every area the reader is offered, with the rows it would leave
     */
    public function __construct(
        public array $rows,
        public int $allCount,
        public array $typeLabels,
        public array $typeCounts,
        public array $stations,
        public array $stationCounts,
        public array $areaCounts,
    ) {
    }
}
