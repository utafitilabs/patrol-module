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

namespace Uhifadhi\Patrol\Tests\Integration\Module;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\GeoFigure;
use Uhifadhi\Contracts\Performance\GeoSeries;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Module\PatrolPerformanceGeo;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolFigureService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\CollectedGeoProviders;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\StoredCoverage;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * WHAT PATROLS PUBLISHES ABOUT THE GROUND, measured against real PostGIS
 * because coverage is a set operation and nothing smaller than the database
 * can answer one honestly.
 *
 * THE WORLD is two ~0.1° squares that run the module — a NORTH area walked
 * down the middle and a QUIET area that switched Patrols on and never went
 * out — and an OUTSIDE area running nothing. The north area is cut into two
 * strips with unzoned ground between them, so one zone is reached by the
 * track and the other is looked at and found empty.
 */
final class PatrolPerformanceGeoTest extends IntegrationTestCase
{
    use StoredCoverage;

    private const string WALKED = 'Walked reserve';
    private const string QUIET = 'Quiet reserve';
    private const string OUTSIDE = 'Unserved reserve';

    private const string NORTH = 'North';
    private const string SOUTH = 'South';

    /** A line down the middle of the north strip, and nowhere near the south one. */
    private const string TRACK = '{"type":"LineString","coordinates":[[-29.98,-2.91],[-29.92,-2.91]]}';

    public function testTheOrganizationGetsOneFigurePerAreaThatRunsTheModule(): void
    {
        $this->world();

        $series = $this->only($this->geo()->geo(PerformanceScope::organization(), self::period()));

        self::assertSame(PatrolPerformanceGeo::BY_AREA, $series->key);
        self::assertSame([self::QUIET, self::WALKED], self::labels($series), 'By name, and only the areas running the module.');
    }

    public function testAnAreaThatDoesNotRunTheModuleIsNoFigureAtAll(): void
    {
        $this->world();

        $series = $this->only($this->geo()->geo(PerformanceScope::organization(), self::period()));

        self::assertNotContains(self::OUTSIDE, self::labels($series), 'Nobody was recording there, so there is nothing to shade and no blank to draw.');
    }

    public function testAnAreaRunningTheModuleWithNoTrackIsNullAndNeverNought(): void
    {
        $this->world();

        $figures = self::byLabel($this->only($this->geo()->geo(PerformanceScope::organization(), self::period())));

        self::assertNotNull($figures[self::WALKED]->value);
        self::assertGreaterThan(0.0, (float) $figures[self::WALKED]->value);
        self::assertNull($figures[self::QUIET]->value, 'The module is on and no track was recorded: unknown, not zero coverage.');
    }

    public function testTheGroundIsNamedByIdentifierAndNeverByGeometry(): void
    {
        $world = $this->world();

        $figures = self::byLabel($this->only($this->geo()->geo(PerformanceScope::organization(), self::period())));

        self::assertSame((string) $world[self::WALKED]->getUuidString(), $figures[self::WALKED]->uuid);
        self::assertTrue(Uuid::isValid($figures[self::QUIET]->uuid), 'Every figure addresses its ground by uuid; no geometry leaves this module.');
        self::assertSame(self::WALKED, $figures[self::WALKED]->label, 'The label is the area\'s name, for the plate\'s key and its hover.');
    }

    public function testTheSeriesSaysWhatItIsOverAndWhichWayIsGood(): void
    {
        $this->world();

        $series = $this->only($this->geo()->geo(PerformanceScope::organization(), self::period()));

        self::assertSame(GeoSeries::OVER_AREAS, $series->over);
        self::assertNull($series->areaUuid, 'A series over areas belongs to no one area.');
        self::assertSame(PatrolPerformanceGeo::UNIT, $series->unit);
        self::assertSame(PatrolPerformanceGeo::POLARITY, $series->polarity);
        self::assertNotSame('', $series->caption, 'The width is part of what the share means.');
    }

    public function testAnAreasPageNarrowsTheAreaSeriesToThatArea(): void
    {
        $world = $this->world();

        $series = $this->geo()->geo(self::scopeOf($world[self::WALKED]), self::period());

        self::assertSame([self::WALKED], self::labels($series[0]));
    }

    public function testAnAreasPageAlsoPublishesItsZonesAndSaysWhoseTheyAre(): void
    {
        $world = $this->world();

        $series = $this->geo()->geo(self::scopeOf($world[self::WALKED]), self::period());

        self::assertCount(2, $series);
        self::assertSame(PatrolPerformanceGeo::BY_ZONE, $series[1]->key);
        self::assertSame(GeoSeries::OVER_ZONES, $series[1]->over);
        self::assertSame((string) $world[self::WALKED]->getUuidString(), $series[1]->areaUuid, 'A zone series carries the area whose zones they are.');
        self::assertSame([self::NORTH, self::SOUTH], self::labels($series[1]));
    }

    public function testAZoneLookedAtAndNotReachedIsAMeasuredNought(): void
    {
        $world = $this->world();

        $zones = self::byLabel($this->geo()->geo(self::scopeOf($world[self::WALKED]), self::period())[1]);

        self::assertGreaterThan(0.0, (float) $zones[self::NORTH]->value);
        self::assertSame(0.0, $zones[self::SOUTH]->value, 'The area recorded tracks: this strip was measured and none of it was covered.');
    }

