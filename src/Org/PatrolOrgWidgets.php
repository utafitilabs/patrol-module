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

namespace Uhifadhi\Patrol\Org;

use Uhifadhi\Bundle\AreaBundle\Overview\ContributesStylesheetInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Patrol\Model\PatrolOrgReading;
use Uhifadhi\Patrol\Overview\PatrolOverviewContributor;
use Uhifadhi\Patrol\Service\PatrolOrgOverviewService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;

/**
 * WHAT PATROLS PUTS ON THE ORGANIZATION DASHBOARD — a transcription of the
 * design's own surface declaration for `/` (index.html and org.widgets.js),
 * which is the spec.
 *
 * ONE FIGURE AND ONE CELL, and the design says which: "Patrols this week" in
 * the four-to-a-row strip, and "Patrols out right now" — who is out, since
 * when, on foot or in a vehicle, and how old their last ping is — across
 * every area at once.
 *
 * A SECOND INTERFACE, NOT A WIDER FIRST ONE. {@see PatrolOverviewContributor}
 * answers for one area and is asked only where this module is switched on
 * there; this answers for the organization and is asked once. The slug is the
 * same, so both disappear together the day the module is uninstalled, and the
 * cell's contributor tag is what makes that disappearance read as the system
 * working rather than as a bug.
 *
 * EVERY FIGURE IS THE PER-AREA READING ONE SCOPE WIDER. Nothing here counts
 * anything: {@see PatrolOrgOverviewService} resolves the scope to areas and
 * adds up the readings the module already publishes per area. A second
 * aggregate would be a second answer to one question.
 *
 * Tagged EXPLICITLY in the bundle extension, like every other contribution
 * this module makes — a reusable bundle is not autoconfigured, so an
 * installation's registerForAutoconfiguration never fires for it.
 */
final class PatrolOrgWidgets implements ContributesStylesheetInterface, OrgOverviewContributorInterface
{
    /** The design's id for this cell, and the name preset E composes it under. */
    public const string CELL = 'patrols';

    /**
     * HOW MANY ROWS THE CELL DRAWS BEFORE IT STOPS.
     *
     * A dashboard cell's height may not grow with its data: an organization
     * with thirty patrols out would otherwise push every cell under it off
     * the screen. The rest are reached through the door, and the cell says
     * how many there are so the bound never hides a total.
     */
    public const int ROWS = 5;

    /**
     * THE PAGE'S ONE MEASUREMENT, HELD FOR THE PAGE.
     *
     * The host asks a contributor for its figures and for its context
     * separately, and both questions are about the same morning. Measuring
     * twice would put the strip's count and the cell's rows a query apart,
     * which is the one thing a dashboard may not do — so the reading is taken
     * once per moment and kept, keyed by the scope and the instant it was
     * taken at. It is not a cache with a lifetime: a second instant is a
     * second reading.
     */
    private ?PatrolOrgReading $reading = null;

    private ?string $readingKey = null;

    public function __construct(private readonly PatrolOrgOverviewService $readings)
    {
    }

