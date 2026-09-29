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

namespace Uhifadhi\Patrol\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Atlas\CalendarPill;
use Uhifadhi\Contracts\Atlas\PillHue;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Service\PatrolCalendar;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THIS MODULE PUTS ON A DAY, and nothing about the grid it lands in.
 *
 * The month, the cell, its height and the "+N more" are the atlas's; the whole
 * of this module's side of the bargain is a day, a mark and what the mark
 * means, which is what these tests hold to.
 */
final class PatrolCalendarTest extends IntegrationTestCase
{
    /** A month safely in the past, so "today" never drifts the fixtures. */
    private const int YEAR = 2019;
    private const int MONTH = 8;

    private AreaOfInterest $area;
    private PatrolCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $calendar = $this->service(PatrolCalendar::class);
        self::assertInstanceOf(PatrolCalendar::class, $calendar);
        $this->calendar = $calendar;

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve');
        $this->em->persist($this->area);
        $this->em->flush();
    }

    private function scope(?string $type = null): string
    {
        return PatrolCalendar::scopeFor((string) $this->area->getUuidString(), $type);
    }

    private function month(): YearMonth
    {
        return new YearMonth(self::YEAR, self::MONTH);
    }

    private function patrol(string $day, string $type = 'walk', ?AreaOfInterest $area = null): Patrol
    {
        $area ??= $this->area;
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, $type))
            ->setStartedAt(new \DateTimeImmutable($day));
        $this->em->persist($patrol);

        return $patrol;
    }

    public function testEveryPresentablePatrolIsOneMarkOnItsOwnDay(): void
    {
        $first = $this->patrol('2019-08-01 06:10');
        $second = $this->patrol('2019-08-14 07:00');
        $third = $this->patrol('2019-08-14 11:00');
        $this->em->flush();

        $month = $this->calendar->month($this->month(), $this->scope());

        self::assertSame(['2019-08-01', '2019-08-14'], array_keys($month->days));

        $busy = $month->day('2019-08-14');
        self::assertNotNull($busy);
        $labels = array_map(static fn (CalendarPill $pill): string => $pill->label, $busy->pills);
        self::assertSame([$second->getRef(), $third->getRef()], $labels);
        self::assertSame($first->getRef(), $month->day('2019-08-01')?->pills[0]->label);
    }

    /**
     * THE DAY'S COUNT IS THE DAY'S, so a cell that folds the surplus into
     * "+N more" still says how full the day really was.
     */
    public function testADayCountsEveryPatrolOnIt(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->patrol(\sprintf('2019-08-15 %02d:00', 6 + $i));
        }
        $this->em->flush();

        $day = $this->calendar->month($this->month(), $this->scope())->day('2019-08-15');

        self::assertNotNull($day);
        // EVERYTHING, HANDED OVER. What fits is the renderer's question, and a
        // feed that trimmed to fit would be deciding a layout it cannot see.
        self::assertCount(5, $day->pills);
        self::assertSame(5, $day->total());
    }

    /**
     * A PATROL STILL ARRIVING IS NOT A RECORD. Its distance will grow and its
     * track stops in the middle of nowhere; drawing it publishes a
     * half-uploaded patrol as a finished one.
     */
    public function testAPatrolStillRecordingIsNotOnTheMonth(): void
    {
        $this->patrol('2019-08-09 06:00')->setStatus(PatrolStatusEnum::Recording);
        $this->em->flush();

        self::assertTrue($this->calendar->month($this->month(), $this->scope())->isEmpty());
    }

    /**
     * A DISCARD KEEPS ITS DAY and reads as withdrawn: quiet, and hollow because
     * the recording is finished. The grid is a record of what happened, and a
     * ranger looking for the patrol they discarded on the 12th finds it there.
     */
    public function testADiscardedPatrolIsQuietAndHollow(): void
    {
        $this->patrol('2019-08-12 06:00')->discard('a test run');
        $this->em->flush();

        $pill = $this->calendar->month($this->month(), $this->scope())->day('2019-08-12')?->pills[0];

        self::assertNotNull($pill);
        self::assertSame(PillHue::Quiet, $pill->hue);
        self::assertTrue($pill->closed);
    }

    /** A discard somebody stopped the purge clock on is one to look at. */
    public function testAHeldDiscardAsksForAttention(): void
    {
        $this->patrol('2019-08-13 06:00')->discard('a test run')->hold(null, new \DateTimeImmutable('2019-08-20'));
        $this->em->flush();

        $pill = $this->calendar->month($this->month(), $this->scope())->day('2019-08-13')?->pills[0];

        self::assertNotNull($pill);
        self::assertSame(PillHue::Attention, $pill->hue);
    }

    /** An ordinary finished patrol is the subject of this month, filled. */
    public function testACompletePatrolIsTheMonthsSubject(): void
    {
        $this->patrol('2019-08-05 06:00');
        $this->em->flush();

        $pill = $this->calendar->month($this->month(), $this->scope())->day('2019-08-05')?->pills[0];

        self::assertNotNull($pill);
        self::assertSame(PillHue::Subject, $pill->hue);
        self::assertFalse($pill->closed);
    }

    /**
     * THE DIMMED DAYS EITHER SIDE ARE REAL DATES and the grid draws them, so a
     * patrol that falls in one belongs in the cell it falls in.
     */
    public function testTheDaysEitherSideOfTheMonthAreAnswered(): void
    {
        $leading = $this->patrol('2019-07-30 06:00');
        $trailing = $this->patrol('2019-09-03 06:00');
        // A month the August grid cannot reach at all.
        $this->patrol('2019-10-05 06:00');
        $this->em->flush();

        $month = $this->calendar->month($this->month(), $this->scope());

        self::assertSame($leading->getRef(), $month->day('2019-07-30')?->pills[0]->label);
        self::assertSame($trailing->getRef(), $month->day('2019-09-03')?->pills[0]->label);
        self::assertNull($month->day('2019-10-05'));
    }

    public function testAnotherAreasPatrolsAreNotOnThisMonth(): void
    {
        $other = new AreaOfInterest()->setSource('test fixture')->setName('other reserve');
        $this->em->persist($other);
        $this->patrol('2019-08-14 06:00', area: $other);
        $this->em->flush();

        self::assertTrue($this->calendar->month($this->month(), $this->scope())->isEmpty());
    }

    /** The surface's own control narrows the month to one kind of patrol. */
    public function testTheScopeMayNarrowToOnePatrolType(): void
    {
        $walk = $this->patrol('2019-08-07 06:00', 'walk');
        $this->patrol('2019-08-08 06:00', 'boat');
        $this->em->flush();

        $month = $this->calendar->month($this->month(), $this->scope('walk'));

        self::assertSame(['2019-08-07'], array_keys($month->days));
        self::assertSame($walk->getRef(), $month->day('2019-08-07')?->pills[0]->label);
    }

    /**
     * A SCOPE THIS FEED CANNOT READ IS AN EMPTY MONTH, never an exception: the
     * atlas draws an empty August, and a missing grid is a broken page.
     */
    public function testAnUnreadableScopeIsAnEmptyMonth(): void
    {
        $this->patrol('2019-08-14 06:00');
        $this->em->flush();

        foreach ([null, '', 'not-a-uuid', new \Symfony\Component\Uid\UuidV4()->toRfc4122()] as $scope) {
            self::assertTrue(
                $this->calendar->month($this->month(), $scope)->isEmpty(),
                \sprintf('scope "%s" answers an empty month', (string) $scope),
            );
        }
    }

    /** A mark is a door: it opens the patrol it stands for. */
    public function testAMarkOpensThePatrolItStandsFor(): void
    {
        $patrol = $this->patrol('2019-08-06 06:00');
        $this->em->flush();

        $pill = $this->calendar->month($this->month(), $this->scope())->day('2019-08-06')?->pills[0];

        self::assertNotNull($pill);
        self::assertSame(
            '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$patrol->getUuid()->toRfc4122(),
            $pill->url,
        );
    }

    /**
     * AND THE DAY IS A DOOR TOO — where "+N more" goes: the register, on the
     * month the day is in and narrowed the way the calendar is.
     */
    public function testADayOpensTheRegisterForThatMonth(): void
    {
        $this->patrol('2019-08-06 06:00', 'walk');
        $this->em->flush();

        $url = $this->calendar->month($this->month(), $this->scope('walk'))->day('2019-08-06')?->url;

        self::assertNotNull($url);
        self::assertStringContainsString('/modules/patrols/patrols', $url);
        self::assertStringContainsString('type=walk', $url);
        self::assertStringContainsString('month=2019-08', $url);
    }

    /**
     * THE MARK SAYS WHAT THE REMOVED HOVER CARD SAID. The fragment on the mark
     * is the reference; the type, the post and the hour are what a reader needs
     * when the fragment is not enough.
     */
    public function testTheMarkNamesItsTypeItsPostAndItsHour(): void
    {
        $patrol = $this->patrol('2019-08-06 06:10');
        $patrol->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'));
        $this->em->flush();

        $title = $this->calendar->month($this->month(), $this->scope())->day('2019-08-06')?->pills[0]->title;

        self::assertNotNull($title);
        self::assertStringContainsString('Walking round', $title);
        self::assertStringContainsString('North post', $title);
        self::assertStringContainsString('06:10', $title);
    }

    /** A month nothing happened in is a fact about that month. */
    public function testAQuietMonthIsEmptyRatherThanAbsent(): void
    {
        $empty = $this->calendar->month(new YearMonth(2031, 11), $this->scope());

        self::assertTrue($empty->isEmpty());
        self::assertTrue($empty->month->equals(new YearMonth(2031, 11)));
    }
}
