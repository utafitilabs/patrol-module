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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\TrackBatch;
use Uhifadhi\Patrol\Entity\TrackPoint;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * WHAT THIS MODULE PUTS ON `/`, ASKED OF THE REAL PAGE.
 *
 * The host composes the organization dashboard from whatever is tagged, so
 * the only honest test of a contribution is the rendered page: the cell drawn
 * from THIS bundle's partial, the figure in the host's four-to-a-row strip,
 * and the sheet in the head. A test of the contributor class alone would pass
 * with the tag missing, the partial misnamed and the page fatal.
 */
final class OrgDashboardContributionTest extends WebTestCase
{
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

        $this->client->loginUser($this->anAdministrator());
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

    /** THE CELL IS ON THE GRID, and it is drawn from this bundle's own template. */
    public function testTheCellIsDrawnFromThisModulesPartial(): void
    {
        $this->aLiveOrganization();

        $cell = $this->dashboard()->filter('[data-w="patrols"]');

        self::assertCount(1, $cell, 'The contributed cell is on the organization dashboard.');
        self::assertStringContainsString('Patrols out right now', $cell->text());
        // Columns only this module's partial writes — the host's templates
        // contain no widget markup at all.
        self::assertStringContainsString('last ping', $cell->text());
    }

    /** AND IT NAMES ITSELF, so the day the module goes its cell going reads as the system working. */
    public function testTheCellStatesWhoseFigureItIs(): void
    {
        $this->aLiveOrganization();

        self::assertStringContainsString('patrols', $this->dashboard()->filter('[data-w="patrols"] .ao-by')->text());
    }

    /** Every area's live patrols, on one cell, with the area named on each row. */
    public function testItReadsEveryAreaAtOnce(): void
    {
        $this->aLiveOrganization();

        $rows = $this->dashboard()->filter('[data-w="patrols"] table.tbl tr');

        // A header row and three patrols, out in two different areas.
        self::assertCount(4, $rows);
        $text = $rows->eq(1)->text().' '.$rows->eq(2)->text().' '.$rows->eq(3)->text();
        self::assertStringContainsString('Northern Reserve', $text);
        self::assertStringContainsString('Southern Reserve', $text);
    }

    /** The tab states what the cell is a reading of: how many are out, and the day's walking. */
    public function testTheTabStatesTheDaysWalking(): void
    {
        $this->aLiveOrganization();

        self::assertStringContainsString('96 km walked today', $this->dashboard()->filter('[data-w="patrols"] .tab')->text());
    }

    /**
     * A MEASURED SILENCE INSIDE THE THRESHOLD IS THE ONLY THING THAT READS AS
     * GOOD. A patrol that has never pinged has not reported at all, and a
     * green cell would say the opposite of what the record holds.
     */
    public function testTheLastPingColumnTellsSilenceFromAGoodReading(): void
    {
        $area = $this->anArea('Northern Reserve');
        $pinging = $this->aPatrol($area, 'walk', '-2 hours', status: PatrolStatusEnum::Recording);
        $this->aPing($pinging, '-4 minutes');
        $this->aPatrol($area, 'walk', '-30 minutes', status: PatrolStatusEnum::Recording);

        $rows = $this->dashboard()->filter('[data-w="patrols"] table.tbl tr');

        self::assertCount(1, $rows->eq(1)->filter('td.g'), 'A handset heard from four minutes ago.');
        self::assertStringContainsString('4 min', $rows->eq(1)->filter('td.g')->text());
        self::assertCount(0, $rows->eq(2)->filter('td.g'), 'And one that has said nothing is not good news.');
        self::assertStringContainsString('no ping yet', $rows->eq(2)->text());
    }

    /**
     * THE MODULE'S FIGURE JOINS THE HOST'S FOUR-TO-A-ROW STRIP.
     *
     * FOUND BY ITS LABEL, NEVER BY ITS POSITION. The row is assembled from
     * every module that publishes one, in priority order, and the host's own
     * tile only fills a slot nobody wanted — so which index this module lands
     * at is a fact about the OTHER modules an installation runs. Asserting it
     * would make this suite fail the day somebody installs a sibling, which
     * is a test of the fleet rather than of patrols.
     */
    public function testTheFigureLandsInTheStrip(): void
    {
        $this->aLiveOrganization();

        $tile = $this->figure();

        self::assertSame('2', $tile->filter('.disp')->text(), 'The week’s count, by this module’s own counting rule.');
        self::assertStringContainsString('3 out right now', $tile->filter('.sub')->text());
        self::assertStringContainsString('2 areas', $tile->filter('.sub')->text());
    }

