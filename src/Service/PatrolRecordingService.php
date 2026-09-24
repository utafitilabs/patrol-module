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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PositionSourceEnum;
use Uhifadhi\Patrol\Exception\InvalidPatrolTimesException;
use Uhifadhi\Patrol\Exception\MissingPatrolStartException;
use Uhifadhi\Patrol\Model\LoggedObservation;
use Uhifadhi\Patrol\Model\LoggedPatrol;
use Uhifadhi\Patrol\Model\ParsedTrack;

/**
 * THE WRITE PATH OF THE ONE ENTRY FLOW — every patrol this module holds was
 * written by {@see self::log()} or, for a record with nothing but a start and an
 * end, by {@see self::record()} underneath it.
 *
 * A patrol somebody walked with a handset and a patrol somebody walked with a
 * flat battery are the SAME RECORD; the only difference is whether a file
 * arrived. So there is one method the screen calls, and inside it the one
 * question that actually branches: is there a track?
 *
 *   WITH A TRACK, the file states the time span, the distance and the route, and
 *   {@see TrackIngestService} — the same parse the tracker app's API POST goes
 *   through — is what reads it. The form contributes only what a file cannot
 *   know.
 *
 *   WITHOUT ONE, every one of those facts is somebody's own account of the
 *   shift, the record is stamped {@see PatrolSourceEnum::Manual}, and no track,
 *   point count or gap count is written — so a hand-entered patrol can never be
 *   read back as a measured one. That distinction is what every coverage figure
 *   in this module rests on.
 *
 * THE FILES ARRIVED BEFORE THE PATROL DID, which is the whole reason a draft
 * exists, and re-homing them is this service's last act: each key the draft
 * holds is copied under the patrol's own prefix and deleted from the draft's,
 * one file at a time, because the storage publishes no rename. The reasoning and
 * the ordering are {@see PatrolDraftService}'s; what is decided HERE is that it
 * happens on the way in and not later, so a saved patrol never points at a key
 * under a draft that a sweep is entitled to delete.
 *
 * IT DECIDES NOTHING ABOUT WHO IS ASKING, and nothing about the deployment's
 * vocabulary. Whether the caller holds "patrols.record", and whether `foot` is a
 * word this installation uses, are questions the screen settles before it calls
 * in here — the same division {@see TrackIngestService} keeps.
 */
