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

namespace Uhifadhi\Patrol;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayerProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTileProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OverviewCopyProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\PulseProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Patrol\Api\PatrolApiContext;
use Uhifadhi\Patrol\Api\State\AppendEventsProcessor;
use Uhifadhi\Patrol\Api\State\AppendFlightsProcessor;
use Uhifadhi\Patrol\Api\State\AppendObservationsProcessor;
use Uhifadhi\Patrol\Api\State\AppendTrackProcessor;
use Uhifadhi\Patrol\Api\State\CompletePatrolProcessor;
use Uhifadhi\Patrol\Api\State\CreatePatrolProcessor;
use Uhifadhi\Patrol\Api\State\UploadPhotoProcessor;
use Uhifadhi\Patrol\Api\State\VocabularyProvider;
use Uhifadhi\Patrol\Command\PurgeDiscardedCommand;
use Uhifadhi\Patrol\Controller\ObservationAmendmentController;
use Uhifadhi\Patrol\Controller\PatrolHoldController;
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Controller\PatrolSettingsController;
use Uhifadhi\Patrol\Controller\PatrolTaxonomyController;
use Uhifadhi\Patrol\Controller\PatrolVocabularyController;
use Uhifadhi\Patrol\Controller\PatrolWidgetsController;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Devkit\PatrolCommandProvider;
use Uhifadhi\Patrol\Devkit\PatrolContentProvider;
use Uhifadhi\Patrol\Module\PatrolDepartmentKpiProvider;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Module\PatrolPerformanceGeo;
use Uhifadhi\Patrol\Module\PatrolPerformanceTopic;
use Uhifadhi\Patrol\Module\PatrolStationFigureProvider;
use Uhifadhi\Patrol\Module\PatrolZoneFigureProvider;
use Uhifadhi\Patrol\Org\PatrolOrgWidgets;
use Uhifadhi\Patrol\Overview\PatrolAttention;
use Uhifadhi\Patrol\Overview\PatrolMapLayers;
use Uhifadhi\Patrol\Overview\PatrolNowTiles;
use Uhifadhi\Patrol\Overview\PatrolOverviewContributor;
use Uhifadhi\Patrol\Overview\PatrolOverviewCopy;
use Uhifadhi\Patrol\Overview\PatrolPulse;
use Uhifadhi\Patrol\Repository\FlightRepository;
use Uhifadhi\Patrol\Repository\LaunchPointRepository;
use Uhifadhi\Patrol\Repository\ObservationPhotoRepository;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolDraftFileRepository;
use Uhifadhi\Patrol\Repository\PatrolEventRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Patrol\Repository\TrackBatchRepository;
use Uhifadhi\Patrol\Repository\TrackPointRepository;
use Uhifadhi\Patrol\Security\PatrolEvidenceVoter;
use Uhifadhi\Patrol\Service\Api\FlightSyncService;
use Uhifadhi\Patrol\Service\Api\ObservationSyncService;
use Uhifadhi\Patrol\Service\Api\PatrolCompletionService;
use Uhifadhi\Patrol\Service\Api\PatrolEventService;
use Uhifadhi\Patrol\Service\Api\PatrolUpsertService;
use Uhifadhi\Patrol\Service\Api\PhotoSyncService;
use Uhifadhi\Patrol\Service\Api\RangerResolver;
use Uhifadhi\Patrol\Service\Api\TrackBatchService;
use Uhifadhi\Patrol\Service\Api\VocabularySyncService;
use Uhifadhi\Patrol\Service\PatrolFigureService;
use Uhifadhi\Patrol\Service\PatrolOrgOverviewService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\Service\PhotoThumbnailBackfillService;
use Uhifadhi\Patrol\Storage\PatrolFileSource;
use Uhifadhi\Patrol\Upload\PatrolObservationPhotoTarget;
use Uhifadhi\Patrol\Upload\PatrolTrackTarget;
use Uhifadhi\Patrol\Widget\PatrolWidgets;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Upload\UploadTargetInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Patrols — field patrol effort as first-class records: GPX track ingest,
 * en-route observations with photos, coverage mapping and a per-user widget
 * dashboard.
 *
 * Zero-config: registering the bundle maps its own entities (no host doctrine
 * block needed) and registers the domain services. Spatial columns ride on
 * utafitilabs/postgis-bundle.
 */
