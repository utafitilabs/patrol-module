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

namespace Uhifadhi\Patrol\Tests\Unit\Org;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Patrol\Model\PatrolOrgReading;
use Uhifadhi\Patrol\Model\PatrolOutRow;
use Uhifadhi\Patrol\Org\PatrolOrgWidgets;
use Uhifadhi\Patrol\Overview\PatrolOverviewContributor;

/**
 * THE FIGURE THE MODULE PUTS IN THE ORGANIZATION'S FOUR-TO-A-ROW STRIP.
 *
 * A pure test of the tile's SHAPE — its label, what it says when the module
 * has measured nothing, and where it sits in the row — with no kernel and no
 * database, because none of that is what the shaping decides.
 */
final class PatrolWeekFigureTest extends TestCase
{
    public function testItIsTheWeeksCount(): void
    {
        $tile = PatrolOrgWidgets::weekTile(self::reading(thisWeek: 41, out: 3, areas: 2));

        self::assertSame('Patrols this week', $tile->label);
        self::assertSame('41', $tile->value);
        self::assertSame(PatrolOverviewContributor::SLUG, $tile->moduleSlug, 'It leaves with the module.');
    }

    /** The instant under the period: a week's count with today's live reading beside it. */
    public function testTheSublinePairsTheWeekWithWhatIsOutNow(): void
    {
        self::assertSame(
            '3 out right now · 2 areas',
            PatrolOrgWidgets::weekTile(self::reading(thisWeek: 41, out: 3, areas: 2))->subline,
        );
    }

    public function testOneAreaIsSaidInTheSingular(): void
    {
        self::assertSame(
            '1 out right now · 1 area',
            PatrolOrgWidgets::weekTile(self::reading(thisWeek: 9, out: 1, areas: 1))->subline,
        );
    }

    /**
     * NOTHING MEASURED IS NOT NOUGHT, and the tile keeps its slot to say so —
     * four cards each stating what they cannot measure is a report; four
     * missing cards is a page somebody has to debug.
     */
    public function testAnInstallationThatHasRecordedNothingSaysSoRatherThanNought(): void
    {
        $tile = PatrolOrgWidgets::weekTile(self::reading(thisWeek: 0, out: 0, areas: 0));

        self::assertSame('—', $tile->value);
        self::assertSame('nothing measured · no patrol recorded yet', $tile->subline);
    }

    /** A quiet week in an area that patrols really is nought — and reads as one. */
    public function testAQuietWeekWhereThereIsARegisterIsAMeasuredNought(): void
    {
        $tile = PatrolOrgWidgets::weekTile(self::reading(thisWeek: 0, out: 0, areas: 1));

        self::assertSame('0', $tile->value);
        self::assertSame('0 out right now · 1 area', $tile->subline);
    }

    /**
     * WHERE THE DESIGN PUTS IT AMONG THE OTHER MODULES' FIGURES: after the
     * roster's people on duty, before the incidents module's open count.
     *
     * The row is assembled from every module that publishes one and sorted on
     * this number, so it is read against the siblings and against nothing
     * else — never against a position, which depends on what an installation
     * happens to run.
     */
    public function testItSortsBetweenTheRostersFigureAndTheIncidentsOne(): void
    {
        $mine = PatrolOrgWidgets::weekTile(self::reading(1, 1, 1))->priority;

        self::assertGreaterThan(self::sibling(20)->priority, $mine, 'After who is on duty.');
        self::assertLessThan(self::sibling(40)->priority, $mine, 'Before what is open.');
    }

    /** A figure some other module publishes, at the priority the design gives it. */
    private static function sibling(int $priority): NowTile
    {
        return new NowTile('XX·G1', 'somebody-else', 'Something else', '1', priority: $priority);
    }

    /** A workshop index is carried, never rendered — the strip's own rule. */
    public function testItCarriesTheDesignsReference(): void
    {
        self::assertSame('PL·G1', PatrolOrgWidgets::weekTile(self::reading(1, 1, 1))->index);
        self::assertSame(NowTile::TONE_PLAIN, PatrolOrgWidgets::weekTile(self::reading(1, 1, 1))->tone);
    }

    private static function reading(int $thisWeek, int $out, int $areas): PatrolOrgReading
    {
        return new PatrolOrgReading(
            out: array_fill(0, $out, new PatrolOutRow('P-1', 'Example square', null, 'Walking round', null, null, false, '/')),
            walkedTodayKm: null,
            thisWeek: $thisWeek,
            areasWithRegister: $areas,
            areasInScope: max($areas, 1),
            dashboardUrl: null,
        );
    }
}
