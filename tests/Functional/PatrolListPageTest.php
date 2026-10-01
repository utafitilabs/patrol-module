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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\PatrolListService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE FULL LOG — every patrol the month holds, one page at a time, driven by one
 * GET: the filter row's chips, the search box and the pager all carry the same
 * question, and the count in the caption is the count of the rows under it.
 */
final class PatrolListPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private const int PATROL_COUNT = 25;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        // A month with more patrols than one page holds, so the pager has work.
        // Five minutes apart in today's small hours, so every one is in this
        // month on any day the suite runs - an hour apart reached back past
        // midnight, and on the 1st into last month.
        for ($i = 0; $i < self::PATROL_COUNT; ++$i) {
            $walk = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 0 === $i % 5 ? 'boat' : 'walk'))
                ->setStationRecord(Vocabulary::station($this->em, $this->area, 0 === $i % 5 ? 'South landing' : 'North post'))
                ->setLead($lead)
                ->setStartedAt(new \DateTimeImmutable('today 03:00')->modify('-'.(5 * $i).' minutes'))
                ->setEndedAt(new \DateTimeImmutable('today 09:20')->modify('-'.(5 * $i).' minutes'))
                ->setDistanceKm(12.0 + $i);
            $this->em->persist($walk);
        }

        // The one patrol a search can single out, by a word only its observation
        // note carries.
        $searchable = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 04:00'))
            ->setEndedAt(new \DateTimeImmutable('today 05:00'));
        $this->em->persist($searchable);
        $this->em->persist(new Observation($searchable, 'maintenance')->setNote('culvert washed out'));

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
    }

    /** @param array<string, scalar> $query */
    private function url(array $query = []): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/patrols'
            .([] === $query ? '' : '?'.http_build_query($query));
    }

    public function testItListsTheMonthsPatrolsWithTheDesignsColumns(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['patrol', 'type', 'station', 'lead', 'team', 'duration', 'distance', 'observations', 'status', ''],
            $crawler->filter('table.tbl thead th')->each(static fn ($th) => trim($th->text())),
        );
    }

    /** The card's caption states the same number the rows are drawn from. */
    public function testTheCaptionCountsEveryPatrolTheFilterMatches(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        self::assertStringContainsString(
            (string) (self::PATROL_COUNT + 1).' patrols',
            $crawler->filter('.c > .tab')->first()->text(),
        );
    }

    /** Twenty rows a page, and every one of them opens. */
    public function testAPageHoldsTwentyRowsAndEveryRowOpens(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        self::assertCount(PatrolListService::PER_PAGE, $crawler->filter('table.tbl tbody tr'));
        self::assertCount(PatrolListService::PER_PAGE, $crawler->filter('table.tbl tbody tr a.open-btn'));
        self::assertSame('Open →', trim($crawler->filter('table.tbl tbody tr a.open-btn')->first()->text()));
    }

    public function testThePagerCarriesTheRestOfTheAnswer(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        self::assertStringContainsString('1–20 of '.(self::PATROL_COUNT + 1), $crawler->filter('.pgr .cnt')->text());

        $crawler = $this->client->request('GET', $this->url(['page' => 2]));

        self::assertResponseIsSuccessful();
        self::assertCount(self::PATROL_COUNT + 1 - PatrolListService::PER_PAGE, $crawler->filter('table.tbl tbody tr'));
    }

    /** The search reads the observation notes, exactly as the caption promises. */
    public function testTheSearchNarrowsToWhatTheNotesSay(): void
    {
        $crawler = $this->client->request('GET', $this->url(['q' => 'culvert']));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.tbl tbody tr'));
    }

    /** A filter chip is a real link and the counts beside it are the live ones. */
    public function testTheTypeChipNarrowsTheList(): void
    {
        $crawler = $this->client->request('GET', $this->url(['type' => 'boat']));

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('table.tbl tbody tr'));
    }

    /** An empty answer says so rather than drawing an empty table. */
    public function testASearchThatMatchesNothingSaysSo(): void
    {
        $crawler = $this->client->request('GET', $this->url(['q' => 'nothing-here-at-all']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No patrols match this filter.', $crawler->filter('table.tbl tbody')->text());
    }

    /**
     * THE SHELL DRAWS THE STRIP, from this module's declaration — three data
     * places, the list one lit. Nothing in this bundle draws a tab.
     */
    public function testTheShellDrawsTheModulesDataPlaces(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        $tabs = $crawler->filter('.atabs a');

        self::assertSame(['Overview', 'Patrols', 'Observation kinds'], $tabs->each(static fn ($a) => trim($a->text())));
        self::assertSame('Patrols', trim($crawler->filter('.atabs a.on')->text()));
    }

    /**
     * AN UNREADABLE PAGE IS THE FIRST ONE, which is what the controller has
     * always claimed and did not do.
     *
     * `InputBag::getInt()` throws a BadRequestException on anything that is not a
     * whole number, so `?page=` — an emptied field, a hand-edited address, a link
     * built by joining strings — answered 400 and the log was unreachable. A page
     * number is navigation: the worst a bad one can mean is "start at the
     * beginning".
     *
     * @param string $page what arrives in the query, verbatim
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadablePages')]
    public function testAnUnreadablePageNumberOpensTheFirstPage(string $page): void
    {
        $crawler = $this->client->request('GET', $this->url().'?page='.$page);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1–20 of '.(self::PATROL_COUNT + 1), $crawler->filter('.pgr .cnt')->text());
        self::assertCount(PatrolListService::PER_PAGE, $crawler->filter('table.tbl tbody tr'));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadablePages(): iterable
    {
        yield 'cleared' => [''];
        yield 'not a number' => ['abc'];
        yield 'a fraction' => ['2.5'];
        yield 'below the first' => ['-3'];
        yield 'zero' => ['0'];
    }

    /**
     * THE WORD "REGISTER" IS NOWHERE ON THE SURFACE — not in the address, not in
     * the title, not in a heading.
     */
    public function testNothingOnThePageSaysRegister(): void
    {
        $this->client->request('GET', $this->url());

        self::assertStringNotContainsStringIgnoringCase(
            'register',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