final readonly class PatrolRecordingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolDraftService $drafts,
        private TrackIngestService $ingest,
    ) {
    }

    /**
     * ONE SUBMISSION OF THE ENTRY FLOW, WRITTEN.
     *
     * @throws InvalidPatrolTimesException                    when the end does not follow the start
     * @throws MissingPatrolStartException                    when there is neither a track nor a typed start
     * @throws \Uhifadhi\Patrol\Exception\InvalidGpxException when a held track will not parse
     */
    public function log(
        AreaOfInterest $area,
        PatrolDraft $draft,
        LoggedPatrol $form,
        ?UserInterface $recordedBy = null,
    ): Patrol {
        $trackFile = $this->heldTrack($draft, $form->trackKey);
        $track = null !== $trackFile ? $this->ingest->preview($this->drafts->bytesOf($trackFile)) : null;

        $patrol = null !== $track
            ? $this->recordFromTrack($area, $form, $track)
            : $this->record(
                $area,
                $form->type,
                $form->startedAt ?? throw new MissingPatrolStartException(),
                $form->endedAt,
                $form->station,
                $form->lead,
                $form->team,
                $form->note,
                $form->distanceKm,
            );

        // THE SOURCE FILE MOVES WITH ITS PATROL. Re-homed before the
        // observations, so the patrol's own folder exists in the order a person
        // reading the storage would expect it to.
        if (null !== $trackFile) {
            $patrol->setTrackFileKey(
                $this->drafts->rehome($trackFile, PhotoEvidenceKey::prefixForPatrol($patrol))->key,
            );
        }

        foreach ($form->observations as $logged) {
            $this->attach($patrol, $draft, $logged, $track, $recordedBy);
        }

        $this->entityManager->flush();

        // Nothing of this submission is left waiting to be swept.
        $this->drafts->discard($draft);

        return $patrol;
    }

    /**
     * @param ?\DateTimeImmutable $endedAt null is a real state: a shift written
     *                                     up before it is closed
     *
     * @throws InvalidPatrolTimesException when the end does not follow the start
     */
    public function record(
        AreaOfInterest $area,
        PatrolType $type,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt = null,
        ?Station $station = null,
        ?UserInterface $lead = null,
        ?string $team = null,
        ?string $note = null,
        ?float $distanceKm = null,
    ): Patrol {
        if (null !== $endedAt && $endedAt <= $startedAt) {
            throw new InvalidPatrolTimesException($startedAt, $endedAt);
        }

        $patrol = new Patrol($area, $type)
            ->setSource(PatrolSourceEnum::Manual)
            ->setStationRecord($station)
            ->setLead($lead)
            ->setTeam($team)
            ->setNote($note)
            ->setStartedAt($startedAt)
            ->setEndedAt($endedAt)
            ->setDistanceKm($distanceKm);

        $this->entityManager->persist($patrol);
        $this->entityManager->flush();

        return $patrol;
    }

    /**
     * A patrol the file speaks for: the span, the distance, the route, the point
     * count and the gaps all come out of the GPX, and the form contributes only
     * what a GPX cannot know.
     */
    private function recordFromTrack(AreaOfInterest $area, LoggedPatrol $form, ParsedTrack $track): Patrol
    {
        $patrol = new Patrol($area, $form->type)
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStationRecord($form->station)
            ->setLead($form->lead)
            ->setTeam($form->team)
            ->setNote($form->note)
            ->setStartedAt($track->startedAt)
            ->setEndedAt($track->endedAt)
            ->setDistanceKm($track->distanceKm)
            ->setTrack($track->toGeoJson())
            ->setPointCount($track->pointCount())
            ->setGapCount($track->gapCount);

        $this->entityManager->persist($patrol);
        $this->entityManager->flush();

        return $patrol;
    }

    /**
     * ONE OF PL·03's RECORDS, WRITTEN AGAINST THE PATROL.
     *
     * The position is the design's own rule: prefilled from the track at the
     * time given, so an observation lands where the patrol actually was. Without
     * a track — or without a time — there is nothing to ask, and the position is
     * left null with {@see PositionSourceEnum::None} rather than dropped on the
     * area's centre. Null and zero are different facts.
     */
    private function attach(
        Patrol $patrol,
        PatrolDraft $draft,
        LoggedObservation $logged,
        ?ParsedTrack $track,
        ?UserInterface $recordedBy,
    ): void {
        if ($logged->isEmpty()) {
            return;
        }

        $at = self::momentOn($patrol->getStartedAt(), $logged->at);
        $position = null !== $track && null !== $at ? $track->positionAt($at) : null;

        $observation = new Observation($patrol, $logged->category)
            ->setNote($logged->note)
            ->setLoggedAt($at)
            ->setRecordedBy($recordedBy)
            ->setPositionSource(null !== $position ? PositionSourceEnum::Gps : PositionSourceEnum::None)
            ->setPosition(null !== $position ? json_encode(
                ['type' => 'Point', 'coordinates' => $position],
                \JSON_THROW_ON_ERROR,
            ) : null);

        $patrol->addObservation($observation);
        $this->entityManager->persist($observation);
        // The photographs point at it, so it needs its identity before they are
        // filed under the prefix that names it.
        $this->entityManager->flush();

        foreach ($this->heldPhotos($draft, $logged) as $file) {
            $stored = $this->drafts->rehome($file, PhotoEvidenceKey::prefixFor($observation));

            $photo = new ObservationPhoto($observation, Uuid::v7(), $stored->key)
                ->setMimeType($stored->mimeType)
                ->setByteSize($stored->byteSize)
                ->setThumbKey($stored->thumbKey);

            $observation->addPhoto($photo);
            $this->entityManager->persist($photo);
        }
    }

    /**
     * The draft's own track row, or null.
     *
     * A key the form posted that this draft does not hold attaches NOTHING. The
     * browser's word about which file it uploaded is worth having — it saves a
     * reload — but it is not evidence of ownership, and the draft's rows are.
     */
    private function heldTrack(PatrolDraft $draft, ?string $key): ?PatrolDraftFile
    {
        if (null === $key) {
            return null;
        }

        foreach ($draft->getFiles() as $file) {
            if (PatrolDraftFile::TRACK_SLOT === $file->getSlot() && $file->getStorageKey() === $key) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The draft's own rows for one observation's evidence grid, in the order
     * they arrived.
     *
     * The grid is addressed by its ordinal rather than by the keys posted, and
     * the posted keys only narrow it — so a photograph that landed while the
     * page was being filled in is attached whether or not the browser managed to
     * tell the form about it.
     *
     * @return list<PatrolDraftFile>
     */
    private function heldPhotos(PatrolDraft $draft, LoggedObservation $logged): array
    {
        $slot = PatrolDraftFile::observationSlot($logged->ordinal);

        $held = [];
        foreach ($draft->getFiles() as $file) {
            if ($file->getSlot() === $slot) {
                $held[] = $file;
            }
        }

        return $held;
    }

    /**
     * PL·03's `time` row is a clock face — "07:20" — on the DAY THE PATROL
     * STARTED, because that is the only day the form is about. A blank is a real
     * answer: an observation somebody remembers but cannot time.
     */
    private static function momentOn(?\DateTimeImmutable $day, ?string $clock): ?\DateTimeImmutable
    {
        if (null === $day || null === $clock || 1 !== preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $parts)) {
            return null;
        }

        $at = $day->setTime((int) $parts[1], (int) $parts[2]);

        // Past midnight: a night patrol's 01:10 is the morning after it set off,
        // never ten hours before it did.
        return $at < $day ? $at->modify('+1 day') : $at;
    }
}
