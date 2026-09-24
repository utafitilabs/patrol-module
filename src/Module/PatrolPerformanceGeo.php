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

namespace Uhifadhi\Patrol\Module;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\GeoFigure;
use Uhifadhi\Contracts\Performance\GeoSeries;
use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolFigureService;

/**
 * WHAT THE PATROLS MODULE HAS TO SAY ABOUT THE GROUND — coverage, per area
 * across the organization, and per zone once the page is about one area.
 *
 * SEPARATE FROM THE TOPIC, AND OPTIONAL, which is why it is its own class
 * beside {@see PatrolPerformanceTopic} rather than another method on it. Most
 * topics have nothing to say about WHERE; this one does, and the page draws it
 * on the atlas plate with the same chrome and the same legend as every other
 * map in the product.
 *
 * THE GROUND IS NAMED BY ITS IDENTIFIER AND NEVER BY ITS GEOMETRY. The area
 * bundle owns the shapes and draws them; a module publishing a figure about an
 * area must not have to carry a polygon to say so, and nothing here reads one
 * out — the uuid and the name are the whole of what leaves this class.
 *
 * TWO PLATES, BECAUSE THEY ARE TWO PLATES. A series says what it is over, so
 * the page never has to match uuids against two tables to find out: the
 * organization's page gets one figure per area, an area's page gets that one
 * area AND a figure per zone of it, carrying the area's uuid so the plate knows
 * whose zones they are. The organization's page gets no zone series at all —
 * "the zones of one area" has no answer when there is no one area.
 *
 * COVERAGE IS THE SAME SHARE THE TOPIC PUBLISHES, made in the same place
 * ({@see PatrolFigureService}), so a director reading 54 % on the card and 54 %
 * on the plate is reading one measurement rather than two that agree today.
 *
 * THE TWO ABSENCES ARE KEPT APART, and they are different facts:
 *
 * - an AREA THAT DOES NOT RUN THIS MODULE IS NOT A FIGURE AT ALL. Nobody was
 *   recording there, so the plate has nothing to shade and no blank to draw —
 *   a null would say "we looked and found nothing", which is not what
 *   happened;
 * - a NULL VALUE is ground this module was asked about and cannot measure: no
 *   track was recorded over it in the period, or the area has no boundary
 *   stored yet. Never a nought — zero coverage is a month somebody walked
 *   nothing, and that is a different month.
 *
 * A SERIES OF NOTHING IS STILL PUBLISHED. Whether a plate of blanks is drawn
 * at all is the page's call, and the contract gives it {@see
 * GeoSeries::isEmpty()} to make it with; a module that pre-filtered would be
 * deciding a picture it cannot see.
 */
