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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station as AreaStation;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Enum\PatrolEventKindEnum;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * One patrol: a typed, timed record of field effort — who led it, from which
 * station, when, how far — with an optional geometry track. GPX-born patrols
 * carry the recorded LineString plus its honesty metadata (point count, GPS
 * gaps); manual patrols may carry a sketched route, clearly marked as such via
 * {@see PatrolSourceEnum}.
 *
 * The type and the station are the AREA's own vocabulary — a {@see PatrolType}
 * record and a {@see Station} record, each renamed and retired on the module's
 * Settings section, never an enum in code. Both are retired rather than
 * deleted, which is what lets a patrol keep the words that describe it.
 */
#[ORM\Entity(repositoryClass: PatrolRepository::class)]
#[ORM\Table(name: 'patrol_patrol')]
#[ORM\HasLifecycleCallbacks]
class Patrol
{
    use TimestampableTrait;

    /**
     * The one patrol type the sync contract gives its own rules: a drone patrol
     * never posts a track (§5) and declares its coverage as launch-point
     * sectors instead (§7). Patrol types are otherwise deployment vocabulary,
     * never an enum — but "drone" carries behaviour, so the module has to be
     * able to name it.
     */
    public const string DRONE_TYPE = 'drone';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The UUID the FIELD APP generated for this patrol, before it had any
     * network — the idempotency key the whole sync contract rests on
     * (API-CONTRACT.md §1). A re-sent create with a clientUuid we already hold
     * is success, never a conflict, and never a second patrol.
     *
     * Deliberately NOT reusing {@see $uuid}: that one is ours, minted here and
     * used in web URLs. Keeping the client's identifier in its own column means
     * a patrol always says plainly where it came from, and a phone can never
     * name a patrol the web module created.
     *
     * Null for every patrol born in the web module (GPX import, manual log).
     */
    #[ORM\Column(type: 'uuid', unique: true, nullable: true)]
    private ?Uuid $clientUuid = null;

    /** The host area this patrol belongs to. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * WHAT KIND OF PATROL THIS IS — one of the AREA's own {@see PatrolType}
     * records, which is what the Patrol types section renames and retires.
     *
     * Not deleted with the type, and it cannot be: a type is retired, never
     * removed, precisely so this key never dangles.
     */
    #[ORM\ManyToOne(targetEntity: PatrolType::class)]
    #[ORM\JoinColumn(name: 'patrol_type_id', nullable: false, onDelete: 'RESTRICT')]
    private PatrolType $patrolType;

    /**
     * WHERE IT SET OFF FROM — one of the AREA's stations, the core's own
     * record (ruled 2026-09-18: a station belongs to the area module, one
     * source, and every module points at it). Null is a real state: plenty of
     * patrols set off from nowhere in particular, and so is a patrol whose
     * handset named a place the area does not keep — {@see $station} holds
     * the word then, and nothing is invented for it.
     */
    #[ORM\ManyToOne(targetEntity: AreaStation::class)]
    #[ORM\JoinColumn(name: 'area_station_id', nullable: true, onDelete: 'SET NULL')]
    private ?AreaStation $stationRecord = null;

    /**
     * The module's OWN station record this patrol pointed at before stations
     * became the area's (0.7). KEPT FOR ONE RELEASE, never written: the 0.8
     * migration moved every one it could onto {@see $stationRecord}, and the
     * column and its table go with a later release under an `@destructive`
     * marker. Read by nothing but a rollback.
     */
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Station $formerStation = null; // @phpstan-ignore property.unusedType, property.onlyWritten (the shadow relation a rollback reads, never this code)

    /**
     * The type as a bare string, which is what this column held before the
     * words became records.
     *
     * KEPT FOR ONE RELEASE and written from {@see $patrolType} on every save,
     * so an installation that has to roll the code back still finds the value
     * where the old code looked for it. The migration that drops it rides a
     * later release and carries an `@destructive` marker.
     */
    #[ORM\Column(length: 40)]
    private string $type; // @phpstan-ignore property.onlyWritten (the shadow column a rollback reads, never this code)

