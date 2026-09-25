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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Entity\UserInterface;
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
use Uhifadhi\Patrol\Service\PatrolScreenAccessService;
use Uhifadhi\Patrol\Widget\PatrolWidgets;

/**
 * The patrols widget dashboard for one area: KPIs, the coverage map, the patrol
 * log, the feed, the per-week and per-station charts and the month calendar.
 *
 * A plain class, not a Symfony AbstractController subclass: a reusable bundle
 * defines its services explicitly ("Services should not use autowiring or
 * autoconfiguration" — https://symfony.com/doc/current/bundles/best_practices.html),
 * and without autoconfiguration AbstractController's #[Required] setContainer is
 * never called. FrameworkBundle's own TemplateController/RedirectController are
 * written exactly this way — see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php.
 *
 * Patrols is a uhifadhi module, so the bundle may depend on the host's
 * AreaOfInterest (never the reverse); the route's uuid resolves to it via
 * MapEntity.
 *
 * READING IS STATED AS A PAIR ON THE ROUTE, with #[IsGranted('patrols.read',
 * subject: 'area')] — the listener resolves the subject by argument name and
 * asks the checker with the area the route resolved. Unlike the entry flow,
 * these read screens are registered unconditionally, so on an installation with
 * no SecurityBundle the listener that honours the attribute is absent and the
 * pages are open — which is what such an installation has always been, having
 * no firewall to sign anybody in with, and is why no write of this module is
 * registered there.
 *
 * @see https://symfony.com/doc/current/security.html#access-control-in-controllers
 * @see vendor/symfony/security-http/EventListener/IsGrantedAttributeListener.php
 */
