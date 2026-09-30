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

namespace Uhifadhi\Patrol\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;

/**
 * WHO MAY WRITE TO A PATROL ALREADY RECORDED (ruled 30 Sep, #67, class 6).
 *
 * Only the ranger who recorded it - its lead, which the handset's first sync
 * sets to whoever sent it - and the tiers above the matrix. Anybody else who
 * records patrols in the same area is refused: a patrol is evidence of who
 * was where and what they saw, and it is not a shared document. The crew on
 * a shared patrol joins this rule when patrols carry a crew (#42).
 *
 * THE TIERS ARE READ FROM THE TOKEN'S ROLES, not from a class of the Team:
 * a module knows the platform's roles and never its entities' methods.
 *
 * @see https://symfony.com/doc/current/security/voters.html
 *
 * @extends Voter<string, Patrol>
 */
final class PatrolWriteVoter extends Voter
{
    public const string WRITE = 'patrol.write';

    private const array TIERS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::WRITE === $attribute && $subject instanceof Patrol;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ([] !== array_intersect(self::TIERS, $token->getRoleNames())) {
            return true;
        }

        $caller = $token->getUser();
        $lead = $subject->getLead();
        if ($caller instanceof UserInterface && $lead instanceof UserInterface
            && null !== $lead->getId() && $lead->getId() === $caller->getId()) {
            return true;
        }

        $vote?->addReason('only the ranger who recorded a patrol writes to it.');

        return false;
    }
}
