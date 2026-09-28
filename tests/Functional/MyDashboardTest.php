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
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;

/**
 * WHAT THIS MODULE PUTS ON A PERSON'S OWN PAGES, ASKED OF THE REAL PAGES (#19).
 *
 * `/` draws a person's own dashboard for somebody who may not read the areas,
 * and `/me/station` the post they are posted at. Both are the area's pages and
 * compose whatever is tagged, so the only honest test of a contribution is the
 * rendered page: the cards in their slots, the band on the post, and the sheet
 * the bars are drawn with in the head.
 *
 * THE PERSON HOLDS A POSITION THAT GRANTS NOTHING, which is what sends `/` to
 * their own page: the suite's fixture voter stands aside for anybody composed
 * with a position, and the core's own voter answers for them.
 */
final class MyDashboardTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testMyOwnDashboardCarriesMyPatrolFiguresAndCards(): void
    {
        [$me] = $this->aRangerPostedAtTheGate();
        $this->client->loginUser($me);

        $page = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('.md-figures [data-me="distance"]'));
        self::assertCount(1, $page->filter('.md-figures [data-me="patrols"]'));
        self::assertCount(1, $page->filter('.md-figures [data-me="observations"]'));
        self::assertCount(1, $page->filter('.md-beside [data-me="week"] .pl-me-bars'));
        self::assertCount(1, $page->filter('.md-beside [data-me="my-patrols"]'));
        self::assertCount(1, $page->filter('.md-row [data-me="my-observations"]'));
    }

    /** THE BARS ARE DRAWN BY THIS MODULE'S SHEET, and the head carries it because the page cannot know to. */
    public function testTheHeadCarriesTheSheetTheBarsAreDrawnWith(): void
    {
        [$me] = $this->aRangerPostedAtTheGate();
        $this->client->loginUser($me);

        $page = $this->client->request('GET', '/');

        $sheets = $page->filter('head link[rel="stylesheet"]')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertNotEmpty(array_filter($sheets, static fn (string $href): bool => str_contains($href, 'uhifadhipatrol/me')), 'The patrol module\'s own-page sheet is in the head.');
        self::assertEmpty(array_filter($sheets, static fn (string $href): bool => str_contains($href, substr(UhifadhiPatrolBundle::STYLESHEET, 0, -\strlen('.css')))), 'And not the whole module sheet, which is for the module\'s own screens.');
    }

    public function testMyStationShowsThePatrolsThatWentOutFromIt(): void
    {
        [$me, $patrol] = $this->aRangerPostedAtTheGate();
        $this->client->loginUser($me);

        $page = $this->client->request('GET', '/me/station');

        self::assertResponseIsSuccessful();
        $band = $page->filter('#patrols');
        self::assertCount(1, $band, 'The band is on the post.');
        self::assertStringContainsString('Patrols from here', $band->text());
        self::assertStringContainsString($patrol->getRef(), $band->text());
        self::assertStringContainsString('N. Example', $band->text());
    }

    /**
     * A person posted at the gate, holding a position that grants nothing,
     * with one patrol out of it this week.
     *
     * @return array{User, Patrol}
     */
    private function aRangerPostedAtTheGate(): array
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('Example square')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $gate = Vocabulary::station($this->em, $area, 'Gate One');
        \assert($gate instanceof Station);

        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        \assert($catalogue instanceof ConcernCatalogue);
        $position = new Position()->setName('Ranger')->setAllowedKinds([ScopeKind::Area])->setGrantValues([], $catalogue->pairs());
        $this->em->persist($position);

        $me = new User()->setPassword('x')->setEmail('naira@example.test')
            ->setFirstName('Naira')->setLastName('Example')->setPosition($position);
        $this->em->persist($me);
        $this->em->persist(new Posting()->setStation($gate)->setPerson($me)
            ->setSince(new \DateTimeImmutable('2026-01-01'))->setSource(PostingSource::WrittenHere));

        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setLead($me)
            ->setStationRecord($gate)
            ->setStartedAt(new \DateTimeImmutable('today 00:01'))
            ->setEndedAt(new \DateTimeImmutable('today 00:02'))
            ->setDistanceKm(4.1);
        $this->em->persist($patrol);

        $this->everyAreaRunsPatrols($this->em);
        $this->em->flush();

        return [$me, $patrol];
    }
}
