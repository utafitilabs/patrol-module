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

use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Patrol\Facts\PatrolFactProvider;
use Uhifadhi\Patrol\Repository\PatrolCorridorRepository;

/**
 * THE GROUND A MONTH'S ROUTES COVERED, HELD FOR THE DAY.
 *
 * The coverage plate draws the ground the month's complete patrols covered, each
 * at its type's own width: the stored corridors
 * ({@see \Uhifadhi\Patrol\Entity\PatrolCorridor}) unioned, clipped to the
 * boundary and simplified. No track is buffered here — the worker did that once
 * — but a union of a month of corridors is still work, and it is asked for on
 * every load of the dashboard and of the widget library.
 *
 * WHY IT IS CACHED, AND WHY BY THE DAY. A month's union is a visible share of
 * a page load for a shape that changes only when a new track arrives. Held per AREA, per MONTH
 * and per DAY, so the key rolls at midnight and a shape can never outlive the
 * day it was measured on — a track synced this afternoon is on the plate
 * tomorrow morning at the latest, and the KPI beside it moves with it.
 *
 * IT IS NOT KEYED BY THE FILTER. The covered ground is the MONTH's, exactly as
 * the KPI beside it is: the shape on the plate and the number in the strip must
 * be the same measurement or one of them is lying. Narrowing to a type or a
 * station changes which routes are drawn over it, never the ground the month
 * covered.
 *
 * THE CACHE IS OPTIONAL. A host that wired none still gets the answer, measured
 * every time — the cache is a saving, never a requirement, and a coverage layer
 * that silently drew nothing without one would be worse than a slow one.
 *
 * @see https://symfony.com/doc/current/cache.html#cache-invalidation
 * @see vendor/symfony/cache-contracts/CacheInterface.php
 */
final readonly class PatrolCoverageService
{
    /**
     * How long a measured shape is held. The DAY is already in the key, so this
     * is only the ceiling for one written just before midnight; the key, not the
     * clock, is what makes the answer a day old at most.
     */
    private const int TTL_SECONDS = 86400;

    public function __construct(
        private PatrolCorridorRepository $corridors,
        private FactReaderInterface $facts,
        private ?CacheInterface $cache = null,
    ) {
    }

    /**
     * The covered ground as GeoJSON text, or null where the month recorded no
     * track at all — which the plate draws as an empty layer that still ships
     * its legend row.
     *
     * "Now" is handed in rather than read from the clock, like every other
     * instant in this module, so the day a shape is filed under is a parameter
     * and not a second code path.
     */
    public function bufferFor(
        AreaOfInterest $area,
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $nextMonth,
        \DateTimeImmutable $now,
    ): ?string {
        $measure = fn (): ?string => $this->corridors->coveredGeoJson($area, $monthStart, $nextMonth);

        $key = self::key($area, $monthStart, $now);
        if (null === $this->cache || null === $key) {
            return $measure();
        }

        /** @var string|null $covered */
        $covered = $this->cache->get(
            $key,
            static function (CacheItemInterface $item) use ($measure): ?string {
                $item->expiresAfter(self::TTL_SECONDS);

                return $measure();
            },
        );

        return $covered;
    }

    /**
     * PL·03 FOR ONE MONTH, AS THE WORKER FILED IT — the share of the area
     * within the module's one width of a complete track, in points, with the
     * time it is true as of. Null where the worker has not computed the month
     * yet; a fact with no value where there was nothing to measure.
     *
     * A read of one ledger row: the union behind it was the worker's.
     */
    public function monthShare(AreaOfInterest $area, \DateTimeImmutable $monthStart): ?Fact
    {
        $uuid = $area->getUuidString();

        return null === $uuid ? null : $this->facts->latest(FactSubject::AREA, $uuid, PatrolFactProvider::AREA_COVERAGE_UNIFORM, FactPeriod::month($monthStart)->key);
    }

    /**
     * The area, the month and the day, in a key PSR-6 accepts: the reserved
     * characters {}()/\@: may not appear, so the area is named by its uuid and
     * the two dates by their plain digits.
     *
     * Null for an area that has never been saved, which has no stable name to
     * file an answer under — and no recorded track to measure either.
     *
     * @see https://www.php-fig.org/psr/psr-6/#definitions
     */
    private static function key(AreaOfInterest $area, \DateTimeImmutable $monthStart, \DateTimeImmutable $now): ?string
    {
        $uuid = $area->getUuid();

        return null === $uuid ? null : \sprintf(
            'patrol.coverage.%s.%s.%s',
            $uuid->toRfc4122(),
            $monthStart->format('Ym'),
            $now->format('Ymd'),
        );
    }
}
