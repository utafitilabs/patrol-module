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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;
use Uhifadhi\Patrol\Exception\TaxonomyConflictException;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Patrol\Service\PatrolScreenAccessService;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;

/**
 * THE AREA-SCOPED PATROL OBSERVATION-TAXONOMY ADMIN — the design's taxonomy.html,
 * ported. It rhymes with the incident taxonomy admin and shares a stylesheet
 * vocabulary, but not one line of PHP: patrol's is SHALLOW — labels only, no
 * behaviour blocks, no colour, no money.
 *
 * ONE ROUTE, TWO DATA CONDITIONS. {@see self::show()} is the whole screen: a
 * populated area gets the two-pane manager (kinds on the left, the selected kind's
 * sub-categories on the right); an area with no kinds gets the empty start — the
 * ghost sketch of a taxonomy's shape and the "write the first kind" path. It is
 * the same address, never a second route.
 *
 * AREA SCOPE IS THE ONE FACT. Every read and every write is confined to the area
 * in the URL: a kind or sub-category whose uuid does not belong to THIS area is a
 * 404, so no request can reach across into another area's list.
 *
 * A plain class, not AbstractController — a reusable bundle defines its services
 * explicitly, patterned on FrameworkBundle's TemplateController. Registered only
 * under the SecurityBundle guard (see UhifadhiPatrolBundle), because every write
 * here rides on `patrols.manage` and there is nobody to grant it without a
 * firewall. The class-level route default tags every route with the module slug,
 * so parking Patrols in an area closes these routes with the rest of the module.
 *
 * THE COPY-FROM-ANOTHER-AREA PICKER IS DEFERRED, and where it will attach is marked in the
 * empty-state template. It needs to enumerate areas and read their NAMES, which
 * requires an area-directory contract that is not yet ruled; this admin ships the
 * "write the first kind" start and leaves the picker's socket open. See
 * templates/taxonomy/_empty.html.twig.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolTaxonomyController
{
    /** The token id every taxonomy write carries. */
    public const string CSRF_TOKEN_ID = 'patrol_taxonomy';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly TaxonomyAdminService $admin,
        private readonly TaxonomyKindRepository $kinds,
        private readonly TaxonomySubcategoryRepository $subcategories,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly PatrolScreenAccessService $screens,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/kinds',
        name: 'patrol_kinds',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $kinds = $this->kinds->forArea($area);
        $selected = $this->selectedKind($kinds, $request->query->getString('kind'));

        return new Response($this->twig->render('@UhifadhiPatrol/kinds/show.html.twig', [
            'area' => $area,
            'kinds' => $kinds,
            'selected' => $selected,
            // The one page action this screen draws. The way back is the strip,
            // the lit Configure and the crumb — never a button of its own.
            'recordScreens' => $this->screens->mayRecord($area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    // ── kinds ────────────────────────────────────────────────────────────────

    #[Route('/areas/{uuid}/modules/patrols/kinds', name: 'patrol_kinds_kind_create', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function createKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        $this->guardWrite($request);

        try {
            $kind = $this->admin->createKind($area, $request->request->getString('label'));

            return $this->backToManager($area, $kind);
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $this->selectedFromRequest($area, $request), $e);
        }
    }

    #[Route('/areas/{uuid}/modules/patrols/kinds/{kind}/rename', name: 'patrol_kinds_kind_rename', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function renameKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);

        try {
            $this->admin->renameKind($entity, $request->request->getString('label'));
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $entity, $e);
        }

        return $this->backToManager($area, $entity);
    }

    #[Route('/areas/{uuid}/modules/patrols/kinds/{kind}/deactivate', name: 'patrol_kinds_kind_deactivate', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function deactivateKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);
        $this->admin->deactivateKind($entity);

        return $this->backToManager($area, $entity);
    }

    #[Route('/areas/{uuid}/modules/patrols/kinds/{kind}/reactivate', name: 'patrol_kinds_kind_reactivate', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function reactivateKind(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);
        $this->admin->reactivateKind($entity);

        return $this->backToManager($area, $entity);
    }

    // ── sub-categories ─────────────────────────────────────────────────────────

    #[Route('/areas/{uuid}/modules/patrols/kinds/{kind}/subcategories', name: 'patrol_kinds_sub_create', requirements: ['uuid' => Requirement::UUID, 'kind' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function createSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $kind): Response
    {
        $this->guardWrite($request);
        $entity = $this->kind($area, $kind);

        try {
            $this->admin->createSubcategory($entity, $request->request->getString('label'));
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $entity, $e);
        }

        return $this->backToManager($area, $entity);
    }

    #[Route('/areas/{uuid}/modules/patrols/kinds/subcategories/{sub}/rename', name: 'patrol_kinds_sub_rename', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function renameSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);

        try {
            $this->admin->renameSubcategory($entity, $request->request->getString('label'));
        } catch (TaxonomyConflictException $e) {
            return $this->refused($request, $area, $entity->getKind(), $e);
        }

        return $this->backToManager($area, $entity->getKind());
    }

    #[Route('/areas/{uuid}/modules/patrols/kinds/subcategories/{sub}/deactivate', name: 'patrol_kinds_sub_deactivate', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function deactivateSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);
        $this->admin->deactivateSubcategory($entity);

        return $this->backToManager($area, $entity->getKind());
    }

    #[Route('/areas/{uuid}/modules/patrols/kinds/subcategories/{sub}/reactivate', name: 'patrol_kinds_sub_reactivate', requirements: ['uuid' => Requirement::UUID, 'sub' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('observation-kinds.configure', subject: 'area')]
    public function reactivateSubcategory(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $sub): Response
    {
        $this->guardWrite($request);
        $entity = $this->subcategory($area, $sub);
        $this->admin->reactivateSubcategory($entity);

        return $this->backToManager($area, $entity->getKind());
    }

    /*
     * DEFERRED — "COPY FROM ANOTHER AREA".
     *
     * RULED (taxonomy design): at first setup an area may copy ANOTHER real area's
     * observation taxonomy as an editable starting point, which then diverges. It
     * is DEFERRED here on purpose: rendering the picker means ENUMERATING the areas
     * that have a non-empty taxonomy and reading their NAMES, which needs an
     * area-directory contract (Option A: enumerate + getName) that is NOT yet
     * ruled. This module must not reach for area names/enumeration on its own — the
     * frame supplies only the CURRENT area. When the contract lands, this is where
     * a `copyFrom` POST action goes, calling a TaxonomyAdminService::copyFrom(...)
     * that deep-copies kinds and sub-categories and stamps the source as
     * provenance. Its absence is asserted by
     * TaxonomyAdminPageTest::testTheEmptyStartDoesNotShipACopyFromAreaPicker().
     */

    // ── the shared machinery ───────────────────────────────────────────────────

    /**
     * Which kind the manager opens on: the one named in the query if it is this
     * area's and it exists, else the first. Null only when the area is empty.
     *
     * @param list<TaxonomyKind> $kinds
     */
    private function selectedKind(array $kinds, string $uuid): ?TaxonomyKind
    {
        if ([] === $kinds) {
            return null;
        }
        if ('' !== $uuid) {
            foreach ($kinds as $kind) {
                if ($kind->getUuid()->toRfc4122() === $uuid) {
                    return $kind;
                }
            }
        }

        return $kinds[0];
    }

    private function kind(AreaOfInterest $area, string $uuid): TaxonomyKind
    {
        $kind = $this->kinds->findOneByAreaAndUuid($area, $uuid);
        if (null === $kind) {
            throw new NotFoundHttpException('No such observation kind in this area.');
        }

        return $kind;
    }

    private function subcategory(AreaOfInterest $area, string $uuid): TaxonomySubcategory
    {
        $subcategory = $this->subcategories->findOneByAreaAndUuid($area, $uuid);
        if (null === $subcategory) {
            throw new NotFoundHttpException('No such sub-category in this area.');
        }

        return $subcategory;
    }

    /** The kind the request meant to be looking at, for putting a refusal back in place. */
    private function selectedFromRequest(AreaOfInterest $area, Request $request): ?TaxonomyKind
    {
        $uuid = $request->query->getString('kind');

        return '' === $uuid ? null : $this->kinds->findOneByAreaAndUuid($area, $uuid);
    }

    /** Post/redirect/get back to the manager, holding the kind that was in play. */
    private function backToManager(AreaOfInterest $area, ?TaxonomyKind $selected = null): RedirectResponse
    {
        $parameters = ['uuid' => $area->getUuidString()];
        if (null !== $selected) {
            $parameters['kind'] = $selected->getUuid()->toRfc4122();
        }

        return new RedirectResponse($this->router->generate('patrol_kinds', $parameters));
    }

    /** A refused write flashes why, beside where it happened, and returns to the manager. */
    private function refused(Request $request, AreaOfInterest $area, ?TaxonomyKind $selected, TaxonomyConflictException $e): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $e->getMessage());
        }

        return $this->backToManager($area, $selected);
    }

    private function guardWrite(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the taxonomy admin.');
        }
    }
}
