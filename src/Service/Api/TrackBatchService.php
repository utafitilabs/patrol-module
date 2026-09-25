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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\TrackBatch;
use Uhifadhi\Patrol\Entity\TrackPoint;
use Uhifadhi\Patrol\Repository\TrackBatchRepository;
use Uhifadhi\Patrol\Service\GeoService;
use Uhifadhi\Patrol\Service\PatrolCorridorQueue;

/**
 * `POST /api/patrols/{uuid}/track` — API-CONTRACT.md §5.
 *
 * Three rules do the real work here, and each exists to stop the module telling
 * a lie about coverage:
 *
 * 1. **A re-sent batch changes nothing.** The phone retries forever, and a
 *    doubled batch would draw a patrol walking the same ridge twice.
 * 2. **A drone patrol has no track.** The phone's fixes during one are the
 *    OPERATOR's position on the ground, not the aircraft's, so they are refused
 *    outright rather than quietly stored as coverage.
 * 3. **Batches may arrive out of order, or days apart.** The route is therefore
 *    rebuilt from every stored fix on each append, sorted by the time the PHONE
 *    recorded them — never by arrival order, which is an accident of signal.
 */
final class TrackBatchService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackBatchRepository $batches,
        private readonly GeoService $geo,
        private readonly float $gapThresholdMinutes,
        private readonly PatrolCorridorQueue $corridors,
    ) {
    }

    /**
     * Append one batch of fixes.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: list<string>, 1: bool} [acceptedUuids, wasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function append(Patrol $patrol, array $data): array
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        // Not a UUID: the contract's key is a composite the phone derives from
        // the patrol and the batch index ("8f1f…:track:3"), stored verbatim so a
        // retry matches byte for byte.
        $batchKey = Payload::requiredString($data, 'batchUuid');

        if ($patrol->isDrone()) {
            throw PatrolApiException::invalidTrackForDronePatrol($batchKey);
        }

        if ($this->batches->findOneByBatchKey($batchKey) instanceof TrackBatch) {
            return [[$batchKey], true];
        }

        $rows = Payload::rows($data, 'points');
        if ([] === $rows) {
            throw PatrolApiException::invalidPayload('A track batch needs at least one point.', ['batchUuid' => $batchKey]);
        }

        $batch = new TrackBatch($patrol, $batchKey);
        $this->entityManager->persist($batch);

        foreach ($rows as $row) {
            $point = new TrackPoint(
                $patrol,
                $batch,
                Payload::geoJsonPoint($row),
                Payload::requiredTimestamp($row, 'recordedAt'),
            )
                // Kept per fix and never used to filter: the app does not drop
                // poor fixes before upload, so the module can re-derive its
                // statistics later under a different rule (§5).
                ->setAccuracyM(Payload::float($row, 'accuracyM'))
                ->setSatellites(Payload::int($row, 'satellites'))
                ->setElevationM(Payload::float($row, 'elevationM'))
                ->setSpeedMs(Payload::float($row, 'speedMs'));

            $this->entityManager->persist($point);
        }

        $batch->setPointCount(\count($rows));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two copies of the same batch in flight at once; the unique index
            // on batch_key settled it. Losing that race is still success.
            return [[$batchKey], true];
        }

        $this->rebuildRoute($patrol);
        $this->entityManager->flush();

        // A batch that lands after the patrol completed changes a track that
        // may already be buffered; the worker buffers it again.
        $this->corridors->settled($patrol);

        return [[$batchKey], false];
    }

    /**
     * Re-derive the patrol's LINESTRING, distance, fix count and GPS gaps from
     * every fix now held.
     *
     * Rebuilt wholesale rather than appended to, because batch 3 can arrive
     * before batch 2 — appending would stitch the route in upload order and
     * draw a line that teleports back and forth across the area.
     */
    private function rebuildRoute(Patrol $patrol): void
    {
        $points = $patrol->getTrackPoints()->toArray();

        usort(
            $points,
            static fn (TrackPoint $a, TrackPoint $b): int => $a->getRecordedAt() <=> $b->getRecordedAt(),
        );

        $coordinates = [];
        $distanceKm = 0.0;
        $gapCount = 0;
        $previous = null;

        foreach ($points as $point) {
            [$lon, $lat] = $this->geo->coordinates($point->getPosition());
            $coordinates[] = [$lon, $lat];

            if (null !== $previous) {
                [$previousLon, $previousLat] = $this->geo->coordinates($previous->getPosition());
                $distanceKm += $this->geo->haversineKm($previousLat, $previousLon, $lat, $lon);

                $silence = $point->getRecordedAt()->getTimestamp() - $previous->getRecordedAt()->getTimestamp();
                if ($silence > $this->gapThresholdMinutes * 60) {
                    // Flagged, never smoothed: a GPS silence is a fact about the
                    // record, and interpolating across it would invent coverage.
                    ++$gapCount;
                }
            }

            $previous = $point;
        }

        $patrol
            ->setPointCount(\count($coordinates))
            ->setGapCount($gapCount)
            ->setDistanceKm(round($distanceKm, 3))
            // A LineString needs two points; one fix is a position, not a route.
            ->setTrack(
                \count($coordinates) >= 2
                    ? json_encode(['type' => 'LineString', 'coordinates' => $coordinates], \JSON_THROW_ON_ERROR)
                    : null,
            );

        $this->alignSpanToFixes($patrol, $points);
    }

    /**
     * Widen the patrol's start/end to cover the fixes actually held.
     *
     * The phone's own `startedAt`/`endedAt` are authoritative and are never
     * narrowed here — but a batch arriving days later can prove the patrol ran
     * longer than the record said, and a track running past its own patrol's end
     * time is the kind of quiet inconsistency that breaks a calendar.
     *
     * @param list<TrackPoint> $points sorted by recordedAt
     */
    private function alignSpanToFixes(Patrol $patrol, array $points): void
    {
        if ([] === $points) {
            return;
        }

        $first = $points[0]->getRecordedAt();
        $last = $points[\count($points) - 1]->getRecordedAt();

        $startedAt = $patrol->getStartedAt();
        if (null === $startedAt || $first < $startedAt) {
            $patrol->setStartedAt($first);
        }

        $endedAt = $patrol->getEndedAt();
        // Only widen a CLOSED patrol. A live upload's null end means "still
        // out there", and filling it in would close a patrol nobody ended.
        if (null !== $endedAt && $last > $endedAt) {
            $patrol->setEndedAt($last);
        }
    }
}
