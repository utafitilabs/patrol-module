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

namespace Uhifadhi\Patrol\Tests\Integration\Shell;

use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Area\StationSection;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Kpi\StationRef;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Shell\PatrolStationSections;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * *PATROLS FROM HERE* — what the patrol module puts on the post a person is
 * posted at, on `/me/station` (#19, design station.html SN·04), asked of the
 * real contributor against the real database.
 *
 * THE MOMENT IS FIXED on the clock the contributor reads: Wednesday 30
 * September 2026, 10:00, so "this week" is Monday the 28th onwards.
 */
final class PatrolStationSectionsTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;

    private AreaOfInterest $area;
    private Station $gate;
    private Station $camp;
    private User $naira;
    private User $otto;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime(new \DateTimeImmutable('2026-09-30 10:00:00'));

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Example square')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($this->area);
        $gate = Vocabulary::station($this->em, $this->area, 'Gate One');
        $camp = Vocabulary::station($this->em, $this->area, 'Camp Two');
        \assert($gate instanceof Station && $camp instanceof Station);
        $this->gate = $gate;
        $this->camp = $camp;
        $this->naira = $this->aPerson('Naira', 'Example');
        $this->otto = $this->aPerson('Otto', 'Other');
        $this->em->flush();
    }

    public function testItSpeaksForThePatrolsModule(): void
    {
        self::assertInstanceOf(StationSectionsInterface::class, $this->sections());
        self::assertSame('patrols', $this->sections()->moduleSlug());
    }

    public function testOnlyThePersonsOwnSurfaceCarriesIt(): void
    {
        $this->aPatrol($this->naira, $this->gate, '2026-09-29 07:00:00', 8.9);
        $this->em->flush();

        self::assertTrue($this->sections()->sectionsFor($this->request(StationSurface::Record))->isEmpty(), 'The post\'s own record is unchanged.');
        self::assertTrue($this->sections()->sectionsFor($this->request(StationSurface::Configure))->isEmpty(), 'And so is its configure card.');
        self::assertCount(1, $this->sections()->sectionsFor($this->request(StationSurface::Mine))->forStation((string) $this->gate->getUuidString()));
    }

    public function testTheSectionCountsTheWeeksPatrolsFromThePostAndTheirDistance(): void
    {
        $this->aWeekAtTheGate();

        $section = $this->section();

        self::assertSame('patrols', $section->id);
        self::assertSame('Patrols from here', $section->label);
        self::assertSame('this week · 4 · 23 km', $section->summary, 'Four went out; the distance is the finished ones\'.');
        self::assertSame([], $section->actions, 'A person posted here may read nothing else of the area, so the band offers no door.');
    }

    public function testTheRowsNameEachPatrolWhoLedItAndHowFarItWent(): void
    {
        [$out, $tuesday, $monday, $word] = $this->aWeekAtTheGate();

        $rows = $this->rendered()->filter('.rln');

        self::assertCount(4, $rows);
        self::assertSame(
            [$out->getRef(), $word->getRef(), $tuesday->getRef(), $monday->getRef()],
            $rows->each(static fn (Crawler $row): string => $row->filter('b')->text()),
        );
        self::assertStringContainsString('N. Example', $rows->eq(0)->text());
        self::assertStringContainsString('out now', $rows->eq(0)->text());
        self::assertStringContainsString('O. Other', $rows->eq(2)->text());
        self::assertStringContainsString('8.9 km', $rows->eq(2)->text());
        self::assertSame('2026-09-29T07:00:00+00:00', $rows->eq(2)->filter('time')->attr('datetime'));
    }

    public function testAQuietWeekSaysSoInTheModulesOwnWords(): void
    {
        $this->aPatrol($this->naira, $this->gate, '2026-09-20 07:00:00', 5.0);
        $this->em->flush();

        $section = $this->section();

        self::assertSame('this week · 0 · 0 km', $section->summary);
        self::assertStringContainsString('Nothing went out from here this week.', $this->rendered()->text());
    }

    public function testAskedAboutNoPostItHasNothingToSay(): void
    {
        self::assertTrue($this->sections()->sectionsFor(new StationSectionRequest([], StationSurface::Mine))->isEmpty());
        self::assertTrue($this->sections()->sectionsFor(new StationSectionRequest(
            [new StationRef('00000000-0000-4000-8000-000000000000', (string) $this->area->getUuidString(), 'Nowhere')],
            StationSurface::Mine,
        ))->isEmpty());
    }

    // ---- fixture ---------------------------------------------------------

    /**
     * Monday and Tuesday out of the gate and finished, one still out, one that
     * named the gate only as a word; and three that do not belong: last week's,
     * the camp's, and a discard.
     *
     * @return array{Patrol, Patrol, Patrol, Patrol} out now, Tuesday, Monday, the word-only one
     */
    private function aWeekAtTheGate(): array
    {
        $monday = $this->aPatrol($this->naira, $this->gate, '2026-09-28 07:00:00', 14.1);
        $tuesday = $this->aPatrol($this->otto, $this->gate, '2026-09-29 07:00:00', 8.9);
        $out = $this->aPatrol($this->naira, $this->gate, '2026-09-30 07:10:00', null, PatrolStatusEnum::Recording);
        $word = $this->aPatrol($this->otto, null, '2026-09-29 15:00:00', null);
        $word->setStationWord('  gate ONE ');
        $this->aPatrol($this->naira, $this->gate, '2026-09-25 07:00:00', 12.4);
        $this->aPatrol($this->naira, $this->camp, '2026-09-29 07:00:00', 30.0);
        $this->aPatrol($this->naira, $this->gate, '2026-09-29 09:00:00', 20.0, PatrolStatusEnum::Discarded);
        $this->em->flush();

        return [$out, $tuesday, $monday, $word];
    }

    private function aPerson(string $first, string $last): User
    {
        $user = new User()->setPassword('x')->setEmail(strtolower($first).'@example.test')->setFirstName($first)->setLastName($last);
        $this->em->persist($user);

        return $user;
    }

    private function aPatrol(User $lead, ?Station $from, string $startedAt, ?float $km, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $started = new \DateTimeImmutable($startedAt);
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setLead($lead)
            ->setStartedAt($started)
            ->setEndedAt(PatrolStatusEnum::Recording === $status ? null : $started->modify('+3 hours'))
            ->setDistanceKm($km)
            ->setStatus($status)
            ->setStationRecord($from);
        $this->em->persist($patrol);

        return $patrol;
    }

    // ---- reading ---------------------------------------------------------

    private function sections(): PatrolStationSections
    {
        $sections = $this->service(PatrolStationSections::class);
        \assert($sections instanceof PatrolStationSections);

        return $sections;
    }

    private function request(StationSurface $surface): StationSectionRequest
    {
        return new StationSectionRequest(
            [new StationRef((string) $this->gate->getUuidString(), (string) $this->area->getUuidString(), 'Gate One')],
            $surface,
        );
    }

    private function section(): StationSection
    {
        $sections = $this->sections()->sectionsFor($this->request(StationSurface::Mine))->forStation((string) $this->gate->getUuidString());
        self::assertCount(1, $sections);

        return $sections[0];
    }

    /** The rows the surface would include inside the band — rendered with what the section hands it, and nothing else. */
    private function rendered(): Crawler
    {
        $section = $this->section();
        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        return new Crawler('<div id="band">'.$twig->render($section->template, $section->variables).'</div>');
    }
}
