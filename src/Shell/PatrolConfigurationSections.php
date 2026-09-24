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

namespace Uhifadhi\Patrol\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Patrol\Controller\PatrolVocabularyController;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Service\PatrolSettingsService;

/**
 * WHAT IS ON THE PATROLS CONFIGURE PAGE — five sections, in the order the
 * platform rules and not the order written here: the library, the types, the
 * places, the words, the numbers.
 *
 * FOUR OF THEM KEEP AN ADDRESS OF THEIR OWN, and the STYLESHEET is the reason
 * rather than taste. A section the shell renders as a BODY inside its own
 * configure page can spend only the vocabulary the SHELL's sheet ships: that page
 * links the shell's sheet and no module's, and it is not the shell's business to
 * know which sheets a module's section needs. The widget library and the
 * observation kinds each draw their own families, and so do the patrol types
 * (`.stype`, `.stun`, `.sbase`, `.sbpick`, `.sbicon`) and the stations
 * (`.spoint`, `.sppick`, and the atlas's own map plate) — so each is declared as
 * {@see ConfigurationSection::screen()}, keeps an address of its own, and links
 * what it draws. Each still belongs to the configure page: it wears the page's
 * heading and the page's strip, and the Configure action stays lit on it, because
 * that screen adopts the same frame.
 *
 * There is a second reason and it is the harder one: `sections()` is consulted on
 * EVERY page of this module, and the library's body is assembled from the month's
 * patrols, the coverage buffer, the map plate, the day's live reading, the
 * viewer's presets and a token. Handing that over as a rendered section's
 * variables would build a whole dashboard preview on every request this module
 * serves — and the stations section would build a map plate on every one.
 *
 * ITS ONE RENDERED SECTION IS BUILT ONLY WHERE IT IS DRAWN. The Settings body
 * spends the shell's vocabulary alone — `.c`, `.frow`, `.fld`, `.staddrow` — so it
 * needs no sheet of its own and stays a body the shell renders; its variables are
 * gathered only when the request IS the shell's configure page, and everywhere
 * else the section is declared with its label alone, which is all the strip needs.
 *
 * IT RESOLVES THE REQUEST ITSELF, like every other source in the frame: the
 * shell passes nothing, because it has a slug and not an area.
 */
final readonly class PatrolConfigurationSections implements ConfigurationSectionsInterface
{
    /** The shell's own configure route, which is where a section body is drawn. */
    private const string CONFIGURE_ROUTE = 'shell_module_configure';

    /**
     * THE TWO SECTIONS THIS MODULE NAMES ITSELF. The ids are the words the design's
     * own crumb ends in — "configure / types", "configure / stations" — and the
     * module's namespace is what makes them unambiguous.
     */
    public const string TYPES = 'types';

    public const string STATIONS = 'stations';

    public function __construct(
        private RequestStack $requests,
        private AreaOfInterestRepository $areas,
        private PatrolSettingsService $settings,
        /*
         * NULL WHERE THE INSTALLATION RUNS NO SECURITY — and there the Settings
         * form has no route to post to either, so the section renders as a
         * reading of what the area runs on rather than a form that cannot save.
         */
        private ?CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function slug(): string
    {
        return PatrolModuleProvider::SLUG;
    }

    /**
     * The whole heading, to which the shell adds " · configure". Empty when the
     * request names no area — a page the configure route never serves, but a
     * source that answers only on the pages it expects is a source that throws
     * on the one it did not.
     */
    public function heading(): string
    {
        $area = $this->currentArea();

        return null === $area ? '' : $area->getName().' — Patrols';
    }

    public function summary(): string
    {
        return 'Everything this module is set up with in this area, in one place: how its dashboard is composed, '
            .'the words a ranger picks from, and the numbers the module runs on.';
    }

    public function sections(): array
    {
        return [
            ConfigurationSection::screen(
                ConfigurationSection::WIDGETS,
                'Widget library',
                'patrol_widgets',
            ),
            ConfigurationSection::screen(
                self::TYPES,
                'Patrol types',
                PatrolVocabularyController::TYPES_ROUTE,
            ),
            ConfigurationSection::screen(
                self::STATIONS,
                'Stations',
                PatrolVocabularyController::STATIONS_ROUTE,
            ),
            // THE WORD IS THIS MODULE'S. The shell prints "Observation kinds"
            // because this line says so; the frame has no vocabulary to impose.
            ConfigurationSection::screen(
                'kinds',
                'Observation kinds',
                'patrol_kinds',
            ),
            ConfigurationSection::page(
                ConfigurationSection::SETTINGS,
                'Settings',
                '@UhifadhiPatrol/configure/_settings.html.twig',
                $this->settingsVariables(),
            ),
        ];
    }

    /**
     * What the Settings body is given — and nothing at all unless the viewer is on
     * the page that draws it.
     *
     * A DECLARATION IS CONSULTED ON EVERY PAGE OF THE MODULE, so the one body the
     * shell renders is gathered only when the request IS the shell's configure
     * page; everywhere else the section is declared with its label alone, which is
     * all the strip needs. A declaration that queried on every page would make a
     * page frame expensive.
     *
     * @return array<string, mixed>
     */
    private function settingsVariables(): array
    {
        $request = $this->requests->getCurrentRequest();
        $area = $this->currentArea();

        if (null === $request || null === $area || self::CONFIGURE_ROUTE !== $request->attributes->get('_route')) {
            return [];
        }

        return [
            'area' => $area,
            'settings' => $this->settings->forArea($area),
            'minGapMinutes' => PatrolSettingsService::MIN_GAP_MINUTES,
            'maxGapMinutes' => PatrolSettingsService::MAX_GAP_MINUTES,
            'minRetentionDays' => PatrolSettingsService::MIN_RETENTION_DAYS,
            'maxRetentionDays' => PatrolSettingsService::MAX_RETENTION_DAYS,
            'csrfToken' => $this->csrfTokenManager?->getToken(PatrolVocabularyController::CSRF_TOKEN_ID)->getValue() ?? '',
        ];
    }

    private function currentArea(): ?AreaOfInterest
    {
        $uuid = $this->requests->getCurrentRequest()?->attributes->get('uuid');

        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
