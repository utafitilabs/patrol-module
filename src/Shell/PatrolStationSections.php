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

namespace Uhifadhi\Patrol\Shell;

use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Area\StationSection;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSections;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * *PATROLS FROM HERE* — what the patrol module puts on the post a person is
 * posted at, on their own `/me/station` page (#19; design station.html SN·04):
 * this week's patrols that went out from it, who led each and how far it
 * went, with the week's count and distance beside the heading.
 *
 * THE PERSON'S OWN SURFACE ONLY. The post's record and its configure card are
 * answered with nothing: what this band says is for somebody posted there,
 * who may read nothing else of the area, so it names each patrol by reference
 * and offers no door into the module's pages behind a grant they do not hold.
 *
 * WHAT COUNTS AS "FROM HERE" is {@see PatrolRepository::findFromStationBetween()}'s
 * to say. A patrol still out is listed and counted, and its distance is not
 * summed until it is finished — the rule every distance in this module keeps
 * ({@see PatrolStatusEnum::countsTowardsStatistics()}).
 *
 * THE WEEK IS MONDAY TO SUNDAY of the moment the page is drawn, read from the
 * clock the framework registers so a test can stand it still.
 *
 * The roster module's *Watch and presence* band is the other contribution to
 * this seam, and this follows its shape.
 *
 * @see vendor/symfony/dependency-injection/Kernel/Resources/config/services.php — the `clock` service, a Clock over the global one
 */
final readonly class PatrolStationSections implements StationSectionsInterface
{
    /** The band's anchor on the page. */
    public const string PATROLS = 'patrols';

    /** How many of the week's patrols the band lists; the heading counts them all. */
    public const int ROWS = 4;

    public function __construct(
        private StationRepository $stations,
        private PatrolRepository $patrols,
        private ClockInterface $clock,
    ) {
    }

    public function moduleSlug(): string
    {
        return PatrolModuleProvider::SLUG;
    }

    public function sectionsFor(StationSectionRequest $request): StationSections
    {
        if (StationSurface::Mine !== $request->surface || $request->isEmpty()) {
            return StationSections::none();
        }

        $uuids = array_values(array_map(
            static fn (string $uuid): Uuid => Uuid::fromString($uuid),
            array_filter($request->stationUuids(), static fn (string $uuid): bool => Uuid::isValid($uuid)),
        ));
        if ([] === $uuids) {
            return StationSections::none();
        }

        $week = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('monday this week')->setTime(0, 0);
        $nextWeek = $week->modify('+7 days');

        $byStation = [];
        foreach ($this->stations->findBy(['uuid' => $uuids]) as $station) {
            $byStation[(string) $station->getUuidString()] = [$this->band($station, $week, $nextWeek)];
        }

        return new StationSections($byStation);
    }

    private function band(Station $station, \DateTimeImmutable $week, \DateTimeImmutable $nextWeek): StationSection
    {
        $patrols = $this->patrols->findFromStationBetween($station, $week, $nextWeek);

        $km = 0.0;
        foreach ($patrols as $patrol) {
            if ($patrol->getStatus()->countsTowardsStatistics()) {
                $km += $patrol->getDistanceKm() ?? 0.0;
            }
        }

        return new StationSection(
            id: self::PATROLS,
            label: 'Patrols from here',
            template: '@UhifadhiPatrol/station/_mine.html.twig',
            variables: [
                'rows' => array_map(self::row(...), \array_slice($patrols, 0, self::ROWS)),
            ],
            summary: \sprintf('this week · %d · %s km', \count($patrols), number_format($km)),
        );
    }

    /**
     * One patrol as the band prints it — plain values, because the template is
     * given what it prints and nothing else.
     *
     * @return array{ref: string, who: string, startedAt: \DateTimeImmutable|null, out: bool, km: float|null}
     */
    private static function row(Patrol $patrol): array
    {
        return [
            'ref' => $patrol->getRef(),
            'who' => self::shortName($patrol->getLead()),
            'startedAt' => $patrol->getStartedAt(),
            'out' => PatrolStatusEnum::Recording === $patrol->getStatus(),
            'km' => $patrol->getDistanceKm(),
        ];
    }

    /** "N. Example", as the patrol's own page names a ranger; a dash where the patrol names nobody. */
    private static function shortName(?UserInterface $lead): string
    {
        if (null === $lead) {
            return '—';
        }

        $initial = mb_substr($lead->getFirstName() ?? '', 0, 1);
        $name = trim(('' === $initial ? '' : $initial.'.').' '.($lead->getLastName() ?? ''));

        return '' === $name ? '—' : $name;
    }
}
