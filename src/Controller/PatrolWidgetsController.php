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

namespace Uhifadhi\Patrol\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Service\PatrolCalendar;
use Uhifadhi\Patrol\Service\PatrolCoverageService;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolKindsService;
use Uhifadhi\Patrol\Service\PatrolMapService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\Service\PatrolWidgetUrls;
use Uhifadhi\Patrol\Widget\PatrolWidgets;

/**
 * THE WIDGET LIBRARY for the patrols surface — the one editing screen.
 *
 * The PAGE is chrome; everything inside it is the host's shared preset component
 * (templates/widgets/_library.html.twig), handed this surface's catalogue, this
 * surface's partial name and this AREA's routes. There are no patrol-specific
 * widget mechanics anywhere, which is the whole point of riding the host's
 * framework: composing a preset here works exactly as it does on departments,
 * team, zones and incidents.
 *
 * Every write is CSRF-checked and answered by {@see WidgetEndpoint}: this
 * controller validates nothing itself, mints no token and chooses no status
 * code.
 *
 * REGISTERED ONLY WHERE THE HOST RUNS SECURITY. A layout belongs to a PERSON,
 * so without a signed-in user there is nothing to read or write — a host in
 * that state gets no library at all rather than a screen that edits nobody's
 * preferences.
 *
 * A plain class, not a Symfony AbstractController subclass — see
 * PatrolController and config/services.php for the reusable-bundle rule.
 */