    public function testTheOrganizationsPageGetsNoZoneSeries(): void
    {
        $this->world();

        self::assertSame(
            [PatrolPerformanceGeo::BY_AREA],
            array_map(static fn (GeoSeries $series): string => $series->key, $this->geo()->geo(PerformanceScope::organization(), self::period())),
            '"The zones of one area" has no answer where there is no one area.',
        );
    }

    public function testAnAreaThatRunsNothingIsAskedNothing(): void
    {
        $world = $this->world();

        self::assertSame([], $this->geo()->geo(self::scopeOf($world[self::OUTSIDE]), self::period()));
    }

    public function testAPeriodNobodyRecordedInIsAnEmptySeriesAndNotAnAbsentOne(): void
    {
        $this->world();

        $series = $this->only($this->geo()->geo(PerformanceScope::organization(), self::period('2026-05-15 09:00:00')));

        self::assertCount(2, $series->figures, 'The ground is still published — the page decides whether a plate of blanks is drawn.');
        self::assertTrue($series->isEmpty());
        foreach ($series->figures as $figure) {
            self::assertNull($figure->value);
        }
    }

    public function testTheGeoIsPublishedUnderTheModulesOwnSlug(): void
    {
        self::assertSame(PatrolModuleProvider::SLUG, $this->geo()->moduleSlug());
    }

    /**
     * THE TAG, END TO END — the classic silent failure of this platform's
     * seams is a perfect class the page never mentions, so the assertion goes
     * through the tag rather than through the service id.
     */
    public function testTheHostCollectsTheseGroundFiguresUnderTheTag(): void
    {
        $this->world();

        $collected = static::getContainer()->get('test_public.performance.geo_providers');
        \assert($collected instanceof CollectedGeoProviders);

        self::assertArrayHasKey(PatrolModuleProvider::SLUG, $collected->byModule());
        self::assertInstanceOf(PatrolPerformanceGeo::class, $collected->byModule()[PatrolModuleProvider::SLUG]);
    }

    /**
     * Two areas that run Patrols — one walked, one that never went out — and
     * one that runs nothing. The walked area is cut into a north and a south
     * strip with unzoned ground between them.
     *
     * @return array<string, AreaOfInterest>
     */
    private function world(): array
    {
        $walked = $this->area(self::WALKED, -3.0);
        $quiet = $this->area(self::QUIET, -5.0);
        $outside = $this->area(self::OUTSIDE, -7.0);

        $module = new Module()->setSlug('patrols')->setName('Patrols');
        $this->em->persist($module);
        $this->install($module, $walked);
        $this->install($module, $quiet);

        $this->zone($walked, self::NORTH, -2.92, -2.90);
        $this->zone($walked, self::SOUTH, -3.00, -2.98);

        $this->patrol($walked);

        $this->em->flush();

        return [self::WALKED => $walked, self::QUIET => $quiet, self::OUTSIDE => $outside];
    }

    /** A 0.1° square whose top edge is at $northLat. */
    private function area(string $name, float $northLat): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName($name)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[-30.0,%1$F],[-29.9,%1$F],[-29.9,%2$F],[-30.0,%2$F],[-30.0,%1$F]]]]}',
                $northLat,
                $northLat + 0.1,
            ));
        $this->em->persist($area);

        return $area;
    }

    private function zone(AreaOfInterest $area, string $name, float $southLat, float $northLat): Zone
    {
        $zone = new Zone()
            ->setName($name)
            ->setArea($area)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[-30.0,%1$F],[-29.9,%1$F],[-29.9,%2$F],[-30.0,%2$F],[-30.0,%1$F]]]]}',
                $southLat,
                $northLat,
            ));
        $this->em->persist($zone);

        return $zone;
    }

    private function install(Module $module, AreaOfInterest $area): AreaModule
    {
        $installed = new AreaModule()->setModule($module)
            ->setArea($area)
            ->setActive(true)
            ->setInstalledAt(new \DateTimeImmutable('2026-06-10 08:00:00'));
        $this->em->persist($installed);

        return $installed;
    }

    private function patrol(AreaOfInterest $area): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable('2026-08-05 07:00:00'))
            ->setStatus(PatrolStatusEnum::Complete)
            ->setTrack(self::TRACK);
        $this->em->persist($patrol);

        return $patrol;
    }

    private function geo(): PatrolPerformanceGeo
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        $areaModules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        \assert($areaModules instanceof AreaModuleService);

        // What the worker would have done by now: the patrols buffered, and the
        // month's zone facts filed.
        $this->em->flush();
        $this->fileFacts(self::period()->from);

        return new PatrolPerformanceGeo($this->em, $areaModules, new PatrolFigureService($repository, $this->corridors(), $this->facts()), 'patrols', 'Patrols');
    }

    /** @param list<GeoSeries> $series */
    private function only(array $series): GeoSeries
    {
        self::assertCount(1, $series);

        return $series[0];
    }

    private static function period(string $now = '2026-08-20 09:00:00'): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable($now));
    }

    private static function scopeOf(AreaOfInterest $area): PerformanceScope
    {
        return PerformanceScope::area((string) $area->getUuidString(), (string) $area->getName());
    }

    /** @return list<string> */
    private static function labels(GeoSeries $series): array
    {
        return array_map(static fn (GeoFigure $figure): string => $figure->label, $series->figures);
    }

    /** @return array<string, GeoFigure> */
    private static function byLabel(GeoSeries $series): array
    {
        $byLabel = [];
        foreach ($series->figures as $figure) {
            $byLabel[$figure->label] = $figure;
        }

        return $byLabel;
    }
}
