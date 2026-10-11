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

namespace Uhifadhi\Patrol\Tests\Integration\Access;

use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PowerCatalogue;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Patrol\Access\PatrolPowers;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Security\PatrolWriteVoter;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE POWER AS THE PERMISSIONS PAGE ASKS IT: found in the installation's
 * catalogue through its tag, its subject a real patrol of the colleague the
 * page found, and its question answered by the real voter for the account
 * being asked about — through isGrantedForUser(), with nobody signed in.
 */
final class PatrolPowersTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Kilimani');
        $this->em->persist($this->area);
        $this->em->flush();
    }

    public function testTheTagPutsThePowerInTheInstallationsCatalogue(): void
    {
        $catalogue = $this->service(PowerCatalogue::class);
        self::assertInstanceOf(PowerCatalogue::class, $catalogue);

        $power = $this->power();
        self::assertSame(PatrolPowers::class, $catalogue->sourceOf($power)::class);
        self::assertContains(PatrolWriteVoter::WRITE, $catalogue->questions());
    }

    public function testTheSubjectIsTheColleaguesLatestPatrol(): void
    {
        $colleague = $this->person('Naserian', 'Lekishon');
        $this->patrol($colleague);
        $latest = $this->patrol($colleague);
        $this->patrol($this->person('Lomayani', 'Laizer'));

        $subject = $this->powers()->subject($this->power(), PatrolWriteVoter::WRITE, PowerTarget::AColleague, $colleague);

        self::assertInstanceOf(Patrol::class, $subject);
        self::assertSame($latest->getId(), $subject->getId());
    }

    public function testAColleagueWhoNeverLedAPatrolGivesNothingToAskAbout(): void
    {
        $colleague = $this->person('Naserian', 'Lekishon');
        $this->patrol($this->person('Lomayani', 'Laizer'));

        self::assertNull($this->powers()->subject($this->power(), PatrolWriteVoter::WRITE, PowerTarget::AColleague, $colleague));
    }

    /**
     * THE QUESTION IS ANSWERED FOR THE ACCOUNT ASKED ABOUT, not for whoever
     * holds the session — there is no session here at all. The lead writes
     * to their own patrol; another ranger does not; the tiers do.
     */
    public function testTheVoterAnswersForTheAccountItIsAskedAbout(): void
    {
        $colleague = $this->person('Naserian', 'Lekishon');
        $this->patrol($colleague);
        $ranger = $this->person('Lomayani', 'Laizer');
        $admin = $this->person('Upendo', 'Massawe', TeamRoleEnum::Admin);
        $superAdmin = $this->person('Asha', 'Kweka', TeamRoleEnum::SuperAdmin);

        $subject = $this->powers()->subject($this->power(), PatrolWriteVoter::WRITE, PowerTarget::AColleague, $colleague);
        $checker = static::getContainer()->get('security.authorization_checker');
        self::assertInstanceOf(UserAuthorizationCheckerInterface::class, $checker);

        self::assertTrue($checker->isGrantedForUser($colleague, PatrolWriteVoter::WRITE, $subject), 'the lead writes to their own patrol');
        self::assertFalse($checker->isGrantedForUser($ranger, PatrolWriteVoter::WRITE, $subject), 'another ranger does not');
        self::assertTrue($checker->isGrantedForUser($admin, PatrolWriteVoter::WRITE, $subject), 'an Admin does');
        self::assertTrue($checker->isGrantedForUser($superAdmin, PatrolWriteVoter::WRITE, $subject), 'a Super Admin does');
    }

    /** A correction is asked of the area the colleague's latest patrol ran in, the pair the amendment route asks. */
    public function testTheAmendmentIsAskedOfTheAreaTheColleaguesLatestPatrolRanIn(): void
    {
        $colleague = $this->person('Naserian', 'Lekishon');
        $this->patrol($colleague);

        $amend = $this->power(PatrolPowers::AMEND_ANOTHER_RANGERS_OBSERVATION);
        $subject = $this->powers()->subject($amend, 'patrols.manage', PowerTarget::AColleague, $colleague);

        self::assertInstanceOf(AreaOfInterest::class, $subject);
        self::assertSame($this->area->getId(), $subject->getId());
        self::assertNull($this->powers()->subject($amend, 'patrols.manage', PowerTarget::AColleague, $this->person('Lomayani', 'Laizer')), 'nobody who never led a patrol made an observation to correct');
    }

    private function powers(): PatrolPowers
    {
        $powers = $this->service(PatrolPowers::class);
        self::assertInstanceOf(PatrolPowers::class, $powers);

        return $powers;
    }

    private function power(string $key = PatrolPowers::WRITE_ANOTHER_RANGERS_PATROL): Power
    {
        $catalogue = $this->service(PowerCatalogue::class);
        self::assertInstanceOf(PowerCatalogue::class, $catalogue);

        $found = array_values(array_filter($catalogue->all(), static fn (Power $power): bool => $key === $power->key));
        self::assertCount(1, $found, 'the power is in the catalogue once');

        return $found[0];
    }

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = new User()->setPassword('x')
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last)
            ->setTeamRole($tier);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function patrol(User $lead): Patrol
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'foot'))
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('2026-09-01 07:00:00'));
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }
}
