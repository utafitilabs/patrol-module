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

namespace Uhifadhi\Patrol\Tests\Integration;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use League\FlysystemBundle\FlysystemBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\CollectedContentProviders;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\CollectedGeoProviders;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;
use Uhifadhi\Storage\Controller\EvidenceController;
use Uhifadhi\Storage\Controller\UploadController;
use Uhifadhi\Storage\UhifadhiStorageBundle;
use UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The smallest INSTALLATION this bundle can live in, and every part of it is
 * real: framework + twig + doctrine + PostGIS + security + api-platform, the
 * five core bundles — the shell every patrol screen renders through and the
 * widget framework the dashboard IS, the atlas its maps draw with, the area a
 * patrol happens in, the registry that switches this module on there and the
 * team the account class comes from — and the storage the photographs go to,
 * against a REAL PostGIS database (PATROL_TEST_DATABASE_URL, see
 * phpunit.dist.xml). Vocabulary config uses the synthetic example domain.
 *
 * NOTHING HERE IS A COPY. Every bundle above is the published one, because a
 * copy cannot hold a contract — it pins whatever the copyist believed.
 *
 * THE AREA, THE PLACE AND THE PERSON ALL COME FROM THE CORE. Patrol's records
 * point at an area (AreaBundle) and at a person (the class an installation
 * resolves the contract to, which TeamBundle answers). Neither is this bundle's
 * to define, and neither is stubbed here.
 *
 * TEAM AND AREA ARE BOOTED FOR THEIR MODELS, NOT FOR THEIR DASHBOARDS. Both
 * carry widget surfaces of their own, which would land in the registry beside
 * this module's; {@see OnlyThisModulesSurfacesPass} keeps them out, so what this
 * suite asserts about the registry stays about PATROLS.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        // UX Map and its Leaflet bridge: the atlas's plates are built on them,
        // and every patrol screen that draws a map renders through them.
        yield new UXMapBundle();
        yield new DoctrineBundle();
        // An installation has this, and this module ships a history for it to run.
        // Without it the bundle's migrations_paths block is guarded out and
        // tests/Integration/Migrations has nothing to assert.
        yield new DoctrineMigrationsBundle();
        yield new UtafitiLabsPostGISBundle();
        yield new SecurityBundle();
        // An installation installs api-platform; this stands in for one so the
        // bundle's own sync endpoints can be exercised.
        yield new ApiPlatformBundle();
        // The per-area catalogue this module registers itself in.
        yield new RegistryBundle();
        // The frame every patrol screen renders in, and the widget framework
        // the dashboard IS — one bundle, because the shell owns both.
        yield new ShellBundle();
        // Leaflet, the map chrome and the map stylesheet every patrol plate
        // draws with.
        yield new AtlasBundle();
        // For the account class every patrol, observation and stored layout is
        // keyed by, the org chart the department figures walk, and the token
        // authenticator the /api firewall runs on.
        yield new TeamBundle();
        // The place a patrol happens in, the zones the gap card reads, and the
        // six contributions this module makes to an area's overview.
        yield new AreaBundle();
        // Where observation photos go. A hard dependency of this bundle, and
        // registered here in the order a host registers it: flysystem first,
        // because the storage bundle PREPENDS a flysystem storage.
        yield new FlysystemBundle();
        yield new UhifadhiStorageBundle();
        yield new UhifadhiPatrolBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // loginUser() needs a stateful firewall and flashes need a session;
            // the mock file storage is the documented test-env choice.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // The widget library's two writes carry a CSRF token, so the token
            // manager must exist here as it does in a real host (FrameworkBundle
            // only defines it when csrf_protection is on).
            'csrf_protection' => ['enabled' => true],
            // api-platform's own services need these three; a real host running
            // api-platform has them via the framework recipe.
            'property_access' => true,
            'property_info' => ['enabled' => true],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
            // asset() has to exist: the shell's document and this module's base
            // template both link stylesheets with it. AssetMapper takes over
            // path resolution here, exactly as in a real installation.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        // The security block a skeleton installation writes, minus the screens
        // this kernel does not mount. The hashers, the entity provider over the
        // account TeamBundle owns and the checker that refuses a deactivated one
        // are the core's own throwaway application's; permission checks go
        // through the real AuthorizationChecker rather than a stub that always
        // says yes, and the people are TeamBundle's entity rather than
        // InMemoryUser because a patrol and a stored layout both carry a foreign
        // key to a person and an in-memory one has no row to point at.
        //
        // No form_login and no team_login route: this kernel mounts none of
        // TeamBundle's sign-in screens and signs people in through loginUser().
        //
        // @see vendor/uhifadhi/uhifadhi/tests/Application/Kernel.php
        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    // Test-only cost floor, the documented Symfony practice.
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                // The machine door, wired exactly as an installation wires it:
                // STATELESS, over TeamBundle's own bearer-token authenticator,
                // which is also the entry point so a request with no token
                // answers 401 rather than the access listener's 403.
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                    'custom_authenticators' => [ApiTokenAuthenticator::class],
                    'entry_point' => ApiTokenAuthenticator::class,
                ],
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                ],
            ],
            'access_control' => [
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
            ],
        ]);

        // The HOST's permission voter, played by a fixture: the bundle declares
        // "patrols.record" and grants it to nobody, so something has to decide
        // who holds it. Tagged by hand — a reusable-bundle test kernel does not
        // autoconfigure.
        $container->services()->set(FixedRecordVoter::class)->tag('security.voter');

        // And DEVKIT's content collector, which the migrations upgrade lock
        // seeds through: this module's demo month depends on team's people, and
        // the tag is where that dependency is actually satisfied. devkit is
        // require-dev and absent here, so the collector is a fixture reading the
        // same tag its command reads.
        $container->services()->set(CollectedContentProviders::class)
            ->args([tagged_iterator('uhifadhi.devkit.content_provider')])->public();
        $container->services()->alias('test_public.devkit.content_providers', CollectedContentProviders::class)->public();

        // And the performance page's GROUND collector. The core publishes the
        // seam and nothing in it reads the tag yet, so a fixture reads the tag
        // the page will read — a provider that was never tagged has to be
        // invisible here exactly as it would be there.
        $container->services()->set(CollectedGeoProviders::class)
            ->args([tagged_iterator(PerformanceGeoProviderInterface::TAG)])->public();
        $container->services()->alias('test_public.performance.geo_providers', CollectedGeoProviders::class)->public();

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(PATROL_TEST_DATABASE_URL)%',
            ],
            'orm' => [
                // The skeleton's own choice (config/packages/doctrine.yaml),
                // mirrored here so the bundle's metadata-driven SQL is exercised
                // against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO 'mappings' AND NO 'resolve_target_entities' HERE, both
                // deliberately. Every entity this module points at arrives with
                // the bundle that owns it — the area and its zones from
                // AreaBundle, the person and the org chart from TeamBundle — and
                // each maps its own; TeamBundle prepends the user contract's
                // resolution from its own bundle, so an installation writes no
                // doctrine line at all. If either ever stopped happening the
                // schema would not build and this whole suite would say so at
                // once.
            ],
        ]);

        // WHAT A DEPLOYMENT SETS, AND WHY IT IS HERE. With on-demand fetching
        // on, a name no file answers to is fetched from a remote API and
        // cached, so a missing glyph stays invisible until the deployment that
        // has no outbound network draws a blank square. An installation turns
        // it off, and this kernel is an installation. Every name these screens
        // draw is answered from a directory a bundle registers — `patrol:` by
        // this one, `shell:` by the shell — which is what the vocabulary
        // conformance test holds them to.
        //
        // @see https://symfony.com/bundles/ux-icons/current/index.html#icons-on-demand
        $container->extension('ux_icons', [
            'iconify' => ['on_demand' => false],
        ]);

        // Which renderer draws the maps. UX Map draws nothing at all until a
        // renderer is named, and an installation names this one.
        //
        // @see https://symfony.com/bundles/ux-map/current/index.html#configuration
        $container->extension('ux_map', ['renderer' => 'leaflet://default']);

        $services = $container->services();

        // Public aliases so tests can fetch the bundle's private services, keyed
        // by class name for readability (see IntegrationTestCase). Needed only
        // until controllers reference them.
        foreach ([
            \Uhifadhi\Patrol\Service\TrackIngestService::class => 'patrol.track_ingest',
            \Uhifadhi\Patrol\Service\GpxParser::class => 'patrol.gpx_parser',
            // The area-scoped observation-taxonomy admin's logic, reached directly
            // by its integration test.
            \Uhifadhi\Patrol\Service\TaxonomyAdminService::class => 'patrol.taxonomy_admin',
            // The area's own patrol types and stations, reached directly by
            // their integration test.
            \Uhifadhi\Patrol\Service\PatrolVocabularyService::class => 'patrol.vocabulary',
            \Uhifadhi\Patrol\Repository\StationRepository::class => \Uhifadhi\Patrol\Repository\StationRepository::class,
            // The inert descriptor devkit materialises into a command. Nothing
            // collects it here — devkit is not in this kernel — so a test holds
            // it and calls the handler devkit would have called.
            \Uhifadhi\Patrol\Devkit\PatrolCommandProvider::class => 'patrol.devkit.commands',
            // The two halves of the storage contract, and the registry the hub reads
            // through — so a test can prove the tag was applied AND that the two
            // halves still claim the same keys.
            \Uhifadhi\Patrol\Storage\PatrolFileSource::class => 'patrol.file_source',
            \Uhifadhi\Patrol\Security\PatrolEvidenceVoter::class => 'patrol.evidence_voter',
            \Uhifadhi\Storage\Registry\FileRegistry::class => 'storage.file_registry',
            // The installation's live catalogue of concerns — every pair every
            // installed package declares. A test that composes a real position
            // needs it, because writing grants onto one validates against it.
            \Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue::class => 'team.access.catalogue',
            // The module's six contributions to the host's area overview, and
            // the one reading behind all of them. A host reaches them through
            // their TAGS; these aliases only let a test hold one directly.
            \Uhifadhi\Patrol\Service\PatrolOverviewService::class => 'patrol.overview',
            \Uhifadhi\Patrol\Overview\PatrolOverviewContributor::class => 'patrol.overview.contributor',
            \Uhifadhi\Patrol\Overview\PatrolNowTiles::class => 'patrol.overview.now_tiles',
            \Uhifadhi\Patrol\Overview\PatrolAttention::class => 'patrol.overview.attention',
            \Uhifadhi\Patrol\Overview\PatrolMapLayers::class => 'patrol.overview.map_layers',
            \Uhifadhi\Patrol\Overview\PatrolPulse::class => 'patrol.overview.pulse',
            \Uhifadhi\Patrol\Overview\PatrolOverviewCopy::class => 'patrol.overview.copy',
            // And the ONE contribution this module makes to the organization
            // dashboard, with the reading behind it — reached by a host through
            // its tag; these aliases only let a test hold them directly.
            \Uhifadhi\Patrol\Service\PatrolOrgOverviewService::class => 'patrol.org_overview',
            \Uhifadhi\Patrol\Org\PatrolOrgWidgets::class => 'patrol.org_widgets',
            // The widget framework, by the ids ShellBundle publishes,
            // plus the registry a surface has to be findable in.
            \Uhifadhi\Patrol\Service\PatrolRecordingService::class => 'patrol.recording',
            \Uhifadhi\Patrol\Devkit\PatrolContentProvider::class => 'patrol.devkit.content',
            \Uhifadhi\Patrol\Service\PatrolHoldService::class => 'patrol.hold',
            \Uhifadhi\Patrol\Service\PatrolCalendar::class => 'patrol.calendar',
            \Uhifadhi\Patrol\Service\ObservationAmendmentService::class => 'patrol.observation_amendments',
            \Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService::class => 'shell.widget.service',
            \Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint::class => 'shell.widget.endpoint',
            \Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceRegistry::class => 'shell.widget.surfaces',
            // The catalogue this module registers itself in, so a test can ask
            // whether Patrols is in it rather than trusting the tag.
            \Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue::class => 'registry.catalogue',
            \Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver::class => 'registry.entry_routes',
            // Per-area install state — what the route gate reads. A page test
            // has to switch this module on for its area, because a parked one's
            // routes are 404 and a fixture area starts with no row at all.
            \Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService::class => 'registry.area_modules',
            // The create-only reconciliation that puts this module in the
            // catalogue. A warm-up runs it in an installation; a suite that
            // drops and recreates the schema after boot calls it itself.
            \Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService::class => 'registry.sync',
            // The credential a field client carries. The sync tests mint a real
            // token through it, so the requests they make cross the same
            // authenticator an installation's do.
            \Uhifadhi\Bundle\TeamBundle\Service\ApiTokenManager::class => 'team.api_token.manager',
            // The host's collector for performance topics. A topic test asks it
            // rather than the container's service list, because what has to be
            // proved is that the TAG reached the page — a provider nobody
            // collected is a perfect class the page never mentions.
            \Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics::class => 'team.performance_topics',
            // Who the departments are, what they attach, and since when they
            // could have been asked — the one read a topic starts with.
            \Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface::class => 'team.department_directory',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }

        // Mirrors an installation's api_platform.yaml: JSON only, stateless. The sync
        // endpoints are asserted against the CONTRACT's literal field names, so
        // a JSON-LD default here would be testing something the app never sees.
        $container->extension('api_platform', [
            'title' => 'Patrol module test API',
            'version' => '1.0.0',
            'formats' => ['json' => ['application/json']],
            'defaults' => ['stateless' => true],
        ]);

        // The evidence storage the photo tests write real bytes into — a
        // throwaway directory, because a mocked filesystem would only prove the
        // mock. The rest is the bundle's own defaults, which is what a host gets.
        $container->extension('storage', [
            'evidence' => [
                'adapter' => 'local',
                'directory' => sys_get_temp_dir().'/patrol-module-tests/evidence',
                /*
                 * A TRACK IS A FILE TOO, and the deployment's allowlist is what
                 * the storage validates against on the way in — a target may
                 * narrow it and may never widen past it. A GPX document is
                 * detected from its BYTES, which makes it generic XML on most
                 * platforms, so both spellings sit here beside the photographs.
                 *
                 * This is the one line an installation adds so the entry flow's
                 * step 1 can accept anything at all; the module's README states
                 * it for exactly that reason.
                 */
                'allowed_mime_types' => [
                    'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp',
                    'application/gpx+xml', 'application/xml', 'text/xml',
                ],
            ],
        ]);

        $container->extension('patrol', [
            // Synthetic example vocabulary (never a client's). Deliberately NOT
            // the field app's words: patrol types and observation categories are
            // DEPLOYMENT config, and the sync tests prove the endpoints work
            // against whatever a deployment configured rather than against a
            // list hard-coded to match one client.
            //
            // "drone" is the exception, and only because it carries behaviour —
            // no track, declared sectors (Â§5, Â§7) — so the rule has something
            // real to fire on.
            'types' => [
                'walk' => ['label' => 'Walking round'],
                'boat' => ['label' => 'Boat'],
            ],
            'observation_categories' => [
                'maintenance' => ['label' => 'Maintenance need'],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $controllers = \dirname(__DIR__, 2).'/src/Controller/';
        if (is_dir($controllers)) {
            $routes->import($controllers, 'attribute');
        }

        // The evidence serving route, mounted exactly as a host mounts it: the
        // storage bundle's controller carries its own #[Route], so the host
        // imports the directory. Without this the photos card would link at
        // nothing and the voter would never be asked anything.
        // Only the serving route: the storage bundle's Files hub is a host
        // screen standing on the shell's widget framework, which a bundle test
        // kernel does not have and does not need.
        $evidence = (new \ReflectionClass(EvidenceController::class))->getFileName();
        if (\is_string($evidence)) {
            $routes->import($evidence, 'attribute');
        }

        // The ONE endpoint every upload in the product goes through, mounted the
        // way a host mounts it. The entry flow's track and every evidence tile
        // POST here, so a suite that never mounted it would be asserting a form
        // whose files could not arrive.
        $upload = (new \ReflectionClass(UploadController::class))->getFileName();
        if (\is_string($upload)) {
            $routes->import($upload, 'attribute');
        }

        // The /api entry point, mounted exactly as the host mounts it
        // (config/routes/api_platform.yaml). The bundle's own ApiResource
        // classes are discovered by api-platform without any registration —
        // see src/ApiResource/PatrolSync.php.
        $routes->import('.', 'api_platform')->prefix('/api');

        // THE SCREENS THIS MODULE'S CRUMB POINTS AT, mounted from the bundles
        // that own them rather than declared as bare paths here. The area
        // register, the area page and the per-area module grid are AreaBundle's;
        // the front door is the shell's.
        $routes->import('@ShellBundle/Controller/', 'attribute');
        $routes->import('@AreaBundle/Controller/', 'attribute');

        // THE CONFIGURE PAGE, mounted the way an installation mounts it — a
        // resource the shell ships and an application asks for in one line. This
        // module declares its configure sections through the contract, and
        // without the page behind them a declared section has no address and the
        // strip drops it.
        $routes->import(ShellBundle::CONFIGURE_ROUTES);

        // THE INCIDENTS MODULE'S FRONT DOOR, STUBBED — but only in the
        // `incident_contract` environment. The File-as-incident button exists
        // only where a host installs an incidents module exposing `incident_new`
        // (the contract is the route name + prefill query keys, and neither
        // bundle names the other's classes). Most of this suite runs with no
        // such module, so the button is honestly absent; a test that needs to
        // inspect the URL it builds boots this one environment, where the route
        // exists to be generated (never dispatched — nothing here navigates to
        // it, so it carries no controller).
        if ('incident_contract' === $this->environment) {
            $routes->add('incident_new', '/areas/{uuid}/modules/incidents/new')
                ->methods(['GET']);
        }
    }

    public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);

        // Team and area are booted for their models, not for their dashboards.
        $container->addCompilerPass(new OnlyThisModulesSurfacesPass());
    }

    /**
     * THE STAND-IN INSTALLATION'S PROJECT DIRECTORY — an application's asset side
     * and nothing else. The shell's document renders the importmap of whatever
     * application it is installed in, so a suite that renders any page through
     * the page frame needs an application that has one. Pointing the kernel at a
     * fixture is how it gets one without this bundle growing an importmap of its
     * own, which a shipped bundle has no business carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    public function getCacheDir(): string
    {
        // Namespaced by environment: a suite that boots a second environment
        // (see the `incident_contract` route above) must not share a compiled
        // container with the default one.
        return sys_get_temp_dir().'/patrol-module-tests/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/patrol-module-tests/log/'.$this->environment;
    }
}
