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

namespace Uhifadhi\Patrol\Tests\Unit\Template;

use Uhifadhi\Test\FoldConformanceTestCase;

/**
 * NO SHEET THIS MODULE SHIPS EASES A HOUSE FOLD OF ITS OWN: every fold eases
 * from the shell's fold block, the same way everywhere.
 */
final class FoldConformanceTest extends FoldConformanceTestCase
{
    protected static function sheets(): iterable
    {
        $public = \dirname(__DIR__, 3).'/public';
        foreach (glob($public.'/*.css') ?: [] as $path) {
            yield basename($path) => (string) file_get_contents($path);
        }
    }
}
