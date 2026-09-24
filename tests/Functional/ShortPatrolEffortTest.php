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
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * A MONTH WHOSE LONGEST PATROL IS SHORTER THAN AN HOUR STILL DRAWS.
 *
 * "Effort by ranger" plots hours, and hours are a float — the only chart on the
 * surface whose figures are not whole numbers. The axis, the grid and the bar
 * geometry are the atlas's now; what this module still has to get right is the
 * FIGURE, and a minute on the track is a real fraction of an hour rather than
 * the nought a credited ranger would otherwise wear.
 *
 * The library previews the whole catalogue, so it renders the effort widget
 * whatever the reader's own composition says — which makes it the one page that
 * proves a widget draws at all.
 */
final class ShortPatrolEffortTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private User $ranger;

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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->ranger = new User()->setPassword('x')->setEmail('ranger@example.test')
            ->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($this->ranger);

        // The month's ONE credited patrol: closed, with a committed lead, and one
        // minute long — 0.0167 h, the fraction the axis has to cope with.
        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($this->ranger)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 06:11')));

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

    public function testTheEffortChartCreditsAMeasurableFigureForAOneMinutePatrol(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request(
            'GET',
            '/areas/'.$this->area->getUuidString().'/modules/patrols/widgets',
        );

        self::assertResponseIsSuccessful();

        $effort = $crawler->filter('template[data-widget-template="effort"]');
        self::assertCount(1, $effort);
        self::assertStringContainsString('Effort by ranger', $effort->html());

        // THE PREVIEW IS MARKUP INSIDE A <template>, so it is parsed on its own
        // to be read. What is asserted is the chart this module STATED: one
        // credited ranger, named the way a patrol row names one, carrying a
        // fraction of an hour rather than a nought.
        $chart = new Crawler($effort->html())->filter('.chart-plate canvas');
        self::assertCount(1, $chart, 'the widget draws the atlas\'s chart, not one of its own.');

        /** @var array{data: array{labels: list<string>, datasets: list<array{data: list<float>}>}} $view */
        $view = json_decode((string) $chart->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['A. Alpha'], $view['data']['labels'], 'one credited ranger, one bar.');
        self::assertCount(1, $view['data']['datasets']);
        self::assertGreaterThan(0.0, $view['data']['datasets'][0]['data'][0], 'a minute on the track is not a nought.');
        self::assertLessThan(1.0, $view['data']['datasets'][0]['data'][0]);
    }
}
