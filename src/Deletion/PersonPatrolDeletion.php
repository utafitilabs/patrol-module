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
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;

/**
 * WHAT A DELETED PERSON RECORDED GOES WITH THEM (ruled 28 Sep, #48): the
 * patrols they led, and the observations they logged on somebody else's
 * patrol. The database would only unlink both; this removes them, and their
 * files, before the account goes.
 */
final readonly class PersonPatrolDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolFileRemover $files,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof UserInterface;
    }

    public function describe(object $record): ?DeletionSubject
    {
        return null;
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof UserInterface);

        return array_values(array_filter([
            new DeletionLine('patrols they led', \count($this->led($record)), singular: 'patrol they led'),
            new DeletionLine('observations on others’ patrols', \count($this->loggedElsewhere($record)), singular: 'observation on another’s patrol'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        return [];
    }

    public function delete(object $record): void
    {
        \assert($record instanceof UserInterface);
        foreach ($this->loggedElsewhere($record) as $observation) {
            $this->files->removePhotosOf($observation);
            $this->entityManager->remove($observation);
        }
        foreach ($this->led($record) as $patrol) {
            $this->files->removeFilesOf($patrol);
            $this->entityManager->remove($patrol);
        }
        $this->entityManager->flush();
    }

    /** @return list<Patrol> */
    private function led(UserInterface $person): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->entityManager->createQuery(\sprintf('SELECT p FROM %s p WHERE p.lead = :person', Patrol::class))
            ->setParameter('person', $person)->getResult();

        return $patrols;
    }

    /** @return list<Observation> */
    private function loggedElsewhere(UserInterface $person): array
    {
        /** @var list<Observation> $observations */
        $observations = $this->entityManager->createQuery(\sprintf('SELECT o FROM %s o JOIN o.patrol p WHERE o.recordedBy = :person AND (p.lead IS NULL OR p.lead <> :person)', Observation::class))
            ->setParameter('person', $person)->getResult();

        return $observations;
    }
}
