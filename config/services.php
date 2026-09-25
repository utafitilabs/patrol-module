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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository as AreaStationRepository;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Patrol\Access\PatrolConcerns;
use Uhifadhi\Patrol\Controller\PatrolCalendarController;
use Uhifadhi\Patrol\Controller\PatrolController;
use Uhifadhi\Patrol\Controller\PatrolDetailController;
use Uhifadhi\Patrol\Controller\PatrolExportController;
use Uhifadhi\Patrol\Controller\PatrolKindsOverviewController;
use Uhifadhi\Patrol\Controller\PatrolListController;
use Uhifadhi\Patrol\Repository\FlightRepository;
use Uhifadhi\Patrol\Repository\LaunchPointRepository;
use Uhifadhi\Patrol\Repository\ObservationAmendmentRepository;
use Uhifadhi\Patrol\Repository\ObservationPhotoRepository;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolDraftFileRepository;
use Uhifadhi\Patrol\Repository\PatrolDraftRepository;
use Uhifadhi\Patrol\Repository\PatrolEventRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolSettingsRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Patrol\Repository\TrackBatchRepository;
use Uhifadhi\Patrol\Repository\TrackPointRepository;
use Uhifadhi\Patrol\Service\GeoService;
use Uhifadhi\Patrol\Service\GpxParser;
use Uhifadhi\Patrol\Service\GpxWriter;
use Uhifadhi\Patrol\Service\ObservationAmendmentService;
use Uhifadhi\Patrol\Service\PatrolCalendar;
use Uhifadhi\Patrol\Service\PatrolCoverageService;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolDraftService;
use Uhifadhi\Patrol\Service\PatrolHoldService;
use Uhifadhi\Patrol\Service\PatrolKindsOverviewService;
use Uhifadhi\Patrol\Service\PatrolKindsService;
use Uhifadhi\Patrol\Service\PatrolListService;
use Uhifadhi\Patrol\Service\PatrolMapService;
use Uhifadhi\Patrol\Service\PatrolRecordingService;
use Uhifadhi\Patrol\Service\PatrolScreenAccessService;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Service\PatrolWidgetUrls;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Service\TrackIngestService;
use Uhifadhi\Patrol\Shell\PatrolConfigurationSections;
use Uhifadhi\Patrol\Shell\PatrolModuleTabs;
use Uhifadhi\Patrol\Twig\PatrolTrailExtension;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiPatrolBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions (module category, vocabulary parameters).
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(), and
 * ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * Controllers extend nothing and take their collaborators explicitly, patterned
 * on FrameworkBundle's own TemplateController (see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('patrol.geo', GeoService::class);

    $services->set('patrol.gpx_parser', GpxParser::class)
        ->args([service('patrol.geo')]);

    // The inverse of the parser: a recorded track back out as a GPX file.
    $services->set('patrol.gpx_writer', GpxWriter::class)
        ->args([service('patrol.geo')]);

    $services->set('patrol.dashboard', PatrolDashboardService::class);

    /*
     * THE MODULE'S MONTH. What patrols has to say about one month, handed to
     * the atlas to draw — a feed is NAMED by the surface that wants it, never
     * tagged and gathered, because a month of patrols and a month of anything
     * else are different pages.
     */
    $services->set('patrol.calendar', PatrolCalendar::class)
        ->args([
            service(PatrolRepository::class),
            service(AreaOfInterestRepository::class),
            service('router'),
        ]);
    $services->alias(PatrolCalendar::class, 'patrol.calendar');

    /*
     * THE MODULE'S PLATES. What patrol states about its two maps, handed to the
     * atlas to draw. The module writes no map JavaScript: this builds the map,
     * and render_map() puts it on the page.
     */
    $services->set('patrol.map', PatrolMapService::class)
        ->args([service(MapBuilderInterface::class)]);
    $services->alias(PatrolMapService::class, 'patrol.map');

    /*
     * THE GROUND THE MONTH'S ROUTES COVERED — PL·03's set operation, held for
     * the day it was measured on.
     *
     * The cache is the framework's own application pool, taken with
     * nullOnInvalid() exactly as the token storage further down is: a host that
     * wired no cache still gets the shape, measured every time.
     *
     * @see https://symfony.com/doc/current/cache.html
     */
    $services->set('patrol.coverage', PatrolCoverageService::class)
        ->args([
            service(PatrolRepository::class),
            PatrolDashboardService::COVERAGE_BUFFER_M,
            service('cache.app')->nullOnInvalid(),
        ]);

    // The widget library's URL map, shared by the dashboard and the library
    // itself, with THIS AREA named in every URL.
    $services->set('patrol.widget_urls', PatrolWidgetUrls::class)
        ->args([service('router')]);

    /*
     * APPENDING ONE CORRECTION to an observation. Unconditional for the reason
     * 'patrol.taxonomy_admin' is: it is domain logic with no security of its
     * own, and only the door that fronts it lives inside the SecurityBundle
     * guard — an amendment is signed, and a host with no security has nobody to
     * sign one.
     */
    $services->set('patrol.observation_amendments', ObservationAmendmentService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            // The same evidence path the field uploads use, so a photograph
            // attached on the web is stored, typed and previewed exactly as one
            // off a handset.
            service('storage.evidence_storage'),
        ]);

    /*
     * HOLDING A DISCARDED PATROL back from the purge, and letting it go again.
     * Unconditional beside the other two writes, and for the same reason: the
     * rule about which patrols have a clock to stop is domain logic, and only
     * the door that fronts it is guarded.
     */
    $services->set('patrol.hold', PatrolHoldService::class)
        ->args([service('doctrine.orm.entity_manager')]);

    /*
     * A PATROL BEING WRITTEN — opened when the entry flow renders, fed by the two
     * upload targets, emptied on save and swept when nobody comes back.
     *
     * Unconditional beside the other writes and for the same reason
     * 'patrol.taxonomy_admin' is: it is domain logic with no security of its
     * own, and only the DOORS that front it — the screen and the platform's
     * upload endpoint — live inside the SecurityBundle guard. The retention
     * sweep is a console command and has no door at all.
     */
    $services->set('patrol.drafts', PatrolDraftService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PatrolDraftRepository::class),
            service(PatrolDraftFileRepository::class),
            service('storage.evidence_storage'),
        ]);

    /*
     * THE WRITE PATH OF THE ONE ENTRY FLOW. It reaches for the ingest service
     * where a track is held and for the draft where files are, so the whole of a
     * submission — the patrol, its observations and their photographs — is one
     * call and one place the rules live. Registered beside 'patrol.track_ingest'
     * and unconditionally, for the reason above.
     */
    $services->set('patrol.recording', PatrolRecordingService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('patrol.drafts'),
            service('patrol.track_ingest'),
        ]);

    $services->set('patrol.track_ingest', TrackIngestService::class)
        ->args([
            service('patrol.gpx_parser'),
            service('doctrine.orm.entity_manager'),
            param('patrol.gap_threshold_minutes'),
        ]);

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    $services->set(PatrolRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(ObservationRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The field-sync entities' repositories. Registered unconditionally with the
    // rest: a repository is just a query surface over a mapped entity, and those
    // entities are mapped whether or not this host installs api-platform.
    $services->set(TrackBatchRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TrackPointRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(LaunchPointRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(FlightRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(ObservationPhotoRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(PatrolEventRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(ObservationAmendmentRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The patrols being written, and the files they have received so far.
    // Registered with the rest and for the same reason.
    $services->set(PatrolDraftRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(PatrolDraftFileRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The area-scoped observation-taxonomy's two levels. Registered with the rest
    // for the same reason: a repository is a query surface over a mapped entity,
    // and these entities are mapped whether or not this host runs security.
    $services->set(TaxonomyKindRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TaxonomySubcategoryRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // What one area runs patrols on. Registered with the rest for the same
    // reason: a repository is a query surface over a mapped entity.
    $services->set(PatrolSettingsRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The two word-lists an area owns — SET·01 and SET·03.
    $services->set(PatrolTypeRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(StationRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * ADD, RENAME, RETIRE, REACTIVATE — for both of the area's word-lists.
     * Unconditional for the reason 'patrol.taxonomy_admin' is: it is pure domain
     * logic with no security of its own, and only the CONTROLLER that fronts it
     * lives inside the SecurityBundle guard. The handset sync reaches it too,
     * and that door has no firewall of this kind.
     *
     * The installation's `patrol.types` reaches it as the SEED a NEW area starts
     * from, and as nothing else — see PatrolVocabularyService::seedTypes().
     */
    $services->set('patrol.vocabulary', PatrolVocabularyService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PatrolTypeRepository::class),
            service(AreaStationRepository::class),
            param('patrol.types'),
        ]);

    /*
     * THE AREA-SCOPED OBSERVATION-TAXONOMY ADMIN's logic. Registered
     * unconditionally — it is pure domain logic (create/rename/retire kinds and
     * sub-categories, keep wire-codes unique per area) with no security of its
     * own; the CONTROLLER that fronts it is registered only under the security
     * guard (see UhifadhiPatrolBundle), because the write rides on
     * "patrols.manage" and there is nobody to grant it without a firewall.
     */
    $services->set('patrol.taxonomy_admin', TaxonomyAdminService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TaxonomyKindRepository::class),
            service(TaxonomySubcategoryRepository::class),
        ]);

    /*
     * THE FULL LOG's own reading: which of the month's patrols one page shows,
     * and the count beside every option in the filter row. Pure — no clock, no
     * repository — which is what lets the whole page be unit-tested.
     */
    $services->set('patrol.list', PatrolListService::class);

    /*
     * HOW OFTEN EACH OBSERVATION KIND WAS LOGGED — the read-only kinds card's
     * one number, counted by the wire-code the two vocabulary models share.
     */
    $services->set('patrol.observation_kinds', PatrolKindsService::class);

    /*
     * WHAT THIS AREA FILES UNDER, AND HOW MUCH OF EACH — the read-only kinds
     * tab's whole reading. Registered unconditionally, like the screen it
     * serves: the words are data, so an operator with no authority over them
     * still has an address where the counts are.
     */
    $services->set('patrol.kinds_overview', PatrolKindsOverviewService::class)
        ->args([
            service(TaxonomyKindRepository::class),
            service(ObservationRepository::class),
        ]);

    /*
     * WHAT THIS MODULE LETS SOMEBODY ACT ON, declared to the installation's
     * catalogue of concerns. Tagged BY HAND with the interface's own constant:
     * a reusable bundle is not autoconfigured, so the platform's
     * registerForAutoconfiguration never fires for it, and a module that
     * forgot this tag would simply have no rows on the positions page — which
     * looks exactly like a module nobody granted anything on.
     *
     * DECLARING GRANTS NOBODY ANYTHING. Installing this module must never
     * hand an existing person a new power; it only gives an organization the
     * rows to tick.
     */
    $services->set('patrol.access.concerns', PatrolConcerns::class)
        ->tag(ConcernSourceInterface::TAG);

    /*
     * WHETHER TO DRAW A DOOR — asked in one place, through the core's Door
     * helper, so every control this module draws names the same pair as the
     * gate behind it and asks it about the same area.
     */
    $services->set('patrol.screen_access', PatrolScreenAccessService::class)
        ->args([service(Door::class)]);

    /*
     * THE MODULE'S DATA PLACES. Tagged BY HAND: a reusable bundle does not
     * autoconfigure, so the platform's registerForAutoconfiguration never fires
     * for it and a forgotten tag is a module with no strip and no children in
     * the sidebar's tree, with nothing anywhere saying why.
     */
    $services->set('patrol.module_tabs', PatrolModuleTabs::class)
        ->tag(ModuleTabsInterface::TAG);

    /*
     * WHAT ONE AREA RUNS PATROLS ON. Unconditional: reading it is not a
     * privilege, and the WRITE rides on a guarded controller.
     */
    $services->set('patrol.settings', PatrolSettingsService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PatrolSettingsRepository::class),
            param('patrol.gap_threshold_minutes'),
            param('patrol.discard_retention_days'),
        ]);

    /*
     * WHAT IS ON THE MODULE'S ONE CONFIGURE PAGE. Tagged by hand, like the tabs
     * and for the same reason; a module with no declaration has no Configure
     * action at all.
     */
    $services->set('patrol.configuration_sections', PatrolConfigurationSections::class)
        ->args([
            service('request_stack'),
            service(AreaOfInterestRepository::class),
            service('patrol.settings'),
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->tag(ConfigurationSectionsInterface::TAG);

    /*
     * THE CRUMB'S ONE HELPER — `patrol_url()`, which answers null for a screen
     * the installation did not mount instead of throwing the page away. See
     * PatrolTrailExtension for why a module's breadcrumb cannot use path().
     */
    $services->set('patrol.twig.trail', PatrolTrailExtension::class)
        ->args([service('router')])
        ->tag('twig.extension');

    /*
     * Controllers: plain classes (they extend nothing), explicit collaborators,
     * prefixed ids. Routes reference "PatrolController::dashboard" and Symfony's
     * controller resolver asks the container for that class name, so each gets the
     * alias the best practices prescribe: "For public services, aliases should be
     * created from the interface/class to the service id."
     *
     * @see https://symfony.com/doc/current/bundles/best_practices.html
     * @see vendor/symfony/framework-bundle/Resources/config/routing.php
     */
    $services->set('patrol.controller.dashboard', PatrolController::class)
        ->args([
            service('twig'),
            service(PatrolRepository::class),
            service('patrol.dashboard'),
            service('patrol.calendar'),
            service('patrol.map'),
            // The area's ground (boundary + zones) as the area answers it, by
            // that bundle's published service id — the id is the reusable
            // bundle's public surface, named here exactly as shell.widget.service
            // is. "Services should not use autowiring or autoconfiguration.
            // Instead, all services should be defined explicitly."
            // https://symfony.com/doc/current/bundles/best_practices.html ;
            // the id is defined in vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AreaBundle/config/services.php
            service('area.map_payload'),
            service('patrol.coverage'),
            // The day's live reading (out now, zone gaps, the observation queue)
            // for the direction widgets — measured in the ONE place the overview
            // measures it, so the dashboard and /areas/{uuid} never disagree.
            service('patrol.overview'),
            // ShellBundle, BY ITS PUBLISHED SERVICE ID: the module
            // ships a catalogue, never a copy of the algebra that resolves it.
            // The id is that bundle's public surface (its service reference),
            // which is what a reusable bundle names another one by.
            service('shell.widget.service'),
            service(TaxonomyKindRepository::class),
            service('patrol.observation_kinds'),
            service(PatrolTypeRepository::class),
            // Whether to draw a door — both questions, in the one place that
            // answers them for every screen of this module.
            service('patrol.screen_access'),
            param('patrol.widget_screens'),
            // Null where the installation runs no security: nobody is signed
            // in, so the dashboard renders the shipped composition for everyone.
            service('security.token_storage')->nullOnInvalid(),
            param('patrol.discard_retention_days'),
        ])
        ->public();

    $services->alias(PatrolController::class, 'patrol.controller.dashboard')->public();

    /*
     * THE DESIGN'S `Export` PAGE ACTION — the filtered log as CSV, the filtered
     * tracks as GPX. Registered beside the dashboard and the list rather than
     * behind the SecurityBundle guard, and for the same reason those two are: it
     * READS what the page already shows, so it must exist wherever the page does.
     */
    $services->set('patrol.controller.export', PatrolExportController::class)
        ->args([
            service(PatrolRepository::class),
            service('patrol.list'),
            service('patrol.gpx_writer'),
        ])
        ->public();

    $services->alias(PatrolExportController::class, 'patrol.controller.export')->public();

    $services->set('patrol.controller.list', PatrolListController::class)
        ->args([
            service('twig'),
            service(PatrolRepository::class),
            service('patrol.list'),
            service('patrol.screen_access'),
            service(PatrolTypeRepository::class),
            param('patrol.discard_retention_days'),
        ])
        ->public();

    $services->alias(PatrolListController::class, 'patrol.controller.list')->public();

    /*
     * The calendar tab (PL·11). Registered beside the dashboard rather than
     * inside the bundle's SecurityBundle guard: it is a slice of the dashboard
     * the same caller already reads, so it must exist wherever the dashboard
     * does — including a host with no security, where the widget still renders
     * and its stepper must still lead somewhere.
     */
    $services->set('patrol.controller.calendar', PatrolCalendarController::class)
        ->args([
            service('twig'),
            service('patrol.calendar'),
            service(PatrolTypeRepository::class),
        ])
        ->public();

    $services->alias(PatrolCalendarController::class, 'patrol.controller.calendar')->public();

    $services->set('patrol.controller.detail', PatrolDetailController::class)
        ->args([
            service('twig'),
            service('router'),
            service('patrol.geo'),
            service('patrol.gpx_writer'),
            service('patrol.map'),
            // The area's ground, as the dashboard takes it (see there).
            service('area.map_payload'),
            // The amendment trail the observation screen reads (PL·06). Not
            // behind the security guard the WRITE is behind: a correction is
            // part of the record and must be readable wherever the record is,
            // including on a host that runs no security and can therefore never
            // append one.
            service(ObservationAmendmentRepository::class),
            service(PatrolTypeRepository::class),
            param('patrol.observation_categories'),
            // Whether to draw the hold and the amend controls — the module's
            // one door service, which asks the core's Door with this area.
            service('patrol.screen_access'),
            param('patrol.discard_retention_days'),
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->public();

    $services->alias(PatrolDetailController::class, 'patrol.controller.detail')->public();

    /*
     * THE READ-ONLY KINDS TAB. Registered beside the dashboard rather than
     * inside the bundle's SecurityBundle guard, for the reason the dashboard is:
     * it reads, it writes nothing, and it must exist wherever the module's other
     * data places do — including a host that runs no security.
     */
    $services->set('patrol.controller.kinds_overview', PatrolKindsOverviewController::class)
        ->args([
            service('twig'),
            service('patrol.kinds_overview'),
        ])
        ->public();

    $services->alias(PatrolKindsOverviewController::class, 'patrol.controller.kinds_overview')->public();
};
