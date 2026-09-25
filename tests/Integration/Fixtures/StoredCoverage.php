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

namespace Uhifadhi\Patrol\Tests\Integration\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolCorridor;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;
use Uhifadhi\Patrol\Service\PatrolCorridorService;

/**
 * WHAT THE WORKER WOULD HAVE DONE, done in the test: buffer the patrols a test
 * made into stored corridors, and file the module's facts for a month through
 * the core's own rebuild — the same path the schedule and
 * `uhifadhi:facts:rebuild` take.
 *
 * For a kernel test case: it reads services from the test container.
 */
trait StoredCoverage
{
    /** Every complete patrol with a track, buffered (again). */
    protected function bufferCorridors(): int
    {
        $corridors = static::getContainer()->get('test_public.'.PatrolCorridorService::class);
        \assert($corridors instanceof PatrolCorridorService);

        return $corridors->catchUp(all: true);
    }

    /** One patrol buffered, as the worker's handler does — the entity manager is left alone. */
    protected function bufferPatrol(Patrol $patrol): bool
    {
        $corridors = static::getContainer()->get('test_public.'.PatrolCorridorService::class);
        \assert($corridors instanceof PatrolCorridorService);

        return $corridors->buffer((int) $patrol->getId());
    }

    protected function corridors(): PatrolCorridorRepository
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $repository = $em->getRepository(PatrolCorridor::class);
        \assert($repository instanceof PatrolCorridorRepository);

        return $repository;
    }

    /** The module's facts for the month holding `$when`, filed as the schedule files them. */
    protected function fileFacts(\DateTimeImmutable $when): int
    {
        $rebuild = static::getContainer()->get('test_public.'.FactRebuildService::class);
        \assert($rebuild instanceof FactRebuildService);

        return $rebuild->rebuild([FactPeriod::month($when)], 'patrols');
    }

    protected function facts(): FactReaderInterface
    {
        $reader = static::getContainer()->get('test_public.'.FactReaderInterface::class);
        \assert($reader instanceof FactReaderInterface);

        return $reader;
    }
}
