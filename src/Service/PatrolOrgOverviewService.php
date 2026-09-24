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

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Model\PatrolOrgReading;
use Uhifadhi\Patrol\Model\PatrolOutRow;

/**
 * WHAT PATROLS TELLS THE ORGANIZATION DASHBOARD — the area reading, one scope
 * wider, and nothing else.
 *
 * THIS CLASS COUNTS NOTHING ITSELF. It resolves the scope to areas and then
 * asks {@see PatrolOverviewService} and {@see PatrolFigureService} — the two
 * places this module's readings are actually made — once per area, and adds
 * the answers up. That is the contract's own rule for an organization-level
 * figure, and writing it this way is the only way to keep it: the
 * organization's "3 out" cannot disagree with the areas' because it IS the
 * areas', concatenated.
 *
 * SO THERE IS NO SECOND QUERY AND NO SECOND THRESHOLD. What counts as out,
 * what counts as silent, and what counts towards a total are each decided in
 * one place, and none of those places is here.
 *
 * PURE OF THE CLOCK: `$now` is handed in and never read, so the dashboard is
 * testable at a fixed moment exactly as the area overview is.
 */
final readonly class PatrolOrgOverviewService
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private PatrolOverviewService $overview,
        private PatrolFigureService $figures,
    ) {
    }

    /**
     * THE WHOLE OF THIS MODULE'S ORGANIZATION-LEVEL READING, at one moment.
     *
     * One call per cell and per figure would measure the same morning twice
     * and let two cards on one page disagree, so the contributor asks this
     * once and hands the answer to both.
     */
    public function forScope(Scope $scope, \DateTimeImmutable $now): PatrolOrgReading
    {
        $areas = $this->areasIn($scope);

        $out = [];
        $walked = null;
        $withRegister = [];

        foreach ($areas as $area) {
            $name = (string) $area->getName();

            foreach ($this->overview->out($area, $now) as $row) {
                $out[] = self::row($row, $name);
            }

            // ASKED ONCE, AND ONLY WHERE THERE IS SOMETHING TO ASK. An area
            // that has never opened a patrol has no day to report, and reading
            // its day would buy a handful of queries for an answer that is not
            // a zero but an absence.
            if (!$this->overview->hasRegister($area)) {
                continue;
            }

            $withRegister[] = $area;
            $today = $this->overview->today($area, $now)['distanceKm'];
            if (null !== $today) {
                $walked = ($walked ?? 0.0) + $today;
            }
        }

        // LONGEST OUT FIRST, ACROSS AREAS, for the reason one area's rows are:
        // the row that has been open longest is the one somebody is deciding
        // about. Which area it is in is a column, not an ordering.
        usort($out, static fn (PatrolOutRow $a, PatrolOutRow $b): int => ($a->startedAt?->getTimestamp() ?? \PHP_INT_MIN) <=> ($b->startedAt?->getTimestamp() ?? \PHP_INT_MIN));

        return new PatrolOrgReading(
            out: $out,
            walkedTodayKm: $walked,
            // THE MODULE'S OWN COUNTING RULE, applied in the one place it is
            // written: a discarded outing did not happen and one still
            // recording has not finished happening, so neither is in the week.
            thisWeek: $this->figures->tally($withRegister, self::weekStart($now), $now)->patrols,
            areasWithRegister: \count($withRegister),
            areasInScope: \count($areas),
            // THE DOOR OPENS A PAGE, so it is offered only where ONE page
            // answers for every row on the cell. An organization patrolling in
            // four areas has four such pages and no page above them; the cell
            // says how many areas it is reading instead of picking one.
            dashboardUrl: 1 === \count($withRegister) ? $this->overview->dashboardUrl($withRegister[0]) : null,
        );
    }

    /** Monday 00:00 of the week `$now` falls in — the week every other weekly figure in this module uses. */
    public static function weekStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('monday this week')->setTime(0, 0);
    }

    /**
     * The areas a scope names, in the register's own order.
     *
     * @return list<AreaOfInterest>
     */
    private function areasIn(Scope $scope): array
    {
        if ($scope->isOrganization()) {
            return $this->areas->findAllOrdered();
        }

        $uuid = (string) $scope->areaUuid;
        if (!Uuid::isValid($uuid)) {
            return [];
        }

        $area = $this->areas->findOneByUuid($uuid);

        return null === $area ? [] : [$area];
    }

    /**
     * One row of the per-area reading, restated as the organization's own row
     * — the same facts, plus the area they came from.
     *
     * @param array{patrol: Patrol, url: string, outSeconds: int|null, outLabel: string|null, lastPingAt: \DateTimeImmutable|null, pingSeconds: int|null, pingLabel: string|null, stale: bool, line: string|null, point: string|null} $row
     */
    private static function row(array $row, string $areaName): PatrolOutRow
    {
        $rangers = \count($row['patrol']->getTeamRangerIds());

        return new PatrolOutRow(
            ref: $row['patrol']->getRef(),
            areaName: $areaName,
            startedAt: $row['patrol']->getStartedAt(),
            kindLabel: $row['patrol']->getTypeLabel(),
            // A RECORD THAT NAMES NOBODY DID NOT GO OUT WITH NOUGHT RANGERS.
            // Only the field app sends a team, so a web-logged patrol says
            // nothing here rather than claiming an empty one.
            rangers: 0 === $rangers ? null : $rangers,
            pingLabel: $row['pingLabel'],
            stale: $row['stale'],
            url: $row['url'],
        );
    }
}
