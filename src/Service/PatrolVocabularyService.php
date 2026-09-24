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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station as AreaStation;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository as AreaStationRepository;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Model\PatrolBaseDefaults;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;

/**
 * THE TWO WORD-LISTS AN AREA OWNS — its patrol types and its stations, a
 * configure section each — and every write either of them takes.
 *
 * ONE SERVICE FOR BOTH, and the reason is that they are one thing twice. Both
 * are a per-area list of {key, label, active, position}; both take exactly add,
 * rename, retire and reactivate; both enforce the same two rules (a label is
 * unique within the area, a key is born once and frozen); both are edited from
 * the SAME screen, in one section each, and both are resolved from a wire
 * string by the same handset sync. Two services would be this file twice with
 * one noun changed, and a rule tightened in one of them would silently not hold
 * in the other. The precedent is beside it: {@see TaxonomyAdminService} already
 * carries two levels of one vocabulary for the same reason.
 *
 * NOTHING IS EVER DELETED. Patrols are filed against a type and a station, and
 * a field record must never lose the words that describe it. Retirement flips a
 * flag; the row, its key and every patrol under it stay, and one click brings it
 * back.
 *
 * THE KEY IS BORN ONCE AND FROZEN. It is derived from the first label, made
 * unique within the area, and never touched again — which is what lets a saved
 * filter, an export column and an offline handset hold it across a rename.
 *
 * A WORD FROM THE FIELD THAT NOBODY CONFIGURED IS CREATED RETIRED, never
 * refused ({@see self::resolveStation()}). The sync contract names no error code
 * for an unknown station, and the same reasoning
 * {@see Api\PatrolUpsertService} states for an unknown
 * TYPE applies unchanged: refusing would throw away a real patrol because a
 * settings screen and an app build disagreed about a word, and a discarded
 * patrol is gone. Retired-on-arrival makes the disagreement VISIBLE on the Stations section —
 * dimmed, with its count — where an administrator either renames it into an
 * existing post or reactivates it.
 *
 * It owns the flush: each call is one discrete admin action behind an HTTP POST,
 * so "did it save?" is the whole question.
 */
final class PatrolVocabularyService
{
    /**
     * @param array<string, array{label: string}> $configuredTypes the installation's
     *                                                             patrol.types — the SEED a new area starts from, and nothing else
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolTypeRepository $types,
        private readonly AreaStationRepository $areaStations,
        private readonly array $configuredTypes,
    ) {
    }

    // ── patrol types ──────────────────────────────────────────────────────────

    /**
     * @param ?PatrolBaseEnum $base  what it records; null where the add panel was
     *                               submitted without a choice, which the section
     *                               then asks for on the row
     * @param ?string         $glyph a mark from {@see PatrolBaseDefaults::GLYPHS};
     *                               anything else is ignored rather than written,
     *                               because a name nothing ships a file for is an
     *                               empty square on every row it reaches
     *
     * @throws VocabularyConflictException on a blank or duplicate label
     */
    public function addType(
        AreaOfInterest $area,
        string $label,
        string $key = '',
        ?PatrolBaseEnum $base = null,
        ?string $glyph = null,
    ): PatrolType {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw VocabularyConflictException::label('A patrol type needs a name.');
        }
        if ($this->types->labelExistsInArea($area, $label)) {
            throw VocabularyConflictException::label(\sprintf('This area already has a patrol type called "%s".', $label));
        }

        $type = new PatrolType($area, $this->freeTypeKey($area, '' !== $key ? $key : $label), $label);
        $type->setPosition($this->types->maxPositionByArea($area) + 1);
        $type->setGlyph(self::knownGlyph($glyph));
        self::adoptBase($type, $base);

        $this->entityManager->persist($type);
        $this->entityManager->flush();

