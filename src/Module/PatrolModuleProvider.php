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

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;
use Uhifadhi\Contracts\Shell\OrgPage;
use Uhifadhi\Contracts\Shell\OrgPagesInterface;
use Uhifadhi\Patrol\Controller\PatrolOrgController;

/**
 * Declares the one module this bundle contributes — "Patrols". It owns its
 * pages (entryRoute), so the host links straight to the patrol dashboard
 * instead of rendering it through the generic module page.
 */
final class PatrolModuleProvider implements ModuleProviderInterface, OrgPagesInterface
{
    use ModuleProviderTrait;

    /**
     * THE SLUG, ONCE. It is the answer below, and it is also what every
     * controller in this bundle stamps on its routes so the registry can close
     * them where an area has parked this module — two places that must never
     * drift, so there is only one string.
     */
    public const string SLUG = 'patrols';

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    /** The one sentence the catalogue prints under the name — what the module is, for a stranger. */
    public function description(): string
    {
        return 'Ranger patrols — tracks, observations and station duty.';
    }

    public function name(): string
    {
        return 'Patrols';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'GPS field tracks';
    }

    public function icon(): string
    {
        return 'patrol:footprints';
    }

    public function entryRoute(): string
    {
        return 'patrol_dashboard';
    }

    /*
     * WHAT THIS MODULE LETS SOMEBODY ACT ON is declared as CONCERNS —
     * {@see \Uhifadhi\Patrol\Access\PatrolConcerns}, tagged
     * `uhifadhi.access.concerns` — a thing to act on with the verbs it
     * supports and the scopes it offers, and nowhere else: two catalogues
     * naming the same power would let an administrator grant it twice and
     * revoke it once.
     */

    /**
     * THE AREA'S LIST AND ITS PATROL TYPES, read across every area the
     * module is switched on in. The shell mounts them in the sidebar and draws
     * their strip; the first is the one the row opens.
     */
    public function orgPages(): array
    {
        return [
            new OrgPage('patrols', 'Patrols', PatrolOrgController::PATROLS_ROUTE),
            new OrgPage('types', 'Patrol types', PatrolOrgController::TYPES_ROUTE),
        ];
    }
}
