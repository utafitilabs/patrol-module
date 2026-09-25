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

namespace Uhifadhi\Patrol\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Patrol\Entity\Observation;

/**
 * @extends ServiceEntityRepository<Observation>
 */
final class ObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Observation::class);
    }

    /**
     * The observation a client UUID already created, if any (API-CONTRACT.md
     * §6). Observations arrive as one part per patrol and the phone re-sends
     * the whole part on failure, so each one is matched individually — a retry
     * after a partial write must add only what is missing.
     */
    public function findOneByClientUuid(Uuid $clientUuid): ?Observation
    {
        return $this->findOneBy(['clientUuid' => $clientUuid]);
    }

    /**
     * The area's observations, newest first — PL·A4's page of rows, and the
     * "observations logged" line beside it.
     *
     * Scoped through the patrol, because an observation has no area of its own:
     * it belongs to the patrol it was logged on, and that patrol belongs to an
     * area. One join says so; storing a second area_id would let the two drift.
     *
     * @return list<Observation>
     */
    public function findByAreaLatestFirst(AreaOfInterest $area, int $limit): array
    {
        /** @var list<Observation> $observations */
        $observations = $this->createQueryBuilder('o')
            ->innerJoin('o.patrol', 'p')
            ->andWhere('p.area = :area')
            ->setParameter('area', $area)
            ->orderBy('o.loggedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $observations;
    }

    /**
     * The area's observations LOGGED inside a half-open window — "11 logged
     * today", and the month figure the same card states beside it.
     *
     * By `loggedAt`, which is when the ranger saw the thing, never by when the
     * row reached the server: a handset that syncs at the end of a shift would
     * otherwise file the whole day's sightings under the hour it found signal.
     * An observation with no logged time sits in no day and is left out by the
     * comparison itself.
     *
     * @return list<Observation>
     */
    public function findByAreaLoggedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Observation> $observations */
        $observations = $this->createQueryBuilder('o')
            ->innerJoin('o.patrol', 'p')
            ->andWhere('p.area = :area')
            ->andWhere('o.loggedAt >= :from')
            ->andWhere('o.loggedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('o.loggedAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $observations;
    }

    /**
     * HOW MANY OBSERVATIONS THE AREA HOLDS UNDER EACH WIRE-CODE, optionally
     * inside a half-open window.
     *
     * An aggregate, not a page of rows: the kinds overview states three numbers
     * for every word an area files under, and loading the observations to count
     * them would read a year of rows to print one figure.
     *
     * By `loggedAt` for the same reason {@see self::findByAreaLoggedBetween()}
     * is — when the ranger saw the thing, not when the handset found signal —
     * so an observation with no logged time sits in no month and reaches only
     * the all-time figure.
     *
     * @return array<string, int> wire-code => how many
     */
    public function countByArea(AreaOfInterest $area, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.category AS category, COUNT(o.id) AS tally')
            ->innerJoin('o.patrol', 'p')
            ->andWhere('p.area = :area')
            ->setParameter('area', $area)
            ->groupBy('o.category');

        if (null !== $from && null !== $until) {
            $qb->andWhere('o.loggedAt >= :from')
                ->andWhere('o.loggedAt < :until')
                ->setParameter('from', $from)
                ->setParameter('until', $until);
        }

        /** @var list<array{category: string, tally: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['category']] = (int) $row['tally'];
        }

        return $counts;
    }

    /**
     * WHICH HOST ZONE EACH OBSERVATION FELL IN, for a page of rows at a time.
     *
     * An observation carries a point and no zone, exactly as a patrol carries a
     * station and no zone (docs/design-decisions.md §1). Zones are the
     * HOST's spatial lens and an org with none is the normal state, so the
     * module asks the one generic question it is allowed to ask — which polygon
     * contains this point — and names no zone of its own.
     *
     * Raw SQL because DQL has no ST_Intersects, and every table and column is
     * read from Doctrine's metadata for the same reason
     * {@see PatrolRepository::zoneEntriesBetween()} reads its own: the host
     * owns Zone and may map it with another naming strategy than this bundle's
     * tests do.
     *
     * An observation with no position is simply absent from the result. The
     * field API accepts an unpositioned observation on purpose — the ranger's
     * honest word about a sighting the handset could not fix — and inventing a
     * zone for it would turn that honesty into a location nobody recorded. So is
     * a point that fell in no zone at all: unzoned is a first-class answer.
     *
     * @param list<Observation> $observations
     *
     * @return array<int, string> observation id => zone name
     */
    public function zoneNamesFor(array $observations): array
    {
        $ids = [];
        foreach ($observations as $observation) {
            $id = $observation->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $entityManager = $this->getEntityManager();
        $observation = $this->getClassMetadata();
        $zoneMeta = $entityManager->getClassMetadata(Zone::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT o.%s AS observation_id, z.%s AS zone_name
                FROM %s o
                INNER JOIN %s z ON ST_Intersects(z.%s, o.%s)
                WHERE o.%s IN (:ids)
                SQL,
            $observationId = $observation->getSingleIdentifierColumnName(),
            $zoneMeta->getColumnName('name'),
            $observation->getTableName(),
            $zoneMeta->getTableName(),
            $zoneMeta->getColumnName('geom'),
            $observation->getColumnName('position'),
            $observationId,
        );

        /** @var list<array{observation_id: int|string, zone_name: string}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative(
            $sql,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $zones = [];
        foreach ($rows as $row) {
            $zones[(int) $row['observation_id']] = $row['zone_name'];
        }

        return $zones;
    }
}
