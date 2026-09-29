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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE CALENDAR TAB (PL·11) — the HOUSE month with patrol marks in it.
 *
 * THE GRID IS THE ATLAS'S, which is what most of this holds to: the page draws
 * `.cal`, `.dc`, `.dh`, `.cal-mark` and `.cal-more` because it called
 * `atlas_calendar()`, and it draws no month grid of its own — a twin under a
 * private name is the same fork, and the one thing a stylesheet test cannot
 * catch.
 *
 * WHAT IS THIS MODULE'S is what is IN a cell: which patrols, on which day,
 * reading as what — and the patrol-type chip that rides in the stepper's row.
 */
final class CalendarTabTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    /**
     * A month safely in the past: "today" then falls outside it whenever the
     * suite runs, so the fixtures never drift with the clock. August 2019 runs
     * Thursday to Saturday, so the atlas lays it out in FIVE whole weeks —
     * Jul 29 to Sep 1 — and never a ragged last line.
     */
    private const string MONTH = '2019-08';
    private const int CELLS = 35;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $firstDay;
    private Patrol $lastDay;
    private Patrol $manual;
    private Patrol $leading;
    private Patrol $far;

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

        $this->otherArea = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->otherArea);

        // The month's BOUNDARIES: its very first and very last day.
        $this->firstDay = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('2019-08-01 06:10'))
            ->setEndedAt(new \DateTimeImmutable('2019-08-01 09:40'))
            ->setDistanceKm(14.2);
        $this->em->persist($this->firstDay);

        $this->lastDay = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'boat'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'South landing'))
            ->setStartedAt(new \DateTimeImmutable('2019-08-31 18:30'))
            ->setEndedAt(new \DateTimeImmutable('2019-08-31 20:00'))
            ->setDistanceKm(58.3);
        $this->em->persist($this->lastDay);

        // A HAND-LOGGED patrol (no GPX, no track): it is an ordinary calendar
        // item — the month is patrol effort, not GPX files.
        $this->manual = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('2019-08-14 07:00'))
            ->setEndedAt(new \DateTimeImmutable('2019-08-14 11:15'));
        $this->em->persist($this->manual);

        // July's last days FALL INSIDE August's grid, in the dimmed leading
        // cells the design draws — they belong there.
        $this->leading = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2019-07-30 06:00'));
        $this->em->persist($this->leading);

        // A month the grid cannot reach at all, and another area's patrol in the
        // same month — neither may appear in this area's August grid.
        $this->far = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2019-10-05 06:00'));
        $this->em->persist($this->far);
        $this->em->persist(new Patrol($this->otherArea, Vocabulary::type($this->em, $this->otherArea, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2019-08-14 06:00')));

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
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

    private function url(?string $month = self::MONTH, ?AreaOfInterest $area = null, ?string $type = null): string
    {
        $area ??= $this->area;
        $query = [];
        if (null !== $month) {
            $query['month'] = $month;
        }
        if (null !== $type) {
            $query['type'] = $type;
        }

        return '/areas/'.$area->getUuidString().'/modules/patrols/calendar'
            .([] === $query ? '' : '?'.http_build_query($query));
    }

    private function open(?string $month = self::MONTH, ?AreaOfInterest $area = null, ?string $type = null): Crawler
    {
        return $this->client->request('GET', $this->url($month, $area, $type));
    }

    /** @return list<string> */
    private function marks(Crawler $crawler): array
    {
        return $crawler->filter('.cal .cal-mark .l')->each(static fn (Crawler $node): string => trim($node->text()));
    }

    /**
     * THE MONTH IS DRAWN BY THE COMPONENT and lands inside the app frame: the
     * shell, the page head and the module's stylesheets.
     */
    public function testTheTabIsTheHouseMonthInsideTheAppFrame(): void
    {
        $crawler = $this->open();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1.pg');
        self::assertSelectorTextContains('h1.pg', 'seed reserve — Patrols');
        self::assertStringContainsString(
            'uhifadhipatrol/patrol',
            (string) $this->client->getResponse()->getContent(),
        );

        // The component's own grid, and the day heads it ships.
        self::assertCount(1, $crawler->filter('.cal-plate .cal'));
        self::assertSame(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            $crawler->filter('.cal .dh')->each(static fn (Crawler $node): string => trim($node->text())),
        );
        self::assertCount(self::CELLS, $crawler->filter('.cal .dc'));
        // Three leading days of July and one trailing day of September, drawn
        // dimmed — 31 days of August in between.
        self::assertCount(3 + 1, $crawler->filter('.cal .dc.out'));
        // Not the month that holds today: nothing is ringed.
        self::assertCount(0, $crawler->filter('.cal .dc.today'));
    }

    /**
     * AND THE MODULE DRAWS NO MONTH OF ITS OWN. A twin under a private name is
     * the same fork as redefining `.cal`, and the stylesheet rule cannot see it
     * — so the page itself says so.
     */
    public function testThePageShipsNoMonthGridOfItsOwn(): void
    {
        $this->open();

        $html = (string) $this->client->getResponse()->getContent();

        foreach (['patrol-cal', 'patrol-dc', 'patrol-dh', 'patrol-daypill', 'patrol-morechip', 'patrol-pop'] as $twin) {
            self::assertStringNotContainsString($twin, $html, \sprintf('"%s" is a private month grid', $twin));
        }
    }

    /**
     * ONE MARK PER PATROL, on the day it started — the boundary days, the
     * hand-logged one and July's, which the grid genuinely draws in a dimmed
     * leading cell. Not another area's, and not a month the grid cannot reach.
     */
    public function testEveryPatrolInTheGridIsOneMark(): void
    {
        $crawler = $this->open();

        $refs = $this->marks($crawler);
        self::assertCount(4, $refs);
        self::assertContains($this->firstDay->getRef(), $refs);
        self::assertContains($this->lastDay->getRef(), $refs);
        self::assertContains($this->manual->getRef(), $refs);
        self::assertContains($this->leading->getRef(), $refs);
        self::assertNotContains($this->far->getRef(), $refs);
        self::assertCount(1, $crawler->filter('.cal .dc.out .cal-mark'));
    }

    /** A mark is a door: it opens the patrol it stands for. */
    public function testEveryMarkOpensThatPatrol(): void
    {
        $crawler = $this->open();

        $expected = '/areas/'.$this->area->getUuidString()
            .'/modules/patrols/'.$this->firstDay->getUuid()->toRfc4122();
        $hrefs = $crawler->filter('.cal a.cal-mark')->each(static fn (Crawler $node): string => (string) $node->attr('href'));

        self::assertCount(4, $hrefs);
        self::assertContains($expected, $hrefs);
    }

    /**
     * THE HUE IS A ROLE AND THE ATLAS PAINTS IT. The module names a meaning and
     * the component writes the paint onto the dot as `--pill-hue`, so no patrol
     * colour is decided in this package.
     */
    public function testAMarkWearsTheRoleTheModuleNamedAndNoColourOfItsOwn(): void
    {
        $this->firstDay->discard('a test run');
        $this->em->flush();

        $crawler = $this->open();

        $discarded = $crawler->filter('.cal a.cal-mark[href$="'.$this->firstDay->getUuid()->toRfc4122().'"]');
        self::assertCount(1, $discarded);
        // Withdrawn: quiet, and hollow because the recording is finished.
        self::assertStringContainsString('done', (string) $discarded->attr('class'));
        self::assertStringContainsString('--pill-hue:var(--fog)', (string) $discarded->filter('i')->attr('style'));

        $complete = $crawler->filter('.cal a.cal-mark[href$="'.$this->manual->getUuid()->toRfc4122().'"]');
        self::assertStringNotContainsString('done', (string) $complete->attr('class'));
        self::assertStringContainsString('--pill-hue:var(--acc)', (string) $complete->filter('i')->attr('style'));
    }

    /** What the removed hover card said now rides on the mark itself. */
    public function testAMarkNamesItsTypeItsPostAndItsHour(): void
    {
        $crawler = $this->open();

        $title = (string) $crawler
            ->filter('.cal a.cal-mark[href$="'.$this->firstDay->getUuid()->toRfc4122().'"]')
            ->attr('title');

        self::assertStringContainsString('Walking round', $title);
        self::assertStringContainsString('North post', $title);
        self::assertStringContainsString('06:10', $title);
    }

    /**
     * OVERFLOW (owner: a day cell never grows with data). Past the component's
     * cap the extras fold into "+N more", and the day's own count in the
     * corner still says how full the day really was.
     */
    public function testABusyDayFoldsItsExtrasIntoTheComponentsMoreLink(): void
    {
        // Five patrols on one ordinary August day — past the cell's cap.
        for ($i = 0; $i < 5; ++$i) {
            $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
                ->setStartedAt(new \DateTimeImmutable(\sprintf('2019-08-15 %02d:00', 6 + $i))));
        }
        $this->em->flush();

        $crawler = $this->open();

        $fold = $crawler->filter('.cal .cal-more');
        self::assertCount(1, $fold);
        self::assertSame('+2 more', trim($fold->text()));
        // And it leads to the register, on the month the day is in.
        self::assertStringContainsString('/modules/patrols/patrols', (string) $fold->attr('href'));
        self::assertStringContainsString('month=2019-08', (string) $fold->attr('href'));

        // The day says how full it really was, which is the point of the fold.
        $counts = $crawler->filter('.cal .dcount')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertContains('5', $counts);
    }

    /**
     * THE STEPPER IS THE COMPONENT'S, and its arrows are LINKS: stepping a
     * month is a navigation, so the walk survives with no JavaScript at all and
     * is unbounded in both directions.
     */
    public function testTheStepperWalksToTheNeighbouringMonths(): void
    {
        $crawler = $this->open();

        $nav = $crawler->filter('.cal-plate .cal-nav');
        self::assertCount(1, $nav);
        self::assertSame('august 2019', trim($nav->filter('.mchip.on')->text()));

        $months = $nav->filter('a.mchip.ghost')->each(static fn (Crawler $node): string => (string) $node->attr('href'));
        self::assertCount(2, $months);
        foreach (['month=2019-07', 'month=2019-09'] as $index => $expected) {
            self::assertStringContainsString($expected, $months[$index]);
        }
    }

    /**
     * THE SURFACE'S OWN CONTROL SHARES THE STEPPER'S ROW — one line of chrome,
     * the stepper at the left and the patrol-type chip at the right. On a
     * second row the two read as two toolbars.
     */
    public function testThePatrolTypeChipRidesInTheStepperRow(): void
    {
        $crawler = $this->open();

        $chip = $crawler->filter('.cal-plate .cal-nav details.i-dd');
        self::assertCount(1, $chip);
        self::assertSame('all types', trim($chip->filter('.i-ddval')->text()));

        // Every option is a real link carrying the month with it.
        $options = $chip->filter('.i-ddmenu a.i-ddopt')->each(
            static fn (Crawler $node): string => (string) $node->attr('href'),
        );
        self::assertCount(3, $options);
        foreach ($options as $href) {
            self::assertStringContainsString('month=2019-08', $href);
        }
    }

    /** And choosing one narrows the month to that kind of patrol. */
    public function testChoosingATypeNarrowsTheMonth(): void
    {
        $crawler = $this->open(type: 'boat');

        self::assertResponseIsSuccessful();
        self::assertSame([$this->lastDay->getRef()], $this->marks($crawler));
        self::assertSame('boat', trim($crawler->filter('.cal-nav .i-ddval')->text()));
        // The stepper keeps the narrowing, so a month either side is the same
        // question asked of another month.
        foreach ($crawler->filter('.cal-nav a.mchip.ghost')->each(static fn (Crawler $n): string => (string) $n->attr('href')) as $href) {
            self::assertStringContainsString('type=boat', $href);
        }
    }

    public function testAMonthWithNoPatrolsIsStillAFullGrid(): void
    {
        $crawler = $this->open('2031-11');

        self::assertResponseIsSuccessful();
        // November 2031 is another five-week month: whole weeks, never a
        // ragged last line, whatever the month happens to hold.
        self::assertCount(35, $crawler->filter('.cal .dc'));
        self::assertCount(0, $crawler->filter('.cal .cal-mark'));
        // An empty November is a fact about November, said in the house's words.
        self::assertStringContainsString('Nothing on this month yet', (string) $crawler->filter('.cal-plate .use')->text());
    }

    public function testTheWalkIsUnboundedInBothDirections(): void
    {
        foreach (['1998-01', '2099-12'] as $month) {
            $this->open($month);
            self::assertResponseIsSuccessful();
        }
    }

    public function testWithoutAMonthTheTabIsTheCurrentOne(): void
    {
        $crawler = $this->open(null);

        self::assertResponseIsSuccessful();
        self::assertSame(
            new \DateTimeImmutable()->format('F Y'),
            ucwords(trim($crawler->filter('.cal-nav .mchip.on')->text())),
        );
        self::assertCount(1, $crawler->filter('.cal .dc.today'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedMonths(): iterable
    {
        yield 'not a month at all' => ['august'];
        yield 'month out of range' => ['2019-13'];
        yield 'month zero' => ['2019-00'];
        yield 'a full date' => ['2019-08-14'];
        yield 'short year' => ['26-08'];
        yield 'injection attempt' => ['2026-08"><script>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedMonths')]
    public function testAMalformedMonthIsRejected(string $month): void
    {
        $this->open($month);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * THE WIDGET IS THE SAME COMPONENT, already rendered for the month the
     * dashboard is reading — so the picture of the calendar on the dashboard
     * and the calendar tab cannot draw two different months.
     */
    public function testTheDashboardWidgetIsTheSameComponent(): void
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();

        $widget = $crawler->filter('[data-w="cal"]');
        self::assertCount(1, $widget);
        self::assertCount(1, $widget->filter('.cal-plate .cal'));
        self::assertCount(7, $widget->filter('.cal .dh'));
        // The dashboard's filter drives the month, so the stepper carries the
        // whole question to the dashboard a month either side.
        $current = new \DateTimeImmutable()->modify('first day of this month');
        self::assertSame(
            [$current->modify('-1 month')->format('Y-m'), $current->modify('+1 month')->format('Y-m')],
            $widget->filter('.cal-nav a.mchip.ghost')->each(
                static fn (Crawler $node): string => self::queryOf((string) $node->attr('href'))['month'] ?? '',
            ),
        );
        // And the widget draws no second filter row: the surface's own is
        // already on this page, once.
        self::assertCount(0, $widget->filter('.cal-nav details.i-dd'));
    }

    /** @return array<string, string> */
    private static function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    public function testAnUnknownAreaIsNotFound(): void
    {
        $this->client->request(
            'GET',
            '/areas/'.new \Symfony\Component\Uid\UuidV4()->toRfc4122().'/modules/patrols/calendar?month='.self::MONTH,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherAreaSeesOnlyItsOwnPatrols(): void
    {
        $crawler = $this->open(area: $this->otherArea);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cal .cal-mark'));
    }
}
