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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolListService;
use Uhifadhi\Patrol\Service\PatrolScreenAccessService;

/**
 * THE FULL LOG — every patrol the month holds, uncapped, which is the one thing
 * the dashboard's log card cannot be: a card that grew with the data would make
 * a busy month a page nobody can read.
 *
 * IT IS A DATA PLACE, so it is one of the module's two tabs, and its detail
 * screens light it — see {@see \Uhifadhi\Patrol\Shell\PatrolModuleTabs}.
 *
 * LEAN, like every controller here: it reads the one filter out of the query,
 * asks the repository for the month and hands both to the service that decides
 * what is on screen. No base class — a reusable bundle's controller takes what
 * it needs in its constructor and is wired explicitly, exactly as
 * FrameworkBundle's own TemplateController is.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final readonly class PatrolListController
{
    public function __construct(
        private Environment $twig,
        private PatrolRepository $patrols,
        private PatrolListService $list,
        private PatrolScreenAccessService $screens,
        private PatrolTypeRepository $types,
        private TablePageService $pages,
        private int $retentionDays,
    ) {
    }

    #[Route('/areas/{uuid}/modules/patrols/patrols', name: 'patrol_list', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('patrols.read', subject: 'area')]
    public function list(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $now = new \DateTimeImmutable();
        $filter = PatrolFilter::fromRequest($request, $now);
        [$monthStart, $nextMonth] = $filter->window();
        // THE AREA'S OWN WORDS, not the installation's — the list of types a
        // filter menu offers and a row is labelled from is the one the types section edits.
        $types = $this->types->findVocabularyByArea($area);

        $list = $this->list->build(
            $this->patrols->findByAreaStartedBetweenLatestFirst($area, $monthStart, $nextMonth),
            // The ZONE each patrol set out in — the same live spatial join
            // the dashboard reads, so the zone a row names here and the zone
            // the map draws it under are one answer.
            $this->patrols->zonesForPatrols($area, $monthStart, $nextMonth),
            $types,
            $filter,
        );
        // `?page` and `?per` are untrusted like every query field: the pager
        // falls back to the first page and twenty rows rather than failing.
        $table = $this->pages->read($request, $list->totalCount);

        return new Response($this->twig->render('@UhifadhiPatrol/list/show.html.twig', [
            'area' => $area,
            'types' => $types,
            'typeCat' => PatrolDashboardService::typePositions($types),
            'now' => $now,
            'month' => $monthStart,
            'filter' => $filter,
            'retentionDays' => $this->retentionDays,
            // The design's Log patrol action. Drawn only where the screen exists
            // AND this viewer may open it — never a door that answers with 403.
            'recordScreens' => $this->screens->mayRecord($area),
            'list' => $list,
            'rows' => $table->slice($list->patrols),
            'table' => $table,
            'links' => $this->pages->links($request, $table),
        ]));
    }
}
