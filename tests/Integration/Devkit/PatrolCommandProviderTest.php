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

namespace Uhifadhi\Patrol\Tests\Integration\Devkit;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\CommandDescriptor;
use Uhifadhi\Patrol\Devkit\PatrolCommandProvider;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\RecordingCommandIo;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Service\EvidenceKey;

/**
 * patrol:photos:backfill-thumbs — the previews for photographs that arrived
 * before this module adopted the evidence storage, exercised as devkit
 * exercises it: the descriptor's handler, an argument tail and a CommandIo.
 *
 * The fixtures use the LEGACY key shape on purpose (`patrol-<uuid>/<uuid>.jpg`),
 * because that is the only shape the backfill will ever meet in a real
 * deployment: everything stored since adoption already had a preview made at
 * upload time. The test therefore also pins the cut-over promise — that pointing
 * the evidence storage at the old directory makes yesterday's paths today's
 * keys, unchanged.
 */
final class PatrolCommandProviderTest extends IntegrationTestCase
{
    private Observation $observation;

    protected function setUp(): void
    {
        parent::setUp();

        $area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($area);

        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setClientUuid(Uuid::fromString('8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111'));
        $this->em->persist($patrol);

        $this->observation = new Observation($patrol, 'maintenance');
        $this->em->persist($this->observation);
        $this->em->flush();
    }

    public function testItGeneratesThePreviewAPreAdoptionPhotoNeverHad(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000001', withBytes: true);

        $io = $this->backfill();

        // One photograph was considered and it now has a preview. Whether the
        // bytes were written or adopted from an earlier run is not the
        // assertion: the evidence directory outlives a single test, so both are
        // correct answers and the row is what has to change either way.
        self::assertStringContainsString(EvidenceKey::thumb($photo->getStoragePath()), $io->output());

        $this->em->refresh($photo);
        $thumbKey = $photo->getThumbKey();
        self::assertIsString($thumbKey, 'A JPEG must get a preview where GD is available.');
        self::assertSame(EvidenceKey::thumb($photo->getStoragePath()), $thumbKey);
        self::assertTrue($this->evidence()->fileExists($thumbKey));

        // A preview is genuinely smaller than what it previews — the whole point
        // of making one.
        self::assertLessThan(
            $this->evidence()->fileSize($photo->getStoragePath()),
            $this->evidence()->fileSize($thumbKey),
        );
    }

    /** The legacy path is a valid evidence key exactly as it stands. */
    public function testALegacyPathIsClaimedByThisModule(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000002', withBytes: true);

        self::assertStringStartsWith(PhotoEvidenceKey::LEGACY_PREFIX, $photo->getStoragePath());
        self::assertTrue(PhotoEvidenceKey::claims($photo->getStoragePath()));
        self::assertTrue(EvidenceKey::isValid($photo->getStoragePath()));
    }

    public function testASecondRunDoesNothing(): void
    {
        $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000003', withBytes: true);

        $this->backfill();
        $second = $this->backfill();

        self::assertStringContainsString('already has a preview', $second->output());
    }

    /**
     * A row whose bytes are gone. Reported and skipped — deciding what a
     * photograph with no file means is not a thumbnail sweep's call, and
     * failing the run would leave every later photo unprocessed.
     */
    public function testAPhotoWhoseBytesAreMissingIsReportedAndSkipped(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000004', withBytes: false);

        $io = $this->backfill();

        self::assertStringContainsString('unavailable', $io->output());
        $this->em->refresh($photo);
        self::assertNull($photo->getThumbKey());
    }

    public function testDryRunWritesNothing(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000005', withBytes: true);

        $io = $this->backfill(['--dry-run']);

        self::assertStringContainsString('Dry run', $io->output());
        $this->em->refresh($photo);
        self::assertNull($photo->getThumbKey());
        self::assertFalse($this->evidence()->fileExists(EvidenceKey::thumb($photo->getStoragePath())));
    }

    /**
     * A photograph filed the way this module filed them BEFORE storage-module:
     * `patrol-<uuid>/<clientUuid>.jpg`, and no preview.
     */
    private function legacyPhoto(string $clientUuid, bool $withBytes): ObservationPhoto
    {
        $patrol = $this->observation->getPatrol();
        $key = PhotoEvidenceKey::LEGACY_PREFIX.$patrol->getClientUuid()?->toRfc4122().'/'.$clientUuid.'.jpg';

        if ($withBytes) {
            $this->evidence()->write($key, $this->jpegBytes());
        }

        $photo = new ObservationPhoto($this->observation, Uuid::fromString($clientUuid), $key)
            ->setMimeType('image/jpeg');
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    /** Real bytes, big enough that a 400px preview is genuinely a reduction. */
    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(1200, 900);
        self::assertNotFalse($image);
        for ($x = 0; $x < 1200; $x += 40) {
            $colour = imagecolorallocate($image, $x % 255, (2 * $x) % 255, 200);
            self::assertNotFalse($colour);
            imagefilledrectangle($image, $x, 0, $x + 20, 900, $colour);
        }

        ob_start();
        imagejpeg($image, null, 92);

        return (string) ob_get_clean();
    }

    /**
     * The descriptor's handler, called the way devkit's wrapper calls it.
     *
     * @param list<string> $arguments the tail a person typed
     */
    private function backfill(array $arguments = []): RecordingCommandIo
    {
        $provider = static::getContainer()->get('test_public.'.PatrolCommandProvider::class);
        self::assertInstanceOf(PatrolCommandProvider::class, $provider);

        $descriptor = $provider->commands()[0] ?? null;
        self::assertInstanceOf(CommandDescriptor::class, $descriptor);
        self::assertSame('patrol:photos:backfill-thumbs', $descriptor->name);

        $io = new RecordingCommandIo();
        self::assertSame(0, ($descriptor->handler)($arguments, $io));

        return $io;
    }

    public function testAnArgumentTheHandlerDoesNotKnowIsRefusedOnTheErrorStream(): void
    {
        $provider = static::getContainer()->get('test_public.'.PatrolCommandProvider::class);
        self::assertInstanceOf(PatrolCommandProvider::class, $provider);

        $io = new RecordingCommandIo();
        $exit = ($provider->commands()[0]->handler)(['--everything'], $io);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--everything', implode("\n", $io->errors));
        self::assertSame([], $io->written, 'A refusal says nothing on stdout.');
    }

    private function evidence(): FilesystemOperator
    {
        $storage = static::getContainer()->get('storage.evidence');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
