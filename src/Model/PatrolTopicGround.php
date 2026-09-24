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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Kpi\FigurePeriod;

/**
 * THE GROUND A SLICE OF THE PERFORMANCE PAGE IS ACTUALLY MEASURED OVER — the
 * areas of it that run this module, and since when.
 *
 * WHY "SINCE WHEN" TRAVELS WITH IT. Every period before the module was
 * installed over this ground is a period nobody was recording, and a figure
 * drawn as nought there is a collapse the organization never had. The
 * distinction has to be made wherever a run of periods is built, so the fact
 * that decides it rides along with the ground rather than being fetched again
 * at each call site.
 *
 * `$within` is the single area a share is measured against, and NULL where the
 * ground rolls several areas up — read as one share of all their boundaries
 * combined, never as a mean of their shares.
 */
final readonly class PatrolTopicGround
{
    /**
     * @param list<AreaOfInterest>    $areas        every area of the slice that runs the module, by name
     * @param AreaOfInterest|null     $within       the one area a share is OF, null for a roll-up
     * @param \DateTimeImmutable|null $measuredFrom the earliest this module was installed over the ground, null where the ledger does not say
     */
    public function __construct(
        public array $areas,
        public ?AreaOfInterest $within = null,
        public ?\DateTimeImmutable $measuredFrom = null,
    ) {
    }

    /** No area here runs the module: the columns are nobody's to answer. */
    public function isUnrun(): bool
    {
        return [] === $this->areas;
    }

    /** Whether this module was recording over the ground at any instant of a period. */
    public function measured(FigurePeriod $period): bool
    {
        return null === $this->measuredFrom || $this->measuredFrom < $period->until;
    }
}