    /**
     * What the RANGER calls this patrol ("River loop"), where they named it.
     *
     * Null for every patrol nobody named, which is most of them: the screens
     * then fall back to the station or the type, exactly as they always did. It
     * is set by a `renamed` event and never invented here — a name the module
     * made up would read on the page as something a person chose.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    /**
     * The station AS A WORD: the area station's name where the patrol points
     * at one, else whatever the handset called the place it set off from. It
     * is what a screen prints when {@see $stationRecord} is null, and what a
     * rollback reads; the record is the truth wherever there is one.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $station = null;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $lead = null;

    /** Free-text team roster ("A. Example, B. Example"). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $team = null;

    /**
     * The team as the FIELD APP named them — the ranger ids it sent and will
     * send again. {@see $team} above is the human sentence the web module
     * renders; these are the identifiers, kept because a name is not an
     * identity and re-deriving ids from a joined string would be guesswork.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $teamRangerIds = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(nullable: true)]
    private ?float $distanceKm = null;

    #[ORM\Column(enumType: PatrolSourceEnum::class)]
    private PatrolSourceEnum $source = PatrolSourceEnum::Manual;

    /**
     * Defaults to Complete, and that is not laziness: a patrol logged or
     * imported in the WEB module is a finished record the moment it is saved.
     * Only the field app's piecemeal upload starts life as Recording, and only
     * its verified `complete` call moves it back here.
     */
    #[ORM\Column(enumType: PatrolStatusEnum::class, options: ['default' => 'complete'])]
    private PatrolStatusEnum $status = PatrolStatusEnum::Complete;

    /**
     * Why the ranger threw this patrol away, in their own words.
     *
     * REQUIRED whenever the status is Discarded and enforced at the door
     * ({@see \Uhifadhi\Patrol\Api\PatrolApiException::discardReasonRequired()}),
     * because a discard with no reason is indistinguishable from a bug: the one
     * question anybody reading a discarded patrol asks is why, and the app
     * already makes the ranger answer it. Free text, and deliberately not a
     * vocabulary — the app offers chips plus an "Other" textarea, and the chip
     * words are ITS product decision to change without a server release.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $discardReason = null;

    /**
     * When somebody put this patrol on hold, stopping the retention clock.
     *
     * A discarded patrol is deleted for real once its window elapses
     * (`patrol:purge-discarded`), and some discarded patrols are the ones you
     * least want gone — the ones under review. This is the brake: set from the
     * detail screen, it makes the purge skip the patrol indefinitely, and
     * clearing it starts the same clock again from the original discard moment
     * rather than from now.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $heldAt = null;

    /** Who applied the hold — the page names them, so a hold has an owner. */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $heldBy = null;

    /** The aircraft's identifier, drone patrols only (API-CONTRACT.md §4). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $droneId = null;

    /** The flight mission this patrol served, drone patrols only. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mission = null;

    /** The handset that recorded it — provenance, for when a device misbehaves. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceId = null;

    /** The app build that produced it, so a bad release can be identified later. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $appVersion = null;

    /**
     * When somebody edited this patrol in the WEB module.
     *
     * The contract gives editing exactly one writer (§10): once a patrol is
     * acknowledged it is immutable on the phone, and corrections happen here.
     * If a phone then re-sends a part for it — a retry that outlived the
     * acknowledgement — the answer is 409 `patrol_immutable`, because silently
     * applying it would overwrite a human's correction with a stale queue.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $webEditedAt = null;

    /** The route as GeoJSON LineString text (postgis-bundle `linestring` type). */
    #[ORM\Column(type: 'linestring', nullable: true)]
    private ?string $track = null;

    #[ORM\Column(nullable: true)]
    private ?int $pointCount = null;

    /** GPS silences above the configured threshold — flagged, never smoothed. */
    #[ORM\Column(options: ['default' => 0])]
    private int $gapCount = 0;

    /**
     * THE FILE THE ROUTE WAS READ OUT OF, as an evidence key — the one artefact
     * that can be handed to somebody who disputes a coverage figure.
     *
     * Nullable, and null is the ordinary state: a patrol written up by hand
     * never had a file, and every patrol recorded before the one entry flow
     * existed had its track parsed and its bytes thrown away. A column that is
     * only sometimes filled says exactly that.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $trackFileKey = null;

    /** @var Collection<int, Observation> */
    #[ORM\OneToMany(targetEntity: Observation::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['loggedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $observations;

