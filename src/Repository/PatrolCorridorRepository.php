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
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolCorridor;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;

/**
 * THE STORED CORRIDORS: buffering a patrol's track once, and every coverage
 * figure read from the shapes that buffering left.
 *
 * Raw SQL throughout — DQL has no ST_Buffer, ST_Union or ST_Intersection —
 * with every table and column read from Doctrine's metadata rather than
 * spelled out, because the area and zone tables are the core's and may be
 * mapped under another naming strategy than this module's tests use.
 *
 * WHAT COUNTS is the rule every coverage figure in this module keeps: only a
 * COMPLETE patrol's corridor reaches a union. A discarded patrol says the
 * effort did not happen as recorded; a recording one has not finished
 * arriving. A corridor is kept for either — it is only the union that
 * filters — so a discard undone is counted again without buffering anything.
 *
 * @see https://postgis.net/docs/ST_Buffer.html — a geography buffer is in metres on the spheroid
 * @see https://postgis.net/docs/ST_Union.html — the aggregate form, so overlapping corridors are counted once
 * @see https://www.postgresql.org/docs/current/queries-with.html#QUERIES-WITH-CTE-MATERIALIZATION — MATERIALIZED computes a union once, not once per row that reads it
 * @see https://www.postgresql.org/docs/current/sql-insert.html#SQL-ON-CONFLICT — one statement that writes or replaces a patrol's corridor
 *
 * @extends ServiceEntityRepository<PatrolCorridor>
 */
