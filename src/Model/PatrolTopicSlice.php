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

namespace Uhifadhi\Patrol\Model;

/**
 * THE GROUND ONE ROW OF THE TOPIC'S MATRIX IS ABOUT — the page's scope and the
 * department's scope, intersected, and nothing else.
 *
 * A DEPARTMENT IS A LENS, NOT A GATE. It never filters records by who recorded
 * them: two departments reading the same ground read the same figures, exactly
 * as {@see \Uhifadhi\Patrol\Module\PatrolDepartmentKpiProvider} already states.
 * All a department contributes to a figure is HOW MUCH GROUND it reads, and
 * that is the whole of what this resolves.
 *
 * THE FOUR CASES, AND THEY ARE ONE RULE. The slice is the intersection of the
 * page's scope with the department's:
 *
 * | Page          | Department    | Reads                                   |
 * |---------------|---------------|-----------------------------------------|
 * | organization  | org-wide      | every area                              |
 * | organization  | one area      | that area                               |
 * | one area      | org-wide      | the page's area — an org-wide department reads it too |
 * | one area      | the same area | that area                               |
 * | one area      | another area  | NOTHING — it is not a row of this page  |
 *
 * The last line is why this answers null rather than an empty slice: a
 * department confined to somewhere else is not a row with no figures, it is not
 * a row at all, which is the same distinction the matrix draws between a dash
 * and an absent row.
 *
 * It is a model, not an entity: made for one render and thrown away.
 */
final readonly class PatrolTopicSlice
{
    private function __construct(
        /** The one area this row reads, or NULL for every area of the organization. */
        public ?string $areaUuid,
    ) {
    }

    /**
     * The ground a department reads on a page of this scope, or NULL where the
     * department is no row of this page at all.
     *
     * @param string|null $pageAreaUuid       null for the organization's page
     * @param string|null $departmentAreaUuid null for an organization-wide department
     */
    public static function of(?string $pageAreaUuid, ?string $departmentAreaUuid): ?self
    {
        if (null === $pageAreaUuid) {
            return new self($departmentAreaUuid);
        }

        if (null === $departmentAreaUuid || $departmentAreaUuid === $pageAreaUuid) {
            return new self($pageAreaUuid);
        }

        return null;
    }

    /** Whether the row rolls up every area rather than reading one. */
    public function isRollUp(): bool
    {
        return null === $this->areaUuid;
    }
}
