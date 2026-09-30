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

namespace Uhifadhi\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Patrol\Deletion\PersonPatrolDeletion;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * A SUPER ADMIN DELETES A PATROL (ruled 28 Sep, #48, design C, drawn for a
 * patrol): what goes counted and named on the core's one delete page, the
 * reference typed, the patrol and everything under it gone, one audit line
 * kept. And a deleted person's patrols go with them.
 */
final class PatrolDeletionTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private User $ada;
    private Patrol $patrol;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $schema->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);
        $this->ada = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($this->ada);
        $this->patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($this->ada)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setSource(PatrolSourceEnum::Manual);
        $this->em->persist($this->patrol);
        $this->em->persist(new Observation($this->patrol, 'maintenance')->setNote('Fence line down.')->setLoggedAt(new \DateTimeImmutable('today 08:15'))->setRecordedBy($this->ada));
        $this->em->flush();
        $this->everyAreaRunsPatrols($this->em);
    }

    public function testASuperAdminDeletesAPatrolAndOneLineIsKept(): void
    {
        $this->signInAs(TeamRoleEnum::SuperAdmin);

        $page = $this->client->request('GET', $this->deleteUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Delete patrol '.$this->patrol->getRef(), $page->filter('h1')->text());
        self::assertSame(['1', '1'], $page->filter('.dcounts .dcnt b')->each(static fn ($c): string => $c->text()), '1 patrol, 1 observation');
        self::assertStringContainsString('Fence line down.', $page->filter('.dlist')->text());

        $this->client->submit($page->filter('form.dconfirm')->form(['reference' => $this->patrol->getRef()]));

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Patrol::class)->findAll());
        self::assertSame([], $this->em->getRepository(Observation::class)->findAll());
        $kept = $this->em->getRepository(DeletionRecord::class)->findAll();
        self::assertCount(1, $kept);
        self::assertStringStartsWith('Patrol P-', $kept[0]->getTitle());
    }

    public function testAnAdminSeesNoDeleteActionAndIsRefusedThePage(): void
    {
        $this->signInAs(TeamRoleEnum::Admin);

        $record = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$this->patrol->getUuid());
        self::assertCount(0, $record->filter('a[href$="/delete"]'));
        $this->client->request('GET', $this->deleteUrl());
        self::assertResponseStatusCodeSame(403);
    }

    /** A deleted person's patrols go with them, counted first (ruled 28 Sep, #48). */
    public function testTheLeadsPatrolsGoWithThem(): void
    {
        $other = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))->setSource(PatrolSourceEnum::Manual)->setStartedAt(new \DateTimeImmutable('today 05:00'));
        $this->em->persist($other);
        $this->em->persist(new Observation($other, 'maintenance')->setNote('Logged on somebody else\'s patrol.')->setRecordedBy($this->ada));
        $this->em->flush();
        $person = static::getContainer()->get('patrol.deletion.person');
        self::assertInstanceOf(PersonPatrolDeletion::class, $person);

        self::assertSame(['1 patrol they led', '1 observation on another’s patrol'], array_map(static fn ($l): string => $l->phrase(), $person->whatGoes($this->ada)));
        $person->delete($this->ada);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Patrol::class)->findAll(), 'the other patrol stays');
        self::assertSame([], $this->em->getRepository(Observation::class)->findAll(), 'both of Ada\'s observations are gone');
    }

    private function signInAs(TeamRoleEnum $tier): void
    {
        $who = new User()->setPassword('x')->setEmail($tier->value.'@example.test')->setFirstName('Naomi')->setLastName('Kileo')->setTeamRole($tier);
        $this->em->persist($who);
        $this->em->flush();
        $this->client->loginUser($who);
    }

    private function deleteUrl(): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$this->patrol->getUuid().'/delete';
    }
}
