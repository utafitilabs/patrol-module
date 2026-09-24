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
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Widget\PatrolWidgets;

/**
 * THE WIDGET LIBRARY, on the HOST's framework.
 *
 * The module ships a catalogue (PatrolWidgets) and nothing else — no save
 * endpoint, no merge algebra, no preset mechanics. These tests exercise the
 * real host component end to end through the module's routes, because "it
 * rides the host framework" is a claim that is either demonstrable over HTTP
 * or is not true.
 */
final class WidgetLibraryFlowTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    /** The shipped composition, in the design's own order — the feed is off the
     * default (owner ruling 2026-09-08), so it is not among the rendered widgets. */
    private const array WIDGET_IDS = ['kpis', 'map', 'log', 'chweek', 'chstation', 'kinds', 'cal'];

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

        // One real patrol so the previews render live rows, not empty states.
        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($this->ranger)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70]]}'));

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

    public function testTheLibraryIsTheHostsComponentOverThisSurfacesCatalogue(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->libraryUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'demo reserve — Patrols · configure');

        $html = $crawler->html();
        // The shipped composition leads the strip under the design's own name,
        // never as a generic "Default layout"…
        self::assertStringContainsString(PatrolWidgets::DEFAULT_LABEL, $html);
        // …and the component is the HOST's: its root carries the framework's
        // own attributes, which is what the shell's widgets script drives.
        self::assertCount(1, $crawler->filter('['.WidgetDom::ROOT.']'));
        self::assertCount(1, $crawler->filter('['.WidgetDom::CSRF_TOKEN.']'));

        // EVERY widget rendered once as the real thing: the same partials the
        // dashboard renders, on the same live data — one inert template clone
        // per catalogue widget. The library previews the WHOLE catalogue (all
        // sixteen), not just the seven the shipped composition turns on.
        self::assertCount(
            \count(PatrolWidgets::declaration()->ids()),
            $crawler->filter('template['.WidgetDom::TEMPLATE.']'),
        );
        self::assertStringContainsString('North post', $html);
    }

    /**
     * THE ONE LINE THAT MAKES THE CARDS DO SOMETHING.
     *
     * The page renders whole without JavaScript — every action on it is a plain
     * form post — but PREVIEW, the picker's live filtering and optimistic
     * editing are the script's, and without it a card draws correctly and does
     * nothing when clicked. That failure is invisible to every other test here,
     * because each of them asserts what a POST DID.
     *
     * The specifier is bare, and that is the contract: the core publishes
     * `uhifadhi/widgets` and Flex writes it into an installation's
     * importmap.php, so this module names no path and no version.
     *
     * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/docs/widget/declaring-a-surface.md
     */
    public function testTheLibraryPageImportsTheShellsWidgetScript(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->libraryUrl());

        self::assertResponseIsSuccessful();
        $modules = $crawler->filter('script[type="module"]')->each(
            static fn (Crawler $script): string => trim($script->text()),
        );
        self::assertContains("import 'uhifadhi/widgets';", $modules);
    }

    /**
     * THE DASHBOARD LINKS TO NO LIBRARY. There is one configuration entry per
     * surface — the shell's `Configure` action — and the library is a section
     * behind it, so the module's own action row names it nowhere.
     */
    public function testTheDashboardDrawsNoWidgetLibraryButton(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->dashboardUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a:contains("Widget library")'));
    }

    /**
     * AND THE LIBRARY IS REACHED FROM THE CONFIGURE PAGE'S STRIP, which is the
     * shell drawing this module's declared sections.
     */
    public function testTheLibraryIsASectionOfTheConfigurePage(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->libraryUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Widget library', 'Patrol types', 'Observation kinds', 'Settings'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Widget library', trim($crawler->filter('.atabs a.on')->text()));
    }

    /** A fresh person lands on the shipped composition — all seven, in catalogue order. */
    public function testTheDashboardShipsTheDesignsOwnComposition(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->dashboardUrl());

        self::assertSame(
            self::WIDGET_IDS,
            $crawler->filter('.patrol-wgrid > [data-w]')->each(static fn (Crawler $w) => (string) $w->attr('data-w')),
        );
        self::assertStringContainsString('patrol-w-6', (string) $crawler->filter('[data-w="chweek"]')->attr('class'));
    }

    /**
     * BUILT-INS ARE IMMUTABLE: while the shipped design is active, a layout
     * save is refused — the way to change it is a copy.
     */
    public function testSavingOverAShippedDesignIsRefused(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->saveUrl(), [
            'order' => ['cal', 'kpis'],
            'widgets' => ['map' => ['on' => false, 'cols' => 12]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * MAKE A COPY TO CUSTOMIZE, then edit: the copy is the person's own preset,
     * it is active at once, and edits write through to it — which the dashboard
     * then obeys.
     */
    public function testCopyingTheShippedDesignMakesItEditableAndTheDashboardObeys(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request('POST', $this->libraryUrl().'/preset/default/copy', [
            '_token' => $this->csrfToken(),
        ]);
        self::assertResponseRedirects($this->libraryUrl());

        $library = $this->client->followRedirect();
        self::assertStringContainsString(PatrolWidgets::DEFAULT_LABEL.' — copy', $library->html());

        $this->postJson($this->saveUrl(), [
            'order' => ['cal', 'kpis', 'chweek'],
            'widgets' => [
                'cal' => ['on' => true, 'cols' => 6],
                'map' => ['on' => false, 'cols' => 12],
                'chweek' => ['on' => true, 'cols' => 3],
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', $this->dashboardUrl());
        $rendered = $crawler->filter('.patrol-wgrid > [data-w]')
            ->each(static fn (Crawler $widget) => (string) $widget->attr('data-w'));
        // Chosen order first, the hidden widget gone, the chosen spans applied.
        self::assertSame(['cal', 'kpis', 'chweek'], \array_slice($rendered, 0, 3));
        self::assertCount(0, $crawler->filter('[data-w="map"]'));
        self::assertStringContainsString('patrol-w-6', (string) $crawler->filter('[data-w="cal"]')->attr('class'));
        self::assertStringContainsString('patrol-w-3', (string) $crawler->filter('[data-w="chweek"]')->attr('class'));
    }

    /** Reset puts the module's own composition back. */
    public function testResettingRestoresTheCompositionTheModuleShipsWith(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request('POST', $this->libraryUrl().'/preset/default/copy', [
            '_token' => $this->csrfToken(),
        ]);
        $this->postJson($this->saveUrl(), [
            'order' => ['cal'],
            'widgets' => ['map' => ['on' => false, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', $this->libraryUrl().'/reset', [
            '_token' => $this->csrfToken(),
        ]);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertSame(
            self::WIDGET_IDS,
            $crawler->filter('.patrol-wgrid > [data-w]')->each(static fn (Crawler $w) => (string) $w->attr('data-w')),
        );
    }

    /**
     * ADOPTING A DIRECTION: one of the five presets applied in a click becomes
     * the active layout, and the dashboard draws exactly the widgets that
     * direction composes, in its order. "Live coverage" (a) is out-now, the
     * coverage+log split and the KPI strip — nothing else.
     */
    public function testAdoptingADirectionRendersItsWidgetsOnTheDashboard(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request('POST', $this->libraryUrl().'/preset/a', [
            '_token' => $this->csrfToken(),
        ]);
        self::assertResponseRedirects($this->libraryUrl());

        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertSame(
            ['kpis', 'now', 'maplog'],
            $crawler->filter('.patrol-wgrid > [data-w]')->each(static fn (Crawler $w) => (string) $w->attr('data-w')),
        );
        // The direction's own widgets rendered on real rows — "Out right now"
        // and the docked coverage+log list, neither of which the shipped
        // composition turns on.
        self::assertCount(1, $crawler->filter('[data-w="now"]'));
        self::assertCount(1, $crawler->filter('[data-w="maplog"] .patrol-maplog-split'));
        self::assertCount(0, $crawler->filter('[data-w="log"]'));
    }

    /**
     * WHAT THE LIBRARY PAGE ITSELF SAYS AFTERWARDS — one card wearing Active,
     * and every other card offering the preview the script performs. A switch
     * the dashboard obeyed while the strip still marked the old card would be
     * two answers to "which layout am I on", and no POST assertion catches it.
     */
    public function testTheStripMarksExactlyOneCardActiveAndOffersPreviewOnTheRest(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request('POST', $this->libraryUrl().'/preset/a', [
            '_token' => $this->csrfToken(),
        ]);

        $crawler = $this->client->request('GET', $this->libraryUrl());
        $active = $crawler->filter('.w-preset-active');
        self::assertCount(1, $active, 'Exactly one card is on the dashboard.');
        self::assertStringContainsString('Active', $active->filter('.w-presetflag-active')->text());
        self::assertSame('On your dashboard', trim($active->filter('.w-presetgo')->text()));
        self::assertSame('true', $active->attr('aria-pressed'));

        // Every other card is a preview, which is the script's job and is why
        // the page has to load it.
        $others = $crawler->filter('.w-preset:not(.w-preset-active):not(.w-preset-new)');
        self::assertGreaterThan(0, $others->count());
        foreach ($others->filter('.w-presetgo') as $go) {
            self::assertSame('Preview', trim(new Crawler($go)->text()));
        }
    }

    /** A design this surface does not ship is refused, not silently ignored. */
    public function testADesignThisSurfaceDoesNotShipIsRefused(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request('POST', $this->libraryUrl().'/preset/nonsense', [
            '_token' => $this->csrfToken(),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** Every widget write carries a token; without one the host's endpoint refuses. */
    public function testAWriteWithoutATokenIsRefused(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request('POST', $this->libraryUrl().'/reset');

        self::assertResponseStatusCodeSame(403);
    }

    /** The token is scoped per area, so one area's library cannot rearrange another's. */
    public function testAnotherAreasTokenIsRefused(): void
    {
        $other = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[1.0,1.0],[1.2,1.0],[1.2,1.2],[1.0,1.2],[1.0,1.0]]]]}',
        );
        $this->em->persist($other);
        $this->em->flush();
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', '/areas/'.$other->getUuidString().'/modules/patrols/widgets');
        $otherToken = (string) $crawler->filter('['.WidgetDom::CSRF_TOKEN.']')->attr(WidgetDom::CSRF_TOKEN);

        $this->client->request('POST', $this->libraryUrl().'/reset', ['_token' => $otherToken]);

        self::assertResponseStatusCodeSame(403);
    }

    /** The token id is the host's, scoped per surface AND per area. */
    public function testTheTokenIsScopedToThisSurfaceAndThisArea(): void
    {
        self::assertSame(
            'widgets_patrols_'.$this->area->getUuidString(),
            WidgetEndpoint::csrfTokenId(PatrolWidgets::SURFACE, $this->area->getUuid()),
        );
    }

    /** The library is one person's, so it needs one — anonymous gets nothing. */
    public function testTheLibraryNeedsSomebodySignedIn(): void
    {
        $this->signOut($this->client);

        $this->client->request('GET', $this->libraryUrl());

        self::assertResponseStatusCodeSame(401);
    }

    /** One person's layout is not another's. */
    public function testOnePersonsLayoutIsNotAnothers(): void
    {
        $other = new User()->setPassword('x')->setEmail('other@example.test')->setFirstName('Ben')->setLastName('Beta');
        $this->em->persist($other);
        $this->em->flush();
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        $this->client->loginUser($this->ranger);
        $this->client->request('POST', $this->libraryUrl().'/preset/default/copy', [
            '_token' => $this->csrfToken(),
        ]);
        $this->postJson($this->saveUrl(), [
            'order' => ['kpis'],
            'widgets' => ['kpis' => ['on' => true, 'cols' => 12], 'map' => ['on' => false, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertCount(1, $crawler->filter('[data-w="map"]'));
    }

    /** Arranging one area leaves every other area alone. */
    public function testArrangingOneAreaLeavesAnotherUntouched(): void
    {
        $other = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[1.0,1.0],[1.2,1.0],[1.2,1.2],[1.0,1.2],[1.0,1.0]]]]}',
        );
        $this->em->persist($other);
        $this->em->flush();
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        $this->client->loginUser($this->ranger);
        $this->client->request('POST', $this->libraryUrl().'/preset/default/copy', [
            '_token' => $this->csrfToken(),
        ]);
        $this->postJson($this->saveUrl(), [
            'order' => ['kpis'],
            'widgets' => ['kpis' => ['on' => true, 'cols' => 12], 'map' => ['on' => false, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', '/areas/'.$other->getUuidString().'/modules/patrols');
        self::assertCount(1, $crawler->filter('[data-w="map"]'));
    }

    /**
     * The full custom-preset lifecycle through the module's routes: create from
     * a posted composition, apply, rename, delete — every one a host endpoint
     * this module only routes to.
     */
    public function testACustomPresetCanBeCreatedRenamedAndDeleted(): void
    {
        $this->client->loginUser($this->ranger);

        // Create: the "+ New preset" canvas posts a name AND the composition.
        $this->postJson($this->libraryUrl().'/presets', [
            'name' => 'Night shift',
            'order' => ['cal', 'kpis'],
            'widgets' => [
                'cal' => ['on' => true, 'cols' => 12],
                'kpis' => ['on' => true, 'cols' => 12],
                'map' => ['on' => false, 'cols' => 12],
                'log' => ['on' => false, 'cols' => 12],
                'feed' => ['on' => false, 'cols' => 12],
                'chweek' => ['on' => false, 'cols' => 6],
                'chstation' => ['on' => false, 'cols' => 6],
            ],
        ]);
        self::assertResponseRedirects($this->libraryUrl());

        $library = $this->client->followRedirect();
        self::assertStringContainsString('Night shift', $library->html());

        // Creating it made it active, so the dashboard now IS that composition.
        $dashboard = $this->client->request('GET', $this->dashboardUrl());
        self::assertSame(
            ['cal', 'kpis'],
            $dashboard->filter('.patrol-wgrid > [data-w]')->each(static fn (Crawler $w) => (string) $w->attr('data-w')),
        );

        // Rename it, through the uuid its card carries.
        $uuid = $this->activePresetUuid();
        $this->client->request('POST', $this->libraryUrl().'/presets/'.$uuid.'/rename', [
            '_token' => $this->csrfToken(),
            'name' => 'Quiet month',
        ]);
        self::assertResponseRedirects();
        self::assertStringContainsString('Quiet month', $this->client->followRedirect()->html());

        // Delete it: the dashboard falls back to the shipped composition.
        $this->client->request('POST', $this->libraryUrl().'/presets/'.$uuid.'/delete', [
            '_token' => $this->csrfToken(),
        ]);
        self::assertResponseRedirects();

        $after = $this->client->request('GET', $this->dashboardUrl());
        self::assertSame(
            self::WIDGET_IDS,
            $after->filter('.patrol-wgrid > [data-w]')->each(static fn (Crawler $w) => (string) $w->attr('data-w')),
        );
    }

    /** Another person's preset answers 404 — the same answer as one that never existed. */
    public function testAnotherPersonsPresetIsNotFound(): void
    {
        $this->client->loginUser($this->ranger);

        $this->client->request(
            'POST',
            $this->libraryUrl().'/presets/00000000-0000-4000-8000-000000000001/apply',
            ['_token' => $this->csrfToken()],
        );

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The active custom preset's uuid, read off the library the way the script
     * reads it: from the component's own catalogue JSON.
     */
    private function activePresetUuid(): string
    {
        $crawler = $this->client->request('GET', $this->libraryUrl());
        $json = $crawler->filter('script['.WidgetDom::CATALOG.']')->text(normalizeWhitespace: false);
        /** @var array{active: array{kind: string, id: string}} $catalog */
        $catalog = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('mine', $catalog['active']['kind'], 'The freshly created preset should be active.');

        return $catalog['active']['id'];
    }

    /**
     * The library's own save call: a JSON body plus the CSRF token the page
     * rendered, in the header the host's script sends.
     *
     * @param array<string, mixed> $payload
     */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request(
            'POST',
            $url,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $this->csrfToken(),
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** The token the library screen mints for this area, read the way a browser would. */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', $this->libraryUrl());

        return (string) $crawler->filter('['.WidgetDom::CSRF_TOKEN.']')->attr(WidgetDom::CSRF_TOKEN);
    }

    private function dashboardUrl(): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols';
    }

    private function libraryUrl(): string
    {
        return $this->dashboardUrl().'/widgets';
    }

    private function saveUrl(): string
    {
        return $this->libraryUrl().'/save';
    }
}
