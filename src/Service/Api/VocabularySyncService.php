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

namespace Uhifadhi\Patrol\Service\Api;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station as AreaStation;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository as AreaStationRepository;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;

/**
 * WHAT THE HANDSET IS ALLOWED TO SAY, READ OUT OF ONE AREA — its patrol types,
 * its stations and its observation kinds with their sub-categories.
 *
 * This is the read side of every word the sync's WRITE side resolves. The app
 * used to ship these lists compiled in; now an administrator writes them on the
 * configure page's own sections and the handset pulls them, so a new station is on
 * the phones at the next sync rather than at the next store release.
 *
 * A PATROL TYPE CARRIES MORE THAN ITS WORD, and that is the point of it: its
 * BASE — `surface` or `aerial` — is what a field client builds its screen from,
 * and the pace band, coverage buffer, observation placement and glyph beside it
 * are this area's numbers for that type. A client that reads the base stops
 * guessing what a type means from the name somebody gave it.
 *
 * EVERY ROW CARRIES ITS KEY, ITS LABEL, WHETHER IT IS STILL OFFERED, ITS
 * POSITION AND WHEN IT LAST CHANGED. The key is the wire value the app sends
 * back and never renames; the label is the only thing a rename touches; `active`
 * false means "keep it for the records you already hold, stop offering it";
 * `position` is the order the words are drawn in; `updatedAt` is what makes a
 * DELTA possible — a client passes back the newest one it holds and is answered
 * with what has changed since.
 *
 * A RETIRED ROW IS SENT, NOT WITHHELD. A handset holding a patrol filed under a
 * word that has since been retired still has to be able to print it.
 */
final readonly class VocabularySyncService
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private PatrolTypeRepository $types,
        private AreaStationRepository $stations,
        private TaxonomyKindRepository $kinds,
    ) {
    }

    /**
     * @param ?string $since an ISO-8601 instant; only rows changed at or after
     *                       it are returned, for a client topping up what it holds
     *
     * @return array{areaId: string, generatedAt: string, patrolTypes: list<array<string, mixed>>, stations: list<array<string, mixed>>, observationKinds: list<array<string, mixed>>}
     *
     * @throws PatrolApiException
     */
    public function forArea(string $areaId, ?string $since = null): array
    {
        $area = $this->area($areaId);
        $changedSince = $this->moment($since);

        return [
            'areaId' => (string) $area->getUuidString(),
            'generatedAt' => new \DateTimeImmutable()->format(\DATE_ATOM),
            'patrolTypes' => $this->rows(
                $this->types->findByArea($area),
                $changedSince,
                static fn (PatrolType $type): array => [
                    'key' => $type->getKey(),
                    'label' => $type->getLabel(),
                    'active' => $type->isActive(),
                    'position' => $type->getPosition(),
                    'updatedAt' => self::stamp($type->getUpdatedAt()),
                    /*
                     * WHAT IT RECORDS, AND WHAT FOLLOWS FROM THAT. `base` is the
                     * fixed key a field client builds its screen from — `surface`,
                     * where the recorder's own position is the track, or `aerial`,
                     * a flight log where it is not — and the four beside it are
                     * this area's numbers for that type: the pace band a patrol of
                     * it is expected to keep, how wide its track counts as covered,
                     * where an observation is put, and the mark it wears.
                     *
                     * ALL FIVE ARE NULLABLE, AND NULL MEANS "NOBODY HAS SAID". A
                     * type carried over from before bases existed has no base, and
                     * a client reading null falls back to whatever it did before —
                     * which for the app that shipped these lists compiled in is
                     * reading the name. A guessed `surface` would be worse than a
                     * null: it would tell a handset that a drone sortie records the
                     * operator's own position as its coverage.
                     */
                    'base' => $type->getBase()?->value,
                    'paceMinKmh' => $type->getPaceMinKmh(),
                    'paceMaxKmh' => $type->getPaceMaxKmh(),
                    'coverageBufferM' => $type->getCoverageBufferM(),
                    'observationPlacement' => $type->getObservationPlacement()?->value,
                    'glyph' => $type->getGlyph(),
                ],
            ),
            'stations' => $this->rows(
                $this->stations->findByArea($area),
                $changedSince,
                // THE AREA'S STATIONS, as the office keeps them (core AreaBundle
                // Station): the key the handset sends back is the station's uuid,
                // the label its name, and the order the register's — by name.
                static fn (AreaStation $station, int $position): array => [
                    'key' => (string) $station->getUuid()?->toRfc4122(),
                    'label' => (string) $station->getName(),
                    'active' => $station->isActive(),
                    'position' => $position,
                    'updatedAt' => self::stamp($station->getUpdatedAt()),
                    'point' => $station->getPoint(),
                ],
            ),
            'observationKinds' => $this->rows(
                $this->kinds->forArea($area),
                $changedSince,
                static fn (TaxonomyKind $kind): array => [
                    'key' => $kind->getCode(),
                    'label' => $kind->getLabel(),
                    'active' => $kind->isActive(),
                    'position' => $kind->getPosition(),
                    'updatedAt' => self::stamp($kind->getUpdatedAt()),
                    // The sub-categories ride WITH their kind rather than in a
                    // list of their own: a sub-category means nothing without
                    // the kind it groups under, and the handset draws them as
                    // one two-step choice.
                    'subcategories' => array_map(
                        static fn (TaxonomySubcategory $sub): array => [
                            'key' => $sub->getCode(),
                            'label' => $sub->getLabel(),
                            'active' => $sub->isActive(),
                            'position' => $sub->getPosition(),
                            'updatedAt' => self::stamp($sub->getUpdatedAt()),
                        ],
                        $kind->getSubcategories()->toArray(),
                    ),
                ],
            ),
        ];
    }

    /**
     * @template T of PatrolType|AreaStation|TaxonomyKind
     *
     * @param list<T>                                $records
     * @param \Closure(T, int): array<string, mixed> $row     the record and its position in the list
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $records, ?\DateTimeImmutable $changedSince, \Closure $row): array
    {
        $rows = [];
        foreach ($records as $position => $record) {
            $updatedAt = $record->getUpdatedAt();
            if (null !== $changedSince && null !== $updatedAt && $updatedAt < $changedSince) {
                continue;
            }
            $rows[] = $row($record, $position);
        }

        return $rows;
    }

    /** @throws PatrolApiException */
    private function area(string $areaId): AreaOfInterest
    {
        if (!Uuid::isValid($areaId)) {
            throw new PatrolApiException(422, 'unknown_area', 'That area id is not one this server issued.', details: ['areaId' => $areaId]);
        }

        $area = $this->areas->findOneBy(['uuid' => Uuid::fromString($areaId)]);

        return $area instanceof AreaOfInterest
            ? $area
            : throw new PatrolApiException(422, 'unknown_area', 'No area is known by that id.', details: ['areaId' => $areaId]);
    }

    /** @throws PatrolApiException */
    private function moment(?string $since): ?\DateTimeImmutable
    {
        if (null === $since || '' === $since) {
            return null;
        }

        try {
            return new \DateTimeImmutable($since);
        } catch (\Exception) {
            throw PatrolApiException::invalidPayload('"since" is not a timestamp this server can read.');
        }
    }

    private static function stamp(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format(\DATE_ATOM);
    }
}
