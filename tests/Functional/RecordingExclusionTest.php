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

namespace Uhifadhi\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;

/**
 * A PATROL THAT IS STILL RECORDING IS NOT A RECORD YET — asserted on every
 * surface that draws one.
 *
 * The status enum has promised this since it was written ("must NOT appear on
 * the map, in the library or on the calendar"), but nothing called
 * {@see PatrolStatusEnum::isPresentable()} and every surface filtered on
 * "not discarded" instead, so a half-arrived patrol was drawn as a finished one
 * and its partial kilometres were added to the month.
 *
 * The trap is deliberate and the same one the discard tests use: 40 km still
 * arriving beside 10 km that has landed. A KPI reading 50 km, a log with two
 * rows or a coverage share that buffered an unfinished track is an unmissable
 * failure.
 *
 * A RECORDING PATROL IS NOT A DISCARDED ONE. A discard is shown, subdued, with
 * a pill, because a ranger uploaded it and hiding it would make their work look
 * lost. A recording patrol is not hidden either — it simply has not arrived yet,
 * and the module says nothing about it until the phone's `complete` call says it
 * is whole.
 */
final class RecordingExclusionTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;
    use StoredCoverage;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $arrived;
    private Patrol $recording;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $lead = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($lead);

        $this->arrived = $this->patrol($lead, 'North post', 10.0, PatrolStatusEnum::Complete);
        $this->recording = $this->patrol($lead, 'South post', 40.0, PatrolStatusEnum::Recording);

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /** THE FIGURES: one patrol, ten kilometres. Never two and never fifty. */
    public function testTheMonthsFiguresCountOnlyWhatHasArrived(): void
    {
        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertResponseIsSuccessful();

        self::assertSame('1', $crawler->filter('[data-kpi="month"] .kpi b')->text());
        $distance = $crawler->filter('[data-kpi="distance"] .kpi b')->text();
        self::assertStringContainsString('10', $distance);
        self::assertStringNotContainsString('50', $distance, 'A track still arriving is not distance walked.');
    }

    /** THE LOG: the register lists records, and a recording patrol is not one. */
    public function testTheLogDoesNotListAPatrolThatIsStillArriving(): void
    {
        $crawler = $this->client->request('GET', $this->dashboardUrl());

        // Greater-than rather than exactly one: the log and the feed both draw a
        // row per patrol, so the arrived one legitimately appears twice.
        self::assertGreaterThan(0, $crawler->filter('[data-patrol="'.$this->arrived->getUuid()->toRfc4122().'"]')->count());
        self::assertCount(
            0,
            $crawler->filter('[data-patrol="'.$this->recording->getUuid()->toRfc4122().'"]'),
            'The log drew a patrol whose parts are still arriving.',
        );
    }

    /**
     * THE CALENDAR, through its own fragment endpoint — the second door into the
     * grid, and the one a filter applied only in the dashboard service would
     * miss.
     */
    public function testTheCalendarFragmentMarksOnlyWhatHasArrived(): void
    {
        $month = new \DateTimeImmutable('today')->format('Y-m');
        $crawler = $this->client->request('GET', $this->dashboardUrl().'/calendar?month='.$month);
        self::assertResponseIsSuccessful();

        $html = $crawler->html();
        self::assertStringContainsString($this->arrived->getRef(), $html);
        self::assertStringNotContainsString(
            $this->recording->getRef(),
            $html,
            'The calendar pilled a patrol that has not finished recording.',
        );
    }

    /**
     * THE MAP. A line on a coverage map is read as ground covered; half a track
     * is not ground covered, it is half a track.
     */
    public function testTheCoverageMapDrawsNoUnfinishedTrack(): void
    {
        $crawler = $this->client->request('GET', $this->dashboardUrl());
        $html = $crawler->html();

        self::assertStringContainsString($this->arrived->getUuid()->toRfc4122(), $html);
        self::assertStringNotContainsString(
            $this->recording->getUuid()->toRfc4122(),
            $html,
            'The coverage payload carried an unfinished track.',
        );
    }

    /**
     * THE COVERAGE SHARE, in SQL. The two tracks are deliberately different
     * lines, and BOTH are given a stored corridor, so a union that counted both
     * would return a bigger share than one that counted the arrived one alone.
     */
    public function testTheCoverageShareCountsOnlyArrivedTracks(): void
    {
        [$from, $until] = PatrolDashboardService::monthRange(new \DateTimeImmutable());
        self::assertTrue($this->bufferPatrol($this->arrived));
        self::assertTrue($this->bufferPatrol($this->recording));

        $withRecording = $this->corridors()->fractionWithin($this->area, $from, $until);

        // Now the same query with the unfinished patrol gone from the table
        // altogether: if the share is unchanged, it was never counted.
        $this->em->remove($this->recording);
        $this->em->flush();
        $withoutRecording = $this->corridors()->fractionWithin($this->area, $from, $until);

        self::assertNotNull($withRecording);
        self::assertNotNull($withoutRecording);
        self::assertEqualsWithDelta(
            $withoutRecording,
            $withRecording,
            1.0e-9,
            'The coverage share changed when an unfinished patrol was removed, so it had been buffering it.',
        );
    }

    private function dashboardUrl(): string
    {
        return '/areas/'.$this->area->getUuid().'/modules/patrols';
    }

    private function patrol(User $lead, string $station, float $km, PatrolStatusEnum $status): Patrol
    {
        // Two clearly different lines, far enough apart that buffering both
        // covers noticeably more of the area than buffering one.
        $track = PatrolStatusEnum::Recording === $status
            ? '{"type":"LineString","coordinates":[[12.45,-5.55],[12.48,-5.52]]}'
            : '{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70]]}';

        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, $station))
            ->setLead($lead)
            ->setSource(PatrolSourceEnum::Api)
            ->setStatus($status)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 07:31'))
            ->setDistanceKm($km)
            ->setTrack($track);
        $this->em->persist($patrol);

        return $patrol;
    }
}
