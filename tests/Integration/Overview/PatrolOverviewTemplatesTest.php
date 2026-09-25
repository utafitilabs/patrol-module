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

namespace Uhifadhi\Patrol\Tests\Integration\Overview;

use Twig\Environment;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Overview\PatrolOverviewContributor;

/**
 * THE FIVE PLATES, RENDERED THE WAY THE HOST RENDERS THEM: one partial, one map,
 * `with_context: false`, this module's own reading under `by.patrols` beside the
 * shared keys the host composes.
 *
 * Rendering them here rather than trusting the context tests is the point — a
 * partial that reaches for a global, misspells a key or reads a shape the
 * service does not return fails only when it is drawn, and on this surface it
 * would be drawn inside somebody else's page.
 */
final class PatrolOverviewTemplatesTest extends PatrolOverviewTestCase
{
    /**
     * The merged map the host hands a contributed partial.
     *
     * @return array<string, mixed>
     */
    private function hostContext(): array
    {
        $contributor = $this->service(PatrolOverviewContributor::class);
        \assert($contributor instanceof PatrolOverviewContributor);

        return [
            'area' => $this->area,
            'now' => $this->now(),
            'tiles' => [],
            'attention' => [],
            'layers' => [],
            'legend' => [],
            'by' => ['patrols' => $contributor->context($this->area, $this->now())],
        ];
    }

    private function render(string $widgetId): string
    {
        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        return $twig->render(
            \sprintf('@UhifadhiPatrol/overview/_w_%s.html.twig', $widgetId),
            $this->hostContext(),
        );
    }

