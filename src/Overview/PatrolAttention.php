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

namespace Uhifadhi\Patrol\Overview;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionItem;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionSeverity;
use Uhifadhi\Patrol\Service\PatrolOverviewService;

/**
 * THE TWO THINGS PATROLS ASKS SOMEBODY TO DO TODAY.
 *
 * A LIVE POSITION THAT HAS STOPPED MOVING — a patrol that is out and has not
 * pinged for longer than the module's own threshold. It is the loudest thing
 * this module can raise, because the record it concerns is a person.
 *
 * A ZONE NOBODY HAS ENTERED — measured from the last track that crossed the
 * polygon, never from the last patrol that named a station. Absence, not
 * activity.
 *
 * THE DESIGN'S THIRD PATROL ROW IS NOT HERE, and its absence is the honest
 * outcome rather than an omission. It reads "O-0411 has been unfiled for five
 * days", and this module cannot know whether an observation has been filed as an
 * incident: the incidents module records that on its own side
 * (`Incident::sourceRecordUuid`) and nothing on this side mirrors it. Raising
 * every observation as unfiled would put a queue of invented work in front of a
 * ranger, and raising none is the only truthful alternative until the contract
 * exists. See PL·A4, which says the same thing in its own words.
 *
 * NOTHING IS STORED and nobody dismisses one by hand. The host asks on every
 * render, so a patrol that pings leaves this list by pinging.
 *
 * Returning `[]` is the right answer on a good day, and a good day is allowed to
 * look like one.
 */
final readonly class PatrolAttention implements AttentionProviderInterface
{
    public function __construct(
        private PatrolOverviewService $overview,
    ) {
    }

    public function moduleSlug(): string
    {
        return PatrolOverviewContributor::SLUG;
    }

    public function attentionFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        return [
            ...$this->silentPatrols($area, $now),
            ...$this->unwatchedZones($area, $now),
        ];
    }

    /**
     * A PATROL THAT HAS STOPPED PINGING — always `Now`, never softer.
     *
     * There is one severity here on purpose. Every other row this module could
     * raise is about a record; this one is about a person who is out and has not
     * been heard from, and a scale that let it read as "this week" would be a
     * scale that got somebody killed. The threshold is the only judgement call,
     * and it is the same 90 minutes the live card prints in its own copy.
     *
     * The headline's first clause is what the host emphasises, so it carries the
     * patrol and the silence; everything a radio operator would ask next — what
     * kind of patrol, out of where, since when, with whom — is the detail.
     *
     * @return list<AttentionItem>
     */
    private function silentPatrols(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $items = [];
        foreach ($this->overview->out($area, $now) as $row) {
            if (!$row['stale']) {
                continue;
            }

            $patrol = $row['patrol'];
            $silence = $row['pingSeconds'] ?? $row['outSeconds'] ?? 0;
            $lead = $patrol->getLead();
            $startedAt = $patrol->getStartedAt();

            $detail = \sprintf(
                '%s patrol out of %s%s%s.',
                ucfirst(mb_strtolower($patrol->getTypeLabel())),
                $patrol->getStation() ?? 'an unrecorded station',
                null === $startedAt ? '' : ' since '.$startedAt->format('H:i'),
                null === $lead ? '' : ', led by '.trim($lead->getFirstName().' '.$lead->getLastName()),
            );

            $meta = [];
            $station = $patrol->getStation();
            if (null !== $station && '' !== $station) {
                $meta[] = $station;
            }
            $meta[] = null === $row['lastPingAt']
                ? 'no ping recorded'
                : 'last ping '.$row['lastPingAt']->format('H:i');

            $items[] = new AttentionItem(
                AttentionSeverity::Now,
                PatrolOverviewContributor::SLUG,
                'Patrols',
                null === $row['pingLabel']
                    ? \sprintf('%s has not pinged at all.', $patrol->getRef())
                    : \sprintf('%s has not pinged for %s.', $patrol->getRef(), $row['pingLabel']),
                'live position',
                PatrolOverviewService::ageLabel($silence),
                $silence,
                $row['url'],
                $detail,
                $meta,
            );
        }

        return $items;
    }

    /**
     * A ZONE NO TRACK HAS ENTERED for long enough to matter.
     *
     * Two steps and no more: a week is somebody's week, a fortnight is somebody's
     * day. `Watch` is deliberately unused — a zone that has gone six days is a
     * zone being patrolled, and putting it on the list would make the list
     * something people scroll past.
     *
     * A zone NO track has ever entered is the worst case there is and has no age
     * to sort by, so it is aged from the beginning of the record this module can
     * see rather than from a date it would have to invent: it says "never" and
     * sorts as the oldest thing in its severity.
     *
     * @return list<AttentionItem>
     */
    private function unwatchedZones(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $gaps = $this->overview->gaps($area, $now);

        $items = [];
        foreach ($gaps['zones'] as $zone) {
            // A zone the worker has not measured yet is not a gap anybody
            // knows about: it raises nothing until its facts are filed.
            if (!$zone['computed']) {
                continue;
            }

            $days = $zone['daysSince'];
            if (null !== $days && $days < PatrolOverviewService::ZONE_GAP_SOON_DAYS) {
                continue;
            }

            $severity = null === $days || $days >= PatrolOverviewService::ZONE_GAP_NOW_DAYS
                ? AttentionSeverity::Now
                : AttentionSeverity::Soon;

            $share = $zone['coverageFraction'];
            $detail = null === $share
                ? 'No track has been recorded here this month, so there is no coverage share to state.'
                : \sprintf('%d %% of the zone is within %s km of a track this month.', (int) round($share * 100), rtrim(rtrim(number_format($gaps['bufferKm'], 1), '0'), '.'));

            $items[] = new AttentionItem(
                $severity,
                PatrolOverviewContributor::SLUG,
                'Patrols',
                null === $days
                    ? \sprintf('No patrol has ever entered %s.', $zone['zone'])
                    : \sprintf('%s has had no patrol for %d days.', $zone['zone'], $days),
                'coverage gap',
                null === $days ? 'never' : \sprintf('%d d', $days),
                // Sorted within the severity by age, so "never" has to be older
                // than any real gap without pretending to a start date.
                null === $days ? \PHP_INT_MAX : $days * 86400,
                $this->overview->dashboardUrl($area),
                $detail,
                array_values(array_filter([
                    $zone['zone'],
                    null === $zone['lastPatrol'] ? null : \sprintf(
                        'last %s, %s',
                        $zone['lastPatrol']->getRef(),
                        $zone['lastEnteredAt']?->format('j M') ?? 'date not recorded',
                    ),
                ])),
            );
        }

        return $items;
    }
}
