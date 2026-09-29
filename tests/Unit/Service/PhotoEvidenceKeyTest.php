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

namespace Uhifadhi\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Storage\Service\EvidenceKey;

/**
 * The one rule three collaborators share: which evidence keys are patrol's.
 *
 * Pinned in a unit test because the failure mode of a disagreement between them
 * is silent — a photograph stored under a prefix nobody claims is a photograph
 * nobody is allowed to look at, and nothing throws.
 */
final class PhotoEvidenceKeyTest extends TestCase
{
    public function testAnObservationsPhotosAreFiledUnderTheirPatrolsClientUuid(): void
    {
        $observation = $this->observation('4a7cf1a2-6b1a-4f34-8f8f-1a0f19a1c111');

        self::assertSame(
            'patrol/4a7cf1a2-6b1a-4f34-8f8f-1a0f19a1c111',
            PhotoEvidenceKey::prefixFor($observation),
        );
    }

    public function testAPatrolThatNeverCameFromAPhoneIsNamedByItsOwnUuid(): void
    {
        $observation = $this->observation(null);
        $prefix = PhotoEvidenceKey::prefixFor($observation);

        self::assertSame(
            'patrol/'.$observation->getPatrol()->getUuid()->toRfc4122(),
            $prefix,
        );
    }

    /** The prefix must survive EvidenceKey's own charset rules, or nothing stores. */
    public function testTheBuiltKeyIsAValidEvidenceKey(): void
    {
        $key = EvidenceKey::build(
            PhotoEvidenceKey::prefixFor($this->observation('4a7cf1a2-6b1a-4f34-8f8f-1a0f19a1c111')),
            'e77c0000-0000-4000-8000-000000000001',
            'jpg',
        );

        self::assertSame(
            'patrol/4a7cf1a2-6b1a-4f34-8f8f-1a0f19a1c111/e77c0000-0000-4000-8000-000000000001.jpg',
            $key,
        );
        self::assertTrue(PhotoEvidenceKey::claims($key));
        self::assertTrue(PhotoEvidenceKey::claims(EvidenceKey::thumb($key)));
    }

    #[DataProvider('claimedKeys')]
    public function testWhichKeysAreOurs(string $key, bool $claimed): void
    {
        self::assertSame($claimed, PhotoEvidenceKey::claims($key));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function claimedKeys(): iterable
    {
        yield 'a key this module writes' => ['patrol/abc/def.jpg', true];
        yield 'its preview' => ['patrol/abc/def.jpg.thumb.jpg', true];
        // The pre-adoption shape, still on disk in every existing deployment.
        yield 'a legacy key' => ['patrol-abc/def.jpg', true];
        yield 'a legacy preview' => ['patrol-abc/def.jpg.thumb.jpg', true];
        yield 'another module' => ['incident/abc/def.jpg', false];
        // "patrols" starts with neither "patrol/" nor "patrol-": the root
        // segment is compared whole, so a neighbouring prefix is never captured.
        yield 'a prefix that merely starts the same' => ['patrols/abc/def.jpg', false];
        yield 'nothing at all' => ['', false];
    }

    public function testAPreviewIsDecidedOnItsOriginal(): void
    {
        self::assertSame(
            'patrol/abc/def.jpg',
            PhotoEvidenceKey::original('patrol/abc/def.jpg.thumb.jpg'),
        );
    }

    public function testAnOriginalIsItsOwnOriginal(): void
    {
        self::assertSame('patrol/abc/def.jpg', PhotoEvidenceKey::original('patrol/abc/def.jpg'));
    }

    private function observation(?string $patrolClientUuid): Observation
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve');
        $patrol = new Patrol($area, Vocabulary::type(null, $area, 'walk'));
        if (null !== $patrolClientUuid) {
            $patrol->setClientUuid(Uuid::fromString($patrolClientUuid));
        }

        return new Observation($patrol, 'maintenance');
    }
}
