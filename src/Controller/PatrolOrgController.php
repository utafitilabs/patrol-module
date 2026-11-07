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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Model\PatrolOrgTypeRow;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Service\PatrolOrgService;

/**
 * PATROLS AT ORGANIZATION SCOPE — the area module's three screens, read
 * across every area it is switched on in.
 *
 * THE SHELL DRAWS THE CHROME. The sidebar row, the tab strip and the scope
 * control are mounted from what {@see PatrolModuleProvider::orgPages()}
 * names; nothing here writes a row, a tab or a control.
 *
 * EVERY FIGURE IS THE AREA QUERY ONE SCOPE WIDER, folded by
 * {@see PatrolOrgService}; not one number is computed here.
 *
 * THE GATE IS THE SCOPE'S, THEN THE MODULE'S. Reaching the page asks the
 * Areas concern, as every organization-level screen does; the areas it reads
 * are then the scope's, narrowed to those where this reader may read patrols,
 * because seeing an area is not the same as reading what its rangers logged.
 *
 * No base class, wired explicitly, as FrameworkBundle's own TemplateController.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 *
 * THE ROUTES SAY WHICH MODULE THEY ARE, like every other: with no area in
 * them the registry's ledger has nothing to close, and a module switched off
 * in an area only leaves that area out of the reading.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final readonly class PatrolOrgController
{
    public const string OVERVIEW_ROUTE = 'patrol_org_overview';
    public const string PATROLS_ROUTE = 'patrol_org_patrols';
    public const string TYPES_ROUTE = 'patrol_org_types';

    /** The pair a reader holds in an area for that area's patrols to be read here. */
    public const string READ_PERMISSION = 'patrols.read';

    public function __construct(
        private Environment $twig,
        private PatrolOrgService $org,
        private Scopes $scopes,
        private TablePageService $pages,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[Route('/patrols', name: self::OVERVIEW_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function overview(): Response
    {
        [$scope, $areas, $now] = $this->reading();

        return new Response($this->twig->render('@UhifadhiPatrol/org/overview.html.twig', [
            'scope' => $scope,
            'facts' => $this->org->facts($areas, $now),
            'silent' => $this->org->silent($areas, $now),
        ]));
    }

    #[Route('/patrols/patrols', name: self::PATROLS_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function patrols(Request $request): Response
    {
        [$scope, $areas, $now] = $this->reading();
        $filter = PatrolFilter::fromRequest($request, $now);
        $list = $this->org->patrols($this->readable($this->org->areasIn(Scope::organization(), $this->scopes->available())), $areas, $filter);
        $table = $this->pages->read($request, \count($list->rows));

        return new Response($this->twig->render('@UhifadhiPatrol/org/patrols.html.twig', [
            'scope' => $scope,
            'now' => $now,
            'filter' => $filter,
            'list' => $list,
            'rows' => $table->slice($list->rows),
            'table' => $table,
            'links' => $this->pages->links($request, $table),
            'silent' => $this->org->silent($areas, $now),
        ]));
    }

    #[Route('/patrols/types', name: self::TYPES_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function types(Request $request): Response
    {
        [$scope, $areas, $now] = $this->reading();
        $types = $this->org->types($areas, $now);

        // ONE TYPE ON THE RIGHT AT A TIME, its own address: the left pane's
        // rows are links, and an unknown key opens the first type.
        $asked = $request->query->getString('type');
        $chosen = null;
        foreach ($types as $type) {
            if ($type->key === $asked) {
                $chosen = $type;
            }
        }
        $chosen ??= $types[0] ?? null;

        // THE STATE CHIP over the chosen type's rows: in use, base not set,
        // not in use — the three answers an area can give for one word.
        $state = $request->query->getString('state');
        $cells = null === $chosen ? [] : $chosen->cells;
        $states = ['in-use' => 0, 'base-not-set' => 0, 'not-in-use' => 0];
        foreach ($cells as $cell) {
            ++$states[self::stateOf($cell->kept, $cell->baseNotSet())];
        }
        if (isset($states[$state])) {
            $cells = array_values(array_filter($cells, static fn ($cell): bool => self::stateOf($cell->kept, $cell->baseNotSet()) === $state));
        } else {
            $state = '';
        }
        $table = $this->pages->read($request, \count($cells));

        return new Response($this->twig->render('@UhifadhiPatrol/org/types.html.twig', [
            'scope' => $scope,
            'types' => $types,
            'chosen' => $chosen,
            'state' => $state,
            'states' => $states,
            'cells' => $table->slice($cells),
            'table' => $table,
            'links' => $this->pages->links($request, $table),
            'unset' => array_values(array_filter($types, static fn (PatrolOrgTypeRow $type): bool => $type->baseNotSetSomewhere())),
            'bases' => PatrolBaseEnum::cases(),
        ]));
    }

    private static function stateOf(bool $kept, bool $baseNotSet): string
    {
        return match (true) {
            !$kept => 'not-in-use',
            $baseNotSet => 'base-not-set',
            default => 'in-use',
        };
    }

    /**
     * WHAT EVERY ONE OF THE THREE READS — one scope, its areas, one instant.
     *
     * @return array{0: Scope, 1: list<AreaOfInterest>, 2: \DateTimeImmutable}
     */
    private function reading(): array
    {
        $scope = $this->scopes->current() ?? Scope::organization();

        return [$scope, $this->readable($this->org->areasIn($scope, $this->scopes->available())), new \DateTimeImmutable()];
    }

    /**
     * @param list<AreaOfInterest> $areas
     *
     * @return list<AreaOfInterest>
     */
    private function readable(array $areas): array
    {
        return array_values(array_filter(
            $areas,
            fn (AreaOfInterest $area): bool => $this->authorization->isGranted(self::READ_PERMISSION, $area),
        ));
    }
}
