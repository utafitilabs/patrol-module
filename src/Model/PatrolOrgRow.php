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

use Uhifadhi\Patrol\Entity\Patrol;

/**
 * ONE PATROL ON THE ORGANIZATION'S LIST — the area's own row with the one fact
 * an area's list has no need of: which area, and its place in the declared
 * order the swatch is drawn from.
 */
final readonly class PatrolOrgRow
{
    /**
     * @param array<string, array{label: string, bufferM: int|null}> $types the area's own vocabulary, which the row's type chip is labelled from
     */
    public function __construct(
        public Patrol $patrol,
        public string $areaName,
        public string $areaUuid,
        public int $position,
        public array $types,
    ) {
    }
}
