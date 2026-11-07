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

use Uhifadhi\Patrol\Enum\PatrolBaseEnum;

/**
 * ONE AREA'S ANSWER FOR ONE PATROL TYPE — whether it keeps the word at all,
 * on which base and buffer, and how much it logged under it.
 */
final readonly class PatrolOrgTypeCell
{
    public function __construct(
        public string $areaName,
        public string $areaUuid,
        public int $position,
        /** False where the area keeps no type of this key: "not in use" there. */
        public bool $kept,
        public bool $active = false,
        public ?PatrolBaseEnum $base = null,
        public ?int $bufferM = null,
        public int $thisMonth = 0,
        public int $allTime = 0,
    ) {
    }

    public function baseNotSet(): bool
    {
        return $this->kept && null === $this->base;
    }
}
