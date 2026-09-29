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

namespace Uhifadhi\Patrol\Tests\Integration\Command;

use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolEvent;
use Uhifadhi\Patrol\Entity\TrackBatch;
use Uhifadhi\Patrol\Entity\TrackPoint;
use Uhifadhi\Patrol\Enum\PatrolEventKindEnum;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Service\EvidenceKey;

/**
 * patrol:purge-discarded — the retention sweep.
 *
 * The promise the field app makes to a ranger, made true: a discarded patrol is
 * removed after the window unless it is held for review. So every test here is
 * about one of the four things that decides a patrol's fate — is it discarded,
 * is it old enough, is it held, and did its photographs actually go.
 */
final class PurgeDiscardedCommandTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);
        $this->em->flush();
    }

    /**
     * The whole point, end to end: an old discarded patrol goes, and takes its
     * fixes, observations, events and the photographs' actual BYTES with it —
     * the preview beside each original included.
     */
    public function testAnOldDiscardedPatrolIsDeletedWithItsPhotographs(): void
    {
        $patrol = $this->discardedPatrol('2026-01-01T08:00:00Z');
        $photo = $this->photo($patrol);
        $key = $photo->getStoragePath();
        $thumbKey = EvidenceKey::thumb($key);

        self::assertTrue($this->evidence()->fileExists($key));
        self::assertTrue($this->evidence()->fileExists($thumbKey));

        $tester = $this->purge();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 discarded patrol', $tester->getDisplay());

        self::assertNull($this->find($patrol->getUuid()));
        // The cascade took the rest of the record with it.
        self::assertCount(0, $this->em->getRepository(Observation::class)->findAll());
        self::assertCount(0, $this->em->getRepository(ObservationPhoto::class)->findAll());
        self::assertCount(0, $this->em->getRepository(TrackPoint::class)->findAll());
        self::assertCount(0, $this->em->getRepository(PatrolEvent::class)->findAll());

        // And the bytes are gone — BOTH of them. Deleting evidence and leaving a
        // readable thumbnail of it behind is the worst kind of half-done.
        self::assertFalse($this->evidence()->fileExists($key));
        self::assertFalse($this->evidence()->fileExists($thumbKey));
    }

    /** Inside the window, nothing happens. */
    public function testARecentlyDiscardedPatrolIsLeftAlone(): void
    {
        $patrol = $this->discardedPatrol(new \DateTimeImmutable()->modify('-3 days')->format(\DateTimeInterface::ATOM));

        $tester = $this->purge();

        self::assertStringContainsString('No discarded patrol is older than 90 days', $tester->getDisplay());
        self::assertNotNull($this->find($patrol->getUuid()));
    }

    /** A patrol that was never discarded is not the sweep's business at all. */
    public function testACompletePatrolIsNeverTouchedHoweverOldItIs(): void
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2020-01-01T06:00:00Z'))
            ->setEndedAt(new \DateTimeImmutable('2020-01-01T09:00:00Z'));
        $this->em->persist($patrol);
        $this->em->flush();

        $this->purge();

        self::assertNotNull($this->find($patrol->getUuid()));
    }

    /**
     * THE HOLD. The one thing that stops the clock, and it has no expiry of its
     * own — the patrol here is years past its window.
     */
    public function testAHeldPatrolSurvivesTheSweepIndefinitely(): void
    {
        $reviewer = new User()->setPassword('x')->setEmail('reviewer@example.test')->setFirstName('Rita')->setLastName('Reviewer');
        $this->em->persist($reviewer);

        $patrol = $this->discardedPatrol('2020-01-01T08:00:00Z');
        $patrol->hold($reviewer);
        $this->em->flush();

        $uuid = $patrol->getUuid();
        $tester = $this->purge();

        self::assertStringContainsString('No discarded patrol is older than 90 days', $tester->getDisplay());
        // find() clears the manager, so the survivor is re-read rather than
        // reused — the release below has to be a real write.
        $survivor = $this->find($uuid);
        self::assertNotNull($survivor);

        // Released, the clock resumes FROM THE ORIGINAL DISCARD — a hold pauses
        // the deletion, it does not grant a fresh lifetime. This patrol was
        // discarded in 2020, so it goes on the very next sweep.
        $survivor->release();
        $this->em->flush();

        $this->purge();
        self::assertNull($this->find($uuid));
    }

    /** The window is measured from the discarded EVENT, not from when it ended. */
    public function testTheClockRunsFromTheDiscardedEventNotTheEnd(): void
    {
        // Ended long ago, but only discarded yesterday: still inside the window.
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2020-01-01T06:00:00Z'))
            ->setEndedAt(new \DateTimeImmutable('2020-01-01T09:00:00Z'))
            ->discard('Started by mistake');
        $this->em->persist($patrol);
        $event = new PatrolEvent(
            $patrol,
            Uuid::v4(),
            PatrolEventKindEnum::Discarded,
            new \DateTimeImmutable()->modify('-1 day'),
        )->setPayload(['reason' => 'Started by mistake']);
        $this->em->persist($event);
        $this->em->flush();

        $this->purge();

        self::assertNotNull($this->find($patrol->getUuid()), 'endedAt is only the FALLBACK; the event wins.');
    }

    /** --dry-run names the sweep and performs none of it. */
    public function testDryRunDeletesNeitherRowsNorBytes(): void
    {
        $patrol = $this->discardedPatrol('2026-01-01T08:00:00Z');
        $key = $this->photo($patrol)->getStoragePath();

        $tester = $this->purge(['--dry-run' => true]);

        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString($patrol->getRef(), $tester->getDisplay());
        self::assertNotNull($this->find($patrol->getUuid()));
        self::assertTrue($this->evidence()->fileExists($key));
    }

    /** Run twice, and the second run has nothing to do and says so. */
    public function testASecondRunIsANoOp(): void
    {
        $patrol = $this->discardedPatrol('2026-01-01T08:00:00Z');
        $this->photo($patrol);

        $this->purge();
        $second = $this->purge();

        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('No discarded patrol is older than 90 days', $second->getDisplay());
    }

    /** The window is the deployment's, and one run may narrow it. */
    public function testTheWindowCanBeOverriddenForOneRun(): void
    {
        $patrol = $this->discardedPatrol(new \DateTimeImmutable()->modify('-10 days')->format(\DateTimeInterface::ATOM));

        $this->purge(['--days' => '30']);
        self::assertNotNull($this->find($patrol->getUuid()));

        $this->purge(['--days' => '5']);
        self::assertNull($this->find($patrol->getUuid()));
    }

    public function testANonsenseWindowIsRefusedRatherThanGuessedAt(): void
    {
        $patrol = $this->discardedPatrol('2020-01-01T08:00:00Z');

        $tester = $this->purge(['--days' => '-4']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertNotNull($this->find($patrol->getUuid()));
    }

    /**
     * A discarded patrol with a track and observations and no end date at all —
     * a live upload thrown away before it closed. It must still be datable, or
     * it would be immortal.
     */
    public function testAPatrolDiscardedBeforeItEndedStillAges(): void
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2026-01-01T06:00:00Z'))
            ->setCreatedAt(new \DateTimeImmutable('2026-01-01T06:00:00Z'))
            ->discard('Started by mistake');
        $this->em->persist($patrol);
        $this->em->flush();

        self::assertNull($patrol->getEndedAt());

        $this->purge();

        self::assertNull($this->find($patrol->getUuid()), 'createdAt is the last fallback, and it always exists.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function discardedPatrol(string $endedAt): Patrol
    {
        $patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable($endedAt)->modify('-1 hour'))
            ->setEndedAt(new \DateTimeImmutable($endedAt))
            ->discard('Started by mistake');
        $this->em->persist($patrol);

        $batch = new TrackBatch($patrol, $patrol->getUuid()->toRfc4122().':track:0');
        $this->em->persist($batch);
        $this->em->persist(new TrackPoint(
            $patrol,
            $batch,
            '{"type":"Point","coordinates":[12.3,-5.7]}',
            new \DateTimeImmutable($endedAt),
        ));

        $this->em->flush();

        return $patrol;
    }

    private function photo(Patrol $patrol): ObservationPhoto
    {
        $observation = new Observation($patrol, 'maintenance');
        $this->em->persist($observation);

        $clientUuid = Uuid::v4();
        $key = PhotoEvidenceKey::prefixFor($observation).'/'.$clientUuid->toRfc4122().'.jpg';
        $this->evidence()->write($key, 'not-really-a-jpeg');
        $this->evidence()->write(EvidenceKey::thumb($key), 'not-really-a-preview');

        $photo = new ObservationPhoto($observation, $clientUuid, $key)
            ->setThumbKey(EvidenceKey::thumb($key));
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    private function find(Uuid $uuid): ?Patrol
    {
        $this->em->clear();

        return $this->em->getRepository(Patrol::class)->findOneBy(['uuid' => $uuid]);
    }

    /** @param array<string, mixed> $input */
    private function purge(array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('patrol:purge-discarded'));
        $tester->execute($input);

        return $tester;
    }

    private function evidence(): FilesystemOperator
    {
        $storage = static::getContainer()->get('storage.evidence');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