        return $type;
    }

    /**
     * WHAT A TYPE RECORDS, AND THE THREE NUMBERS THAT COME WITH IT.
     *
     * Choosing a base SEEDS the tunables and never overwrites one: a number the
     * area tuned is the area's, and re-saving the section — which posts every base
     * on the page, chosen or not — must not walk back over it. Only a tunable
     * standing empty is filled, which is exactly what "prefilled from the base"
     * means and is why moving a type from surface to aerial leaves a buffer
     * somebody widened alone.
     *
     * THERE IS NO CLEARING. A type with no base is a type nobody has answered for
     * yet, never an answer somebody withdrew, and the section draws no control that
     * would take one back off.
     */
    public function setTypeBase(PatrolType $type, PatrolBaseEnum $base): PatrolType
    {
        self::adoptBase($type, $base);
        $this->entityManager->flush();

        return $type;
    }

    /**
     * THE FOUR A ROW'S DISCLOSURE EDITS. A null leaves the type's own value where
     * it is rather than clearing it — the section posts what its fields hold, and a
     * row whose disclosure was never opened holds nothing to say.
     *
     * Out of range is CLAMPED rather than refused, for the reason
     * {@see PatrolBaseDefaults::MIN_PACE_KMH} gives: the bounds are the fields'
     * own, so anything outside them was hand-posted.
     */
    public function tuneType(
        PatrolType $type,
        ?int $paceMinKmh = null,
        ?int $paceMaxKmh = null,
        ?int $coverageBufferM = null,
        ?ObservationPlacementEnum $placement = null,
        ?string $glyph = null,
    ): PatrolType {
        if (null !== $paceMinKmh) {
            $type->setPaceMinKmh(self::clamp($paceMinKmh, PatrolBaseDefaults::MIN_PACE_KMH, PatrolBaseDefaults::MAX_PACE_KMH));
        }
        if (null !== $paceMaxKmh) {
            $type->setPaceMaxKmh(self::clamp($paceMaxKmh, PatrolBaseDefaults::MIN_PACE_KMH, PatrolBaseDefaults::MAX_PACE_KMH));
        }
        if (null !== $coverageBufferM) {
            $type->setCoverageBufferM(self::clamp($coverageBufferM, PatrolBaseDefaults::MIN_BUFFER_M, PatrolBaseDefaults::MAX_BUFFER_M));
        }
        if (null !== $placement) {
            $type->setObservationPlacement($placement);
        }
        $known = self::knownGlyph($glyph);
        if (null !== $known) {
            $type->setGlyph($known);
        }

        $this->entityManager->flush();

        return $type;
    }

    /** Seed what stands empty under the base, and nothing that does not. */
    private static function adoptBase(PatrolType $type, ?PatrolBaseEnum $base): void
    {
        $type->setBase($base);
        if (null === $base) {
            return;
        }

        $defaults = PatrolBaseDefaults::of($base);
        $type->setPaceMinKmh($type->getPaceMinKmh() ?? $defaults->paceMinKmh);
        $type->setPaceMaxKmh($type->getPaceMaxKmh() ?? $defaults->paceMaxKmh);
        $type->setCoverageBufferM($type->getCoverageBufferM() ?? $defaults->coverageBufferM);
        $type->setObservationPlacement($type->getObservationPlacement() ?? $defaults->observationPlacement);
    }

    /** A mark this module ships a file for, or nothing at all. */
    private static function knownGlyph(?string $glyph): ?string
    {
        return \in_array($glyph, PatrolBaseDefaults::GLYPHS, true) ? $glyph : null;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /** @throws VocabularyConflictException on a blank or duplicate label */
    public function renameType(PatrolType $type, string $label): PatrolType
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw VocabularyConflictException::label('A patrol type needs a name.');
        }
        if ($this->types->labelExistsInArea($type->getArea(), $label, $type)) {
            throw VocabularyConflictException::label(\sprintf('This area already has a patrol type called "%s".', $label));
        }

        $type->setLabel($label); // the key is deliberately untouched
        $this->entityManager->flush();

        return $type;
    }

    public function retireType(PatrolType $type): PatrolType
    {
        $type->deactivate();
        $this->entityManager->flush();

        return $type;
    }

    public function reactivateType(PatrolType $type): PatrolType
    {
        $type->reactivate();
        $this->entityManager->flush();

        return $type;
    }

    /**
     * The record behind a wire string — the key first, then the label, and
     * failing both a new RETIRED record. Never null: a patrol always has a type.
     */
    public function resolveType(AreaOfInterest $area, string $value): PatrolType
    {
        $value = $this->cleanLabel($value);
        if ('' === $value) {
            $value = 'unspecified';
        }

        $found = $this->types->findOneByAreaAndKey($area, $value) ?? $this->findTypeByLabel($area, $value);
        if (null !== $found) {
            return $found;
        }

        $type = new PatrolType($area, $this->freeTypeKey($area, $value), $value);
        $type->setPosition($this->types->maxPositionByArea($area) + 1)->deactivate();

        $this->entityManager->persist($type);
        $this->entityManager->flush();

        return $type;
    }

    /**
     * Give a NEW area the installation's configured types, and only a new one.
     *
     * This is the whole of what `patrol.types` is for now: the words an area
     * starts with, copied in once so it can then rename and retire them without
     * asking every other area's permission. An area that already has any type
     * is left exactly as it is, so a config change never reaches back into an
     * area somebody has curated.
     *
     * @return bool whether anything was written
     */
    public function seedTypes(AreaOfInterest $area): bool
    {
        if ([] !== $this->types->findByArea($area)) {
            return false;
        }

        $position = 0;
        foreach ($this->configuredTypes as $key => $type) {
            $seeded = new PatrolType($area, (string) $key, $type['label']);
            $seeded->setPosition($position++);
            $this->entityManager->persist($seeded);
        }

        if (0 === $position) {
            return false;
        }

        $this->entityManager->flush();

        return true;
    }

    // ── stations ──────────────────────────────────────────────────────────────

    /**
     * THE AREA'S STATION BEHIND A WIRE VALUE. The handset holds the station's
     * uuid, which is what the vocabulary sync hands it; a value that is not a
     * uuid is tried as a name, case-folded, for handsets that synced before
     * stations had one. Null where nothing matches — and the caller keeps the
     * word on the patrol ({@see Patrol::setStationWord()}) rather than making a
     * station of it: the handset collects, the office configures, and an
     * area's station needs a point nobody on a handset was asked for.
     */
    public function resolveStation(AreaOfInterest $area, ?string $value): ?AreaStation
    {
        $value = trim($value ?? '');
        if ('' === $value) {
            return null;
        }

        if (Uuid::isValid($value)) {
            $byUuid = $this->areaStations->findOneBy(['area' => $area, 'uuid' => Uuid::fromString($value)]);
            if ($byUuid instanceof AreaStation) {
                return $byUuid;
            }
        }

        $wanted = mb_strtolower($value);
        foreach ($this->areaStations->findByArea($area) as $station) {
            if (mb_strtolower(trim((string) $station->getName())) === $wanted) {
                return $station;
            }
        }

        return null;
    }

    private function findTypeByLabel(AreaOfInterest $area, string $label): ?PatrolType
    {
        foreach ($this->types->findByArea($area) as $type) {
            if (mb_strtolower($type->getLabel()) === mb_strtolower($label)) {
                return $type;
            }
        }

        return null;
    }

    private function freeTypeKey(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 40, 'type');
        $key = $base;
        $n = 2;
        while (null !== $this->types->findOneByAreaAndKey($area, $key)) {
            $key = $this->slug($base.'-'.$n, 40, 'type');
            ++$n;
        }

        return $key;
    }

    /** A wire-safe key from a label: lower-case, dashes, bounded, never empty. */
    private function slug(string $value, int $limit, string $fallback): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        $value = mb_substr($value, 0, $limit);
        $value = trim($value, '-');

        return '' !== $value ? $value : $fallback;
    }

    private function cleanLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $label) ?? '');
    }
}