final class UhifadhiPatrolBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S VOCABULARY IS SERVED FROM — what AssetMapper serves
     * public/patrol.css under, stated once because it has two readers that must
     * never disagree: `templates/base.html.twig`, which links it on every patrol
     * page of this module's own, and
     * {@see PatrolOverviewContributor::stylesheet()},
     * which hands it to a HOST that is rendering this module's plates on the
     * area overview. The bundle's name is the bundle's own knowledge.
     */
    public const string STYLESHEET = 'bundles/uhifadhipatrol/patrol.css';

    /**
     * The observation-taxonomy admin's own component stylesheet, served the same
     * way and linked ONLY by the taxonomy screen (see taxonomy/show.html.twig) —
     * its `tx-` vocabulary has no reader anywhere else.
     */
    public const string TAXONOMY_STYLESHEET = 'bundles/uhifadhipatrol/taxonomy.css';

    /** Config lives under "patrol:", not the class-derived "uhifadhi_labs_patrol:". */
    protected string $extensionAlias = 'patrol';

    public function configure(DefinitionConfigurator $definition): void
    {
        PatrolConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhipatrol` and content-versioned — no
        // config here, no assets:install.

        // Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
        // namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
        // The recipe enables them in the host's assets/controllers.json.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => '@uhifadhi/patrol-module',
                    ],
                ],
            ]);
        }

        /*
         * THE GLYPHS THIS MODULE DRAWS, SHIPPED WITH IT AND RESOLVED FROM DISK.
         *
         * An icon set maps a prefix to a single directory, and a prefix so
         * mapped is answered ONLY from that directory — the lookup never falls
         * back to an installation's icon_dir. So each package answers for its
         * own alias and no other: the shell's marks stay reachable as `shell:`,
         * and everything this module draws of its own is `patrol:`. A public
         * library's prefix is never named from a bundle, `lucide:` included —
         * that word belongs to the installation, which may answer it with its
         * own artwork or, on a deployment with fetching disabled, not at all.
         *
         * @see https://symfony.com/bundles/ux-icons/current/index.html#full-configuration
         * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/ShellBundle.php
         */
        if ($builder->hasExtension('ux_icons')) {
            $container->extension('ux_icons', [
                'icon_sets' => [
                    'patrol' => ['path' => __DIR__.'/../assets/icons/patrol'],
                ],
            ]);
        }

        /*
         * THE SQL THAT CREATES THIS MODULE'S TABLES, SHIPPED WITH IT.
         *
         * An installation runs `doctrine:migrations:migrate` and writes no
         * version for patrol_* — the same way it writes none for the core.
         * `doctrine:migrations:diff` stays what it runs for the entities IT
         * writes, and after an update of this package it must report no changes.
         *
         * One guarded block, and an installation configures nothing:
         *
         * > migrations_paths: A list of namespace/path pairs where to look for
         * > migrations.
         *
         * The guard is not decoration: an application may have this bundle and
         * not the migrations bundle, and there this module simply has no history
         * to run.
         *
         * The namespace is mapped by this package's composer.json with an
         * EXPLICIT psr-4 prefix. `Uhifadhi\Patrol\` is `src/`, so a lowercase
         * `migrations/` directory resolves under nothing; the failure would show
         * up only on a case-sensitive filesystem, which is to say in an
         * installation and not here.
         *
         * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
         * @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php
         *      — the `migrations_paths` node, keyed by namespace.
         * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AreaBundle/AreaBundle.php
         */
        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Uhifadhi\\Patrol\\Migrations' => __DIR__.'/../migrations',
                ],
            ], prepend: true);
        }

        // Zero-config persistence: the bundle maps its own entities, so hosts
        // never write a doctrine mappings block for patrol_* tables.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiPatrol' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Patrol\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php for
        // the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the host's
        // catalogue seed + module grid. The host tags every ModuleProviderInterface
        // via registerForAutoconfiguration, but that only fires for autoconfigured
        // services — and a reusable bundle doesn't autoconfigure — so the tag is
        // applied explicitly here.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'operations';
        $services->set('patrol.module_provider', PatrolModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module');

        // The deployment's vocabulary (patrol types, observation categories) and
        // ingest thresholds, exposed as parameters for the services that need them.
        $types = $config['types'] ?? [];
        $builder->setParameter('patrol.types', \is_array($types) ? $types : []);
        $categories = $config['observation_categories'] ?? [];
        $builder->setParameter('patrol.observation_categories', \is_array($categories) ? $categories : []);
        $gap = $config['gap_threshold_minutes'] ?? 5.0;
        $builder->setParameter('patrol.gap_threshold_minutes', \is_float($gap) || \is_int($gap) ? (float) $gap : 5.0);
        // How long a discarded patrol survives. Read by the purge command and by
        // the detail screen, which states the same window to the person looking
        // at the patrol — one number, so the page cannot promise a date the
        // command will not honour.
        $retention = $config['discard_retention_days'] ?? PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS;
        $builder->setParameter(
            'patrol.discard_retention_days',
            \is_int($retention) ? $retention : PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
        );

        /*
         * THE ONE ENTRY FLOW is registered ONLY inside this guard. It is the only
         * screen that creates patrols, so it must never exist unprotected:
         * without symfony/security there is no authorization checker to enforce
         * "patrols.record", and a host in that state gets no recording controller
         * at all (the routes fail loudly) rather than an open write endpoint. The
         * two upload targets sit under the same guard and for the same reason: a
         * target registered where nothing can refuse would be a door with no
         * lock — and the screens inside this guard state their pair with
         * #[IsGranted], whose listener ships in symfony/security-http and is
         * therefore only ever present here. See PatrolRecordController.
         *
         * The guard asks whether SecurityBundle is actually in the kernel, read
         * from the kernel.bundles parameter. Two other checks look right and are
         * not: hasExtension('security') cannot be used here, because while an
         * extension is loading the builder is a restricted
         * MergeExtensionConfigurationContainerBuilder that does not expose other
         * extensions; and interface_exists() only proves a class is autoloadable —
         * security-core is one of this bundle's DEV dependencies, so it autoloads
         * in our own test runs even when SecurityBundle is absent, and services
         * would then reference security.* ids that do not exist. FrameworkExtension
         * reads kernel.bundles for exactly this reason.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);

        /*
         * THE EVIDENCE STORAGE — a hard requirement, said here where a developer
         * will read it rather than as a missing "storage.evidence_storage" three
         * screens into a stack trace.
         *
         * Not made optional on purpose. Photographs are not a decorative extra
         * on an observation: the upload endpoint takes them, the detail screen
         * shows them, and both would have to grow a "…unless the host skipped
         * storage" branch that no deployment would ever run. One composer
         * dependency and one line in bundles.php is the cheaper contract.
         */
        if (!\is_array($bundles) || !isset($bundles['UhifadhiStorageBundle'])) {
            throw new \LogicException('UhifadhiPatrolBundle stores observation photos in uhifadhi/storage-module. Register FlysystemBundle and Uhifadhi\Storage\UhifadhiStorageBundle in config/bundles.php.');
        }

        /*
         * PATROL'S HALF OF THE PERMISSION CONTRACT.
         *
         * Storage denies any key no module claims, so without this service every
         * patrol photograph is a 403 — the right failure direction, and the
         * reason the voter ships in the same change as the rewire. Registered
         * unconditionally, beside the entities rather than inside the field-API
         * guard: a host that never installed api-platform can still HOLD photos
         * (imported, seeded, migrated) and must still be able to show them.
         *
         * Tagged explicitly. A reusable bundle is not autoconfigured, and a
         * module that forgot this tag would silently lose access to its own
         * evidence — a confusing way to find out.
         */
        /*
                 * THE TWO WRITES THE FIELD APP MAKES — appending a patrol's observations
                 * and storing one photograph — registered UNCONDITIONALLY, beside the
                 * evidence voter and the file source and for the same reason those are:
                 * an installation that never installed api-platform can still HOLD
                 * observations and photographs (imported, seeded, migrated) and must
                 * still be able to write them.
                 *
                 * The processors that put them on /api stay inside the api-platform
                 * guard below; only the door is guarded, exactly as it is for
                 * 'patrol.taxonomy_admin' and the three screen writes.
                 *
                 * The photo bytes go to the platform's evidence storage, by service id —
                 * the storage bundle is a reusable bundle and its ids are its public
                 * surface (its docs/service-reference.md).
                 */
        $services->set('patrol.api.observation_sync', ObservationSyncService::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(ObservationRepository::class),
                service(LaunchPointRepository::class),
                service(FlightRepository::class),
                // The area's own observation vocabulary, which is what the
                // handset was handed by the vocabulary endpoint. The
                // deployment-wide list below stays beside it while the two
                // models coexist.
                service('patrol.taxonomy_admin'),
                param('patrol.observation_categories'),
            ]);

        $services->set('patrol.api.photo_sync', PhotoSyncService::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(ObservationPhotoRepository::class),
                service('storage.evidence_storage'),
            ]);

        $services->set('patrol.evidence_voter', PatrolEvidenceVoter::class)
            ->args([
                service(ObservationPhotoRepository::class),
                service(PatrolDraftFileRepository::class),
                service(PatrolRepository::class),
            ])
            ->tag('uhifadhi.evidence_access_voter');

        /*
         * PATROL'S FILES, ON THE PLATFORM'S FILES HUB.
         *
         * The other half of the same contract: the voter says who may READ a
         * photograph, this says which photographs exist and what may be done to
         * them. Registered beside it and for the same reason — a host that holds
         * photographs must be able to list them whether or not it ever installed
         * api-platform.
         *
         * Tagged by hand with the interface's own constant. A reusable bundle is
         * not autoconfigured, and a module that forgot this tag would simply not
         * appear on /files — the hub grows by MODULES, so a missing source looks
         * exactly like a module that was never installed.
         */
        $services->set('patrol.file_source', PatrolFileSource::class)
            ->args([service(ObservationPhotoRepository::class), service('router')])
            ->tag(FileSourceInterface::TAG);

        // The widget library edits ONE PERSON's layout, so it needs a signed-in
        // user for the same reason and lives under the same guard; a host without
        // security simply renders the design's default layout for everyone.
        $builder->setParameter('patrol.widget_screens', $hasSecurity);

        /*
         * THE PATROLS DASHBOARD IS A DECLARED WIDGET SURFACE, tagged by hand
         * because a reusable bundle is not autoconfigured. The tag is what makes
         * the surface FINDABLE: `widget:prune` walks the registry, and layouts
         * keyed to a surface no service claims are exactly what it deletes.
         *
         * OUTSIDE the security guard, unlike the library screen. The catalogue
         * is a statement of what this module ships and is true of every
         * installation that registered the bundle; an installation with no
         * firewall still RENDERS the surface — as the shipped composition, for
         * everyone — and the rows already stored against it from before a
         * firewall was removed must not read as orphans while it is gone.
         */
        $services->set('patrol.widget_surface', PatrolWidgets::class)
            ->tag(WidgetSurfaceInterface::TAG);

        if ($hasSecurity) {
            $services->set('patrol.controller.widgets', PatrolWidgetsController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service(PatrolRepository::class),
                    service('patrol.dashboard'),
                    service('patrol.calendar'),
                    service('patrol.map'),
                    service('patrol.coverage'),
                    // The day's live reading for the direction widgets the library
                    // previews (out now, gaps, the observation queue).
                    service('patrol.overview'),
                    // ShellBundle, BY ITS PUBLISHED SERVICE IDS: the
                    // module ships a catalogue (PatrolWidgets), never a copy of
                    // the algebra that resolves it — and that bundle's endpoint
                    // service answers every widget write, so this module
                    // validates no token and chooses no status code.
                    service('shell.widget.service'),
                    service('patrol.widget_urls'),
                    service('shell.widget.endpoint'),
                    service(TaxonomyKindRepository::class),
                    service('patrol.observation_kinds'),
                    service(PatrolTypeRepository::class),
                    param('patrol.discard_retention_days'),
                ])
                ->public();
            $services->alias(PatrolWidgetsController::class, 'patrol.controller.widgets')->public();

            $services->set('patrol.controller.record', PatrolRecordController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('doctrine.orm.entity_manager'),
                    service(PatrolTypeRepository::class),
                    service(StationRepository::class),
                    service(TaxonomyKindRepository::class),
                    service('patrol.vocabulary'),
                    service('patrol.map'),
                    service('patrol.drafts'),
                    service('patrol.recording'),
                    service('security.token_storage'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(PatrolRecordController::class, 'patrol.controller.record')->public();

            /*
             * THE TWO DOORS A FILE COMES THROUGH ON THE ENTRY FLOW — this
             * module's half of the platform's one upload component.
             *
             * Tagged BY HAND with the interface's own constant, because a
             * reusable bundle is not autoconfigured
             * (https://symfony.com/doc/current/bundles/best_practices.html) and
             * attributes are read off a definition's own class, never inherited
             * from an implemented interface. A kind nobody claims is refused, so
             * a missing tag here is not a warning — it is a dropzone that draws
             * nothing.
             *
             * INSIDE THE SECURITY GUARD, like the screen they serve. Both ask
             * "patrols.record" on an area, and a target registered where there
             * is no authorization checker would be a target that cannot refuse.
             * Where SecurityBundle is absent the storage simply finds no module
             * claiming these kinds, which is the same honest outcome as the
             * route not existing.
             */
            $services->set('patrol.upload.track', PatrolTrackTarget::class)
                ->args([
                    service('patrol.drafts'),
                    service('security.authorization_checker'),
                    service('patrol.track_ingest'),
                    service('storage.evidence_constraints'),
                ])
                ->tag(UploadTargetInterface::TAG);

            $services->set('patrol.upload.observation_photo', PatrolObservationPhotoTarget::class)
                ->args([
                    service('patrol.drafts'),
                    service('security.authorization_checker'),
                    service('storage.evidence_constraints'),
                ])
                ->tag(UploadTargetInterface::TAG);

            // Holding a discarded patrol for review — the detail screen's one
            // write. Under this guard for the same reason the entry flow
            // are: it changes a field record, so it must never exist where there
            // is no authorization checker to enforce "patrols.record".
            $services->set('patrol.controller.hold', PatrolHoldController::class)
                ->args([
                    service('router'),
                    service('security.token_storage'),
                    service('security.csrf.token_manager'),
                    service('patrol.hold'),
                ])
                ->public();
            $services->alias(PatrolHoldController::class, 'patrol.controller.hold')->public();

            // Appending a correction to an observation (PL·06–PL·09). Under the
            // same guard, and for a sharper version of the same reason: an
            // amendment is SIGNED, and a host with no security has nobody to
            // sign one. The route not existing there is the honest outcome —
            // better than a trail of unattributed corrections.
            $services->set('patrol.controller.observation_amend', ObservationAmendmentController::class)
                ->args([
                    service('router'),
                    service('security.token_storage'),
                    service('security.csrf.token_manager'),
                    service('patrol.observation_amendments'),
                ])
                ->public();
            $services->alias(ObservationAmendmentController::class, 'patrol.controller.observation_amend')->public();

            /*
             * THE AREA-SCOPED OBSERVATION-TAXONOMY ADMIN. A writing screen: every
             * route on it rides on "patrols.manage", so like the entry flow
             * it exists only where SecurityBundle can enforce that. Its logic
             * (patrol.taxonomy_admin) is unconditional; only this door is guarded.
             */
            $services->set('patrol.controller.taxonomy', PatrolTaxonomyController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('patrol.taxonomy_admin'),
                    service(TaxonomyKindRepository::class),
                    service(TaxonomySubcategoryRepository::class),
                    service('security.csrf.token_manager'),
                    service('patrol.screen_access'),
                ])
                ->public();
            $services->alias(PatrolTaxonomyController::class, 'patrol.controller.taxonomy')->public();

            /*
             * THE SETTINGS SECTION'S ONE POST. It changes what the area runs
             * patrols on, so it rides on "patrols.manage" and exists only where
             * SecurityBundle can enforce it. The SERVICE behind it is
             * unconditional — reading what an area runs on is not a privilege —
             * and only this door is guarded.
             */
            $services->set('patrol.controller.settings', PatrolSettingsController::class)
                ->args([
                    service('router'),
                    service('patrol.settings'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(PatrolSettingsController::class, 'patrol.controller.settings')->public();

            /*
             * THE TWO WORD-LIST SECTIONS — the patrol types and the stations.
             * Each keeps an address of its own (so its template can link the
             * sheets it draws with), and each WRITE on it changes the words
             * everybody else must use, so the writes ride on "patrols.manage" and
             * the whole controller exists only where SecurityBundle can enforce
             * that. Reading a word-list is not a privilege, and the service behind
             * it is unconditional.
             */
            $services->set('patrol.controller.vocabulary', PatrolVocabularyController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('patrol.vocabulary'),
                    service('patrol.map'),
                    service('patrol.geo'),
                    service(PatrolTypeRepository::class),
                    service(StationRepository::class),
                    service('patrol.screen_access'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(PatrolVocabularyController::class, 'patrol.controller.vocabulary')->public();
        }

        /*
         * The FIELD-SYNC API (API-CONTRACT.md) — the mobile app's endpoints.
         *
         * Registered only where the host actually runs api-platform AND
         * security, and for the same reason the entry flow is: these
         * routes CREATE patrols, so they must never exist unprotected. Without
         * security there is no authorization checker to enforce
         * "patrols.record"; without api-platform there is no /api to attach to.
         * A host missing either gets no sync endpoints at all rather than an
         * open write surface.
         *
         * The RESOURCES need no registration: api-platform discovers
         * src/ApiResource/ from kernel.bundles_metadata by itself (see
         * ApiResource/PatrolSync.php). Only the services behind them are wired
         * here — explicitly, with prefixed ids, no autowiring, as everything
         * else in this bundle is.
         *
         * Processors are the one exception to the id-prefix rule, and by
         * necessity: api-platform resolves `processor: SomeProcessor::class` as
         * a SERVICE ID, so the id has to be the class name. Each still gets a
         * prefixed alias for consistency with the controllers above.
         */
        // $bundles is known to be a non-empty array by here: the storage guard
        // above cannot have been passed otherwise.
        $hasApiPlatform = isset($bundles['ApiPlatformBundle']);
        $builder->setParameter('patrol.field_api', $hasSecurity && $hasApiPlatform);

        if ($hasSecurity && $hasApiPlatform) {
            $services->set('patrol.api.ranger_resolver', RangerResolver::class)
                ->args([service('doctrine.orm.entity_manager')]);

            $services->set('patrol.api.context', PatrolApiContext::class)
                ->args([
                    service('request_stack'),
                    service('security.token_storage'),
                    service('security.authorization_checker'),
                    service(PatrolRepository::class),
                    service(ObservationRepository::class),
                    // The GROUND an endpoint is about, so `patrols.record` is
                    // asked with the area the phone named rather than with
                    // none — see PatrolApiContext::requireRecorder().
                    service(AreaOfInterestRepository::class),
                ]);

            $services->set('patrol.api.patrol_upsert', PatrolUpsertService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(PatrolRepository::class),
                    service('patrol.api.ranger_resolver'),
                    // The handset still sends a type and a station as WORDS;
                    // this is what turns each into one of the area's records.
                    service('patrol.vocabulary'),
                ]);

            $services->set('patrol.api.track_batch', TrackBatchService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(TrackBatchRepository::class),
                    service('patrol.geo'),
                    param('patrol.gap_threshold_minutes'),
                ]);

            $services->set('patrol.api.flight_sync', FlightSyncService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(LaunchPointRepository::class),
                    service(FlightRepository::class),
                ]);

            $services->set('patrol.api.completion', PatrolCompletionService::class)
                ->args([service('doctrine.orm.entity_manager')]);

            $services->set('patrol.api.events', PatrolEventService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(PatrolEventRepository::class),
                    service('patrol.vocabulary'),
                ]);

            foreach ([
                CreatePatrolProcessor::class => 'patrol.api.patrol_upsert',
                AppendEventsProcessor::class => 'patrol.api.events',
                AppendTrackProcessor::class => 'patrol.api.track_batch',
                AppendObservationsProcessor::class => 'patrol.api.observation_sync',
                AppendFlightsProcessor::class => 'patrol.api.flight_sync',
                UploadPhotoProcessor::class => 'patrol.api.photo_sync',
                CompletePatrolProcessor::class => 'patrol.api.completion',
            ] as $processor => $collaborator) {
                $services->set($processor)
                    ->args([service('patrol.api.context'), service($collaborator)])
                    ->tag('api_platform.state_processor');
            }

            /*
             * THE SYNC'S ONE READ — what an area lets a handset say. A PROVIDER
             * rather than a processor, so it carries its own tag; everything
             * else about it is the same, the permission included.
             */
            $services->set('patrol.api.vocabulary', VocabularySyncService::class)
                ->args([
                    service(AreaOfInterestRepository::class),
                    service(PatrolTypeRepository::class),
                    service(StationRepository::class),
                    service(TaxonomyKindRepository::class),
                ]);

            $services->set(VocabularyProvider::class)
                ->args([
                    service('patrol.api.context'),
                    service('request_stack'),
                    service('patrol.api.vocabulary'),
                ])
                ->tag('api_platform.state_provider');
        }

        /*
         * RETENTION. Registered unconditionally, unlike the two dev commands
         * below: deleting discarded patrols past their window is an operation a
         * PRODUCTION console must have, because production is the only place
         * there is anything to purge. It writes nothing unless run, and
         * `--dry-run` shows the sweep before it is trusted.
         */
        $services->set('patrol.command.purge_discarded', PurgeDiscardedCommand::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(PatrolRepository::class),
                service('storage.evidence_storage'),
                service('patrol.drafts'),
                param('patrol.discard_retention_days'),
            ])
            ->tag('console.command');

        /*
         * THE ONE-OFF PREVIEW BACKFILL, OFFERED RATHER THAN SHIPPED. A
         * migration aid somebody runs once is not an operation a production
         * console needs standing by, so this module ships an INERT provider
         * naming it and hands over a closure; devkit — dev-only, installed
         * through `require-dev` — collects every such service and turns each
         * descriptor into a real console command. In a production build devkit
         * is absent, nothing collects this, and it is data waiting for a tool
         * that is not there.
         *
         * THE TAG IS A LITERAL STRING, not a constant of devkit's. Reading one
         * would load a class that is not installed in production, which is the
         * whole arrangement inverted: the always-installed side names the
         * promise, never the tool.
         *
         * Registered unconditionally, and gated by no flag of its own: the
         * dependency graph is the firewall, and a flag beside it would be a
         * second one that can disagree with it.
         */
        /*
                 * A DEMO MONTH, OFFERED THE SAME WAY. An inert provider naming a slice
                 * of sample content; devkit collects it and seeds it in a dev install,
                 * and in production nothing collects it. Its load() calls this module's
                 * own public services and nothing else, so demo content can only ever be
                 * shaped the way the product shapes it.
                 *
                 * THE TAG IS A LITERAL STRING for the same reason the command tag is:
                 * reading a constant of devkit's would load a class that is not
                 * installed in production.
                 */
        $services->set('patrol.devkit.content', PatrolContentProvider::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(PatrolRepository::class),
                service(ObservationRepository::class),
                service('patrol.geo'),
                service('patrol.track_ingest'),
                service('patrol.recording'),
                service('patrol.api.observation_sync'),
                service('patrol.api.photo_sync'),
                service('patrol.taxonomy_admin'),
                service('patrol.vocabulary'),
                param('patrol.types'),
                param('patrol.observation_categories'),
            ])
            ->tag('uhifadhi.devkit.content_provider');

        $services->set('patrol.devkit.commands', PatrolCommandProvider::class)
            ->args([service('patrol.photo_thumbnail_backfill')])
            ->tag('uhifadhi.devkit.command_provider');

        $services->set('patrol.photo_thumbnail_backfill', PhotoThumbnailBackfillService::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(ObservationPhotoRepository::class),
                service('storage.evidence'),
                service('storage.thumbnail_generator'),
            ]);

        // The department KPI contribution point. APPENDED last on purpose: it is the newest thing this bundle
        // plugs into, and it depends on nothing declared above it.
        //
        // Tagged EXPLICITLY, exactly like 'uhifadhi.module' above and for the same reason: a
        // reusable bundle is not autoconfigured (symfony.com/doc/current/bundles/best_practices
        // .html), so the host's autoconfiguration never fires for it.
        //
        // The slug and name are the scalars PatrolModuleProvider::slug()/name() return: they must
        // MATCH, because the host only asks this provider for figures when a department attaches
        // the module of that slug, and captions the plates with that name. They are literals here
        // rather than constants because the provider exposes them as methods and a scalar is what
        // a service argument can carry; PatrolDepartmentKpiProviderTest pins the two together.
        $services->set('patrol.figure_service', PatrolFigureService::class)
            ->args([service(PatrolRepository::class)]);
        $services->alias(PatrolFigureService::class, 'patrol.figure_service');

        $services->set('patrol.department_kpi_provider', PatrolDepartmentKpiProvider::class)
            ->args([
                service('patrol.figure_service'),
                service('doctrine.orm.entity_manager'),
                'patrols',
                'Patrols',
            ])
            ->tag(DepartmentKpiProviderInterface::TAG);

        /*
         * THE ZONE FIGURE CONTRIBUTION POINT — the same three-figure answer the
         * department seam gives, over the area module's ground instead of the
         * team module's lens.
         *
         * Tagged EXPLICITLY, for the reason every tag above is: a reusable bundle
         * is not autoconfigured (symfony.com/doc/current/bundles/best_practices.html),
         * so the host's autoconfiguration never fires for it — and an
         * #[AutoconfigureTag] written on the interface would be silently dead,
         * because Symfony reads that attribute off the definition's own class only.
         *
         * The slug and the name are the scalars PatrolModuleProvider::slug()/name()
         * return, and they must MATCH: a zone surface asks this provider only where
         * the area runs the module of that slug, and captions the plates with that
         * name.
         */
        $services->set('patrol.zone_figure_provider', PatrolZoneFigureProvider::class)
            ->args([
                service(PatrolRepository::class),
                'patrols',
                'Patrols',
            ])
            ->tag(ZoneFigureProviderInterface::TAG);

        /*
         * THE STATION FIGURE CONTRIBUTION POINT — one headline per post of an
         * area: the complete patrols that went out of it, with the kilometres
         * they covered said in the caption.
         *
         * Tagged EXPLICITLY for the reason the zone provider above is, and with
         * the same slug and name: a station dock asks this provider only where
         * the area runs the module of that slug, and captions the row with that
         * name.
         */
        $services->set('patrol.station_figure_provider', PatrolStationFigureProvider::class)
            ->args([
                service(PatrolRepository::class),
                'patrols',
                'Patrols',
            ])
            ->tag(StationFigureProviderInterface::TAG);

        /*
         * THE PERFORMANCE TOPIC — registered only where TeamBundle is in the
         * kernel, read from kernel.bundles for the reason the SecurityBundle
         * guard above states. Departments are TeamBundle's, and a topic asks
         * DepartmentDirectoryInterface who they are; without that bundle the
         * alias does not exist and a hard reference would break the container
         * rather than simply leaving the page a topic short.
         *
         * THE PERFORMANCE TOPIC — this module's whole section of the
         * organisation's performance page: five headline figures, two charts
         * and a matrix of only the departments that read it.
         *
         * A TOPIC, NOT A COLUMN, which is why nothing in the host changes when
         * this is added: adding a module adds a topic and touches no shared
         * list.
         *
         * Tagged EXPLICITLY for the reason every tag above is: a reusable
         * bundle is not autoconfigured (symfony.com/doc/current/bundles/
         * best_practices.html), so the host's autoconfiguration never fires for
         * it — and an #[AutoconfigureTag] written on the interface would be
         * silently dead, because Symfony reads that attribute off the
         * definition's own class only.
         *
         * The slug and the name are the scalars PatrolModuleProvider::slug()/
         * name() return and they must MATCH: the page orders the module topics
         * by the order the organisation arranged its modules, and finds this
         * one by that slug.
         */
        if (isset($bundles['TeamBundle'])) {
            $services->set('patrol.performance_topic', PatrolPerformanceTopic::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(DepartmentDirectoryInterface::class),
                    service('patrol.figure_service'),
                    'patrols',
                    'Patrols',
                ])
                ->tag(PerformanceTopicProviderInterface::TAG);
        }

        /*
         * THIS MODULE'S FIGURES OVER THE GROUND — coverage per area, and per
         * zone once the performance page is about one area.
         *
         * A SEPARATE SEAM FROM THE TOPIC, AND DELIBERATELY UNGUARDED. The
         * topic above needs TeamBundle because a matrix is rows of
         * departments; the ground is not — an area and its zones are
         * AreaBundle's, which this module requires outright — so the plate is
         * published in any installation, including one running no departments
         * at all.
         *
         * IT ASKS THE REGISTRY WHETHER THE MODULE IS ON, area by area, because
         * an area that switched Patrols on and has not been out yet is ground
         * with nothing measured on it while an area that never switched it on
         * is not this module's ground at all. That is one question about an
         * area and a module, which is exactly what the ledger answers, and
         * nothing here joins it to anybody's departments.
         *
         * Tagged EXPLICITLY for the reason every tag above is: a reusable
         * bundle is not autoconfigured (symfony.com/doc/current/bundles/
         * best_practices.html), so the host's autoconfiguration never fires for
         * it.
         *
         * The slug and the name MATCH the topic's and the module provider's:
         * the page finds a module's ground figures by the same slug it orders
         * its topic by, and drops them wherever the module is switched off.
         */
        $services->set('patrol.performance_geo', PatrolPerformanceGeo::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('registry.area_modules'),
                service('patrol.figure_service'),
                'patrols',
                'Patrols',
            ])
            ->tag(PerformanceGeoProviderInterface::TAG);

        /*
         * THE AREA OVERVIEW CONTRIBUTION POINTS — the module's contribution to /areas/{uuid}.
         *
         * FIVE TAGS, FIVE SEPARATE THINGS. One puts this module's widgets and its
         * own templates on the page; the other four put PARTS into widgets the HOST
         * draws — a tile in the right-now strip, an item in needs attention, a layer
         * with its legend entry on the operational plate, a move in the pulse. The
         * host owns those widgets and knows what none of these parts count, which is
         * what stops "Right now" and "Needs attention" from becoming a hard-coded
         * list of every module the product ever shipped.
         *
         * Tagged EXPLICITLY, exactly like 'uhifadhi.module' and the KPI provider
         * above and for the same reason: a reusable bundle is not autoconfigured
         * (symfony.com/doc/current/bundles/best_practices.html), so an
         * installation's registerForAutoconfiguration never fires for these classes.
         *
         * THE TAG NAMES ARE THE INTERFACES' OWN CONSTANTS. They belong to
         * AreaBundle, which ships in the core this package requires, so the
         * constant is readable here — and a rename is a compile error rather
         * than a module that silently stops contributing.
         *
         * ONE READING, FIVE CONSUMERS. All five share 'patrol.overview', so the
         * strip's "3 out", the live card's three rows and the plate's three live
         * tracks are the same three patrols rather than three answers measured a
         * query apart.
         */
        $services->set('patrol.overview', PatrolOverviewService::class)
            ->args([
                service(PatrolRepository::class),
                service(TrackPointRepository::class),
                service(ObservationRepository::class),
                service('router'),
                service(PatrolTypeRepository::class),
                param('patrol.observation_categories'),
            ]);

        $services->set('patrol.overview.contributor', PatrolOverviewContributor::class)
            ->args([service('patrol.overview'), service(PatrolTypeRepository::class)])
            ->tag(OverviewContributorInterface::TAG);

        $services->set('patrol.overview.now_tiles', PatrolNowTiles::class)
            ->args([service('patrol.overview')])
            ->tag(NowTileProviderInterface::TAG);

        $services->set('patrol.overview.attention', PatrolAttention::class)
            ->args([service('patrol.overview')])
            ->tag(AttentionProviderInterface::TAG);

        $services->set('patrol.overview.map_layers', PatrolMapLayers::class)
            ->args([service('patrol.overview'), service(PatrolRepository::class), service(PatrolTypeRepository::class)])
            ->tag(MapLayerProviderInterface::TAG);

        // THE MODULE'S WORDS INSIDE THE HOST'S SENTENCES. Not a widget and not a
        // part of one: the phrases the host drops into its own copy about the
        // operational plate, so "today's tracks" is said by the module that draws
        // them rather than written into the host.
        $services->set('patrol.overview.copy', PatrolOverviewCopy::class)
            ->tag(OverviewCopyProviderInterface::TAG);

        $services->set('patrol.overview.pulse', PatrolPulse::class)
            ->args([
                service(PatrolRepository::class),
                service(ObservationRepository::class),
                service('patrol.overview'),
                param('patrol.observation_categories'),
            ])
            ->tag(PulseProviderInterface::TAG);

        /*
         * THE ORGANISATION DASHBOARD CONTRIBUTION POINT — the module's
         * contribution to `/`.
         *
         * A SECOND SEAM BESIDE THE AREA'S, opted into deliberately. The area
         * contract is answered against an area ENTITY and is asked only where
         * an area runs this module; this one is answered against a SCOPE and
         * is asked once. Both carry the same slug, so the figure and the cell
         * leave together the day the module is uninstalled.
         *
         * ONE READING BEHIND BOTH HALVES. 'patrol.org_overview' resolves the
         * scope to areas and adds up the readings 'patrol.overview' and
         * 'patrol.figure_service' already make per area — so the organisation's
         * answer IS the areas' answers, which is the rule the contract is
         * built on, rather than a second aggregate nobody could reconcile.
         */
        $services->set('patrol.org_overview', PatrolOrgOverviewService::class)
            ->args([
                service(AreaOfInterestRepository::class),
                service('patrol.overview'),
                service('patrol.figure_service'),
            ]);

        $services->set('patrol.org_widgets', PatrolOrgWidgets::class)
            ->args([service('patrol.org_overview')])
            ->tag(OrgOverviewContributorInterface::TAG);
    }
}
