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

use Uhifadhi\Bundle\ShellBundle\Contract\StylesheetSourceInterface;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;

/**
 * THE SHEET THIS MODULE'S CARDS ON A PERSON'S OWN DASHBOARD NEED, asked for by
 * the head before the body that draws them is rendered (#19).
 *
 * ONLY THAT SHEET. The module's own screens link `patrol.css` in their own
 * `stylesheets` block, which is the normal arrangement and stays. The week's
 * bars are written into the area's `/` — a page that has never heard of this
 * module and cannot link a sheet for a card it does not know is coming, while
 * a `<link rel="stylesheet">` in the body is not conforming HTML. So the head
 * carries a small sheet with the bars in it and nothing else.
 *
 * @see StylesheetSourceInterface why the head asks, and the HTML rule it quotes
 */
final readonly class PatrolStylesheets implements StylesheetSourceInterface
{
    public function stylesheets(): iterable
    {
        yield UhifadhiPatrolBundle::ME_STYLESHEET;
    }
}
