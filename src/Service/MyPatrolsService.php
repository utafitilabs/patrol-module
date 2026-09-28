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
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Model\MyPatrols;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * WHAT ONE PERSON HAS WALKED — the reading behind the patrol module's cards on
 * their own dashboard (#19, option A ruled 28 Sep 2026).
 *
 * THE PERSON IS THE PATROL'S LEAD ({@see \Uhifadhi\Patrol\Entity\Patrol::getLead()}):
 * the account the handset was signed in on when it opened the patrol, or the
 * lead the web entry flow named. Their observations are the ones logged on
 * the patrols they led.
 *
 * THE WEEK IS MONDAY TO SUNDAY and the month is the calendar month, both of
 * the moment the dashboard is drawn at; last month is the calendar month
 * before. Distance is the patrols' own recorded `distanceKm`, summed over the
 * patrols that count towards a statistic — finished, not withdrawn — which is
 * the same figure every other distance in this module adds up.
 */
final readonly class MyPatrolsService
{
    /** How many patrols and observations the two lists carry. */
    public const int LATEST = 4;

    private const array DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolRepository $patrols,
        private ObservationRepository $observations,
    ) {
    }

    /** One person's reading at one moment, or null where no account answers to the uuid. */
    public function read(string $personUuid, \DateTimeImmutable $now): ?MyPatrols
    {
        $person = $this->person($personUuid);
        if (null === $person) {
            return null;
        }

        $month = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $month->modify('+1 month');
        $lastMonth = $month->modify('-1 month');
        $week = $now->modify('monday this week')->setTime(0, 0);
        $nextWeek = $week->modify('+7 days');

        $monthKm = $weekKm = $lastMonthKm = 0.0;
        $monthPatrols = $monthCounted = $lastMonthCounted = 0;
        $longest = null;
        $days = array_fill(0, 7, 0.0);

        // ONE READ FOR EVERY WINDOW: last month's first day is always before
        // this week's Monday, and the week may run past the month's end.
        foreach ($this->patrols->findLedByBetween($personUuid, $lastMonth, $nextWeek > $nextMonth ? $nextWeek : $nextMonth) as $patrol) {
            $started = $patrol->getStartedAt();
            if (null === $started) {
                continue;
            }
            $counts = $patrol->getStatus()->countsTowardsStatistics();
            $km = $counts ? ($patrol->getDistanceKm() ?? 0.0) : 0.0;

            if ($started >= $month && $started < $nextMonth) {
                ++$monthPatrols;
                if ($counts) {
                    ++$monthCounted;
                    $monthKm += $km;
                    if (null !== $patrol->getDistanceKm() && ($longest?->getDistanceKm() ?? -1.0) < $km) {
                        $longest = $patrol;
                    }
                }
            } elseif ($started >= $lastMonth && $started < $month && $counts) {
                ++$lastMonthCounted;
                $lastMonthKm += $km;
            }

            if ($counts && $started >= $week && $started < $nextWeek) {
                $weekKm += $km;
                $days[(int) $week->diff($started)->days] += $km;
            }
        }

        $tallest = max($days);
        $bars = [];
        foreach (self::DAYS as $i => $label) {
            $bars[] = [
                'label' => $label,
                'km' => $days[$i],
                'height' => $tallest > 0.0 ? round($days[$i] / $tallest * MyPatrols::TALLEST_BAR, 2) : 0.0,
            ];
        }

        $observed = $this->observations->countOnPatrolsLedBetween($personUuid, $month, $nextMonth);

        return new MyPatrols(
            person: $person,
            monthKm: $monthKm,
            weekKm: $weekKm,
            monthPatrols: $monthPatrols,
            monthCounted: $monthCounted,
            outNow: $this->patrols->countOutLedBy($personUuid),
            lastMonthKm: $lastMonthKm,
            lastMonthCounted: $lastMonthCounted,
            longest: $longest,
            monthObservations: $observed['all'],
            monthObservationsWithPhotos: $observed['withPhotos'],
            week: $bars,
            latestPatrols: $this->patrols->findLatestLedBy($personUuid, self::LATEST),
            latestObservations: $this->observations->findLatestOnPatrolsLedBy($personUuid, self::LATEST),
        );
    }

    /**
     * The account, by the published uuid — read through the contract the
     * installation resolves, the way {@see Api\RangerResolver} reads one, so
     * this module never names the class that implements it.
     */
    private function person(string $personUuid): ?UserInterface
    {
        if (!Uuid::isValid($personUuid)) {
            return null;
        }

        /** @var UserInterface|null $person */
        $person = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(UserInterface::class, 'u')
            ->where('u.uuid = :uuid')
            ->setParameter('uuid', Uuid::fromString($personUuid), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $person;
    }
}