final class PatrolCorridorRepository extends ServiceEntityRepository
{
    /**
     * The tolerance a drawn union is simplified to, in degrees: roughly 11 m
     * at the equator, under a screen pixel at area zoom. Drawing only — every
     * figure is measured on the shapes as stored.
     *
     * @see https://postgis.net/docs/ST_SimplifyPreserveTopology.html
     */
    public const string SIMPLIFY_DEGREES = '0.0001';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatrolCorridor::class);
    }

    /**
     * BUFFER ONE PATROL'S TRACK AND KEEP IT — at its type's width (the
     * fallback where the type sets none) and at the module's one width.
     *
     * A patrol with no track keeps no corridor: a stored one is removed, so a
     * track taken away is not still counted. Idempotent: a second run replaces
     * the first's shapes.
     *
     * @return bool whether the patrol has a corridor now
     */
    public function buffer(int $patrolId, int $fallbackWidthM, int $uniformWidthM, \DateTimeImmutable $now): bool
    {
        $c = $this->getClassMetadata();
        $p = $this->getEntityManager()->getClassMetadata(Patrol::class);
        $t = $this->getEntityManager()->getClassMetadata(PatrolType::class);
        $connection = $this->getEntityManager()->getConnection();

        // THE BUFFER IS TAKEN ONCE PER WIDTH. The lateral computes the type's
        // shape once; the uniform shape reuses it where the two widths agree,
        // which a type left at the module's width does.
        $sql = \sprintf(
            <<<'SQL'
                INSERT INTO %1$s (%2$s, %3$s, %4$s, %5$s, %6$s, %7$s, %8$s)
                SELECT p.%9$s,
                       b.geom,
                       w.width,
                       CASE WHEN w.width = :uniform THEN b.geom
                            ELSE ST_Multi(ST_Buffer(p.%10$s::geography, :uniform)::geometry)
                       END,
                       :uniform,
                       COALESCE(p.%11$s, 0),
                       :now
                FROM %12$s p
                LEFT JOIN %13$s t ON t.%14$s = p.%15$s
                CROSS JOIN LATERAL (SELECT COALESCE(t.%16$s, :fallback) AS width) w
                CROSS JOIN LATERAL (SELECT ST_Multi(ST_Buffer(p.%10$s::geography, w.width)::geometry) AS geom) b
                WHERE p.%9$s = :patrol
                  AND p.%10$s IS NOT NULL
                ON CONFLICT (%2$s) DO UPDATE
                   SET %3$s = EXCLUDED.%3$s,
                       %4$s = EXCLUDED.%4$s,
                       %5$s = EXCLUDED.%5$s,
                       %6$s = EXCLUDED.%6$s,
                       %7$s = EXCLUDED.%7$s,
                       %8$s = EXCLUDED.%8$s
                SQL,
            $c->getTableName(),
            $c->getSingleAssociationJoinColumnName('patrol'),
            $c->getColumnName('geom'),
            $c->getColumnName('widthM'),
            $c->getColumnName('uniformGeom'),
            $c->getColumnName('uniformWidthM'),
            $c->getColumnName('pointCount'),
            $c->getColumnName('computedAt'),
            $p->getSingleIdentifierColumnName(),
            $p->getColumnName('track'),
            $p->getColumnName('pointCount'),
            $p->getTableName(),
            $t->getTableName(),
            $t->getSingleIdentifierColumnName(),
            $p->getSingleAssociationJoinColumnName('patrolType'),
            $t->getColumnName('coverageBufferM'),
        );

        $written = $connection->executeStatement($sql, [
            'patrol' => $patrolId,
            'fallback' => $fallbackWidthM,
            'uniform' => $uniformWidthM,
            'now' => $now,
        ], [
            'patrol' => ParameterType::INTEGER,
            'fallback' => ParameterType::INTEGER,
            'uniform' => ParameterType::INTEGER,
            'now' => Types::DATETIME_IMMUTABLE,
        ]);

        if ($written > 0) {
            return true;
        }

        $connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE %s = :patrol', $c->getTableName(), $c->getSingleAssociationJoinColumnName('patrol')),
            ['patrol' => $patrolId],
            ['patrol' => ParameterType::INTEGER],
        );

        return false;
    }

    /**
     * THE NEXT COMPLETE PATROLS WHOSE CORRIDOR IS MISSING OR STALE, by id,
     * after the one given — a batch for the rebuild and the hourly catch-up.
     *
     * Stale is measured, not guessed: a corridor buffered at a width the
     * patrol's type no longer has, at a module width that changed, or from a
     * track with a different number of fixes than the patrol holds now.
     *
     * @param bool $all every complete patrol with a track, stale or not — the rebuild's --all
     *
     * @return list<int>
     */
    public function findPatrolIdsToBuffer(int $fallbackWidthM, int $uniformWidthM, int $afterId, int $limit, bool $all = false, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): array
    {
        $c = $this->getClassMetadata();
        $p = $this->getEntityManager()->getClassMetadata(Patrol::class);
        $t = $this->getEntityManager()->getClassMetadata(PatrolType::class);

        $window = null === $from || null === $until ? '' : \sprintf(' AND p.%1$s >= :from AND p.%1$s < :until', $p->getColumnName('startedAt'));
        $stale = $all ? '' : \sprintf(
            ' AND (c.%1$s IS NULL OR c.%2$s <> COALESCE(p.%3$s, 0) OR c.%4$s <> COALESCE(t.%5$s, :fallback) OR c.%6$s <> :uniform)',
            $c->getSingleAssociationJoinColumnName('patrol'),
            $c->getColumnName('pointCount'),
            $p->getColumnName('pointCount'),
            $c->getColumnName('widthM'),
            $t->getColumnName('coverageBufferM'),
            $c->getColumnName('uniformWidthM'),
        );

        $sql = \sprintf(
            <<<'SQL'
                SELECT p.%1$s
                FROM %2$s p
                LEFT JOIN %3$s t ON t.%4$s = p.%5$s
                LEFT JOIN %6$s c ON c.%7$s = p.%1$s
                WHERE p.%8$s IS NOT NULL
                  AND p.%9$s = :counted
                  AND p.%1$s > :after%10$s%11$s
                ORDER BY p.%1$s
                LIMIT %12$d
                SQL,
            $p->getSingleIdentifierColumnName(),
            $p->getTableName(),
            $t->getTableName(),
            $t->getSingleIdentifierColumnName(),
            $p->getSingleAssociationJoinColumnName('patrolType'),
            $c->getTableName(),
            $c->getSingleAssociationJoinColumnName('patrol'),
            $p->getColumnName('track'),
            $p->getColumnName('status'),
            $window,
            $stale,
            max(1, $limit),
        );

        $parameters = ['counted' => PatrolStatusEnum::Complete->value, 'after' => $afterId];
        $types = ['counted' => Types::STRING, 'after' => ParameterType::INTEGER];
        if (!$all) {
            $parameters += ['fallback' => $fallbackWidthM, 'uniform' => $uniformWidthM];
            $types += ['fallback' => ParameterType::INTEGER, 'uniform' => ParameterType::INTEGER];
        }
        if ('' !== $window) {
            $parameters += ['from' => $from, 'until' => $until];
            $types += ['from' => Types::DATETIME_IMMUTABLE, 'until' => Types::DATETIME_IMMUTABLE];
        }

        return array_map(
            static fn (mixed $id): int => (int) $id, // @phpstan-ignore cast.int (a PostgreSQL integer column)
            $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, $parameters, $types),
        );
    }

    /**
     * THE SHARE OF EVERY ZONE AND EVERY AREA THE WINDOW'S CORRIDORS COVER, as
     * fractions of 1 — one statement for the whole installation, each area's
     * union computed once and clipped per zone.
     *
     * A zone is covered by its area's union, not only by the tracks that
     * entered it: a round walked along a zone's edge covers ground inside it
     * at the patrol's width. `typed` is the union of the type-width corridors,
     * `uniform` the union at the module's one width. An area's share is the
     * uniform one, over its own boundary.
     *
     * NULL, never 0, where there is nothing to measure: an area whose window
     * holds no corridor at all, or ground with no measurable surface. A zone
     * whose area has corridors elsewhere is a MEASURED nought.
     *
     * @return array{zones: array<string, array{area: string, typed: float|null, uniform: float|null}>, areas: array<string, float|null>} keyed by published uuid
     */
    public function coverageBetween(\DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $em = $this->getEntityManager();
        $c = $this->getClassMetadata();
        $p = $em->getClassMetadata(Patrol::class);
        $z = $em->getClassMetadata(Zone::class);
        $a = $em->getClassMetadata(AreaOfInterest::class);

        $sql = \sprintf(
            <<<'SQL'
                WITH covered AS MATERIALIZED (
                    SELECT p.%1$s AS area_id,
                           ST_Union(c.%2$s) AS typed,
                           ST_Union(c.%3$s) AS uniform
                    FROM %4$s c
                    INNER JOIN %5$s p ON p.%6$s = c.%7$s
                    WHERE p.%8$s = :counted
                      AND p.%9$s >= :from
                      AND p.%9$s < :until
                    GROUP BY p.%1$s
                )
                SELECT 'zone' AS kind,
                       CAST(z.%10$s AS TEXT) AS uuid,
                       CAST(a.%11$s AS TEXT) AS area_uuid,
                       ST_Area(ST_Intersection(cv.typed, z.%12$s)::geography) / NULLIF(ST_Area(z.%12$s::geography), 0) AS typed,
                       ST_Area(ST_Intersection(cv.uniform, z.%12$s)::geography) / NULLIF(ST_Area(z.%12$s::geography), 0) AS uniform
                FROM %13$s z
                INNER JOIN %14$s a ON a.%15$s = z.%16$s
                LEFT JOIN covered cv ON cv.area_id = z.%16$s
                UNION ALL
                SELECT 'area',
                       CAST(a.%11$s AS TEXT),
                       CAST(a.%11$s AS TEXT),
                       NULL,
                       CASE WHEN a.%17$s IS NULL THEN NULL
                            ELSE ST_Area(ST_Intersection(cv.uniform, a.%17$s)::geography) / NULLIF(ST_Area(a.%17$s::geography), 0)
                       END
                FROM %14$s a
                LEFT JOIN covered cv ON cv.area_id = a.%15$s
                SQL,
            $p->getSingleAssociationJoinColumnName('area'),
            $c->getColumnName('geom'),
            $c->getColumnName('uniformGeom'),
            $c->getTableName(),
            $p->getTableName(),
            $p->getSingleIdentifierColumnName(),
            $c->getSingleAssociationJoinColumnName('patrol'),
            $p->getColumnName('status'),
            $p->getColumnName('startedAt'),
            $z->getColumnName('uuid'),
            $a->getColumnName('uuid'),
            $z->getColumnName('geom'),
            $z->getTableName(),
            $a->getTableName(),
            $a->getSingleIdentifierColumnName(),
            $z->getSingleAssociationJoinColumnName('area'),
            $a->getColumnName('geom'),
        );

        /** @var list<array{kind: string, uuid: string|null, area_uuid: string|null, typed: float|string|null, uniform: float|string|null}> $rows */
        $rows = $em->getConnection()->fetchAllAssociative($sql, self::window($from, $until), self::windowTypes());

        $coverage = ['zones' => [], 'areas' => []];
        foreach ($rows as $row) {
            if (null === $row['uuid'] || null === $row['area_uuid']) {
                continue;
            }
            if ('area' === $row['kind']) {
                $coverage['areas'][$row['uuid']] = self::fraction($row['uniform']);
                continue;
            }
            $coverage['zones'][$row['uuid']] = [
                'area' => $row['area_uuid'],
                'typed' => self::fraction($row['typed']),
                'uniform' => self::fraction($row['uniform']),
            ];
        }

        return $coverage;
    }

    /**
     * THE SHARE OF ONE AREA WITHIN THE MODULE'S ONE WIDTH OF A COMPLETE
     * PATROL'S TRACK in a window, as a fraction of 1 — the union of the stored
     * uniform corridors, clipped to the boundary, over the boundary.
     *
     * Null where there is nothing to measure: no corridor in the window, or no
     * stored boundary.
     */
    public function fractionWithin(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $rows = $this->perArea($from, $until, $area);

        return null === ($rows[0] ?? null) ? null : ($rows[0]['total'] > 0 ? $rows[0]['covered'] / $rows[0]['total'] : null);
    }

    /**
     * THE SAME SHARE ROLLED UP ACROSS EVERY AREA as one ratio — ground covered
     * in each area that has a corridor in the window, summed, over those
     * areas' boundaries, summed. Not a mean of shares, which would let a small
     * area outvote a large one. Null where no area has anything to measure.
     */
    public function fractionAcrossAreas(\DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $covered = 0.0;
        $total = 0.0;
        foreach ($this->perArea($from, $until) as $row) {
            $covered += $row['covered'];
            $total += $row['total'];
        }

        return $total > 0 ? $covered / $total : null;
    }

    /**
     * THE GROUND THE WINDOW'S CORRIDORS COVER IN ONE AREA, as GeoJSON — the
     * type-width corridors unioned and clipped to the boundary, for the
     * coverage plate to draw. Null where the window holds no corridor, so the
     * layer draws nothing and still ships its legend row.
     *
     * @param bool $simplify false for the shape exactly as stored
     */
    public function coveredGeoJson(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $until, bool $simplify = true): ?string
    {
        $em = $this->getEntityManager();
        $c = $this->getClassMetadata();
        $p = $em->getClassMetadata(Patrol::class);
        $a = $em->getClassMetadata(AreaOfInterest::class);

        $covered = \sprintf(
            'CASE WHEN a.%1$s IS NULL THEN ST_Union(c.%2$s) ELSE ST_Intersection(ST_Union(c.%2$s), a.%1$s) END',
            $a->getColumnName('geom'),
            $c->getColumnName('geom'),
        );

        $sql = \sprintf(
            <<<'SQL'
                SELECT ST_AsGeoJSON(%1$s) AS geojson
                FROM %2$s a
                INNER JOIN %3$s p ON p.%4$s = a.%5$s
                INNER JOIN %6$s c ON c.%7$s = p.%8$s
                WHERE a.%5$s = :area
                  AND p.%9$s = :counted
                  AND p.%10$s >= :from
                  AND p.%10$s < :until
                GROUP BY a.%5$s, a.%11$s
                SQL,
            $simplify ? \sprintf('ST_SimplifyPreserveTopology(%s, %s)', $covered, self::SIMPLIFY_DEGREES) : $covered,
            $a->getTableName(),
            $p->getTableName(),
            $p->getSingleAssociationJoinColumnName('area'),
            $a->getSingleIdentifierColumnName(),
            $c->getTableName(),
            $c->getSingleAssociationJoinColumnName('patrol'),
            $p->getSingleIdentifierColumnName(),
            $p->getColumnName('status'),
            $p->getColumnName('startedAt'),
            $a->getColumnName('geom'),
        );

        $geoJson = $em->getConnection()->fetchOne(
            $sql,
            ['area' => $area->getId()] + self::window($from, $until),
            ['area' => ParameterType::INTEGER] + self::windowTypes(),
        );

        return \is_string($geoJson) ? $geoJson : null;
    }

    /**
     * Each area with a boundary and a corridor in the window: the covered and
     * the whole surface, in square metres.
     *
     * @return list<array{covered: float, total: float}>
     */
    private function perArea(\DateTimeImmutable $from, \DateTimeImmutable $until, ?AreaOfInterest $only = null): array
    {
        $em = $this->getEntityManager();
        $c = $this->getClassMetadata();
        $p = $em->getClassMetadata(Patrol::class);
        $a = $em->getClassMetadata(AreaOfInterest::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT ST_Area(ST_Intersection(ST_Union(c.%1$s), a.%2$s)::geography) AS covered,
                       ST_Area(a.%2$s::geography) AS total
                FROM %3$s a
                INNER JOIN %4$s p ON p.%5$s = a.%6$s
                INNER JOIN %7$s c ON c.%8$s = p.%9$s
                WHERE a.%2$s IS NOT NULL
                  AND p.%10$s = :counted
                  AND p.%11$s >= :from
                  AND p.%11$s < :until%12$s
                GROUP BY a.%6$s, a.%2$s
                SQL,
            $c->getColumnName('uniformGeom'),
            $a->getColumnName('geom'),
            $a->getTableName(),
            $p->getTableName(),
            $p->getSingleAssociationJoinColumnName('area'),
            $a->getSingleIdentifierColumnName(),
            $c->getTableName(),
            $c->getSingleAssociationJoinColumnName('patrol'),
            $p->getSingleIdentifierColumnName(),
            $p->getColumnName('status'),
            $p->getColumnName('startedAt'),
            null === $only ? '' : \sprintf(' AND a.%s = :area', $a->getSingleIdentifierColumnName()),
        );

        $parameters = self::window($from, $until);
        $types = self::windowTypes();
        if (null !== $only) {
            $parameters['area'] = $only->getId();
            $types['area'] = ParameterType::INTEGER;
        }

        /** @var list<array{covered: float|string|null, total: float|string|null}> $rows */
        $rows = $em->getConnection()->fetchAllAssociative($sql, $parameters, $types);

        return array_map(static fn (array $row): array => [
            'covered' => is_numeric($row['covered']) ? (float) $row['covered'] : 0.0,
            'total' => is_numeric($row['total']) ? (float) $row['total'] : 0.0,
        ], $rows);
    }

    /** @return array{counted: string, from: \DateTimeImmutable, until: \DateTimeImmutable} */
    private static function window(\DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        return ['counted' => PatrolStatusEnum::Complete->value, 'from' => $from, 'until' => $until];
    }

    /** @return array{counted: string, from: string, until: string} */
    private static function windowTypes(): array
    {
        // Bound as Doctrine types, so the window is written exactly the way
        // the ORM wrote started_at.
        return ['counted' => Types::STRING, 'from' => Types::DATETIME_IMMUTABLE, 'until' => Types::DATETIME_IMMUTABLE];
    }

    private static function fraction(float|string|null $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