    /**
     * THE PREVIEW IS THE WIDGET. The organization's widget library renders
     * every contributed partial on real data at full size, so what somebody
     * arranges there is exactly what they get — and a cell that fataled off
     * the dashboard would take the library with it.
     */
    public function testTheLibraryOffersTheCellAndDrawsTheRealThing(): void
    {
        $this->aLiveOrganization();

        $crawler = $this->client->request('GET', '/widgets');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-w="patrols"]')->count());
        self::assertStringContainsString('96 km walked today', $crawler->filter('[data-w="patrols"]')->eq(0)->text());
    }

    /** A contributed cell brings its own stylesheet, and the page links it. */
    public function testThePageLinksThisModulesSheet(): void
    {
        $this->aLiveOrganization();

        self::assertStringContainsString(
            'bundles/uhifadhipatrol/patrol-',
            (string) $this->client->request('GET', '/')->filter('head')->html(),
        );
    }

    /**
     * AN INSTALLATION THAT HAS RECORDED NOTHING STILL GETS BOTH: the figure
     * keeps its slot and says nothing was measured, and the cell says why it
     * is empty rather than claiming nobody is out.
     */
    public function testAnUnmeasuredInstallationSaysSoInBothPlaces(): void
    {
        $tile = $this->figure();

        self::assertSame('—', $tile->filter('.disp')->text(), 'Nothing measured is not nought.');
        self::assertStringContainsString('nothing measured', $tile->filter('.sub')->text());
        self::assertStringContainsString('No area has opened a patrol yet', $this->dashboard()->filter('[data-w="patrols"]')->text());
    }

    /**
     * THIS MODULE'S TILE ON THE STRIP, found by the label it publishes.
     *
     * A filler slot the host draws where nobody published carries the same
     * markup, so the search is over the tab's text and the result is asserted
     * to be exactly one — a tile published twice is as wrong as one missing.
     */
    private function figure(): Crawler
    {
        $mine = $this->dashboard()->filter('[data-w="kpis"] .kpi')->reduce(
            static fn (Crawler $tile): bool => 'Patrols this week' === trim($tile->filter('.tab')->text()),
        );

        self::assertCount(1, $mine, 'This module publishes exactly one figure, and it is on the strip.');

        return $mine;
    }

    private function dashboard(): Crawler
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Two areas that patrol and one that does not, on the saturday the design describes. */
    private function aLiveOrganization(): void
    {
        $north = $this->anArea('Northern Reserve');
        $south = $this->anArea('Southern Reserve');
        $this->anArea('Western Reserve');

        $this->aPatrol($north, 'walk', '-4 hours', status: PatrolStatusEnum::Recording);
        $this->aPatrol($north, 'walk', '-2 hours', status: PatrolStatusEnum::Recording);
        $this->aPatrol($south, 'boat', '-1 hour', status: PatrolStatusEnum::Recording);

        $this->aPatrol($north, 'walk', 'today 05:00', 'today 09:00', 61.0);
        $this->aPatrol($south, 'boat', 'today 05:30', 'today 09:30', 35.0);
    }

    private function anArea(string $name): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName($name);
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function aPatrol(
        AreaOfInterest $area,
        string $type,
        string $startedAt,
        ?string $endedAt = null,
        ?float $distanceKm = null,
        PatrolStatusEnum $status = PatrolStatusEnum::Complete,
    ): Patrol {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, $type))
            ->setStatus($status)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setEndedAt(null === $endedAt ? null : new \DateTimeImmutable($endedAt))
            ->setDistanceKm($distanceKm);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    /** One position report, which is what "the handset is reporting" means here. */
    private function aPing(Patrol $patrol, string $at): void
    {
        $batch = new TrackBatch($patrol, 'batch-'.uniqid());
        $this->em->persist($batch);
        $this->em->persist(new TrackPoint(
            $patrol,
            $batch,
            '{"type":"Point","coordinates":[-29.95,-2.95]}',
            new \DateTimeImmutable($at),
        ));
        $this->em->flush();
    }

    /**
     * Somebody who may read the organization. `area.view` is the HOST's
     * permission and the host's voter decides it; a tier that stands above the
     * matrix is how an installation's first account holds it.
     */
    private function anAdministrator(): User
    {
        $user = new User()->setPassword('x')
            ->setEmail('admin@example.test')
            ->setFirstName('A')
            ->setLastName('Dmin')
            ->setTeamRole(TeamRoleEnum::Admin);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
