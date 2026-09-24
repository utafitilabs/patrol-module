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
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Model\PatrolBaseDefaults;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Service\PatrolScreenAccessService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;

/**
 * THE WRITES BEHIND THE TWO WORD-LIST SECTIONS — Patrol types and Stations, each
 * a section of the configure page with an address of its own.
 *
 * ONE CONTROLLER FOR BOTH, for the reason {@see PatrolVocabularyService} is one
 * service for both: they are one thing twice. Each takes the same two shapes of
 * write, at the same two shapes of address, and a rule tightened in one of them
 * must hold in the other.
 *
 * TWO SHAPES OF WRITE, BECAUSE THE DESIGN DRAWS TWO.
 *
 *   THE SECTION SAVES IN ONE POST. Every base, glyph and tunable on the page is a
 *   field of the section, and the design draws ONE save row under them; the add
 *   panel's own button submits the same form, so somebody who types a name and
 *   presses Save gets their type either way. Nothing on the section writes on
 *   change.
 *
 *   A ROW'S RENAME, RETIRE AND REACTIVATE ARE EACH THEIR OWN POST. Renaming a
 *   station is not part of choosing a coverage buffer, and a retirement that
 *   rode along with a save nobody meant to make would be a word off the handsets
 *   by accident.
 *
 * LEAN: every action authorizes, checks the token, reads scalars off the request
 * and hands them to a service. What a word MEANS, what a base prefills, what is
 * clamped, what collides and why a retirement never deletes are all
 * {@see PatrolVocabularyService}'s.
 *
 * IT SERVES THE TWO GETS AS WELL, and the stylesheet is why. A section the shell
 * renders as a BODY inside its own configure page can spend only the vocabulary
 * the SHELL's sheet ships: that page links the shell's sheet and no module's, and
 * it is not the shell's business to know which sheets a module's section needs.
 * Both of these sections draw this module's own families — `.stype`, `.stun`,
 * `.sbase`, `.sbpick`, `.sbicon`, `.spoint`, `.sppick`, `.tx-say` — and the
 * stations picker draws the atlas's map plate besides, so each keeps an ADDRESS of
 * its own and its template links what it draws. That is not a second answer to
 * where configuration lives: the section still belongs to the configure page, wears
 * its heading and its strip, and keeps the Configure action lit, exactly as the
 * observation kinds section beside it does.
 *
 * @see PatrolTaxonomyController::show() — the same
 *      arrangement, for the same reason
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final readonly class PatrolVocabularyController
{
    /**
     * The token id every form on this module's configure page carries — the
     * Settings section's included, because one page with two ids is a page where
     * a token read off one form is refused by the next.
     */
    public const string CSRF_TOKEN_ID = PatrolSettingsController::CSRF_TOKEN_ID;

    /**
     * THE TWO ADDRESSES, published because the declaration names them in the strip
     * and every save redirects back to one. A route name typed twice is a route
     * name that eventually differs.
     */
    public const string TYPES_ROUTE = 'patrol_types';

    /** What a row's buttons may ask for. Anything else is not a button we drew. */
    private const array ACTIONS = ['rename', 'retire', 'reactivate'];

    /**
     * What a point off the world is told. It names the plate rather than the
     * fields, because the fields are hidden and the plate is what a person used.
     */
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private PatrolVocabularyService $vocabulary,
        private PatrolTypeRepository $types,
        private PatrolScreenAccessService $screens,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    // ── the Patrol types section ──────────────────────────────────────────────

    /**
     * THE SECTION, READ. Open to anybody the installation lets onto the configure
     * page, exactly as the Settings section is — reading what an area patrols on is
     * not a privilege. The WRITE controls are drawn only for somebody who may
     * manage, so a reader gets the section rather than a form that answers 403.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/types',
        name: 'patrol_types',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    #[IsGranted('patrol-types.read', subject: 'area')]
    public function types(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        /*
         * AN AREA NOBODY HAS CONFIGURED YET OPENS ON THE INSTALLATION'S WORDS,
         * written in as its own — the one thing `patrol.types` is still for. After
         * this the two have nothing to do with each other: renaming a type here
         * changes this area and no other, and a later config change never reaches
         * back into a list somebody has curated.
         */
        $this->vocabulary->seedTypes($area);
        $types = $this->types->findByArea($area);

        return new Response($this->twig->render('@UhifadhiPatrol/types/show.html.twig', [
            'area' => $area,
            'types' => $types,
            'typeCounts' => $this->types->countPatrolsByArea($area),
            'bases' => PatrolBaseEnum::cases(),
            // WHAT EACH BASE SEEDS AND WHAT MARK A ROW DRAWS, resolved here: both
            // are one `match` over an enum, and a template that reached for them
            // would be a template holding the rule.
            'baseDefaults' => self::baseDefaults(),
            'typeGlyphs' => self::glyphsOf($types),
            'glyphs' => PatrolBaseDefaults::GLYPHS,
            'placements' => ObservationPlacementEnum::cases(),
            'minPaceKmh' => PatrolBaseDefaults::MIN_PACE_KMH,
            'maxPaceKmh' => PatrolBaseDefaults::MAX_PACE_KMH,
            'minBufferM' => PatrolBaseDefaults::MIN_BUFFER_M,
            'maxBufferM' => PatrolBaseDefaults::MAX_BUFFER_M,
            ...$this->chrome($area, $this->screens->mayConfigureTypes($area)),
        ]));
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/types',
        name: 'patrol_types_save',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('patrol-types.configure', subject: 'area')]
    public function saveTypes(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        $this->guard($request);

        /*
         * EVERY ROW THE PAGE CARRIED, keyed by the uuid its fields were named
         * with. A row the section never drew is not looked up: the fields are
         * walked, not the table, so a stale uuid posted by hand writes nothing
         * instead of 404-ing a save that had eleven good rows in it.
         */
        foreach ($this->types->findByArea($area) as $type) {
            $uuid = $type->getUuid()->toRfc4122();

            $base = PatrolBaseEnum::tryFrom(self::string($request, 'base', $uuid));
            if (null !== $base && $base !== $type->getBase()) {
                $this->vocabulary->setTypeBase($type, $base);
            }

            $this->vocabulary->tuneType(
                $type,
                self::number($request, 'pace_min', $uuid),
                self::number($request, 'pace_max', $uuid),
                self::number($request, 'buffer', $uuid),
                ObservationPlacementEnum::tryFrom(self::string($request, 'placement', $uuid)),
                '' !== ($glyph = self::string($request, 'glyph', $uuid)) ? $glyph : null,
            );
        }

        // THE ADD PANEL IS PART OF THE SAME FORM, so a name typed into it is
        // created whichever of the two buttons was pressed. An empty field is
        // simply nobody adding anything, not a refusal.
        $label = trim($request->request->getString('label'));
        if ('' !== $label) {
            try {
                $created = $this->vocabulary->addType(
                    $area,
                    $label,
                    base: PatrolBaseEnum::tryFrom($request->request->getString('add_base')),
                    glyph: $request->request->getString('add_glyph'),
                );
            } catch (VocabularyConflictException $conflict) {
                return $this->back($request, $area, self::TYPES_ROUTE, 'error', $conflict->getMessage());
            }

            return $this->back($request, $area, self::TYPES_ROUTE, 'success', \sprintf(
                '"%s" is a patrol type in this area now.',
                $created->getLabel(),
            ));
        }

        return $this->back($request, $area, self::TYPES_ROUTE, 'success', 'Saved. These are this area’s patrol types.');
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/types/{type}/{action}',
        name: 'patrol_type_act',
        requirements: ['uuid' => Requirement::UUID, 'type' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('patrol-types.configure', subject: 'area')]
    public function actOnType(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $type,
        string $action,
    ): RedirectResponse {
        $this->guard($request);

        $record = $this->types->findOneByAreaAndUuid($area, $type);
        if (!$record instanceof PatrolType || !\in_array($action, self::ACTIONS, true)) {
            throw new NotFoundHttpException('No such patrol type in this area.');
        }

        try {
            $message = match ($action) {
                'rename' => \sprintf('Renamed to "%s".', $this->vocabulary->renameType($record, $request->request->getString('label'))->getLabel()),
                'retire' => \sprintf('"%s" is retired. Every patrol filed under it keeps it.', $this->vocabulary->retireType($record)->getLabel()),
                default => \sprintf('"%s" is back in use.', $this->vocabulary->reactivateType($record)->getLabel()),
            };
        } catch (VocabularyConflictException $conflict) {
            return $this->back($request, $area, self::TYPES_ROUTE, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, self::TYPES_ROUTE, 'success', $message);
    }

    // ── what every section's template is given besides its own rows ───────────

    /**
     * @return array{recordScreens: bool, mayManage: bool, csrfToken: string}
     */
    private function chrome(AreaOfInterest $area, bool $mayConfigure): array
    {
        return [
            // The one page action either screen draws. The way back is the strip,
            // the lit Configure and the crumb — never a button of its own.
            'recordScreens' => $this->screens->mayRecord($area),
            // WHETHER THE FORM IS DRAWN AT ALL. A reader gets the section read-only
            // rather than controls that answer 403 when pressed — and it is THIS
            // section's own pair, because the types and the stations are two
            // things an organization may hand over separately.
            'mayManage' => $mayConfigure,
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ];
    }

    /**
     * What each base prefills, keyed by the wire value the section's radios carry.
     *
     * @return array<string, PatrolBaseDefaults>
     */
    private static function baseDefaults(): array
    {
        $defaults = [];
        foreach (PatrolBaseEnum::cases() as $base) {
            $defaults[$base->value] = PatrolBaseDefaults::of($base);
        }

        return $defaults;
    }

    /**
     * The mark each row draws, keyed by the type's uuid — its own, or its base's,
     * or the question mark a row with no base is asking with.
     *
     * @param list<PatrolType> $types
     *
     * @return array<string, string>
     */
    private static function glyphsOf(array $types): array
    {
        $glyphs = [];
        foreach ($types as $type) {
            $glyphs[$type->getUuid()->toRfc4122()] = PatrolBaseDefaults::glyphOf($type->getBase(), $type->getGlyph());
        }

        return $glyphs;
    }

    // ── reading the section's fields ──────────────────────────────────────────

    /**
     * One row's value out of a field named `field[uuid]`, as a plain string.
     *
     * The whole bag is read rather than `InputBag::getString('field[uuid]')`,
     * which does not address into an array, and the shape is checked rather than
     * asserted: a hand-posted `field=1` is a string where an array was drawn, and
     * that is a row nobody said anything about rather than a 400.
     */
    private static function string(Request $request, string $field, string $uuid): string
    {
        $values = $request->request->all()[$field] ?? null;
        $value = \is_array($values) ? ($values[$uuid] ?? null) : null;

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * The same, as a whole number — and NULL rather than a 400 for anything that
     * is not one, an empty string included.
     *
     * A null means "this row said nothing about it", which is what a row whose
     * disclosure was never opened posts and what the service leaves alone. Out of
     * range is the service's to clamp, not this method's to refuse.
     */
    private static function number(Request $request, string $field, string $uuid): ?int
    {
        $value = filter_var(self::string($request, $field, $uuid), \FILTER_VALIDATE_INT);

        return false === $value ? null : $value;
    }

    // ── the two things every action does ──────────────────────────────────────

    private function guard(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the patrols configure page.');
        }
    }

    private function back(Request $request, AreaOfInterest $area, string $route, string $type, string $message): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        // BACK TO THE SECTION THAT WAS SAVED, at its own address. A save that
        // returned to the bare configure address would land somebody on the widget
        // library — the page's first section — rather than on what they saved.
        return new RedirectResponse($this->router->generate($route, ['uuid' => $area->getUuidString()]));
    }
}
