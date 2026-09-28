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

namespace Uhifadhi\Patrol\Me;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Model\MyPatrols;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Service\MyPatrolsService;
use Uhifadhi\Patrol\Service\PatrolScreenAccessService;

/**
 * WHAT THE PATROL MODULE SHOWS A PERSON ABOUT THEMSELVES on their own
 * dashboard (#19, option A of variants-my-dashboard, ruled 28 Sep 2026):
 * three figures — distance covered, my patrols, observations — then the week's
 * walking as a bar a day and my latest patrols beside the plate, and my latest
 * observations in the row at the foot.
 *
 * THE SLOTS ARE THE APPROVED LAYOUT'S, and the orders are fixed beside the
 * core's own cards: the area's watch is FIGURE 10, so these are 20, 30 and
 * 40; the plate is the area's, so these two stand beside it; the team's phone
 * is ROW 30, so the observations are ROW 10.
 *
 * THE PERSON IS THE PATROL'S LEAD ({@see MyPatrolsService}), and every figure
 * is theirs alone: a ranger reads their own walking without holding any grant
 * to the area's patrols.
 *
 * THE ONE DOOR IS DRAWN ONLY FOR SOMEBODY IT OPENS FOR — "All my patrols"
 * leads into the module's own list, which is gated by `patrols.read` on the
 * area and closed where the area parked the module, so both are asked before
 * the link is written ({@see PatrolScreenAccessService}). A ranger is never
 * handed a link that answers 403 or 404.
 *
 * THE TRACKS ON THE PLATE ARE NOT HERE. The plate is the area's card, and a
 * person's tracks on it would be a layer the area asks for; this provider
 * says nothing about the map.
 *
 * @see \Uhifadhi\Bundle\AreaBundle\Me\AreaMyCards the ground's cards, whose shape this follows
 */
final readonly class PatrolMyCards implements MyCardProviderInterface
{
    /**
     * @param array<string, array{label: string}> $categories the installation's `patrol.observation_categories`, the words an area's own kinds fall back to
     */
    public function __construct(
        private Environment $twig,
        private MyPatrolsService $reading,
        private PatrolScreenAccessService $access,
        private AreaModuleService $areaModules,
        private TaxonomyKindRepository $kinds,
        private UrlGeneratorInterface $router,
        private array $categories,
    ) {
    }

    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array
    {
        $mine = $this->reading->read($personUuid, $now);
        if (null === $mine) {
            return [];
        }

        return [
            new MyCard(MyCard::FIGURE, 20, $this->twig->render('@UhifadhiPatrol/me/_distance_figure.html.twig', ['mine' => $mine])),
            new MyCard(MyCard::FIGURE, 30, $this->twig->render('@UhifadhiPatrol/me/_patrols_figure.html.twig', ['mine' => $mine])),
            new MyCard(MyCard::FIGURE, 40, $this->twig->render('@UhifadhiPatrol/me/_observations_figure.html.twig', ['mine' => $mine])),
            new MyCard(MyCard::BESIDE_PLATE, 10, $this->twig->render('@UhifadhiPatrol/me/_week_card.html.twig', ['mine' => $mine])),
            new MyCard(MyCard::BESIDE_PLATE, 20, $this->twig->render('@UhifadhiPatrol/me/_patrols_card.html.twig', [
                'mine' => $mine,
                'door' => $this->door($mine),
            ])),
            new MyCard(MyCard::ROW, 10, $this->twig->render('@UhifadhiPatrol/me/_observations_card.html.twig', [
                'rows' => array_map(
                    fn (Observation $observation): array => ['observation' => $observation, 'kind' => $this->kindOf($observation)],
                    $mine->latestObservations,
                ),
            ])),
        ];
    }

    /**
     * THE LIST, NARROWED TO THIS PERSON, in the area of their latest patrol —
     * or null where it would not open for them. The list searches a patrol's
     * lead by name, so the person's own name is the filter.
     */
    private function door(MyPatrols $mine): ?string
    {
        $area = ($mine->latestPatrols[0] ?? null)?->getArea();
        if (null === $area
            || !$this->areaModules->isActive($area, PatrolModuleProvider::SLUG)
            || !$this->access->mayRead($area)) {
            return null;
        }

        return $this->router->generate('patrol_list', [
            'uuid' => $area->getUuidString(),
            'q' => $mine->person->getFullName(),
        ]);
    }

    /**
     * The kind an observation was filed under, in words: the patrol's area's
     * own kind where it keeps one by that code, else the installation's
     * category of that key, else the code itself — the order every other
     * screen of this module reads a kind in.
     */
    private function kindOf(Observation $observation): string
    {
        $code = $observation->getCategory();

        return $this->kinds->findOneByAreaAndCode($observation->getPatrol()->getArea(), $code)?->getLabel()
            ?? $this->categories[$code]['label']
            ?? $code;
    }
}