    /**
     * The batches of fixes the phone uploaded, kept as rows so a re-sent batch
     * can be recognised and ignored.
     *
     * @var Collection<int, TrackBatch>
     */
    #[ORM\OneToMany(targetEntity: TrackBatch::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $trackBatches;

    /**
     * Every individual fix, with its own accuracy. The assembled LINESTRING in
     * {@see $track} is derived from these; the fixes are the record.
     *
     * @var Collection<int, TrackPoint>
     */
    #[ORM\OneToMany(targetEntity: TrackPoint::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['recordedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $trackPoints;

    /** @var Collection<int, LaunchPoint> */
    #[ORM\OneToMany(targetEntity: LaunchPoint::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $launchPoints;

    /** @var Collection<int, Flight> */
    #[ORM\OneToMany(targetEntity: Flight::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC', 'id' => 'ASC'])]
    private Collection $flights;

    /**
     * Everything that has happened to this patrol, oldest first — the history
     * card's contents. Ordered by the moment the RANGER acted, not by arrival:
     * two events queued on a handset for a day must still read in the order
     * they were done.
     *
     * @var Collection<int, PatrolEvent>
     */
    #[ORM\OneToMany(targetEntity: PatrolEvent::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['at' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    public function __construct(AreaOfInterest $area, PatrolType $type)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->setPatrolType($type);
        $this->observations = new ArrayCollection();
        $this->trackBatches = new ArrayCollection();
        $this->trackPoints = new ArrayCollection();
        $this->launchPoints = new ArrayCollection();
        $this->flights = new ArrayCollection();
        $this->events = new ArrayCollection();
        // Values exist pre-flush; PrePersist keeps them if already set.
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getPatrolType(): PatrolType
    {
        return $this->patrolType;
    }

    public function setPatrolType(PatrolType $type): static
    {
        $this->patrolType = $type;
        // The shadow column stays true to the relation for the one release it
        // still exists — see the property.
        $this->type = $type->getKey();

        return $this;
    }

    /** The wire value — a saved filter, an export column and the handset's. */
    public function getType(): string
    {
        return $this->patrolType->getKey();
    }

