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
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station as AreaStation;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;

/**
 * @extends ServiceEntityRepository<Patrol>
 */
final class PatrolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Patrol::class);
    }

    /**
     * The patrol a field device already created, if any — API-CONTRACT.md §4's
     * upsert key. A repeated create with a clientUuid we hold is answered with
     * the SAME patrol, which is the promise the app's retry loop depends on.
     */
    public function findOneByClientUuid(Uuid $clientUuid): ?Patrol
    {
        return $this->findOneBy(['clientUuid' => $clientUuid]);
    }

    /**
     * The area's patrols, latest first.
     *
     * @return list<Patrol>
     */
    public function findByAreaLatestFirst(AreaOfInterest $area, ?int $limit = null): array
    {
        return $this->findBy(['area' => $area], ['startedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * WHETHER THIS AREA PATROLS AT ALL — one row is enough, so this asks for one
     * row rather than counting a history that can run to thousands.
     *
     * It is the difference between "nothing happened today" and "nothing has
     * ever happened here", which is the difference between a zero the overview
     * may print and an absence it must not dress up as one.
     */
    public function areaHasAnyPatrol(AreaOfInterest $area): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.area = :area')
            ->setParameter('area', $area)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The area's patrols that STARTED inside a half-open window, earliest first —
     * the calendar reads a month (its grid window, see
     * PatrolDashboardService::calendarRange) without loading the area's whole
     * history. Hand-logged patrols are ordinary rows and come with the rest; a
     * patrol with no start date has no day to sit on and is left out by the
     * comparison itself.
     *
     * @return list<Patrol>
     */
    public function findByAreaStartedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.startedAt >= :from')
            ->andWhere('p.startedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('p.startedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * The area's patrols that STARTED inside a half-open window, LATEST first —
     * what the dashboard's map, log and feed read now that they are scoped to the
     * month on screen (the MONTH filter), rather than to the whole all-time
     * history {@see self::findByAreaLatestFirst()} returned.
     *
     * The sibling {@see self::findByAreaStartedBetween()} orders EARLIEST first
     * for the calendar, which lays days out left to right; this one orders latest
     * first for the log, which shows the most recent patrols at the top and slices
     * the head. A patrol with no start date has no month to sit in and is left out
     * by the comparison itself — which is right for the map and log, where only a
     * finished patrol (always dated) can be drawn.
     *
     * @return list<Patrol>
     */
    public function findByAreaStartedBetweenLatestFirst(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.startedAt >= :from')
            ->andWhere('p.startedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('p.startedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * THE ZONE EACH PATROL SET OUT IN — the spatial join that gives the dashboard
     * its ZONE filter without a stored zone field on the patrol.
     *
     * A patrol names the station it set off from and NO zone
     * (docs/design-decisions.md §1); the host owns the zone polygons
     * ({@see Zone}). So "which zone is this patrol in" is a spatial question,
     * answered here against the geometry rather than guessed from the station —
     * the same reasoning
     * {@see self::zoneLastEntriesBefore()} states for measuring absence from tracks
     * and not from station names.
     *
     * ONE ZONE PER PATROL: its START POINT's zone, by ST_Covers — where the patrol
     * set out, the same evidence the coverage map places a station marker at
     * (PatrolDashboardService::coveragePayload). A track that wanders across a
     * boundary is filed under where it began, so a patrol appears under exactly
     * one zone in the filter and the by-zone grouping this unblocks stays a
     * partition. A hand-logged patrol has no track and no start point, so it is
     * absent from the map — unzoned, which the filter menu reads as "no zone".
     * On a shared edge ST_Covers is true for both zones; the join takes the lowest
     * zone id, so the assignment is deterministic rather than order-dependent.
     *
     * Scoped to the same half-open window the map and log read, so the join is
     * over exactly the patrols on screen and no more.
     *
     * KEYED BY UUID, not the sequential id — the module addresses a patrol by its
     * uuid everywhere it is public (a log row, a coverage track), so the map the
     * templates and the coverage payload look a patrol's zone up in is keyed the
     * same way (project convention: public addressing is by UUID).
     *
     * @return array<string, string> patrol uuid → zone name; a patrol whose start falls in no zone is absent
     */
    public function zonesForPatrols(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $zoneMeta = $entityManager->getClassMetadata(Zone::class);

        // DISTINCT ON (patrol) keeps one row per patrol — the lowest-id zone it
        // covers — so a start point sitting exactly on a shared edge (ST_Covers
        // true for both) resolves the same way every time.
        $sql = \sprintf(
            <<<'SQL'
                SELECT DISTINCT ON (p.%1$s) p.%2$s AS patrol_uuid, z.%3$s AS zone_name
                FROM %4$s p
                INNER JOIN %5$s z ON z.%6$s = :area AND ST_Covers(z.%7$s, ST_StartPoint(p.%8$s))
                WHERE p.%9$s = :area
                  AND p.%8$s IS NOT NULL
                  AND p.%10$s >= :from
                  AND p.%10$s < :until
                ORDER BY p.%1$s, z.%11$s
                SQL,
            $patrol->getSingleIdentifierColumnName(),
            $patrol->getColumnName('uuid'),
            $zoneMeta->getColumnName('name'),
            $patrol->getTableName(),
            $zoneMeta->getTableName(),
            $zoneMeta->getSingleAssociationJoinColumnName('area'),
            $zoneMeta->getColumnName('geom'),
            $patrol->getColumnName('track'),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $patrol->getColumnName('startedAt'),
            $zoneMeta->getSingleIdentifierColumnName(),
        );

        /** @var list<array{patrol_uuid: string, zone_name: string}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative($sql, [
            'area' => $area->getId(),
            'from' => $from,
            'until' => $until,
        ], [
            'area' => Types::INTEGER,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        $zones = [];
        foreach ($rows as $row) {
            // The uuid column comes back in Postgres's canonical hyphenated form,
            // which is exactly Uuid::toRfc4122() — the key the templates and the
            // coverage payload look a patrol up by.
            $zones[(string) $row['patrol_uuid']] = $row['zone_name'];
        }

        return $zones;
    }

    /**
     * WHO IS OUT RIGHT NOW — the area's patrols that have opened and not closed,
     * longest out first.
     *
     * `recording` IS "out": it is the status a patrol holds between the field
     * app opening it and the app completing it, which is exactly the window the
     * overview's live card describes. It is deliberately the one status the rest
     * of this module counts nothing from
     * ({@see PatrolStatusEnum::isPresentable()}) — an open patrol has no
     * duration and no distance total yet, and those fields are ABSENT rather
     * than zero.
     *
     * @return list<Patrol>
     */
    public function findByAreaRecording(AreaOfInterest $area): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.status = :recording')
            ->setParameter('area', $area)
            ->setParameter('recording', PatrolStatusEnum::Recording)
            ->orderBy('p.startedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * The area's patrols that CLOSED inside a half-open window — "6 closed
     * today", by the only column that says when a patrol was closed.
     *
     * By `endedAt` and not by `startedAt`, which is the sibling
     * {@see self::findByAreaStartedBetween()} the calendar reads. The two answer
     * different questions and are allowed to disagree: a night patrol that
     * opened at 20:00 and closed at 04:00 is yesterday's opening and today's
     * closing, and a card headed "closed today" that left it out because it
     * began before midnight would be quietly wrong about the night shift.
     *
     * Discarded patrols come back with the rest and are filtered by the caller
     * through {@see PatrolStatusEnum::countsTowardsStatistics()}, which is the
     * one predicate every "does this count" decision in this module goes
     * through.
     *
     * @return list<Patrol>
     */
    public function findByAreaEndedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.area = :area')
            ->andWhere('p.endedAt >= :from')
            ->andWhere('p.endedAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('p.endedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * HOW MANY PATROLS WERE OUT AT ONE INSTANT, over a set of areas — the live
     * "out right now" figure, and the same question asked of a moment that has
     * passed.
     *
     * "OUT AT `$at`" IS READ FROM THE CLOCK, NOT FROM THE STATUS, because a
     * status only ever describes now: a patrol that closed in March is
     * `complete` today and was nonetheless out on the fourth of March. So the
     * test is the one the record itself supports — it had opened, and it had
     * not closed:
     *
     * - it started at or before `$at`;
     * - and either it closed after `$at`, or it has no close at all AND is
     *   still `recording`, which is the one state in which a missing `endedAt`
     *   means "still out" rather than "never written down".
     *
     * A DISCARDED PATROL WAS NEVER OUT. A discard withdraws the whole outing,
     * exactly as it does for every other figure this module publishes, so it is
     * excluded here too rather than counted as somebody in the field.
     *
     * Asked at `$at = now`, this is precisely the set
     * {@see self::findByAreaRecording()} returns, counted over several areas at
     * once.
     *
     * @param list<AreaOfInterest> $areas
     */
    public function countOutAt(array $areas, \DateTimeImmutable $at): int
    {
        if ([] === $areas) {
            return 0;
        }

        $total = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.area IN (:areas)')
            ->andWhere('p.status != :discarded')
            ->andWhere('p.startedAt IS NOT NULL')
            ->andWhere('p.startedAt <= :at')
            ->andWhere('p.endedAt > :at OR (p.endedAt IS NULL AND p.status = :recording)')
            ->setParameter('areas', $areas)
            ->setParameter('at', $at)
            ->setParameter('discarded', PatrolStatusEnum::Discarded)
            ->setParameter('recording', PatrolStatusEnum::Recording)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }

    /**
     * Every discarded patrol whose retention clock is RUNNING — the purge
     * command's working set.
     *
     * Deliberately not filtered by age in SQL. "Older than the window" is
     * measured from {@see Patrol::discardedAt()}, which reads the last
     * `discarded` EVENT and falls back through `endedAt` to `createdAt`; that is
     * a three-way coalesce across a joined table, and expressing it here would
     * put the definition of "when it was discarded" in two places that could
     * drift. The set is small by construction — a deployment's undeleted
     * discards, minus the held ones — so the age test is done in PHP where it is
     * defined once and unit-testable.
     *
     * @return list<Patrol>
     */
    public function findDiscardedNotHeld(): array
    {
        /** @var list<Patrol> $patrols */
        $patrols = $this->createQueryBuilder('p')
            ->andWhere('p.status = :discarded')
            ->andWhere('p.heldAt IS NULL')
            ->setParameter('discarded', PatrolStatusEnum::Discarded)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $patrols;
    }

    /**
     * WHAT ENTERED EACH ZONE IN A WINDOW — for every zone the installation has,
     * the complete patrols whose track crossed into it and the metres they ran
     * inside. One statement for every zone; the worker asks it, never a page.
     *
     * "Entered" is ST_Intersects against the core's zone polygon, never the
     * patrol's station, which is a word and no evidence anybody crossed
     * anything. Only COMPLETE patrols count, for the reason every figure here
     * gives: a discard withdraws the effort, a recording track is still
     * arriving.
     *
     * @see https://postgis.net/docs/ST_Intersects.html
     *
     * @return array<string, array{patrols: int, metres: float}> zone uuid to its figures, every zone present
     */
    public function zoneEntriesBetween(\DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $zoneMeta = $entityManager->getClassMetadata(Zone::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT CAST(z.%1$s AS TEXT) AS zone_uuid,
                       COUNT(p.%2$s) AS patrols,
                       COALESCE(SUM(ST_Length(ST_Intersection(p.%3$s, z.%4$s)::geography)), 0) AS metres
                FROM %5$s z
                LEFT JOIN %6$s p ON p.%7$s = z.%8$s
                    AND p.%3$s IS NOT NULL
                    AND p.%9$s = :counted
                    AND p.%10$s >= :from
                    AND p.%10$s < :until
                    AND ST_Intersects(p.%3$s, z.%4$s)
                GROUP BY z.%11$s, z.%1$s
                SQL,
            $zoneMeta->getColumnName('uuid'),
            $patrol->getSingleIdentifierColumnName(),
            $patrol->getColumnName('track'),
            $zoneMeta->getColumnName('geom'),
            $zoneMeta->getTableName(),
            $patrol->getTableName(),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $zoneMeta->getSingleAssociationJoinColumnName('area'),
            $patrol->getColumnName('status'),
            $patrol->getColumnName('startedAt'),
            $zoneMeta->getSingleIdentifierColumnName(),
        );

        /** @var list<array{zone_uuid: string, patrols: int|string, metres: float|string}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative($sql, [
            'counted' => PatrolStatusEnum::Complete->value,
            'from' => $from,
            'until' => $until,
        ], [
            'counted' => Types::STRING,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        $entries = [];
        foreach ($rows as $row) {
            $entries[$row['zone_uuid']] = ['patrols' => (int) $row['patrols'], 'metres' => (float) $row['metres']];
        }

        return $entries;
    }

    /**
     * THE LAST COMPLETE PATROL WHOSE TRACK ENTERED EACH ZONE, started before
     * an instant — every zone the installation has, with null for a zone no
     * track has ever entered.
     *
     * ALL OF HISTORY, deliberately: a zone nobody has entered for eleven
     * months is exactly the gap this answers, and a window would hide it. So
     * it runs in the worker, which files the answer; a page reads the filed
     * instant and counts the days itself.
     *
     * @return array<string, array{patrolId: int, enteredAt: \DateTimeImmutable}|null> zone uuid to its last entry
     */
    public function zoneLastEntriesBefore(\DateTimeImmutable $until): array
    {
        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $zoneMeta = $entityManager->getClassMetadata(Zone::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT CAST(z.%1$s AS TEXT) AS zone_uuid,
                       entered.patrol_id,
                       entered.entered_at
                FROM %2$s z
                LEFT JOIN LATERAL (
                    SELECT p.%3$s AS patrol_id, p.%4$s AS entered_at
                    FROM %5$s p
                    WHERE p.%6$s = z.%7$s
                      AND p.%8$s IS NOT NULL
                      AND p.%9$s = :counted
                      AND p.%4$s < :until
                      AND ST_Intersects(p.%8$s, z.%10$s)
                    ORDER BY p.%4$s DESC, p.%3$s DESC
                    LIMIT 1
                ) entered ON TRUE
                SQL,
            $zoneMeta->getColumnName('uuid'),
            $zoneMeta->getTableName(),
            $patrol->getSingleIdentifierColumnName(),
            $patrol->getColumnName('startedAt'),
            $patrol->getTableName(),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $zoneMeta->getSingleAssociationJoinColumnName('area'),
            $patrol->getColumnName('track'),
            $patrol->getColumnName('status'),
            $zoneMeta->getColumnName('geom'),
        );

        /** @var list<array{zone_uuid: string, patrol_id: int|string|null, entered_at: string|null}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative($sql, [
            'counted' => PatrolStatusEnum::Complete->value,
            'until' => $until,
        ], [
            'counted' => Types::STRING,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        $last = [];
        foreach ($rows as $row) {
            $last[$row['zone_uuid']] = null === $row['patrol_id'] || null === $row['entered_at']
                ? null
                : ['patrolId' => (int) $row['patrol_id'], 'enteredAt' => new \DateTimeImmutable($row['entered_at'])];
        }

        return $last;
    }

    /**
     * WHAT WENT OUT OF EACH OF A SET OF STATIONS in a half-open window — how
     * many complete patrols started there and how far they went in total.
     *
     * ONE STATEMENT FOR THE WHOLE SET, for the reason
     * {@see self::zoneEntriesBetween()} is one: the station seam is asked once for
     * every post a page draws. Stations are the AREA module's and are
     * addressed by their published uuid; this module's own station records are
     * a different table and a different vocabulary.
     *
     * TWO WAYS A PATROL IS ATTRIBUTED TO A POST, because this module does not
     * yet hold a reference to the area's station and both of these are things
     * it can honestly know today:
     *
     *  1. BY NAME. The patrol's own station text — the label of the module's
     *     station record — equals the area station's name, compared trimmed and
     *     case-folded. An installation types the same post into both lists, and
     *     that identity of words is the evidence.
     *  2. BY THE FIRST FIX, and only where the patrol names no station at all.
     *     A patrol whose track begins within $proximityMetres of the post's
     *     point set off from it; a patrol that named a post is attributed to
     *     THAT post even when it started beside another, because a person's
     *     word beats a coordinate.
     *
     * A patrol matching neither is counted nowhere, and a patrol may be counted
     * at one post only: the name rule matches at most one post (the area's
     * names are unique within it) and the proximity rule is asked only where
     * there is no name, so the two cannot both fire for one row.
     *
     * ONLY COMPLETE PATROLS COUNT, as everywhere else in this repository: a
     * discard says the effort did not happen as recorded, and a recording
     * patrol has not finished arriving.
     *
     * THE DISTANCE IS THE PATROL'S OWN RECORDED FIGURE, summed — the same
     * `distanceKm` the department figures add up — and a patrol that recorded
     * none adds nothing rather than having a length invented from its track.
     *
     * `areaRecorded` says whether the post's area recorded ANY complete patrol
     * in the window, which is what tells a measured naught from an unmeasured
     * one: a post that launched nothing in a month the area patrolled reads
     * zero, and every post of an area that recorded nothing at all is unknown.
     *
     * @param list<string> $stationUuids
     *
     * @return array<string, array{patrols: int, distanceKm: float, areaRecorded: bool}> station uuid to its figures
     */
    public function stationFiguresFor(array $stationUuids, float $proximityMetres, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ([] === $stationUuids) {
            return [];
        }

        $entityManager = $this->getEntityManager();
        $patrol = $this->getClassMetadata();
        $stationMeta = $entityManager->getClassMetadata(AreaStation::class);

        // The uuid is compared as TEXT so the column may be a native uuid or a
        // string: which one it is belongs to whoever mapped it, not here.
        $sql = \sprintf(
            <<<'SQL'
                WITH asked AS (
                    SELECT s.%1$s AS id, CAST(s.%2$s AS TEXT) AS uuid, s.%3$s AS name, s.%4$s AS point, s.%5$s AS area_id
                    FROM %6$s s
                    WHERE CAST(s.%2$s AS TEXT) IN (:stations)
                ),
                recorded AS (
                    SELECT p.%7$s AS area_id, COUNT(*) AS patrols
                    FROM %8$s p
                    WHERE p.%7$s IN (SELECT DISTINCT area_id FROM asked)
                      AND p.%9$s = :counted
                      AND p.%10$s >= :from
                      AND p.%10$s < :until
                    GROUP BY p.%7$s
                ),
                started AS (
                    SELECT a.id AS station_id,
                           COUNT(*) AS patrols,
                           SUM(COALESCE(p.%11$s, 0)) AS km
                    FROM asked a
                    INNER JOIN %8$s p ON p.%7$s = a.area_id
                    WHERE p.%9$s = :counted
                      AND p.%10$s >= :from
                      AND p.%10$s < :until
                      AND (
                          p.%12$s = a.id
                          OR (
                              p.%12$s IS NULL
                              AND LOWER(BTRIM(COALESCE(p.%13$s, ''))) = LOWER(BTRIM(a.name))
                          )
                          OR (
                              p.%12$s IS NULL
                              AND COALESCE(BTRIM(p.%13$s), '') = ''
                              AND p.%14$s IS NOT NULL
                              AND ST_DWithin(
                                  ST_StartPoint(ST_GeometryN(p.%14$s, 1))::geography,
                                  a.point::geography,
                                  :metres
                              )
                          )
                      )
                    GROUP BY a.id
                )
                SELECT a.uuid AS station_uuid,
                       COALESCE(s.patrols, 0) AS patrols,
                       COALESCE(s.km, 0) AS km,
                       COALESCE(r.patrols, 0) AS area_patrols
                FROM asked a
                LEFT JOIN started s ON s.station_id = a.id
                LEFT JOIN recorded r ON r.area_id = a.area_id
                SQL,
            $stationMeta->getSingleIdentifierColumnName(),
            $stationMeta->getColumnName('uuid'),
            $stationMeta->getColumnName('name'),
            $stationMeta->getColumnName('point'),
            $stationMeta->getSingleAssociationJoinColumnName('area'),
            $stationMeta->getTableName(),
            $patrol->getSingleAssociationJoinColumnName('area'),
            $patrol->getTableName(),
            $patrol->getColumnName('status'),
            $patrol->getColumnName('startedAt'),
            $patrol->getColumnName('distanceKm'),
            $patrol->getSingleAssociationJoinColumnName('stationRecord'),
            $patrol->getColumnName('station'),
            $patrol->getColumnName('track'),
        );

        /** @var list<array{station_uuid: string, patrols: int|string, km: float|string, area_patrols: int|string}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative($sql, [
            'stations' => $stationUuids,
            'metres' => $proximityMetres,
            'counted' => PatrolStatusEnum::Complete->value,
            'from' => $from,
            'until' => $until,
        ], [
            'stations' => ArrayParameterType::STRING,
            'metres' => Types::FLOAT,
            'counted' => Types::STRING,
            'from' => Types::DATETIME_IMMUTABLE,
            'until' => Types::DATETIME_IMMUTABLE,
        ]);

        $figures = [];
        foreach ($rows as $row) {
            $figures[$row['station_uuid']] = [
                'patrols' => (int) $row['patrols'],
                'distanceKm' => (float) $row['km'],
                'areaRecorded' => 0 < (int) $row['area_patrols'],
            ];
        }

        return $figures;
    }
}