// EVERY ROUTE BELOW BELONGS TO THIS MODULE, and says so: where an area has
// parked Patrols, the registry closes these routes before the controller runs.
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolController
{
    /**
     * @param bool                       $widgetScreens whether the widget library exists in this installation (it needs SecurityBundle)
     * @param TokenStorageInterface|null $tokenStorage  null without security — the layout is then the shipped composition for everyone
     * @param int                        $retentionDays patrol.discard_retention_days — the register row states each discarded patrol's removal date from it
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        // WHAT THE MONTH IS MADE OF. The grid is the atlas's; this says what
        // happened on which day, and the calendar widget hands it over.
        private readonly PatrolCalendar $calendar,
        private readonly PatrolMapService $plates,
        // THE AREA'S GROUND — the boundary and the zones every plate stands
        // on, as the area answers it; the atlas draws it.
        private readonly AreaMapPayload $ground,
        // The ground the month's routes covered, held for the day it was
        // measured on — PL·03's set operation is real work on a busy month.
        private readonly PatrolCoverageService $coverage,
        // The one place the day's live reading is measured — "out right now",
        // the zone gaps and the observation queue. The dashboard's direction
        // widgets (Out right now, Where nobody has been, Observations awaiting
        // action, the handover note) read exactly what the area overview reads,
        // so the two surfaces can never disagree about the same morning.
        private readonly PatrolOverviewService $overview,
        private readonly WidgetService $widgets,
        // The area's own observation vocabulary, and the one place its counts
        // are worked out — the read-only kinds card reads both.
        private readonly TaxonomyKindRepository $kinds,
        private readonly PatrolKindsService $observationKinds,
        // THE AREA'S OWN PATROL TYPES — the Patrol types section's list, which is what every chip,
        // colour, chart and filter on this page is drawn from.
        private readonly PatrolTypeRepository $patrolTypes,
        // WHETHER TO DRAW A DOOR, asked in the one place that answers it. The
        // dashboard used to carry its own copy of the two-question policy; two
        // copies is one too many, and this is the copy.
        private readonly PatrolScreenAccessService $screens,
        private readonly bool $widgetScreens = false,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly int $retentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
    ) {
    }

    #[Route('/areas/{uuid}/modules/patrols', name: 'patrol_dashboard', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function dashboard(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        // "now" is injected into the pure dashboard service (never read from a
        // clock inside it) and handed to the template too — the last-patrol KPI
        // and the calendar title are stated relative to the SAME instant.
        $now = new \DateTimeImmutable();

        // ONE FILTER DRIVES EVERYTHING, and this is where it is read: type,
        // station, zone and month, once, out of the query. The map, the log and
        // the charts are all readings of the set it selects, so they cannot be
        // answering different questions. Untrusted like every query field — an
        // unreadable value widens the view rather than narrowing it.
        $filter = PatrolFilter::fromRequest($request, $now);
        [$monthStart, $nextMonth] = $filter->window();

        // The rows to LOAD are wider than the month: the calendar draws its dimmed
        // neighbours and the five-week chart runs back before the month — one
        // window covers all three, and the service buckets each widget within it.
        [$loadFrom, $loadUntil] = PatrolDashboardService::loadRange($monthStart, $now);
        $patrols = $this->patrols->findByAreaStartedBetweenLatestFirst($area, $loadFrom, $loadUntil);

        $types = $this->patrolTypes->findVocabularyByArea($area);

        $dashboard = $this->dashboard->build(
            $patrols,
            $types,
            $now,
            // PL·03 is the one month figure the loaded rows cannot answer: it is
            // a PostGIS set operation over the month's tracks, asked for exactly
            // the window the service counts in.
            $this->patrols->coverageFractionWithin(
                $area,
                PatrolDashboardService::COVERAGE_BUFFER_M,
                $monthStart,
                $nextMonth,
            ),
            $filter,
            // The ZONE each patrol set out in — a live PostGIS spatial join
            // against the host's zone polygons, over exactly the month's rows.
            // The zone FILTER reads it too, so a patrol is narrowed by the same
            // answer the map draws it under.
            $patrolZones = $this->patrols->zonesForPatrols($area, $monthStart, $nextMonth),
        );

        return new Response($this->twig->render('@UhifadhiPatrol/dashboard/show.html.twig', [
            'area' => $area,
            'types' => $types,
            'typeCat' => PatrolDashboardService::typePositions($types),
            'now' => $now,
            // The month on screen — the filter's choice, so the bar can name it
            // and mark the chosen option, and the page can read one month.
            'month' => $monthStart,
            // THE FILTER ITSELF, so every chip and option in the bar can link to
            // the same question narrowed on one axis, and mark what is chosen.
            'filter' => $filter,
            // THE MONTH'S FEED AND ITS SUBJECT. The calendar widget hands both
            // to atlas_calendar(); the module draws no grid of its own, and the
            // scope carries the surface's own type filter so the month narrows
            // with everything else on the screen.
            'calendarFeed' => $this->calendar,
            'calendarScope' => PatrolCalendar::scopeFor((string) $area->getUuidString(), $filter->type),
            // patrol id → zone name: the log rows name the zone each patrol set
            // out in, which is what the ZONE options are chosen from.
            'patrolZones' => $patrolZones,
            'recordScreens' => $this->screens->mayRecord($area),
            'manageScreens' => $this->screens->mayConfigureObservationKinds($area),
            'retentionDays' => $this->retentionDays,
            // The read-only kinds card: what a ranger may log here, and how
            // often each was logged this month. Editing is one click away in
            // Configure and never on a dashboard.
            'kinds' => $kinds = $this->kinds->forArea($area),
            'kindCounts' => $this->observationKinds->countsByCode($dashboard->patrols),
            'kindSubcategoryCount' => array_sum(array_map(
                static fn (TaxonomyKind $kind): int => \count($kind->getSubcategories()),
                $kinds,
            )),
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // HOST's widget framework resolving this surface's catalogue: the
            // shipped composition until they change it in the widget library.
            'widgets' => $this->widgets->resolve(PatrolWidgets::declaration(), $this->widgetUser(), $area->getUuid()),
            'dashboard' => $dashboard,
            // What the coverage map draws — boundary + every recorded track this
            // month, each tagged with the zone it set out in.
            'map' => $this->plates->coverage(
                $this->ground->forArea($area),
                $this->dashboard->coveragePayload($dashboard, $types, $patrolZones),
                $types,
                PatrolDashboardService::typeSwatches($types),
                // The ground the MONTH's routes covered — PL·03's own set
                // operation, drawn. Not narrowed by the chips, exactly as the
                // KPI beside it is not: the shape and the number are the same
                // measurement or one of them is lying.
                $this->coverage->bufferFor($area, $monthStart, $nextMonth, $now),
            ),
            // The live reading the off-by-default direction widgets need, from
            // the ONE service that measures it (see the overview service): who is
            // still out, which handsets are silent, the zone gaps, and the recent
            // observation queue. A dashboard that shows none of these still pays
            // for them, which is cheap; a preset that shows them must have them.
        ] + $this->overview->dashboardReading($area, $now)));
    }

    /**
     * WHOSE LAYOUT TO RESOLVE — the contract's person, never an installation's
     * own account class. Null where the installation has no security, or nobody
     * is signed in, and the framework then draws the shipped composition, which
     * is the right screen for a reader who has arranged nothing.
     */
    private function widgetUser(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