    public function moduleSlug(): string
    {
        return PatrolOverviewContributor::SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            PatrolOverviewContributor::SLUG,
            'Patrols · uhifadhi/patrol-module',
            'Who is out right now, across every area, and how far the organization walked today. Not the module\'s own dashboard at organization scope — the one live reading somebody watching the whole organization needs before opening any area.',
        );
    }

    /**
     * The design's one cell, with its id, its label, its width and its note.
     *
     * TWELVE OR SIX, which is the narrowing the host's own cells took for the
     * same reason: the compositions that carry this cell give it either the
     * whole row (the duty officer) or half of it (everything), and a table of
     * five columns has no honest reading at a quarter of the page.
     */
    public function widgets(): array
    {
        return [
            new Widget(self::CELL, 'Patrols out right now', PatrolOverviewContributor::SLUG, 12, [12, 6], true,
                'Who is out, since when, on foot or in a vehicle, and how old their last ping is — in every area at once.'),
        ];
    }

    public function partialPattern(): string
    {
        return '@UhifadhiPatrol/org/_w_%s.html.twig';
    }

    /**
     * THE CELL IS DRAWN HERE BY THE HOST, so it arrives with this module's own
     * sheet or it renders as browser defaults.
     *
     * The path is the BUNDLE'S constant, so this, the area contributor and
     * base.html.twig cannot name three different files.
     */
    public function stylesheet(): string
    {
        return UhifadhiPatrolBundle::STYLESHEET;
    }

    /** @return list<NowTile> */
    public function figures(Scope $scope, \DateTimeImmutable $now): array
    {
        return [self::weekTile($this->read($scope, $now))];
    }

    /**
     * THE STRIP'S THIRD FIGURE — how many patrols this organization has logged
     * since monday.
     *
     * A PERIOD, NOT AN INSTANT, and deliberately so: the area strip already
     * carries "Patrols out" as a live tile, and repeating it here would spend
     * the organization's one slot on a number the cell beneath it states in
     * full. The week is what an organization is asked about.
     *
     * COUNTED BY THE MODULE'S OWN RULE — a discarded outing did not happen and
     * one still recording has not finished happening — so this figure and the
     * department plates cannot come to disagree about the same monday.
     *
     * NOTHING MEASURED IS NOT NOUGHT, and the tile keeps its slot to say so.
     * An installation where no area has ever opened a patrol has not walked
     * nought patrols; there is nothing yet to measure, and four cards each
     * stating what they cannot measure is a report where four missing cards
     * is a page somebody has to debug.
     */
    public static function weekTile(PatrolOrgReading $reading): NowTile
    {
        return new NowTile(
            // THE DESIGN'S OWN REFERENCE FOR THIS FIGURE, never rendered:
            // a workshop index in shipped markup is refused by
            // tests/Unit/Template/NoWorkshopLabelsTest.
            index: 'PL·G1',
            moduleSlug: PatrolOverviewContributor::SLUG,
            label: 'Patrols this week',
            value: $reading->measured() ? (string) $reading->thisWeek : '—',
            subline: $reading->measured()
                ? \sprintf('%d out right now · %s', \count($reading->out), self::areaPhrase($reading->areasWithRegister))
                : 'nothing measured · no patrol recorded yet',
            url: $reading->dashboardUrl,
            // WHERE THE DESIGN PUTS IT IN THE ROW OF MODULE FIGURES: after
            // the roster's people on duty, before what the incidents module
            // has open and what the files module is keeping. The row is the
            // modules' and the host only fills what is left of it, so this
            // number is read against the other modules and against nothing
            // else.
            priority: 30,
        );
    }

    /**
     * Everything this module's org partial reads, measured ONCE at `$now`.
     *
     * @return array<string, mixed>
     */
    public function context(Scope $scope, \DateTimeImmutable $now): array
    {
        $reading = $this->read($scope, $now);

        return [
            'out' => $reading->shown(self::ROWS),
            'outTotal' => \count($reading->out),
            'rows' => self::ROWS,
            'walkedTodayKm' => $reading->walkedTodayKm,
            'measured' => $reading->measured(),
            'areasWithRegister' => $reading->areasWithRegister,
            'areaPhrase' => self::areaPhrase($reading->areasWithRegister),
            'dashboardUrl' => $reading->dashboardUrl,
            // The module's own threshold, said on the cell with the number the
            // query uses, so the rule on screen and the rule in code are one.
            'stalePingMinutes' => intdiv(PatrolOverviewService::PING_STALE_AFTER_SECONDS, 60),
        ];
    }

    /** The reading for this scope at this instant, taken once. */
    private function read(Scope $scope, \DateTimeImmutable $now): PatrolOrgReading
    {
        $key = ($scope->areaUuid ?? '').'@'.$now->format('c');
        if ($key !== $this->readingKey || null === $this->reading) {
            $this->reading = $this->readings->forScope($scope, $now);
            $this->readingKey = $key;
        }

        return $this->reading;
    }

    /** "1 area" / "4 areas" — said in one place so the tile and the cell agree. */
    private static function areaPhrase(int $areas): string
    {
        return \sprintf('%d %s', $areas, 1 === $areas ? 'area' : 'areas');
    }
}