    private function busyMorning(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makeZone('South', -3.0, -2.95);

        $lead = $this->makeUser('Neema', 'Kileo');
        $pinging = $this->makePatrol('river', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $pinging->setLead($lead);
        $this->em->flush();
        $this->ping($pinging, [
            ['2026-03-21T11:00:00+00:00', -29.99, -2.95],
            ['2026-03-21T11:30:00+00:00', -29.95, -2.95],
        ]);

        $silent = $this->makePatrol('ridge', 'boat', '2026-03-21T10:37:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($silent, [['2026-03-21T09:32:00+00:00', -29.95, -2.95]]);

        $closed = $this->makePatrol('lake', 'walk', '2026-03-21T05:00:00+00:00', '2026-03-21T09:00:00+00:00');
        $closed->setDistanceKm(41.8)->setTrack('{"type":"LineString","coordinates":[[-30.0,-2.92],[-29.9,-2.92]]}');
        $this->em->flush();

        $this->makeObservation($closed, '2026-03-16T11:42:00+00:00', '{"type":"Point","coordinates":[-29.95,-2.92]}', 'Snare line, six snares, cut and collected');
    }

    // ---- PL·A1 ------------------------------------------------------------

    public function testTheLiveCardCarriesItsContributorTagAndItsLiveDot(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_now');

        self::assertStringContainsString('data-w="pl_now"', $html);
        // The workshop's own reference for this frame stays in the design files.
        self::assertStringNotContainsString("PL\u{00B7}A1", $html);
        self::assertStringContainsString('Out right now', $html);
        // Provenance has to survive a screenshot.
        // The host's contributor tag, unqualified: a module has no hue, so there
        // is no `.patrols` class to carry one and the dot stays the quiet fog
        // every contributor's dot is.
        self::assertStringContainsString('<span class="ao-by"><i></i>patrols</span>', $html);
        self::assertStringContainsString('<span class="ao-live"><i></i>live</span>', $html);
        self::assertStringContainsString('2 open patrols', $html);
    }

    public function testTheLiveCardStatesItsOwnThresholdWithTheNumberTheCodeUses(): void
    {
        $this->busyMorning();

        self::assertStringContainsString('A ping older than 90 minutes is called out', $this->render('pl_now'));
    }

    public function testEachRowNamesThePatrolItsLeadItsTypeAndItsPing(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_now');

        self::assertStringContainsString('NK', $html);
        self::assertStringContainsString('River Post · N. Kileo · out 6 h 22', $html);
        self::assertStringContainsString('<span class="chip ok">ping 12 min ago</span>', $html);
        self::assertStringContainsString('<span class="chip fail">no ping 2 h 10</span>', $html);
        self::assertStringContainsString('Open →', $html);
    }

    public function testTheLiveCardClosesOnHowManyHandsetsAreReporting(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_now');

        self::assertStringContainsString('Handsets reporting', $html);
        // The "last seen" time is a machine <time> the shell's frame localises;
        // the readable UTC text stays as the no-JS fallback.
        self::assertStringContainsString('1 of 2 · Ridge Camp last seen <time datetime="2026-03-21T09:32:00+00:00" data-localtime-format="time">09:32</time>', $html);
    }

    public function testAnEmptyLiveCardSaysNobodyIsOut(): void
    {
        self::assertStringContainsString('No patrol is out right now.', $this->render('pl_now'));
    }

    // ---- PL·A2 ------------------------------------------------------------

    public function testTheDayCardComparesTodayWithTheSameWeekday(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_today');

        // The workshop's own reference for this frame stays in the design files.
        self::assertStringNotContainsString("PL\u{00B7}A2", $html);
        self::assertStringContainsString('· sat 21 mar · vs sat 14 mar', $html);
        self::assertStringContainsString('<b class="disp">1<em>closed</em></b>', $html);
        self::assertStringContainsString('<b class="disp">42<em>km</em></b>', $html);
        self::assertStringContainsString('Still out', $html);
    }

    public function testTheDayCardSaysPlainlyThatFilingIsNotRecordedHere(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_today');

        // The design's "2 already filed as incidents" is a fact this module does
        // not hold, so it is an em dash and a sentence rather than a number.
        self::assertStringContainsString('— filed as incidents', $html);
        self::assertStringContainsString('recorded by the incidents module, not on this side', $html);
    }

    public function testADayWithNoRecordedDistanceShowsAnEmDashAndNotZero(): void
    {
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00');

        $html = $this->render('pl_today');

        self::assertStringContainsString('<b class="disp">—</b>', $html);
        self::assertStringContainsString('no distance recorded today', $html);
        self::assertStringNotContainsString('<em>km</em>', $html);
    }

    // ---- PL·A3 ------------------------------------------------------------

    public function testTheGapsTableIsWorstFirstAndNamesTheTrackThatEntered(): void
    {
        $this->busyMorning();
        $this->runWorker();

        $html = $this->render('pl_gaps');

        // The workshop's own reference for this frame stays in the design files.
        self::assertStringNotContainsString("PL\u{00B7}A3", $html);
        self::assertStringContainsString('· by zone · worst first', $html);
        // South has never been entered and leads the table.
        self::assertLessThan(mb_strpos($html, 'North'), (int) mb_strpos($html, 'South'));
        self::assertStringContainsString('<span class="chip fail">never</span>', $html);
        self::assertStringContainsString('no track has entered it', $html);
        self::assertStringContainsString($this->patrols['lake']->getRef(), $html);
        self::assertStringContainsString('Area within 2 km of a track, this month', $html);
    }

    /**
     * BEFORE THE WORKER HAS RUN, every zone reads "not computed yet · runs
     * hourly" — no date, no gap, no share, and never a false 0 % — and the
     * caption line says the same of the area's figure.
     */
    public function testTheGapsTableBeforeTheWorkerHasRunSaysNotComputedYet(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_gaps');

        self::assertSame(2, substr_count($html, 'not computed yet · runs hourly'));
        self::assertStringNotContainsString('<span class="chip fail">never</span>', $html);
        self::assertStringNotContainsString('0 %', $html);
        self::assertStringContainsString('Area within 2 km of a track, this month · <span class="muted">not computed yet</span><span class="muted"> · runs hourly</span>', $html);
    }

    public function testAnAreaWithNoZonesSaysSoRatherThanShowingAnEmptyTable(): void
    {
        self::assertStringContainsString('This area has no zones drawn', $this->render('pl_gaps'));
    }

    // ---- PL·A4 ------------------------------------------------------------

    public function testTheObservationCardMarksNothingAsUnfiledAndSaysWhy(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_obsq');

        // The workshop's own reference for this frame stays in the design files.
        self::assertStringNotContainsString("PL\u{00B7}A4", $html);
        self::assertStringContainsString('filed as an incident is not recorded on this side', $html);
        // NO INVENTED QUEUE: the design's "18 unfiled" is a count this module has
        // no evidence for, so the caption says what the rows actually are.
        self::assertStringContainsString('· oldest first · the latest 1', $html);
        self::assertStringNotContainsString('unfiled ·', $html);
        // NO INVENTED ACTION: filing belongs to another module's route, and this
        // module names no other module.
        self::assertStringNotContainsString('File as incident', $html);
    }

    public function testEachObservationRowCarriesItsRealAgePatrolCategoryNoteAndZone(): void
    {
        $this->busyMorning();

        $html = $this->render('pl_obsq');

        self::assertStringContainsString('OBS-', $html);
        self::assertStringContainsString($this->patrols['lake']->getRef(), $html);
        self::assertStringContainsString('Maintenance need — Snare line, six snares, cut and collected', $html);
        self::assertStringContainsString('North', $html);
        self::assertStringContainsString('<span class="chip fail">5 d</span>', $html);
    }

    // ---- the column -------------------------------------------------------

    public function testTheColumnIsTheModulesOwnCardsAndNotACopyOfThem(): void
    {
        $this->busyMorning();

        $column = $this->render('pl_column');

        self::assertStringContainsString('<div class="ao-col">', $column);
        self::assertStringContainsString('<i></i>', $column);
        self::assertStringContainsString('2 out · 1 closed today', $column);
        self::assertStringContainsString('Open the module →', $column);

        // The three cards are INCLUDED, so each appears in the column exactly as
        // it appears on its own. If they ever diverge, this fails.
        foreach (['pl_now', 'pl_today', 'pl_gaps'] as $widgetId) {
            self::assertStringContainsString(trim($this->render($widgetId)), $column, $widgetId.' is restated rather than included');
        }
    }
}
