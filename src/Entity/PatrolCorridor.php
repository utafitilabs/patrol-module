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

namespace Uhifadhi\Patrol\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;

/**
 * THE GROUND ONE PATROL'S TRACK COVERED — its track buffered once, by the
 * worker, and kept.
 *
 * A coverage figure is a union of buffered tracks. Buffering is the expensive
 * half: a long track is thousands of fixes, and buffering it in geography
 * projects every one of them. So a patrol is buffered ONCE, when it settles,
 * and every figure after that unions the stored shapes instead of the tracks.
 * No page buffers a track.
 *
 * TWO SHAPES, because the module measures coverage two ways and both are kept
 * as they are:
 *  - {@see $geom} at the patrol TYPE's own width (a walk sees less either side
 *    than a flight), the module's width standing in where a type sets none —
 *    what the coverage plate draws and the zone figures report;
 *  - {@see $uniformGeom} at the module's one width for every patrol — what the
 *    "within 2 km" coverage KPI and the gaps card report.
 * Where the two widths are the same the shape is computed once and stored twice.
 *
 * STALE IS DETECTABLE: the widths and the patrol's fix count it was measured
 * from are kept beside the shapes, so a type whose width changed or a track
 * that grew after a late upload is found and buffered again by
 * `patrol:coverage:rebuild` and by the hourly run, without a timestamp race.
 *
 * WRITTEN BY SQL ONLY ({@see PatrolCorridorRepository::buffer()}): the buffer
 * is a PostGIS operation over the patrol's own row, and pulling a long track
 * through PHP to hand it straight back would be the cost this table exists to
 * avoid. The mapping is here so the schema, its migration and its spatial
 * indexes are Doctrine's.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/composite-primary-keys.html#identity-through-foreign-entities — a to-one association as the identifier
 * @see vendor/utafitilabs/postgis-bundle/src/Types/MultiPolygonType.php — `geometry(MultiPolygon, 4326)`
 * @see vendor/utafitilabs/postgis-bundle/src/EventListener/SpatialSchemaListener.php — the GiST index on each shape
 */
#[ORM\Entity(repositoryClass: PatrolCorridorRepository::class)]
#[ORM\Table(name: 'patrol_corridor')]
class PatrolCorridor
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Patrol::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /** The track buffered at its type's width, as GeoJSON text. */
    #[ORM\Column(type: 'multipolygon')]
    private string $geom;

    /** The width {@see $geom} was buffered at, in metres. */
    #[ORM\Column]
    private int $widthM;

    /** The track buffered at the module's one width, as GeoJSON text. */
    #[ORM\Column(type: 'multipolygon')]
    private string $uniformGeom;

    /** The width {@see $uniformGeom} was buffered at, in metres. */
    #[ORM\Column]
    private int $uniformWidthM;

    /** The patrol's fix count when it was buffered: a different count now means the track changed. */
    #[ORM\Column]
    private int $pointCount;

    #[ORM\Column]
    private \DateTimeImmutable $computedAt;

    public function __construct(Patrol $patrol, string $geom, int $widthM, string $uniformGeom, int $uniformWidthM, int $pointCount, \DateTimeImmutable $computedAt)
    {
        $this->patrol = $patrol;
        $this->geom = $geom;
        $this->widthM = $widthM;
        $this->uniformGeom = $uniformGeom;
        $this->uniformWidthM = $uniformWidthM;
        $this->pointCount = $pointCount;
        $this->computedAt = $computedAt;
    }

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getGeom(): string
    {
        return $this->geom;
    }

    public function getWidthM(): int
    {
        return $this->widthM;
    }

    public function getUniformGeom(): string
    {
        return $this->uniformGeom;
    }

    public function getUniformWidthM(): int
    {
        return $this->uniformWidthM;
    }

    public function getPointCount(): int
    {
        return $this->pointCount;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }
}
