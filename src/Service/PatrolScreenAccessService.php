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

namespace Uhifadhi\Patrol\Service;

use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\AreaInterface;
use Uhifadhi\Patrol\Access\PatrolConcerns;

/**
 * WHETHER TO DRAW A DOOR — asked in one place, so no screen of this module
 * asks a different question from the gate behind the link it is drawing.
 *
 * IT GOES THROUGH THE CORE'S {@see Door}. Every door in the product names the
 * pair the thing behind it enforces, and a test can then walk the doors and
 * hold them against the routes. A module that asked the authorization checker
 * itself would be outside that proof.
 *
 * EVERY QUESTION CARRIES ITS AREA, and that is not a convenience. A pair
 * asked with no subject means "no area in context", which any placement
 * reaching any ground at all satisfies — so a door asked without the ground
 * would be answering a different question from the gate, and would draw
 * somebody a control that then refuses. Every screen of this module is drawn
 * under one area, so every one of these takes it.
 *
 * A CONTROL THE VIEWER MAY NOT HAVE IS ABSENT, never greyed out — a disabled
 * button tells a ranger a screen exists and they are not trusted with it, and a
 * live link that fails tells them nothing until they have lost the click.
 */
final readonly class PatrolScreenAccessService
{
    public function __construct(
        private Door $door,
    ) {
    }

    /** The entry flow: importing a track, logging a patrol by hand. */
    public function mayRecord(AreaInterface $area): bool
    {
        return $this->door->opensFor(PatrolConcerns::PATROLS, Verb::Record, $area);
    }

    /**
     * Acting on a record somebody else made: holding a discarded patrol back
     * from the purge, appending a signed correction to an observation.
     */
    public function mayManage(AreaInterface $area): bool
    {
        return $this->door->opensFor(PatrolConcerns::PATROLS, Verb::Manage, $area);
    }

    /** The two thresholds this area runs patrols on. */
    public function mayConfigure(AreaInterface $area): bool
    {
        return $this->door->opensFor(PatrolConcerns::PATROLS, Verb::Configure, $area);
    }

    /** Naming the types an area patrols by. */
    public function mayConfigureTypes(AreaInterface $area): bool
    {
        return $this->door->opensFor(PatrolConcerns::TYPES, Verb::Configure, $area);
    }

    /** Naming the words a ranger logs an observation against. */
    public function mayConfigureObservationKinds(AreaInterface $area): bool
    {
        return $this->door->opensFor(PatrolConcerns::OBSERVATION_KINDS, Verb::Configure, $area);
    }

    /** Carrying the log or a track out of the building. */
    public function mayExport(AreaInterface $area): bool
    {
        return $this->door->opensFor(PatrolConcerns::PATROLS, Verb::Export, $area);
    }
}
