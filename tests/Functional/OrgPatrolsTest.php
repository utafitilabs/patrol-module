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
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Shell\OrgPage;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * PATROLS AT ORGANIZATION SCOPE — three screens, read across every area the
 * module is switched on in.
 *
 * WHAT IS ASSERTED IS THE WIDENING. Every figure here has to be the area
 * reading with the area filter widened, so the tests that matter compare the
 * organization's number with the areas' own, one scope at a time.
 *
 * AND WHICH AREA IS A COLUMN. Every row says which area it is about, by the
 * area's place in the declared order and never by a colour of its own.
 *
 * The fixture: two areas running the module, each with its own types and
 * stations, and a third that has the module switched off — present in the
 * database, absent from every page.
 */
final class OrgPatrolsTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private const string OVERVIEW = '/patrols';
    private const string PATROLS = '/patrols/patrols';
    private const string TYPES = '/patrols/types';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<AreaOfInterest> */
    private array $running = [];

    private AreaOfInterest $parked;

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

        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        foreach (['north reserve', 'south reserve', 'quiet reserve'] as $index => $name) {
            $area = new AreaOfInterest()->setSource('test fixture')->setName($name)->setGeom(
                \sprintf('{"type":"MultiPolygon","coordinates":[[[[%1$d.2,-5.8],[%1$d.5,-5.8],[%1$d.5,-5.5],[%1$d.2,-5.5],[%1$d.2,-5.8]]]]}', 12 + $index),
            );
            $this->em->persist($area);
            if (2 === $index) {
                $this->parked = $area;
            } else {
                $this->running[] = $area;
            }
        }

        [$north, $south] = $this->running;

        // THE SAME WORD ON TWO BUFFERS, and a word one area does not keep:
        // the matrix exists because one row per type would have to pick.
        Vocabulary::type($this->em, $north, 'walk')->setBase(PatrolBaseEnum::Surface)->setCoverageBufferM(150);
        Vocabulary::type($this->em, $south, 'walk')->setBase(PatrolBaseEnum::Surface)->setCoverageBufferM(80);
        Vocabulary::type($this->em, $north, 'boat');

        // North: three closed walks and one boat round out now, silent.
        for ($i = 0; $i < 3; ++$i) {
            $this->em->persist(new Patrol($north, Vocabulary::type($this->em, $north, 'walk'))
                ->setStationRecord(Vocabulary::station($this->em, $north, 'North gate'))
                ->setLead($lead)
                ->setStartedAt(new \DateTimeImmutable('today 01:00')->modify('+'.(5 * $i).' minutes'))
                ->setEndedAt(new \DateTimeImmutable('today 02:00')->modify('+'.(5 * $i).' minutes'))
                ->setDistanceKm(10.0));
        }
        $this->em->persist(new Patrol($north, Vocabulary::type($this->em, $north, 'boat'))
            ->setStationRecord(Vocabulary::station($this->em, $north, 'North landing'))
            ->setLead($lead)
            ->setStatus(PatrolStatusEnum::Recording)
            ->setStartedAt(new \DateTimeImmutable('-3 hours')));

        // South: two closed walks, one of them searchable by its note.
        for ($i = 0; $i < 2; ++$i) {
            $this->em->persist(new Patrol($south, Vocabulary::type($this->em, $south, 'walk'))
                ->setStationRecord(Vocabulary::station($this->em, $south, 'South gate'))
                ->setLead($lead)
                ->setNote(0 === $i ? 'culvert washed out' : null)
                ->setStartedAt(new \DateTimeImmutable('today 01:30')->modify('+'.(5 * $i).' minutes'))
                ->setEndedAt(new \DateTimeImmutable('today 02:30')->modify('+'.(5 * $i).' minutes'))
                ->setDistanceKm(4.0));
        }

        // The parked area patrols too; none of it may surface.
        $this->em->persist(new Patrol($this->parked, Vocabulary::type($this->em, $this->parked, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->parked, 'Quiet gate'))
            ->setStartedAt(new \DateTimeImmutable('today 01:00'))
            ->setEndedAt(new \DateTimeImmutable('today 02:00')));

        $this->everyAreaRunsPatrols($this->em);

        $modules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        self::assertInstanceOf(AreaModuleService::class, $modules);
        $modules->uninstall($this->parked, PatrolModuleProvider::SLUG);

        $this->signIn($this->client, $this->em);
    }

    /** @param array<string, string> $query */
    private function open(string $path, array $query = []): Crawler
    {
        $crawler = $this->client->request('GET', $path.([] === $query ? '' : '?'.http_build_query($query)));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function scoped(AreaOfInterest $area): string
    {
        return (string) $area->getUuidString();
    }

    public function testTheThreeScreensAnswer(): void
    {
        foreach ([self::OVERVIEW, self::PATROLS, self::TYPES] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path.' is one of the organization-level screens.');
        }
    }

    /** ONE STRIP, THE SHELL'S, from the declaration the sidebar is mounted from. */
    public function testTheStripIsTheThreeScreensTheModuleContributes(): void
    {
        $page = $this->open(self::PATROLS);

        self::assertSame(['Overview', 'Patrols', 'Patrol types'], $page->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertSame('Patrols', trim($page->filter('.atabs a.on')->text()));
        self::assertSame(
            ['overview', 'patrols', 'types'],
            array_map(static fn (OrgPage $page): string => $page->key, new PatrolModuleProvider('operations')->orgPages()),
        );
    }

    /** THE SIDEBAR CARRIES THE ROW, and it opens the Overview. */
    public function testTheSidebarOpensTheOverview(): void
    {
        $page = $this->open(self::TYPES);

        self::assertGreaterThan(0, $page->filter('a[href="'.self::OVERVIEW.'"]')->count(), 'The shell mounts a row that opens the first screen.');
    }

    /** THE STRIP OF FACTS IS THE AREAS', ADDED — and only the areas running the module. */
    public function testTheOverviewsFactsAreTheAreasAdded(): void
    {
        $org = self::facts($this->open(self::OVERVIEW));

        self::assertSame('2', $org['Areas'], 'The area with the module switched off is not one of them.');
        self::assertSame('1', $org['Out now']);
        self::assertSame('5', $org['Finished today']);

        $summed = ['Out now' => 0, 'Finished today' => 0];
        foreach ($this->running as $area) {
            $one = self::facts($this->open(self::OVERVIEW, ['area' => $this->scoped($area)]));
            self::assertSame('1', $one['Areas']);
            foreach ($summed as $key => $sum) {
                $summed[$key] = $sum + (int) $one[$key];
            }
        }

        self::assertSame((int) $org['Out now'], $summed['Out now']);
        self::assertSame((int) $org['Finished today'], $summed['Finished today']);
    }

    /** ATTENTION IS THE BANNER: the silent patrol, named with its area. */
    public function testASilentPatrolIsInTheBanner(): void
    {
        $banner = $this->open(self::OVERVIEW)->filter('.banner');

        self::assertCount(1, $banner);
        self::assertStringContainsString('north reserve', $banner->text());
        self::assertStringContainsString('not pinged', $banner->text());
    }

    /** THE LIST: every area's patrols, the area a column, the house table and its foot. */
    public function testTheListIsEveryAreasPatrolsWithTheAreaAColumn(): void
    {
        $page = $this->open(self::PATROLS);

        self::assertSame(
            ['patrol', 'area', 'type', 'station', 'lead', 'duration', 'distance', 'observations', 'status', ''],
            $page->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        $rows = $page->filter('table.tbl tbody tr');
        self::assertCount(6, $rows, 'Four in the north and two in the south; the parked area adds none.');
        $rows->each(static function (Crawler $row): void {
            self::assertCount(1, $row->filter('.orgarea[data-cat]'), 'A row at this scope says which area it is about.');
            self::assertCount(1, $row->filter('td.door a'), 'And it opens on the area’s own patrol page.');
        });
        self::assertStringNotContainsString('quiet reserve', $page->filter('table.tbl')->text());

        self::assertStringContainsString('6 patrols', $page->filter('.rdf-foot')->text());
        self::assertStringContainsString('out now', $page->filter('table.tbl')->text());
    }

    /** THE BAR: four of the shell's dropdowns in one row, and the search at its end. */
    public function testTheBarIsTheHouseBar(): void
    {
        $bar = $this->open(self::PATROLS)->filter('form.lfilt');

        self::assertCount(1, $bar);
        self::assertSame(['area', 'type', 'station', 'month'], $bar->filter('[data-dd]')->each(static fn (Crawler $dd): string => (string) $dd->attr('data-dd')));
        self::assertCount(1, $bar->filter('.lsearch input[name="q"]'));
    }

    /** NARROWING: by type, by area (the scope) and by the search, each one GET. */
    public function testTheBarNarrowsTheList(): void
    {
        self::assertCount(5, $this->open(self::PATROLS, ['type' => 'walk'])->filter('table.tbl tbody tr'));
        self::assertCount(2, $this->open(self::PATROLS, ['area' => $this->scoped($this->running[1])])->filter('table.tbl tbody tr'));
        self::assertCount(1, $this->open(self::PATROLS, ['q' => 'culvert'])->filter('table.tbl tbody tr'));
        self::assertStringContainsString('No patrols match', $this->open(self::PATROLS, ['q' => 'nothing-like-it'])->filter('table.tbl tbody')->text());
    }

    /** A STATION BELONGS TO ONE AREA: narrowing the area narrows the station list. */
    public function testTheStationsAreThoseOfTheAreasInScope(): void
    {
        $all = $this->open(self::PATROLS)->filter('[data-dd="station"] .dd-o');
        $south = $this->open(self::PATROLS, ['area' => $this->scoped($this->running[1])])->filter('[data-dd="station"] .dd-o');

        self::assertSame(['All stations', 'North gate', 'North landing', 'South gate'], $all->each(static fn (Crawler $o): string => trim($o->filter('.dd-ol')->text())));
        self::assertSame(['All stations', 'South gate'], $south->each(static fn (Crawler $o): string => trim($o->filter('.dd-ol')->text())));
    }

    /** TYPES: the words on the left, who runs them on the right, one row per area. */
    public function testTheTypesMatrixHasARowPerAreaInScope(): void
    {
        $page = $this->open(self::TYPES, ['type' => 'walk']);

        self::assertSame(['walk', 'boat'], $page->filter('.kpane .krow')->each(static fn (Crawler $row): string => (string) $row->attr('data-kind')));
        self::assertSame('walk', $page->filter('.kpane .krow.on')->attr('data-kind'));

        $table = $page->filter('.kpane table.tbl');
        self::assertSame(
            ['Area', 'This month', 'All time', 'Base', 'Buffer', ''],
            $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        $rows = $table->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('150 m', $rows->eq(0)->text());
        self::assertStringContainsString('80 m', $rows->eq(1)->text());
        self::assertSame(
            '/areas/'.$this->scoped($this->running[0]).'/modules/patrols/types',
            $rows->eq(0)->filter('td.door a')->attr('href'),
            'The door goes to the area, where a type is edited.',
        );
        self::assertStringContainsString('2 areas', $page->filter('.rdf-foot')->text());
    }

    /** A WORD AN AREA DOES NOT KEEP is said so, and a type with no base is in the banner. */
    public function testATypeAnAreaDoesNotKeepIsNotInUseThere(): void
    {
        $page = $this->open(self::TYPES, ['type' => 'boat']);

        $rows = $page->filter('.kpane table.tbl tbody tr');
        self::assertStringContainsString('base not set', $rows->eq(0)->text());
        self::assertStringContainsString('not in use', $rows->eq(1)->text());

        self::assertStringContainsString('no base set', $page->filter('.banner')->text());
    }

    /** THE AREA THE MODULE IS OFF IN is nowhere, and asking for it narrows to nothing of it. */
    public function testAnAreaWithTheModuleOffIsOnNoScreen(): void
    {
        foreach ([self::OVERVIEW, self::PATROLS, self::TYPES] as $path) {
            // The scope control is the shell's and offers every area the reader may open.
            $content = $this->open($path)->filter('.page .banner, .page .factband, .page .c, .page .kpane');
            self::assertGreaterThan(0, $content->count());
            $content->each(static fn (Crawler $part) => self::assertStringNotContainsString('quiet reserve', $part->text(), $path));
        }
    }

    /**
     * @return array<string, string> the band's key → its value's leading figure
     */
    private static function facts(Crawler $page): array
    {
        $facts = [];
        $page->filter('.factband .f')->each(static function (Crawler $fact) use (&$facts): void {
            $facts[trim($fact->filter('.k')->text())] = preg_match('/^[\d,.]+|^—/u', trim($fact->filter('.v')->text()), $m) ? $m[0] : '';
        });

        return $facts;
    }
}
