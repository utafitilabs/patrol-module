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

use PHPUnit\Framework\TestCase;

/**
 * THE DESIGN WORKSPACE'S LABELS DO NOT SHIP.
 *
 * Every widget in the design files carries an identifier — PL·07, TM·K1, FL·05,
 * MB·02 — printed in the card's tab. That is the workspace's own referencing
 * system: it is how a decisions table points at a frame, and it exists so two
 * people arguing about a design can name the thing they are arguing about. It
 * is not product. A ranger on the patrol dashboard has no decisions table,
 * cannot follow the reference and reads "PL·07Patrol feed" as a typo.
 *
 * These labels were ported into the shipped templates once and rendered in a
 * live installation. This test pins the whole class of defect rather than the
 * instances somebody happened to notice: no template may emit a widget identifier,
 * in any of the forms Twig can write one.
 *
 * The design COMMENTS at the top of these files still name the widgets, and
 * should — that is where the reference belongs, and a comment reaches nobody's
 * screen. Only markup is scanned.
 */
final class NoWorkshopLabelsTest extends TestCase
{
    /**
     * The chip's own class, and the identifier pattern itself in the three
     * spellings a template can carry it: the literal middot, the named entity
     * and the numeric one.
     */
    private const array FORBIDDEN = [
        'idx-chip' => '/class="[^"]*\bidx\b[^"]*"/',
        'literal middot' => '/\b(?:TM|PM|DP|MB|AU|FL|PL|ME|SN)\x{00B7}/u',
        'named entity' => '/\b(?:TM|PM|DP|MB|AU|FL|PL|ME|SN)&middot;/',
        'numeric entity' => '/\b(?:TM|PM|DP|MB|AU|FL|PL|ME|SN)&#(?:183|xB7);/i',
    ];

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('twig' !== $file->getExtension()) {
                continue;
            }
            yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testNoTemplateEmitsAWorkshopLabel(string $path): void
    {
        $markup = self::withoutComments((string) file_get_contents($path));

        foreach (self::FORBIDDEN as $what => $pattern) {
            self::assertSame(
                0,
                preg_match($pattern, $markup),
                \sprintf('%s carries a design-workshop %s. Widget identifiers belong in the design files, never in shipped markup.', basename($path), $what),
            );
        }
    }

    /** Twig comments are not markup: a design note may name every widget it likes. */
    private static function withoutComments(string $twig): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $twig);
    }
}
