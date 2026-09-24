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
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Enum\PositionSourceEnum;
use Uhifadhi\Patrol\Repository\ObservationRepository;

/**
 * A georeferenced field note logged en route — the patrol's eyes: a category and
 * an optional sub-category (this area's observation taxonomy, or the
 * deployment-wide patrol.observation_categories list it coexists with), a
 * verbatim note and an optional position. Observations are the raw material
 * other modules refine (an observation can later be filed as an incident); they
 * are records, never silently edited — corrections append.
 */
#[ORM\Entity(repositoryClass: ObservationRepository::class)]
#[ORM\Table(name: 'patrol_observation')]
#[ORM\HasLifecycleCallbacks]
class Observation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The UUID the field app gave this observation before it had a network —
     * the idempotency key (API-CONTRACT.md §1). Null for observations logged in
     * the web module.
     */
    #[ORM\Column(type: 'uuid', unique: true, nullable: true)]
    private ?Uuid $clientUuid = null;

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'observations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /**
     * THE WORD THIS OBSERVATION IS FILED UNDER — a {@see TaxonomyKind} wire-code
     * from THIS PATROL'S AREA, or a `patrol.observation_categories` key from the
     * deployment-wide list the taxonomy still coexists with.
     *
     * A code, never a label: it is what an export column, a saved filter and an
     * offline handset hold, and a rename must not reach it.
     */
    #[ORM\Column(length: 40)]
    private string $category;

    /**
     * The finer word under {@see $category} — a {@see TaxonomySubcategory}
     * wire-code — where the field client recorded one.
     *
     * Null is the ordinary state and means the ranger was offered no second
     * chip: a kind with no sub-categories is normal, and the column is not
     * backfilled with the kind itself to pretend otherwise.
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $subcategory = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Where it was seen, as GeoJSON Point text. */
    #[ORM\Column(type: 'point', nullable: true)]
    private ?string $position = null;

    /**
     * Whether {@see $position} is a GPS fix or a spot the operator marked —
     * API-CONTRACT.md §6, and the contract insists it be shown in the web
     * module. A drone observation is where the operator SAYS the drone was; it
     * is not a measurement, and rendering the two identically would turn a
     * judgement into evidence. Defaults to Gps because every observation logged
     * on foot in the web module is exactly that.
     */
    #[ORM\Column(enumType: PositionSourceEnum::class, options: ['default' => 'gps'])]
    private PositionSourceEnum $positionSource = PositionSourceEnum::Gps;

    /** Reported accuracy of {@see $position} in metres, when it was measured. */
    #[ORM\Column(nullable: true)]
    private ?float $accuracyM = null;

    #[ORM\Column(nullable: true)]
    private ?int $satellites = null;

    /**
     * How many photos the PHONE intends to send for this observation (§6).
     *
     * Kept apart from the photos actually held, because the gap between the two
     * is the whole point: a patrol is not complete in the module's eyes until
     * that many parts have arrived, and pretending otherwise would publish an
     * observation whose evidence is still on a handset in a valley.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $photoCount = 0;

    /** The launch point this was seen from, for drone observations. */
    #[ORM\ManyToOne(targetEntity: LaunchPoint::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LaunchPoint $launchPoint = null;

    /** The sortie it was seen on, for drone observations. */
    #[ORM\ManyToOne(targetEntity: Flight::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Flight $flight = null;

    /**
     * The launch point and flight the PHONE named, kept as raw client uuids
     * beside the associations above.
     *
     * Not redundancy — order. The upload sequence (§11) sends observations
     * BEFORE flights, so when a drone observation arrives the flight it refers
     * to does not exist here yet and the association cannot be made. Dropping
     * the reference would lose which sortie saw what; holding the id lets
     * {@see \Uhifadhi\Patrol\Service\Api\FlightSyncService} link them the
     * moment the flights land, and makes the module indifferent to the order
     * the two parts actually arrive in.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $launchPointClientUuid = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $flightClientUuid = null;

    /** @var Collection<int, ObservationPhoto> */
    #[ORM\OneToMany(targetEntity: ObservationPhoto::class, mappedBy: 'observation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $photos;

    /**
     * THE CORRECTIONS APPENDED TO THIS OBSERVATION, oldest first — the trail
     * read under the record it corrects.
     *
     * No `orphanRemoval` and no cascade remove, deliberately: an amendment is
     * evidence, and a collection that quietly deleted one when it was detached
     * would put a delete path into a record whose whole value is that it has
     * none.
     *
     * @var Collection<int, ObservationAmendment>
     */
    #[ORM\OneToMany(targetEntity: ObservationAmendment::class, mappedBy: 'observation', cascade: ['persist'])]
    #[ORM\OrderBy(['writtenAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $amendments;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $loggedAt = null;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $recordedBy = null;

    public function __construct(Patrol $patrol, string $category)
    {
        $this->uuid = Uuid::v7();
        $this->patrol = $patrol;
        $this->category = $category;
        $this->photos = new ArrayCollection();
        $this->amendments = new ArrayCollection();
        $patrol->addObservation($this);
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

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getSubcategory(): ?string
    {
        return $this->subcategory;
    }

    public function setSubcategory(?string $subcategory): static
    {
        $this->subcategory = $subcategory;

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

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getLoggedAt(): ?\DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(?\DateTimeImmutable $loggedAt): static
    {
        $this->loggedAt = $loggedAt;

        return $this;
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

    public function getPositionSource(): PositionSourceEnum
    {
        return $this->positionSource;
    }

    public function setPositionSource(PositionSourceEnum $positionSource): static
    {
        $this->positionSource = $positionSource;

        return $this;
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

    public function getPhotoCount(): int
    {
        return $this->photoCount;
    }

    public function setPhotoCount(int $photoCount): static
    {
        $this->photoCount = max(0, $photoCount);

        return $this;
    }

    public function getLaunchPoint(): ?LaunchPoint
    {
        return $this->launchPoint;
    }

    public function setLaunchPoint(?LaunchPoint $launchPoint): static
    {
        $this->launchPoint = $launchPoint;

        return $this;
    }

    public function getFlight(): ?Flight
    {
        return $this->flight;
    }

    public function setFlight(?Flight $flight): static
    {
        $this->flight = $flight;

        return $this;
    }

    public function getLaunchPointClientUuid(): ?Uuid
    {
        return $this->launchPointClientUuid;
    }

    public function setLaunchPointClientUuid(?Uuid $launchPointClientUuid): static
    {
        $this->launchPointClientUuid = $launchPointClientUuid;

        return $this;
    }

    public function getFlightClientUuid(): ?Uuid
    {
        return $this->flightClientUuid;
    }

    public function setFlightClientUuid(?Uuid $flightClientUuid): static
    {
        $this->flightClientUuid = $flightClientUuid;

        return $this;
    }

    /** @return Collection<int, ObservationPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ObservationPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
        }

        return $this;
    }

    /**
     * THE FIELD PHOTOGRAPHS — the ones the handset took, in the field, at the
     * time, and the only ones PL·05 shows.
     *
     * A photograph attached to an AMENDMENT is different evidence with different
     * provenance: taken later, by somebody else, on a follow-up. It is shown in
     * the trail under the amendment that carries it, and it is emphatically not
     * a field photograph — the design says so in as many words.
     *
     * @return list<ObservationPhoto>
     */
    public function fieldPhotos(): array
    {
        return array_values(array_filter(
            $this->photos->toArray(),
            static fn (ObservationPhoto $photo): bool => !$photo->isAmendmentAttachment(),
        ));
    }

    /**
     * How many of the photos the phone promised are actually here. The module
     * must not present the observation as fully evidenced until this reaches
     * {@see getPhotoCount()}.
     *
     * FIELD photographs only. The phone's `photoCount` is a promise about what
     * IT is sending, so counting a photograph somebody attached to a web
     * amendment against it would let a correction made months later satisfy §9's
     * completeness check for an upload that never finished.
     */
    public function heldPhotoCount(): int
    {
        return \count($this->fieldPhotos());
    }

    /** @return Collection<int, ObservationAmendment> */
    public function getAmendments(): Collection
    {
        return $this->amendments;
    }

    public function addAmendment(ObservationAmendment $amendment): static
    {
        if (!$this->amendments->contains($amendment)) {
            $this->amendments->add($amendment);
        }

        return $this;
    }

    /**
     * How many times this observation has been corrected — the number PL·03
     * prints over the note.
     */
    public function amendmentCount(): int
    {
        return $this->amendments->count();
    }

    /** Whether anything has been corrected, so PL·06 knows to draw the empty state. */
    public function isAmended(): bool
    {
        return !$this->amendments->isEmpty();
    }

    /** Every promised photo has arrived (§9's completeness check). */
    public function hasAllPhotos(): bool
    {
        return $this->heldPhotoCount() >= $this->photoCount;
    }

    public function getRecordedBy(): ?UserInterface
    {
        return $this->recordedBy;
    }

    public function setRecordedBy(?UserInterface $recordedBy): static
    {
        $this->recordedBy = $recordedBy;

        return $this;
    }

    /**
     * Display reference ("OBS-0214") — presentation only, derived from the id,
     * exactly as {@see Patrol::getRef()} is.
     *
     * The observation's own screen numbers it WITHIN its patrol ("3 of 7"),
     * which is the right thing there and useless anywhere else: two patrols both
     * have a third observation. Off its patrol's page — on the Files hub, beside
     * an incident's evidence and a permit's document — a photograph has to name
     * one record out of every record the organization holds, and this is that
     * name.
     */
    public function getRef(): string
    {
        return \sprintf('OBS-%04d', $this->id ?? 0);
    }
}
