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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Security\Reach;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WHO MAY BE NAMED AS A PATROL'S LEAD — the select the entry flow offers, and
 * the same answer when the form comes back.
 *
 * The lead owns the patrol and its figures are filed under them, so a lead is
 * somebody who works where the patrol ran: an active person whose placement
 * covers the patrol's area and names a department — somebody with none runs no
 * module — and whom the recorder's own placement reaches
 * ({@see Reach::reachesPerson()}). A recorder placed at one area reaches
 * nobody placed across the organization; a Super Admin or an Admin reaches
 * everybody, and still names nobody from another area.
 *
 * THE RECORDER IS ALWAYS OFFERED. Logging one's own patrol needs no reach.
 *
 * A posted id outside the offer is a stale form rather than somebody's intent,
 * and records no lead, the reading the station chips take.
 */
final readonly class PatrolLeadService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<UserInterface> by last name, then first */
    public function candidates(AreaOfInterest $area, ?UserInterface $recorder): array
    {
        $reach = new Reach($recorder instanceof User ? $recorder : null);

        $people = $this->entityManager->getRepository(UserInterface::class)
            ->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);

        return array_values(array_filter(
            $people,
            static fn (UserInterface $person): bool => self::isTheRecorder($person, $recorder)
                || ($person instanceof User
                    && $person->isActive()
                    && null !== ($placement = $person->getPlacement())
                    && !$placement->reachesNothing()
                    && $placement->coversArea($area)
                    && $reach->reachesPerson($person)),
        ));
    }

    /** The lead a form chose, or null when it chose nobody or somebody it was not offered. */
    public function chosen(AreaOfInterest $area, ?UserInterface $recorder, ?int $id): ?UserInterface
    {
        if (null === $id) {
            return null;
        }

        foreach ($this->candidates($area, $recorder) as $candidate) {
            if ($candidate->getId() === $id) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isTheRecorder(UserInterface $person, ?UserInterface $recorder): bool
    {
        return null !== $recorder && null !== $person->getId() && $person->getId() === $recorder->getId();
    }
}