// EVERY ROUTE BELOW BELONGS TO THIS MODULE, and says so: where an area has
// parked Patrols, the registry closes these routes before the controller runs.
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolWidgetsController
{
    /**
     * @param int $retentionDays patrol.discard_retention_days — the previewed log widget is the REAL one, and states removal dates from the same number
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        // The library previews the REAL month, on the same feed the dashboard
        // draws: the picture of a widget IS the widget.
        private readonly PatrolCalendar $calendar,
        private readonly PatrolMapService $plates,
        // THE AREA'S GROUND — the boundary and the zones every plate stands
        // on, as the area answers it; the atlas draws it.
        private readonly AreaMapPayload $ground,
        private readonly PatrolCoverageService $coverage,
        // The library previews EVERY widget, including the direction widgets that
        // read the day's live state (out now, gaps, the observation queue), so it
        // needs the same reading the dashboard does — from the same service.
        private readonly PatrolOverviewService $overview,
        private readonly WidgetService $widgets,
        private readonly PatrolWidgetUrls $widgetUrls,
        private readonly WidgetEndpoint $endpoint,
        private readonly TaxonomyKindRepository $kinds,
        private readonly PatrolKindsService $observationKinds,
        private readonly PatrolTypeRepository $types,
        private readonly int $retentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/widgets',
        name: 'patrol_widgets',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('patrols.read', subject: 'area')]
    public function library(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $catalog = PatrolWidgets::declaration();
        $person = $this->endpoint->user();
        $areaUuid = $area->getUuid();
        // Same instant for the last-patrol KPI and the calendar title, exactly
        // as the dashboard does it.
        $now = new \DateTimeImmutable();
        // The library previews the REAL widgets on the CURRENT month, exactly as
        // the dashboard opens on it — one month, driven the same way.
        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);
        $patrolZones = $this->patrols->zonesForPatrols($area, $monthStart, $nextMonth);
        // The library previews the widgets as they arrive: the current month,
        // narrowed by nothing. A preview is not somebody's filtered view of a
        // month, it is what the widget looks like.
        $filter = new PatrolFilter($monthStart);

        $dashboard = $this->dashboard->build(
            $this->patrols->findByAreaLatestFirst($area),
            $types = $this->types->findVocabularyByArea($area),
            $now,
            // The library previews the REAL KPI strip, so PL·03 is read here
            // exactly as the dashboard reads it.
            $this->coverage->monthShare($area, $monthStart),
            $filter,
            $patrolZones,
        );

        return new Response($this->twig->render('@UhifadhiPatrol/widgets/show.html.twig', [
            'area' => $area,
            // The preset component, whole, over this surface's catalogue and
            // this AREA's routes.
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $person, $areaUuid),
            'active' => $this->widgets->activeRef($catalog, $person, $areaUuid),
            'widgets' => $this->widgets->resolve($catalog, $person, $areaUuid),
            'partial' => '@UhifadhiPatrol/dashboard/_w_%s.html.twig',
            // EVERY widget partial renders the REAL widget on REAL data here,
            // at full size — the picture of a widget IS the widget, so what you
            // arrange is exactly what you get.
            'widgetContext' => [
                'area' => $area,
                'types' => $types,
                'typeCat' => PatrolDashboardService::typePositions($types),
                'now' => $now,
                'month' => $monthStart,
                'filter' => $filter,
                // THE MONTH'S FEED AND ITS SUBJECT. The calendar widget hands both
                // to atlas_calendar(); the module draws no grid of its own, and the
                // scope carries the surface's own type filter so the month narrows
                // with everything else on the screen.
                'calendarFeed' => $this->calendar,
                'calendarScope' => PatrolCalendar::scopeFor((string) $area->getUuidString(), $filter->type),
                'patrolZones' => $patrolZones,
                'dashboard' => $dashboard,
                'map' => $this->plates->coverage(
                    $this->ground->forArea($area),
                    $this->dashboard->coveragePayload($dashboard, $types, $patrolZones),
                    $types,
                    PatrolDashboardService::typeSwatches($types),
                    // The real coverage layer, so the previewed plate is the plate.
                    $this->coverage->bufferFor($area, $monthStart, $nextMonth, $now),
                ),
                // The read-only kinds card: what a ranger may log here, and how
                // often each was logged this month. Editing is one click away in
                // Configure and never on a dashboard.
                'kinds' => $kinds = $this->kinds->forArea($area),
                'kindCounts' => $this->observationKinds->countsByCode($dashboard->patrols),
                'kindSubcategoryCount' => array_sum(array_map(
                    static fn (TaxonomyKind $kind): int => \count($kind->getSubcategories()),
                    $kinds,
                )),
                'retentionDays' => $this->retentionDays,
                // The live reading the direction widgets bind, exactly as the
                // dashboard hands it in — so a widget previewed here IS the widget
                // the dashboard draws, on the same data.
            ] + $this->overview->dashboardReading($area, $now),
            'urls' => $this->widgetUrls->forArea($area),
            'csrfToken' => $this->endpoint->csrfToken($catalog, $areaUuid),
        ]));
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/save', name: 'patrol_widgets_save', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->endpoint->save($request, PatrolWidgets::declaration(), $area->getUuid());
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/reset', name: 'patrol_widgets_reset', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function reset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->reset($request, PatrolWidgets::declaration(), $area->getUuid()),
            \sprintf('This area’s patrols dashboard is back to “%s”.', PatrolWidgets::DEFAULT_LABEL),
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/preset/{presetId}', name: 'patrol_widgets_preset', requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function applyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        $catalog = PatrolWidgets::declaration();
        // A design the surface does not ship is refused by the endpoint below;
        // naming it in the flash is only for the case where it IS shipped.
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s patrols dashboard now follows “%s”.', null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/preset/{presetId}/copy', name: 'patrol_widgets_preset_copy', requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 1)]
    #[IsGranted('patrols.read', subject: 'area')]
    public function copyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->copyPreset($request, PatrolWidgets::declaration(), $presetId, $area->getUuid()),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets', name: 'patrol_widgets_preset_create', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function createPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->createCustomPreset($request, PatrolWidgets::declaration(), $area->getUuid()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets/{presetUuid}/apply', name: 'patrol_widgets_preset_apply', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function applyCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyCustomPreset($request, PatrolWidgets::declaration(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Your design is on.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets/{presetUuid}/rename', name: 'patrol_widgets_preset_rename', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function renameCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->renameCustomPreset($request, PatrolWidgets::declaration(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets/{presetUuid}/delete', name: 'patrol_widgets_preset_delete', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function deleteCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->deleteCustomPreset($request, PatrolWidgets::declaration(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Design deleted. Your dashboard is back on the one this module ships with.',
        );
    }

    /**
     * A refused write is returned as it came (the library's fetch() reads the
     * status and the message); a successful one says so and goes back to the
     * library, so the plain-form path works with no JavaScript at all.
     */
    private function afterWrite(Request $request, AreaOfInterest $area, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate('patrol_widgets', ['uuid' => $area->getUuidString()]));
    }
}
