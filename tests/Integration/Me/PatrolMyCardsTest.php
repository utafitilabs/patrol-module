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

namespace Uhifadhi\Patrol\Tests\Integration\Me;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\MyDashboard;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Me\PatrolMyCards;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THE PATROL MODULE SHOWS A PERSON ABOUT THEMSELVES on their own
 * dashboard (#19, option A ruled 28 Sep 2026), asked of the real provider
 * against the real database.
 *
 * THE MOMENT IS FIXED: Wednesday 30 September 2026, 10:00, so "this week" is
 * Monday the 28th onwards and "this month" is September. The person is the
 * patrol's LEAD — the account the handset was signed in on when it opened the
 * patrol — and the fixture gives them:
 *
 *  - a complete patrol on Monday (6.8 km, two observations, one with photos),
 *  - a complete patrol on Tuesday (11.0 km, one observation),
 *  - a complete patrol on the 10th (12.4 km, the month's longest),
 *  - a patrol still out since this morning (no distance yet, one observation),
 *  - a discarded patrol on the 15th (20 km that never counted),
 *  - a complete patrol in August (9.0 km),
 *
 * and somebody else a 50 km patrol on Tuesday that is none of theirs.
 */
final class PatrolMyCardsTest extends IntegrationTestCase
{
    private const string NOW = '2026-09-30 10:00:00';

    private AreaOfInterest $area;
    private User $me;
    private Patrol $monday;
    private Patrol $tuesday;
    private Patrol $tenth;
    private Patrol $outNow;

    /**
     * THE TAG REACHED THE PAGE: the area's own dashboard composer — the one
     * `/` draws a person's page with — carries this module's cards. A provider
     * nobody collected would be a perfect class the page never mentions.
     */
    public function testTheDashboardTheAreaComposesCarriesTheseCards(): void
    {
        $this->aMonthOfPatrols();

        $dashboard = static::getContainer()->get('area.my_dashboard');
        \assert($dashboard instanceof MyDashboard);
        $slots = $dashboard->for((string) $this->me->getUuidString(), new \DateTimeImmutable(self::NOW));

        self::assertInstanceOf(MyCardProviderInterface::class, $this->cards());
        self::assertStringContainsString('data-me="distance"', implode('', $slots[MyCard::FIGURE]));
        self::assertStringContainsString('data-me="week"', implode('', $slots[MyCard::BESIDE_PLATE]));
        self::assertStringContainsString('data-me="my-observations"', implode('', $slots[MyCard::ROW]));
    }

    public function testTheSixCardsStandInTheSlotsTheLayoutGivesThem(): void
    {
        $this->aMonthOfPatrols();

        $placed = array_map(
            static fn (MyCard $card): string => $card->slot.' '.$card->order,
            $this->cardsNow(),
        );

        self::assertSame([
            MyCard::FIGURE.' 20',
            MyCard::FIGURE.' 30',
            MyCard::FIGURE.' 40',
            MyCard::BESIDE_PLATE.' 10',
            MyCard::BESIDE_PLATE.' 20',
            MyCard::ROW.' 10',
        ], $placed);
    }

    public function testTheDistanceFigureCountsTheMonthsFinishedPatrolsAndSaysTheWeek(): void
    {
        $this->aMonthOfPatrols();

        $figure = $this->card('distance');

        self::assertSame('Distance covered', $figure->filter('.tab')->text());
        self::assertSame('30.2 km', $figure->filter('b.disp')->text(), 'Monday, Tuesday and the 10th; not the one still out, not the discard, not August, not somebody else\'s.');
        self::assertSame('this month · 17.8 km this week', $figure->filter('.sub')->text());
    }

    public function testThePatrolsFigureCountsEveryPatrolOfTheMonthAndWhoIsOutNow(): void
    {
        $this->aMonthOfPatrols();

        $figure = $this->card('patrols');

        self::assertSame('My patrols', $figure->filter('.tab')->text());
        self::assertSame('4', $figure->filter('b.disp')->text(), 'A discarded patrol is withdrawn; the one out now is mine this month.');
        self::assertSame('this month · 1 out now', $figure->filter('.sub')->text());
    }

    public function testTheObservationsFigureCountsTheMonthsObservationsOnMyPatrols(): void
    {
        $this->aMonthOfPatrols();

        $figure = $this->card('observations');

        self::assertSame('Observations', $figure->filter('.tab')->text());
        self::assertSame('4', $figure->filter('b.disp')->text());
        self::assertSame('this month · 1 with photos', $figure->filter('.sub')->text());
    }

    public function testTheDistanceCardDrawsABarForEachDayOfTheWeek(): void
    {
        $this->aMonthOfPatrols();

        $card = $this->card('week');
        $bars = $card->filter('.pl-me-bars > div');

        self::assertCount(7, $bars);
        self::assertSame(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], $bars->each(static fn (Crawler $bar): string => $bar->filter('span')->text()));
        self::assertSame(['6.8', '11.0', '', '', '', '', ''], $bars->each(static fn (Crawler $bar): string => $bar->filter('b')->text()));
        self::assertCount(5, $card->filter('.pl-me-bars i.pl-me-zero'), 'A day with nothing walked is a flat bar.');
        self::assertStringContainsString('height:82.5px', (string) $bars->eq(1)->filter('i')->attr('style'), 'The longest day stands full height.');
        self::assertStringContainsString('height:51px', (string) $bars->eq(0)->filter('i')->attr('style'));
        self::assertStringContainsString('this week · 17.8 km', $card->filter('.tab .src')->text());
    }

    public function testTheDistanceCardComparesTheMonthWithTheLastAndNamesTheLongest(): void
    {
        $this->aMonthOfPatrols();

        $rows = $this->rows($this->card('week'));

        self::assertSame('30.2 km · 3 patrols', $rows['This month']);
        self::assertSame('9.0 km · 1 patrol', $rows['Last month']);
        self::assertSame($this->tenth->getRef().' · 12.4 km', $rows['Longest this month']);
        self::assertSame('counted from my recorded tracks', $this->card('week')->filter('.sxfoot')->text());
    }

    public function testThePatrolsCardListsTheLatestFourNewestFirstWithTheirState(): void
    {
        $this->aMonthOfPatrols();

        $card = $this->card('my-patrols');
        $rows = $card->filter('.rln');

        self::assertStringContainsString('4 this month', $card->filter('.tab .src')->text());
        self::assertCount(4, $rows);
        self::assertSame(
            [$this->outNow->getRef(), $this->tuesday->getRef(), $this->monday->getRef(), $this->tenth->getRef()],
            $rows->each(static fn (Crawler $row): string => $row->filter('b')->text()),
        );
        self::assertSame('out now', $rows->eq(0)->filter('.chip.ok')->text());
        self::assertSame('sent', $rows->eq(1)->filter('.chip.idle')->text());
        self::assertStringContainsString('Gate One · 11.0 km · 1 obs', $rows->eq(1)->text());
        self::assertStringContainsString('2 obs', $rows->eq(2)->text());
        self::assertSame('2026-09-29T07:00:00+00:00', $rows->eq(1)->filter('time')->attr('datetime'), 'An instant reaches the reader in their own zone.');
        self::assertCount(1, $rows->eq(0)->filter('.chip'), 'One state a row.');
    }

    public function testThePatrolsCardOpensTheListForSomebodyWhoMayReadIt(): void
    {
        $this->aMonthOfPatrols();
        $this->patrolsRunIn($this->area);
        $this->signedInAs($this->me);

        $door = $this->card('my-patrols')->filter('.sxfoot a.tgl');

        self::assertCount(1, $door);
        self::assertSame('All my patrols →', $door->text());
        self::assertStringContainsString('/areas/'.$this->area->getUuidString().'/modules/patrols/patrols', (string) $door->attr('href'));
        self::assertStringContainsString('q=Naira', (string) $door->attr('href'));
        self::assertStringContainsString('recorded on my phone', $this->card('my-patrols')->filter('.sxfoot')->text());
    }

    public function testThePatrolsCardDrawsNoDoorForSomebodyWhoMayNotRead(): void
    {
        $this->aMonthOfPatrols();
        $this->patrolsRunIn($this->area);

        self::assertCount(0, $this->card('my-patrols')->filter('.sxfoot a'), 'Nobody is signed in, so nobody may read.');
    }

    public function testThePatrolsCardDrawsNoDoorWhereTheAreaParkedTheModule(): void
    {
        $this->aMonthOfPatrols();
        $this->signedInAs($this->me);

        self::assertCount(0, $this->card('my-patrols')->filter('.sxfoot a'), 'A door to a parked module answers 404.');
    }

    public function testTheObservationsCardListsTheLatestFourWithTheirKind(): void
    {
        $this->aMonthOfPatrols();

        $card = $this->card('my-observations');
        $rows = $card->filter('.rln');

        self::assertStringContainsString('latest four', $card->filter('.tab .src')->text());
        self::assertCount(4, $rows);
        self::assertSame('snare', $rows->eq(0)->filter('.chip')->text(), 'The area\'s own word for the kind.');
        self::assertStringContainsString('Snare lifted at the fence', $rows->eq(0)->text());
        self::assertStringContainsString($this->outNow->getRef(), $rows->eq(0)->text());
        self::assertSame('maintenance need', $rows->eq(1)->filter('.chip')->text(), 'The installation\'s word where the area keeps none.');
    }

    public function testSomebodyWhoHasNeverPatrolledReadsHonestEmpties(): void
    {
        $this->aMonthOfPatrols();
        $stranger = $this->aPerson('Nobody', 'Walked', 'stranger@example.test');
        $this->em->flush();

        $cards = $this->cardsFor($stranger);

        self::assertSame('0.0 km', $cards['distance']->filter('b.disp')->text());
        self::assertSame('0', $cards['patrols']->filter('b.disp')->text());
        self::assertSame('0', $cards['observations']->filter('b.disp')->text());
        self::assertStringContainsString('No patrols recorded yet', $cards['my-patrols']->text());
        self::assertStringContainsString('No observations yet', $cards['my-observations']->text());
        self::assertCount(7, $cards['week']->filter('.pl-me-bars i.pl-me-zero'));
        self::assertSame('none yet', $this->rows($cards['week'])['Longest this month']);
    }

    public function testAnUnknownPersonHasNoCards(): void
    {
        self::assertSame([], $this->cards()->cardsFor('00000000-0000-4000-8000-000000000000', new \DateTimeImmutable(self::NOW)));
    }

    // ---- fixture ---------------------------------------------------------

    private function aMonthOfPatrols(): void
    {
        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('Example square')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($this->area);
        $this->me = $this->aPerson('Naira', 'Example', 'naira@example.test');
        $other = $this->aPerson('Otto', 'Other', 'otto@example.test');
        Vocabulary::kind($this->em, $this->area, 'Snare');

        $this->monday = $this->aPatrol($this->me, '2026-09-28 07:00:00', 6.8);
        $this->anObservation($this->monday, 'maintenance', 'Gate hinge broken', '2026-09-28 08:00:00', 2);
        $this->anObservation($this->monday, 'maintenance', 'Signpost down', '2026-09-28 09:00:00');
        $this->tuesday = $this->aPatrol($this->me, '2026-09-29 07:00:00', 11.0);
        $this->anObservation($this->tuesday, 'maintenance', 'Culvert blocked', '2026-09-29 08:00:00');
        $this->tenth = $this->aPatrol($this->me, '2026-09-10 07:00:00', 12.4);
        $this->outNow = $this->aPatrol($this->me, '2026-09-30 07:10:00', null, PatrolStatusEnum::Recording);
        $this->anObservation($this->outNow, 'snare', 'Snare lifted at the fence', '2026-09-30 08:15:00');
        $discarded = $this->aPatrol($this->me, '2026-09-15 07:00:00', 20.0, PatrolStatusEnum::Discarded);
        $this->anObservation($discarded, 'maintenance', 'A test run', '2026-09-15 08:00:00');
        $this->aPatrol($this->me, '2026-08-20 07:00:00', 9.0);
        $this->aPatrol($other, '2026-09-29 07:00:00', 50.0);

        $this->em->flush();
    }

    private function aPerson(string $first, string $last, string $email): User
    {
        $user = new User()->setPassword('x')->setEmail($email)->setFirstName($first)->setLastName($last);
        $this->em->persist($user);

        return $user;
    }

    private function aPatrol(User $lead, string $startedAt, ?float $km, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $started = new \DateTimeImmutable($startedAt);
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setLead($lead)
            ->setStartedAt($started)
            ->setEndedAt(PatrolStatusEnum::Recording === $status ? null : $started->modify('+3 hours'))
            ->setDistanceKm($km)
            ->setStatus($status)
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'Gate One'));
        $this->em->persist($patrol);

        return $patrol;
    }

    private function anObservation(Patrol $patrol, string $category, string $note, string $at, int $photos = 0): void
    {
        $observation = new Observation($patrol, $category)
            ->setNote($note)
            ->setLoggedAt(new \DateTimeImmutable($at))
            ->setPhotoCount($photos);
        $this->em->persist($observation);
    }

    private function patrolsRunIn(AreaOfInterest $area): void
    {
        $sync = static::getContainer()->get('test_public.'.RegistrySyncService::class);
        \assert($sync instanceof RegistrySyncService);
        $sync->sync();

        $modules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        \assert($modules instanceof AreaModuleService);
        $modules->install($area, PatrolModuleProvider::SLUG);
    }

    private function signedInAs(User $user): void
    {
        $tokens = static::getContainer()->get('security.token_storage');
        \assert($tokens instanceof TokenStorageInterface);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    // ---- reading ---------------------------------------------------------

    private function cards(): PatrolMyCards
    {
        $cards = $this->service(PatrolMyCards::class);
        \assert($cards instanceof PatrolMyCards);

        return $cards;
    }

    /** @return list<MyCard> */
    private function cardsNow(): array
    {
        return $this->cards()->cardsFor((string) $this->me->getUuidString(), new \DateTimeImmutable(self::NOW));
    }

    /** @return array<string, Crawler> each card by its data-me */
    private function cardsFor(User $person): array
    {
        $byName = [];
        foreach ($this->cards()->cardsFor((string) $person->getUuidString(), new \DateTimeImmutable(self::NOW)) as $card) {
            $crawler = new Crawler('<div id="root">'.$card->html.'</div>')->filter('#root > [data-me]');
            self::assertCount(1, $crawler, 'Each card is one element, named by its data-me.');
            $byName[(string) $crawler->attr('data-me')] = $crawler;
        }

        return $byName;
    }

    private function card(string $name): Crawler
    {
        $cards = $this->cardsFor($this->me);
        self::assertArrayHasKey($name, $cards);

        return $cards[$name];
    }

    /** @return array<string, string> each row's label to its value */
    private function rows(Crawler $card): array
    {
        $rows = [];
        foreach ($card->filter('.rln') as $row) {
            $cells = new Crawler($row)->children();
            $rows[$cells->eq(0)->text()] = $cells->eq(1)->text();
        }

        return $rows;
    }
}