final readonly class PatrolPerformanceGeo implements PerformanceGeoProviderInterface
{
    /** One figure per area of the page's scope. */
    public const string BY_AREA = 'patrols.coverage_by_area';

    /** One figure per zone, on a page about a single area. */
    public const string BY_ZONE = 'patrols.coverage_by_zone';

    /** A share, carried as the number a plate prints — 54.0 for 54 %. */
    public const string UNIT = '%';

    /** More ground within reach of a track is more of the work this module measures. */
    public const ColumnPolarity POLARITY = ColumnPolarity::Up;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AreaModuleService $areaModules,
        private PatrolFigureService $figures,
        /** The slug this module is registered under in the registry's catalogue. */
        private string $slug,
        private string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * @return list<GeoSeries>
     */
    public function geo(PerformanceScope $scope, FigurePeriod $period): array
    {
        $areas = $this->areasRunningIn($scope);
        if ([] === $areas) {
            return [];
        }

        $series = [$this->coverageByArea($areas, $period)];

        if (!$scope->isOrganization()) {
            $zones = $this->coverageByZone($areas[0], $period);
            if (null !== $zones) {
                $series[] = $zones;
            }
        }

        return $series;
    }

    /**
     * THE SHARE OF EACH AREA WITHIN REACH OF A TRACK, area by area.
     *
     * Each figure is a share OF THAT AREA'S OWN BOUNDARY — never the scope's
     * boundaries combined, which is what the topic's headline reads and is a
     * different number. A plate compares one piece of ground with the next, and
     * a figure that was a share of everything would rank them all identically.
     *
     * @param list<AreaOfInterest> $areas
     */
    private function coverageByArea(array $areas, FigurePeriod $period): GeoSeries
    {
        $figures = [];
        foreach ($areas as $area) {
            $figures[] = new GeoFigure(
                uuid: (string) $area->getUuidString(),
                label: (string) $area->getName(),
                value: $this->figures->coverage($area, $period->from, $period->until),
            );
        }

        return new GeoSeries(
            key: self::BY_AREA,
            title: \sprintf('%s coverage by area', $this->name),
            figures: $figures,
            over: GeoSeries::OVER_AREAS,
            unit: self::UNIT,
            polarity: self::POLARITY,
            caption: \sprintf('the share of each area within %s km of a recorded track', self::buffer()),
        );
    }

    /**
     * THE SAME SHARE, ZONE BY ZONE, for the one area the page is about.
     *
     * NULL WHERE THE AREA RECORDED NO TRACK AT ALL. A zone the month's tracks
     * ran nowhere near, in an area that recorded tracks elsewhere, is a
     * MEASURED NOUGHT: the ground was looked at and none of it was covered.
     * Only an area with no track in the window leaves every zone unmeasured,
     * and that is the null.
     *
     * An area with no zones publishes no series rather than an empty one:
     * there is no ground to plate, which is not the same as ground nobody
     * measured.
     */
    private function coverageByZone(AreaOfInterest $area, FigurePeriod $period): ?GeoSeries
    {
        /** @var list<Zone> $zones */
        $zones = $this->entityManager->createQueryBuilder()
            ->select('z')
            ->from(Zone::class, 'z')
            ->andWhere('z.area = :area')
            ->setParameter('area', $area)
            ->orderBy('z.name', 'ASC')
            ->getQuery()
            ->getResult();

        if ([] === $zones) {
            return null;
        }

        $uuids = [];
        foreach ($zones as $zone) {
            $uuids[] = (string) $zone->getUuidString();
        }

        $measured = $this->figures->zoneCoverage($uuids, $period->from, $period->until);

        $figures = [];
        foreach ($zones as $zone) {
            $uuid = (string) $zone->getUuidString();
            $figures[] = new GeoFigure($uuid, (string) $zone->getName(), $measured[$uuid] ?? null);
        }

        return new GeoSeries(
            key: self::BY_ZONE,
            title: \sprintf('%s coverage by zone', $this->name),
            figures: $figures,
            over: GeoSeries::OVER_ZONES,
            areaUuid: (string) $area->getUuidString(),
            unit: self::UNIT,
            polarity: self::POLARITY,
            // The width is part of what the share MEANS, and a zone's is read
            // per track: each counts as covering its own type's width, and a
            // type that sets none falls back on the module's figure.
            caption: \sprintf('the share of each zone covered, each track at its type\'s own width, %s km where a type sets none', self::buffer()),
        );
    }

    /**
     * THE AREAS OF THE SCOPE THAT ACTUALLY RUN THIS MODULE, by name.
     *
     * WHETHER A MODULE IS ON IS THE REGISTRY'S LEDGER, and this asks it rather
     * than inferring it from whether any patrol happens to have been recorded:
     * an area that switched Patrols on last week and has not been out yet is
     * ground with nothing measured on it, while an area that never switched it
     * on is not this module's ground at all. Reading the ledger is what keeps
     * those two apart, and it is one question about an area and a module —
     * nothing here joins it to departments, which is the topic's question and
     * the directory's answer.
     *
     * @return list<AreaOfInterest>
     */
    private function areasRunningIn(PerformanceScope $scope): array
    {
        $areaUuid = $scope->areaUuid;
        if (null !== $areaUuid && !Uuid::isValid($areaUuid)) {
            return [];
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AreaOfInterest::class, 'a')
            ->orderBy('a.name', 'ASC');

        if (null !== $areaUuid) {
            $query->andWhere('a.uuid = :area')->setParameter('area', Uuid::fromString($areaUuid));
        }

        /** @var list<AreaOfInterest> $areas */
        $areas = $query->getQuery()->getResult();

        return array_values(array_filter(
            $areas,
            fn (AreaOfInterest $area): bool => $this->areaModules->isActive($area, $this->slug),
        ));
    }

    /** The module's own coverage width, in kilometres, written plainly. */
    private static function buffer(): string
    {
        return rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.');
    }
}
