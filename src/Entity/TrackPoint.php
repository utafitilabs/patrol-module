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
use Uhifadhi\Patrol\Repository\TrackPointRepository;

/**
 * A single GPS fix on a patrol — API-CONTRACT.md §5.
 *
 * Fixes are stored individually, with their own accuracy, rather than being
 * folded straight into a LINESTRING. The contract is explicit about why: the
 * app does NOT filter poor fixes out before upload (it only excludes them from
 * its own distance figure), so the module keeps everything and can re-derive
 * statistics later under a different accuracy rule. Throwing the metadata away
 * at ingest would make that impossible and would quietly turn one afternoon's
 * threshold choice into permanent history.
 *
 * The assembled route lives on {@see Patrol::getTrack()}; it is derived from
 * these rows, and these rows are the record.
 */
#[ORM\Entity(repositoryClass: TrackPointRepository::class)]
#[ORM\Table(name: 'patrol_track_point')]
// fields, not columns: the HOST owns the naming strategy, so the column
// names are not knowable here (see PatrolRepository::zoneEntriesBetween
// for the same reason spelled out at length).
#[ORM\Index(name: 'idx_patrol_track_point_patrol_time', fields: ['patrol', 'recordedAt'])]
class TrackPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'trackPoints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /** Which upload carried it — so a batch can be traced back to its request. */
    #[ORM\ManyToOne(targetEntity: TrackBatch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrackBatch $batch;

    /** Where the fix was, as GeoJSON Point text (postgis-bundle `point` type). */
    #[ORM\Column(type: 'point')]
    private string $position;

    /**
     * When the PHONE recorded it. Trusted verbatim: the handset may have been
     * offline for hours, and substituting our receive time would move a
     * morning's patrol to whenever the truck found signal (§1).
     */
    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    /** Reported horizontal accuracy in metres — kept per fix, never averaged away. */
    #[ORM\Column(nullable: true)]
    private ?float $accuracyM = null;

    #[ORM\Column(nullable: true)]
    private ?int $satellites = null;

    #[ORM\Column(nullable: true)]
    private ?float $elevationM = null;

    #[ORM\Column(nullable: true)]
    private ?float $speedMs = null;

    public function __construct(Patrol $patrol, TrackBatch $batch, string $position, \DateTimeImmutable $recordedAt)
    {
        $this->patrol = $patrol;
        $this->batch = $batch;
        $this->position = $position;
        $this->recordedAt = $recordedAt;
        $patrol->addTrackPoint($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getBatch(): TrackBatch
    {
        return $this->batch;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getAccuracyM(): ?float
    {
        return $this->accuracyM;
    }

    public function setAccuracyM(?float $accuracyM): static
    {
        $this->accuracyM = $accuracyM;

        return $this;
    }

    public function getSatellites(): ?int
    {
        return $this->satellites;
    }

    public function setSatellites(?int $satellites): static
    {
        $this->satellites = $satellites;

        return $this;
    }

    public function getElevationM(): ?float
    {
        return $this->elevationM;
    }

    public function setElevationM(?float $elevationM): static
    {
        $this->elevationM = $elevationM;

        return $this;
    }

    public function getSpeedMs(): ?float
    {
        return $this->speedMs;
    }

    public function setSpeedMs(?float $speedMs): static
    {
        $this->speedMs = $speedMs;

        return $this;
    }
}
