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

namespace Uhifadhi\Patrol\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Patrol\Access\PatrolConcerns;

/**
 * THE INSTALLATION'S END OF THE ACCESS MODEL, played by a fixture.
 *
 * This bundle DECLARES its concerns ({@see PatrolConcerns}) and grants them to
 * nobody: which positions hold which pairs is an organization's business, and
 * the core's own `GrantVoter` answers it from a position and a placement. Most
 * of this suite is about what a screen DOES once somebody may open it, so it
 * would rather say "this person may record here" in one line than compose a
 * position, a grant set and a placement per test.
 *
 * THREE TIERS, AND THE SPLIT IS THE POINT. A blanket "may do everything" stub
 * could never show that logging a patrol is not enough to name the words
 * everybody else must use, nor that reading the register is not enough to
 * carry it out of the building. So:
 *
 *   - THE BYSTANDER reads, and only reads.
 *   - THE RECORDER reads, records, and exports what they can read.
 *   - THE MANAGER reads, exports, acts on records somebody else made, and
 *     names the words: the types, the stations, the observation kinds and the
 *     two thresholds the area runs on.
 *
 * THE PAIRS COME FROM THE DECLARATIONS, never from a typed string — this
 * module's from {@see PatrolConcerns} and the ground's from
 * {@see AreaConcerns}. A fixture that spelled them by hand would keep passing
 * after a rename and prove nothing.
 *
 * THE GROUND QUESTION IS NOT ASKED HERE, deliberately. This decides by tier
 * and ignores the subject, so the area a gate is asked with is proved
 * somewhere it can be proved properly: {@see \Uhifadhi\Patrol\Tests\Functional\RouteByComposedPositionTest}
 * composes a real position and a real placement and drives the routes as
 * somebody holding exactly the right pairs at a DIFFERENT area.
 *
 * @extends Voter<string, mixed>
 */
final class FixedRecordVoter extends Voter
{
    /** Reads the module, and nothing else. */
    public const string BYSTANDER_EMAIL = 'bystander@example.test';

    /** May record a patrol, and may NOT name the words or act on other people's records. */
    public const string RECORDER_EMAIL = 'recorder@example.test';

    /** May configure this area's patrol vocabulary, and manage records somebody else made. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return [] !== array_filter(
            self::tiers(),
            static fn (array $pairs): bool => \in_array($attribute, $pairs, true),
        );
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /*
         * SOMEBODY THE TEST ACTUALLY COMPOSED IS THE REAL VOTER'S BUSINESS.
         * A user holding a position was given one on purpose, with grants and
         * a placement, so that the core's GrantVoter can answer for them —
         * this fixture stands aside rather than handing them a tier they were
         * never meant to have and masking the answer under test.
         */
        if (null !== $user->getPosition()) {
            return false;
        }

        $email = (string) $user->getEmail();

        // ANYBODY SIGNED IN READS. This installation lets its whole team read
        // the module and reserves the decisions; a suite full of ad-hoc people
        // - a lead, a ranger, somebody else's account - would otherwise have to
        // be enrolled one by one before a page would open. What a READ gate
        // actually costs is proved where it can be proved properly, against a
        // real position and a real placement.
        return \in_array($attribute, self::tiers()[$email] ?? self::reads(), true);
    }

    /**
     * What each tier holds, spelt from the declarations.
     *
     * @return array<string, list<string>>
     */
    private static function tiers(): array
    {
        $reads = self::reads();

        $recorder = [
            ...$reads,
            self::pair(PatrolConcerns::PATROLS, Verb::Record),
            self::pair(PatrolConcerns::PATROLS, Verb::Export),
        ];

        $manager = [
            ...$reads,
            self::pair(PatrolConcerns::PATROLS, Verb::Manage),
            self::pair(PatrolConcerns::PATROLS, Verb::Configure),
            self::pair(PatrolConcerns::PATROLS, Verb::Export),
            self::pair(PatrolConcerns::TYPES, Verb::Configure),
            self::pair(PatrolConcerns::OBSERVATION_KINDS, Verb::Configure),
        ];

        return [
            self::BYSTANDER_EMAIL => $reads,
            self::RECORDER_EMAIL => $recorder,
            self::MANAGER_EMAIL => $manager,
        ];
    }

    /**
     * What anybody signed in holds: every read this module declares, and the
     * ground's own - the pairs spelt from {@see PatrolConcerns} and
     * {@see AreaConcerns} rather than typed out.
     *
     * @return list<string>
     */
    private static function reads(): array
    {
        return [
            self::pair(PatrolConcerns::PATROLS, Verb::Read),
            self::pair(PatrolConcerns::TYPES, Verb::Read),
            self::pair(AreaConcerns::AREAS, Verb::Read),
        ];
    }

    private static function pair(string $concern, Verb $verb): string
    {
        return (string) Grant::of($concern, $verb);
    }
}
