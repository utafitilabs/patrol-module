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

namespace Uhifadhi\Patrol\Deletion;

use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * THE BYTES A DELETED PATROL OR OBSERVATION LEAVES IN THE FILE STORE: every
 * photograph and the source GPX. The database removes the rows that name
 * them; nothing but this removes the files, and a file nobody names is
 * evidence nobody owns. Deleting what is already gone is not an error.
 */
final readonly class PatrolFileRemover
{
    public function __construct(private EvidenceStorage $storage)
    {
    }

    public function removeFilesOf(Patrol $patrol): void
    {
        foreach ($patrol->getObservations() as $observation) {
            $this->removePhotosOf($observation);
        }
        if (null !== $patrol->getTrackFileKey()) {
            $this->storage->delete($patrol->getTrackFileKey());
        }
    }

    public function removePhotosOf(Observation $observation): void
    {
        foreach ($observation->getPhotos() as $photo) {
            $this->storage->delete($photo->getStoragePath());
        }
    }
}