    /** What a screen prints, which a rename changes and the wire value does not. */
    public function getTypeLabel(): string
    {
        return $this->patrolType->getLabel();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * What to call this patrol on a screen: the ranger's name where they gave
     * one, else the station it set out from, else nothing — and the caller
     * falls back to the type label, as every screen already did before patrols
     * could be named.
     */
    public function getDisplayName(): ?string
    {
        return $this->name ?? $this->getStation();
    }

    public function getStationRecord(): ?AreaStation
    {
        return $this->stationRecord;
    }

    /** The area's station this patrol set off from; its name becomes the word. */
    public function setStationRecord(?AreaStation $station): static
    {
        $this->stationRecord = $station;
        if (null !== $station) {
            $this->station = $station->getName();
        }

        return $this;
    }

    /**
     * The place as the handset named it, where the area keeps no such station:
     * the word is kept, the record stays null, and nobody makes a station out
     * of it — the handset collects, the office configures.
     */
    public function setStationWord(?string $word): static
    {
        $word = null === $word ? null : trim($word);
        $this->station = '' === $word ? null : $word;
        if (null !== $this->station && null !== $this->stationRecord && $this->station !== $this->stationRecord->getName()) {
            $this->stationRecord = null;
        }

        return $this;
    }

    /** What a screen prints: the station's name, or the word the handset sent. */
    public function getStation(): ?string
    {
        return $this->stationRecord?->getName() ?? $this->station;
    }

    /**
     * What a filter, an export column and the map key a station by: the area
     * station's uuid, or — for a patrol that carries only a word — the word
     * itself, so the word can still be chosen, counted and drawn. Only records
     * ever go out to a handset ({@see VocabularySyncService}), never a word.
     */
    public function getStationKey(): ?string
    {
        return $this->stationRecord?->getUuid()?->toRfc4122() ?? $this->station;
    }

    public function getLead(): ?UserInterface
    {
        return $this->lead;
    }

    public function setLead(?UserInterface $lead): static
    {
        $this->lead = $lead;

        return $this;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    public function setTeam(?string $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getDistanceKm(): ?float
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?float $distanceKm): static
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    public function getSource(): PatrolSourceEnum
    {
        return $this->source;
    }

    public function setSource(PatrolSourceEnum $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getTrack(): ?string
    {
        return $this->track;
    }

    public function setTrack(?string $track): static
    {
        $this->track = $track;

        return $this;
    }

    public function getPointCount(): ?int
    {
        return $this->pointCount;
    }

    public function setPointCount(?int $pointCount): static
    {
        $this->pointCount = $pointCount;

        return $this;
    }

    public function getGapCount(): int
    {
        return $this->gapCount;
    }

    public function getTrackFileKey(): ?string
    {
        return $this->trackFileKey;
    }

    public function setTrackFileKey(?string $trackFileKey): static
    {
        $this->trackFileKey = $trackFileKey;

        return $this;
    }

    public function setGapCount(int $gapCount): static
    {
        $this->gapCount = $gapCount;

        return $this;
    }

    /** @return Collection<int, Observation> */
    public function getObservations(): Collection
    {
        return $this->observations;
    }

    public function addObservation(Observation $observation): static
    {
        if (!$this->observations->contains($observation)) {
            $this->observations->add($observation);
        }

        return $this;
    }

    /**
     * A drone patrol: its coverage is declared sectors, not a walked line. The
     * phone's own positions during one are the OPERATOR's, so they are never
     * accepted as this patrol's track (API-CONTRACT.md §5).
     */
    public function isDrone(): bool
    {
        return self::DRONE_TYPE === $this->getType();
    }

    public function getClientUuid(): ?Uuid
    {
        return $this->clientUuid;
    }

    public function setClientUuid(?Uuid $clientUuid): static
    {
        $this->clientUuid = $clientUuid;

        return $this;
    }

    /** @return list<string> */
    public function getTeamRangerIds(): array
    {
        return $this->teamRangerIds;
    }

    /** @param list<string> $teamRangerIds */
    public function setTeamRangerIds(array $teamRangerIds): static
    {
        $this->teamRangerIds = array_values(array_unique($teamRangerIds));

        return $this;
    }

    public function getStatus(): PatrolStatusEnum
    {
        return $this->status;
    }

    public function setStatus(PatrolStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isDiscarded(): bool
    {
        return PatrolStatusEnum::Discarded === $this->status;
    }

    public function getDiscardReason(): ?string
    {
        return $this->discardReason;
    }

    /**
     * Throw this patrol away, with the reason as the only way to do it.
     *
     * One method rather than two setters, because the status and the reason are
     * a single fact: a Discarded row with a null reason is the state the whole
     * feature exists to make impossible, and leaving two independent setters
     * lying around is an invitation to produce one.
     */
    public function discard(string $reason): static
    {
        $this->status = PatrolStatusEnum::Discarded;
        $this->discardReason = $reason;

        return $this;
    }

    public function getHeldAt(): ?\DateTimeImmutable
    {
        return $this->heldAt;
    }

    public function getHeldBy(): ?UserInterface
    {
        return $this->heldBy;
    }

    /** Whether the retention clock is stopped for this patrol. */
    public function isHeld(): bool
    {
        return null !== $this->heldAt;
    }

    public function hold(?UserInterface $by, ?\DateTimeImmutable $at = null): static
    {
        $this->heldAt = $at ?? new \DateTimeImmutable();
        $this->heldBy = $by;

        return $this;
    }

    /**
     * Release the hold. The clock resumes from the ORIGINAL discard moment, not
     * from now — a patrol held for a fortnight and cleared is fourteen days
     * closer to its window, not fourteen days further from it. That falls out of
     * {@see self::discardedAt()} reading the discard rather than the release,
     * and it is the behaviour a reviewer expects: a hold pauses the deletion,
     * it does not grant the patrol a fresh lifetime.
     */
    public function release(): static
    {
        $this->heldAt = null;
        $this->heldBy = null;

        return $this;
    }

    /**
     * When this patrol was thrown away — the instant the retention window is
     * measured from.
     *
     * The `discarded` EVENT's `at` is the answer wherever there is one: it is
     * the moment the ranger acted, which is what "90 days after it was
     * discarded" means to a person. The LAST such event wins, because a patrol
     * discarded, re-discarded with a better reason, and re-sent should age from
     * the decision that stands.
     *
     * Without one — a patrol that arrived already discarded through §4/§9, where
     * the contract carries a status but no moment — the fallbacks are `endedAt`
     * and then `createdAt`. The second is not cosmetic: a live upload discarded
     * before it ever ended has neither an event nor an end, and a patrol with no
     * measurable age would never be purged at all. Its arrival here is the one
     * moment that always exists.
     */
    public function discardedAt(): ?\DateTimeImmutable
    {
        if (!$this->isDiscarded()) {
            return null;
        }

        $discarded = null;
        foreach ($this->events as $event) {
            if (PatrolEventKindEnum::Discarded === $event->getKind()) {
                $discarded = $event->getAt();
            }
        }

        return $discarded ?? $this->endedAt ?? $this->createdAt;
    }

    public function getDroneId(): ?string
    {
        return $this->droneId;
    }

    public function setDroneId(?string $droneId): static
    {
        $this->droneId = $droneId;

        return $this;
    }

    public function getMission(): ?string
    {
        return $this->mission;
    }

    public function setMission(?string $mission): static
    {
        $this->mission = $mission;

        return $this;
    }

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function setDeviceId(?string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function setAppVersion(?string $appVersion): static
    {
        $this->appVersion = $appVersion;

        return $this;
    }

    public function getWebEditedAt(): ?\DateTimeImmutable
    {
        return $this->webEditedAt;
    }

    public function markWebEdited(?\DateTimeImmutable $at = null): static
    {
        $this->webEditedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Whether the phone may still add parts to this patrol. False once a human
     * has corrected it in the web module — from then on the phone is told
     * `patrol_immutable` and stops trying (API-CONTRACT.md §10).
     */
    public function acceptsFieldUploads(): bool
    {
        return null === $this->webEditedAt;
    }

    /** @return Collection<int, TrackBatch> */
    public function getTrackBatches(): Collection
    {
        return $this->trackBatches;
    }

    public function addTrackBatch(TrackBatch $batch): static
    {
        if (!$this->trackBatches->contains($batch)) {
            $this->trackBatches->add($batch);
        }

        return $this;
    }

    /** @return Collection<int, TrackPoint> */
    public function getTrackPoints(): Collection
    {
        return $this->trackPoints;
    }

    public function addTrackPoint(TrackPoint $point): static
    {
        if (!$this->trackPoints->contains($point)) {
            $this->trackPoints->add($point);
        }

        return $this;
    }

    /** @return Collection<int, LaunchPoint> */
    public function getLaunchPoints(): Collection
    {
        return $this->launchPoints;
    }

    public function addLaunchPoint(LaunchPoint $launchPoint): static
    {
        if (!$this->launchPoints->contains($launchPoint)) {
            $this->launchPoints->add($launchPoint);
        }

        return $this;
    }

    /** @return Collection<int, Flight> */
    public function getFlights(): Collection
    {
        return $this->flights;
    }

    public function addFlight(Flight $flight): static
    {
        if (!$this->flights->contains($flight)) {
            $this->flights->add($flight);
        }

        return $this;
    }

    /** @return Collection<int, PatrolEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(PatrolEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }

        return $this;
    }

    /**
     * Whether this patrol carries a RECORDED route — a GPX import or an API
     * feed — as opposed to nothing at all or a hand-sketched line. Only a
     * recorded route may be offered back as GPX: handing a sketch out as a
     * .gpx file would let it re-enter the world as a recording
     * (docs/design-decisions.md §4).
     */
    public function hasRecordedTrack(): bool
    {
        return null !== $this->track && PatrolSourceEnum::Manual !== $this->source;
    }

    /** Display reference ("P-0142") — presentation only, derived from the id. */
    public function getRef(): string
    {
        return \sprintf('P-%04d', $this->id ?? 0);
    }
}
