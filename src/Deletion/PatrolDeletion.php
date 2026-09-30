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

namespace Uhifadhi\Patrol\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolEvent;
use Uhifadhi\Patrol\Entity\TrackPoint;

/**
 * A PATROL, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48, design C, drawn for a
 * patrol): its track, observations, photographs, source file and history go
 * with it - the database removes the rows, this removes the bytes - and the
 * area's figures are recounted without it.
 */
final readonly class PatrolDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
        private PatrolFileRemover $files,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof Patrol;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof Patrol);
        $area = $record->getArea();
        $station = $record->getStation();

        return new DeletionSubject(
            kind: 'patrol',
            reference: $record->getRef(),
            title: 'Patrol '.$record->getRef().(null !== $station ? ' · '.$station : ''),
            summary: implode(' · ', array_filter([
                $record->getPatrolType()->getLabel(),
                $record->getLead()?->getFullName(),
                $record->getStartedAt()?->format('D j M'),
                null !== $record->getDistanceKm() ? round($record->getDistanceKm(), 1).' km' : null,
            ])),
            recordUrl: $this->router->generate('patrol_show', ['uuid' => $area->getUuidString(), 'patrol' => $record->getUuid()]),
            afterUrl: $this->router->generate('patrol_list', ['uuid' => $area->getUuidString()]),
            register: 'patrols',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof Patrol);
        $observations = $record->getObservations()->toArray();
        $photos = array_sum(array_map(static fn (Observation $o): int => \count($o->getPhotos()), $observations));
        $items = [];
        foreach (array_values($observations) as $n => $observation) {
            $items[] = [
                \sprintf('%d · %s · %s', $n + 1, $observation->getLoggedAt()?->format('H:i') ?? '--:--', $observation->getCategory()),
                trim(mb_strimwidth((string) $observation->getNote(), 0, 60, '…').' · '.\count($observation->getPhotos()).' photos', ' ·'),
            ];
        }

        return array_values(array_filter([
            new DeletionLine('patrols', 1, singular: 'patrol'),
            new DeletionLine('track points', $this->count(TrackPoint::class, $record), singular: 'track point'),
            new DeletionLine('observations', \count($observations), items: $items, singular: 'observation'),
            new DeletionLine('photos', $photos, singular: 'photo'),
            new DeletionLine('source files', null !== $record->getTrackFileKey() ? 1 : 0, detail: 'the original GPX, from the file store', singular: 'source file'),
            new DeletionLine('history lines', $this->count(PatrolEvent::class, $record), singular: 'history line'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        \assert($record instanceof Patrol);

        return [new DeletionLine($record->getArea()->getName().'’s figures', 1, detail: 'coverage and distance are recounted without it')];
    }

    public function delete(object $record): void
    {
        \assert($record instanceof Patrol);
        $this->files->removeFilesOf($record);
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, Patrol $patrol): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.patrol = :patrol', $entity))
            ->setParameter('patrol', $patrol)->getSingleScalarResult();
    }
}
