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
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * EVERY PATROL PLATE STANDS ON THE AREA'S GROUND, as a browser is served it:
 * the zones layer first, the "Zones · N" row under "The area" after the
 * boundary row, the area's own zones in it.
 */
final class AreaGroundOnPlatesTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private const string WEST = '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.35,-5.8],[12.35,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}';
    private const string EAST = '{"type":"MultiPolygon","coordinates":[[[[12.35,-5.8],[12.5,-5.8],[12.5,-5.5],[12.35,-5.5],[12.35,-5.8]]]]}';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $walk;

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
        $this->em->persist(new Zone()->setArea($this->area)->setName('West block')->setGeom(self::WEST));
        $this->em->persist(new Zone()->setArea($this->area)->setName('East block')->setGeom(self::EAST));

        $this->walk = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->walk);
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

    public function testTheCoverageMapStandsOnTheAreasGround(): void
    {
        $crawler = $this->client->request('GET', $this->dashboard());

        self::assertResponseIsSuccessful();
        self::assertStandsOnTheGround($crawler->filter('[data-controller="uhifadhi--atlas-bundle--map-plate"]')->first());
    }

    /** The coverage widget previewed in the library is the plate, ground and all. */
    public function testTheCoverageWidgetStandsOnTheAreasGround(): void
    {
        $crawler = $this->client->request('GET', $this->dashboard().'/widgets');

        self::assertResponseIsSuccessful();
        $plates = $crawler->filter('[data-controller="uhifadhi--atlas-bundle--map-plate"]');
        self::assertGreaterThan(0, $plates->count());
        $plates->each(static function (Crawler $plate): void {
            self::assertStandsOnTheGround($plate);
        });
    }

    public function testTheTrackPlateStandsOnTheAreasGround(): void
    {
        $crawler = $this->client->request('GET', $this->dashboard().'/'.$this->walk->getUuid()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStandsOnTheGround($crawler->filter('[data-controller="uhifadhi--atlas-bundle--map-plate"]')->first());
    }

    private static function assertStandsOnTheGround(Crawler $plate): void
    {
        self::assertCount(1, $plate);

        $extra = json_decode((string) $plate->filter('[data-symfony--ux-leaflet-map--map-extra-value]')->attr('data-symfony--ux-leaflet-map--map-extra-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($extra);
        $atlas = $extra['atlas'] ?? null;
        self::assertIsArray($atlas);
        $layers = $atlas['layers'] ?? null;
        self::assertIsList($layers);
        self::assertIsArray($layers[0]);

        // The zones are the first layer, so every patrol mark is drawn over them.
        self::assertSame(Ground::ZONES_LAYER_ID, $layers[0]['id']);
        $features = $layers[0]['features'];
        self::assertIsArray($features);
        self::assertIsList($features['features']);
        self::assertCount(2, $features['features']);
        $names = array_map(static fn (mixed $feature): mixed => \is_array($feature) && \is_array($feature['properties']) ? $feature['properties']['label'] : null, $features['features']);
        sort($names);
        self::assertSame(['East block', 'West block'], $names);

        // The legend opens on "The area": the boundary row, then Zones · 2.
        $group = $plate->filter('.map-legend .grp')->first();
        self::assertSame(Ground::GROUP, trim($group->filter('b')->text()));
        $rows = $group->filter('.lay');
        self::assertStringStartsWith('Boundary', trim($rows->eq(0)->text()));
        self::assertStringStartsWith('Zones', trim($rows->eq(1)->text()));
        self::assertSame('2', $rows->eq(1)->filter('em')->text());

        // One area group on the plate: nothing else names its own heading for the area.
        self::assertCount(1, $plate->filter('.map-legend .grp b')->reduce(
            static fn (Crawler $heading): bool => 0 === strcasecmp(trim($heading->text()), Ground::GROUP),
        ));
    }

    private function dashboard(): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols';
    }
}
