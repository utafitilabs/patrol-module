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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE TWO WORD-LISTS THE TYPES AND STATIONS SECTIONS EDIT, PROVEN AGAINST THE REAL DATABASE:
 * add, rename, retire, reactivate — and never delete, because patrols are filed
 * against both.
 */
final class PatrolVocabularyServiceTest extends IntegrationTestCase
{
    private function vocabulary(): PatrolVocabularyService
    {
        /** @var PatrolVocabularyService $vocabulary */
        $vocabulary = $this->service(PatrolVocabularyService::class);

        return $vocabulary;
    }

    private function types(): PatrolTypeRepository
    {
        $repository = $this->em->getRepository(PatrolType::class);
        self::assertInstanceOf(PatrolTypeRepository::class, $repository);

        return $repository;
    }

    private function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name);
        $area->setSource('test fixture');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    // ── area scope ────────────────────────────────────────────────────────────

    public function testEachAreaOwnsItsOwnWords(): void
    {
        $first = $this->anArea('First Area');
        $second = $this->anArea('Second Area');

        $this->vocabulary()->addType($first, 'Foot patrol');
        $this->vocabulary()->addType($second, 'Foot patrol');

        self::assertCount(1, $this->types()->findByArea($first));
        self::assertCount(1, $this->types()->findByArea($second));
    }

    // ── types ─────────────────────────────────────────────────────────────────

    public function testAddingATypeSlugsItsKeyAndAppendsIt(): void
    {
        $area = $this->anArea();

        $first = $this->vocabulary()->addType($area, 'Foot patrol');
        $second = $this->vocabulary()->addType($area, 'Vehicle patrol');

        self::assertSame('foot-patrol', $first->getKey());
        self::assertSame(0, $first->getPosition());
        self::assertSame('vehicle-patrol', $second->getKey());
        self::assertSame(1, $second->getPosition());
        self::assertTrue($second->isActive());
    }

    public function testRenamingATypeLeavesItsKeyAlone(): void
    {
        $area = $this->anArea();
        $type = $this->vocabulary()->addType($area, 'Foot patrol');

        $this->vocabulary()->renameType($type, 'Foot');

        self::assertSame('Foot', $type->getLabel());
        self::assertSame('foot-patrol', $type->getKey());
    }

    public function testATypeIsRetiredAndBroughtBack(): void
    {
        $area = $this->anArea();
        $type = $this->vocabulary()->addType($area, 'Foot patrol');

        $this->vocabulary()->retireType($type);
        self::assertFalse($type->isActive());
        self::assertCount(1, $this->types()->findByArea($area), 'Retiring must never delete.');
        self::assertCount(0, $this->types()->findByAreaActive($area));

        $this->vocabulary()->reactivateType($type);
        self::assertTrue($type->isActive());
    }

    public function testASecondTypeWithTheSameNameIsRefused(): void
    {
        $area = $this->anArea();
        $this->vocabulary()->addType($area, 'Foot patrol');

        $this->expectException(VocabularyConflictException::class);
        $this->vocabulary()->addType($area, 'foot PATROL');
    }

    public function testATypeNeedsAName(): void
    {
        $area = $this->anArea();

        $this->expectException(VocabularyConflictException::class);
        $this->vocabulary()->addType($area, '   ');
    }

    // ── stations: the area's, resolved and never written here ────────────────

    /**
     * WHAT THE HANDSET'S STRING BECOMES. The vocabulary sync hands it the area
     * station's uuid; a handset that synced before stations had one still sends
     * the name. Both find the area's record; nothing here makes one.
     */
    public function testAKnownStationIsResolvedByUuidAndByName(): void
    {
        $area = $this->anArea();
        $configured = Vocabulary::station($this->em, $area, 'River Post', 'river-post');
        $this->em->flush();
        self::assertInstanceOf(Station::class, $configured);

        self::assertSame($configured, $this->vocabulary()->resolveStation($area, (string) $configured->getUuid()?->toRfc4122()));
        self::assertSame($configured, $this->vocabulary()->resolveStation($area, 'river post'));
        self::assertSame($configured, $this->vocabulary()->resolveStation($area, '  River Post '));
    }

    public function testAStationOfAnotherAreaIsNotThisAreasStation(): void
    {
        $here = $this->anArea('Here');
        $there = $this->anArea('There');
        $elsewhere = Vocabulary::station($this->em, $there, 'River Post', 'river-post');
        $this->em->flush();

        self::assertNull($this->vocabulary()->resolveStation($here, (string) $elsewhere?->getUuid()?->toRfc4122()));
        self::assertNull($this->vocabulary()->resolveStation($here, 'River Post'));
    }

    public function testAnUnknownStationFromTheFieldResolvesToNothingAndMakesNothing(): void
    {
        $area = $this->anArea();

        self::assertNull($this->vocabulary()->resolveStation($area, 'North Gate'));
        self::assertSame([], $this->em->getRepository(Station::class)->findBy(['area' => $area]), 'a word off a handset never becomes a station');
    }

    public function testABlankStationResolvesToNothing(): void
    {
        self::assertNull($this->vocabulary()->resolveStation($this->anArea(), null));
        self::assertNull($this->vocabulary()->resolveStation($this->anArea(), '   '));
    }

    public function testAnUnknownTypeFromTheFieldIsCreatedRetired(): void
    {
        $area = $this->anArea();

        $type = $this->vocabulary()->resolveType($area, 'horseback');

        self::assertSame('horseback', $type->getKey());
        self::assertFalse($type->isActive());
    }

    // ── the seed ──────────────────────────────────────────────────────────────

    /**
     * The installation's `patrol.types` is the seed a NEW area starts from, and
     * nothing more: an area that already has words is left exactly as it is.
     */
    public function testTheInstallationsTypesSeedAnAreaOnceOnly(): void
    {
        $area = $this->anArea();

        self::assertTrue($this->vocabulary()->seedTypes($area));
        $seeded = $this->types()->findByArea($area);
        // The installation this suite plays configures two (see TestKernel).
        self::assertSame(['walk', 'boat'], array_map(static fn (PatrolType $t): string => $t->getKey(), $seeded));
        self::assertSame(['Walking round', 'Boat'], array_map(static fn (PatrolType $t): string => $t->getLabel(), $seeded));

        $this->vocabulary()->renameType($seeded[0], 'On foot');
        self::assertFalse($this->vocabulary()->seedTypes($area));
        self::assertSame('On foot', $this->types()->findByArea($area)[0]->getLabel());
    }
}
