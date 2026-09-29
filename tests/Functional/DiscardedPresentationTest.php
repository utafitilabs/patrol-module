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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolEvent;
use Uhifadhi\Patrol\Enum\PatrolEventKindEnum;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * How a discarded patrol READS on the web — the settled discard design's web
 * rulings, made testable:
 *
 * * the row stays and is fully viewable, subdued, with a DISCARDED pill;
 * * the reason sits beside the status and the purge date is on the row, not
 *   buried;
 * * it is excluded from every figure on the same page;
 * * the detail screen states the discard, the reason and the window, and offers
 *   the hold to whoever may raise one — and to nobody else.
 */
final class DiscardedPresentationTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $discarded;
    private Patrol $kept;
    private User $recorder;
    /** Somebody who may act on records other people made — `patrols.manage`, which is what a hold is. */
    private User $keeper;
    private User $bystander;

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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->bystander = new User()->setPassword('x')->setEmail('bystander@example.test')
            ->setFirstName('Ben')->setLastName('Bystander');
        $this->keeper = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mary')->setLastName('Mollel');
        $this->em->persist($this->recorder);
        $this->em->persist($this->keeper);
        $this->em->persist($this->bystander);

        $this->kept = $this->patrol('North post', 10.0);
        $this->discarded = $this->patrol('South post', 40.0)->discard('Started by mistake');
        $this->em->persist(new PatrolEvent(
            $this->discarded,
            Uuid::v4(),
            PatrolEventKindEnum::Discarded,
            new \DateTimeImmutable('today 07:31'),
        )->setPayload(['reason' => 'Started by mistake'])->setActor($this->recorder));

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

    /** The register keeps it, subdued, pilled, and annotated with its fate. */
    public function testTheRegisterRowStaysAndSaysWhatBecameOfIt(): void
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuid().'/modules/patrols');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('[data-patrol="'.$this->discarded->getUuid()->toRfc4122().'"]')->first();
        self::assertCount(1, $row, 'A discarded patrol is never dropped from the register.');
        self::assertStringContainsString('patrol-abandoned', (string) $row->attr('class'));

        $text = $row->text();
        self::assertStringContainsString('discarded', $text);
        self::assertStringContainsString('Started by mistake', $text, 'The reason sits beside the status.');
        self::assertStringContainsString('removed', $text, 'The purge date is on the row, not buried.');
        self::assertStringContainsString('not counted', $text);

        // The patrol that counts is not marked.
        $keptRow = $crawler->filter('[data-patrol="'.$this->kept->getUuid()->toRfc4122().'"]')->first();
        self::assertStringNotContainsString('patrol-abandoned', (string) $keptRow->attr('class'));
    }

    /**
     * Same page, the figures. 40 discarded kilometres beside 10 real ones is a
     * deliberate trap: a KPI reading 50 km would be an unmissable failure.
     */
    public function testTheSamePageCountsItInNothing(): void
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuid().'/modules/patrols');
        self::assertResponseIsSuccessful();

        // PL·01 counts one patrol, not two.
        self::assertSame('1', $crawler->filter('[data-kpi="month"] .kpi b')->text());
        // PL·02 sums 10 km, not 50.
        self::assertStringContainsString('10', $crawler->filter('[data-kpi="distance"] .kpi b')->text());
        self::assertStringNotContainsString('50', $crawler->filter('[data-kpi="distance"] .kpi b')->text());
    }

    /** The detail page: the discard, the reason, and the window, all stated. */
    public function testTheDetailPageStatesTheDiscardTheReasonAndTheWindow(): void
    {
        $crawler = $this->client->request('GET', $this->detailUrl());
        self::assertResponseIsSuccessful();

        $notice = $crawler->filter('.patrol-discard');
        self::assertCount(1, $notice);
        self::assertStringContainsString('discarded', $notice->text());
        self::assertStringContainsString('Started by mistake', $notice->text());
        self::assertStringContainsString('90 days', $notice->text(), 'The window is stated, not implied.');

        // The history card renders the event, append-only, with its actor.
        $history = $crawler->filter('[data-patrol-history]')->text();
        self::assertStringContainsString('Discarded', $history);
        self::assertStringContainsString('R. Recorder', $history);
    }

    /** A patrol that was never discarded carries none of that chrome. */
    public function testAnOrdinaryPatrolsDetailPageIsUnchanged(): void
    {
        $crawler = $this->client->request(
            'GET',
            '/areas/'.$this->area->getUuid().'/modules/patrols/'.$this->kept->getUuid(),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.patrol-discard'));
    }

    /**
     * The hold is offered to whoever may MANAGE a patrol — act on a record
     * somebody else made — and to nobody else. The ranger who logs patrols is
     * a refusal here as squarely as a reader is: pulling the brake on a
     * discard is not the same power as recording a day.
     */
    public function testTheHoldActionIsOfferedOnlyToSomebodyWhoMayManage(): void
    {
        foreach ([$this->bystander, $this->recorder] as $withoutIt) {
            $this->client->loginUser($withoutIt);
            $denied = $this->client->request('GET', $this->detailUrl());
            self::assertResponseIsSuccessful();
            self::assertCount(0, $denied->filter('.patrol-discard-act'), 'Absent, not disabled — a greyed control advertises a power the reader has not got.');
        }

        $this->client->loginUser($this->keeper);
        $allowed = $this->client->request('GET', $this->detailUrl());
        self::assertCount(1, $allowed->filter('.patrol-discard-act'));
        self::assertStringContainsString('Hold for review', $allowed->filter('.patrol-discard-act')->text());
    }

    /** Raising and releasing the hold, through the form the page renders. */
    public function testHoldingAndReleasingThroughThePage(): void
    {
        $this->client->loginUser($this->keeper);

        $crawler = $this->client->request('GET', $this->detailUrl());
        $this->client->submit($crawler->filter('form.patrol-discard-act')->form());
        self::assertResponseRedirects($this->detailUrl());

        $held = $this->reload();
        self::assertTrue($held->isHeld());
        self::assertSame($this->keeper->getId(), $held->getHeldBy()?->getId());
        // Holding is not editing: the phone must keep being able to deliver the
        // parts somebody raised the hold in order to look at.
        self::assertTrue($held->acceptsFieldUploads());

        $crawler = $this->client->request('GET', $this->detailUrl());
        self::assertStringContainsString('held for review', $crawler->filter('.patrol-discard')->text());
        self::assertStringContainsString('Release hold', $crawler->filter('.patrol-discard-act')->text());
        self::assertStringNotContainsString('removed', $crawler->filter('.patrol-discard-fate')->text(), 'A stopped clock has no date to promise.');

        $this->client->submit($crawler->filter('form.patrol-discard-act')->form());
        self::assertResponseRedirects($this->detailUrl());
        self::assertFalse($this->reload()->isHeld());
    }

    public function testTheHoldRouteRefusesSomebodyWhoMayNotManage(): void
    {
        $this->client->loginUser($this->keeper);
        $crawler = $this->client->request('GET', $this->detailUrl());
        $form = $crawler->filter('form.patrol-discard-act')->form();

        $this->client->loginUser($this->bystander);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload()->isHeld());
    }

    public function testTheHoldRouteRefusesAMissingCsrfToken(): void
    {
        $this->client->loginUser($this->keeper);
        $this->client->request('POST', $this->detailUrl().'/hold', ['hold' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload()->isHeld());
    }

    /** Only a discarded patrol has a clock to stop. */
    public function testAPatrolThatWasNeverDiscardedCannotBeHeld(): void
    {
        $this->client->loginUser($this->keeper);
        $crawler = $this->client->request('GET', $this->detailUrl());
        $token = $crawler->filter('form.patrol-discard-act input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/areas/'.$this->area->getUuid().'/modules/patrols/'.$this->kept->getUuid().'/hold',
            ['hold' => '1', '_token' => $token],
        );

        // The token is minted per patrol, so this is refused before the status
        // is even reached — which is the stronger of the two guards.
        self::assertResponseStatusCodeSame(403);
    }

    private function detailUrl(): string
    {
        return '/areas/'.$this->area->getUuid().'/modules/patrols/'.$this->discarded->getUuid();
    }

    private function reload(): Patrol
    {
        $this->em->clear();
        $patrol = $this->em->getRepository(Patrol::class)->findOneBy(['uuid' => $this->discarded->getUuid()]);
        self::assertInstanceOf(Patrol::class, $patrol);

        return $patrol;
    }

    private function patrol(string $station, float $km): Patrol
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, $station))
            ->setLead($this->recorder)
            ->setSource(PatrolSourceEnum::Api)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 07:31'))
            ->setDistanceKm($km)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70]]}');
        $this->em->persist($patrol);

        return $patrol;
    }
}
