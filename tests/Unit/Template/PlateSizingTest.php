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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PATROL'S PLATES ARE SIZED BY PATROL'S OWN DESIGN, and by one property.
 *
 * A plate carries a real height off `--map-plate-height` and refuses to stretch,
 * so a screen states its height by setting that property on the card the plate
 * is in. Sizing one with `min-height` plus `flex: 1` is what made a plate grow
 * to whatever the tallest sibling in its row happened to be.
 *
 * A TEXT CHECK over the shipped sheet, and that is the limit of what it
 * promises: it catches a plate sized some other way or against some other
 * screen's number, never a plate that renders wrongly for another reason.
 * Rendered fidelity is a sweep, not a unit test.
 */
final class PlateSizingTest extends TestCase
{
    /** The height the patrols coverage map states in the design. */
    private const string COVERAGE_HEIGHT = 'min(56vh, 540px)';

    public function testTheCoveragePlateIsTheHeightTheDesignStates(): void
    {
        self::assertMatchesRegularExpression(
            '/\.patrol-coverage-plate \{[^}]*--map-plate-height: '.preg_quote(self::COVERAGE_HEIGHT, '/').'/',
            self::stylesheet(),
        );
    }

    /** The height of a record page's IMAGERY in the design: the house record plate. */
    private const string RECORD_HEIGHT = 'min(58vh, 560px)';

    /**
     * A RECORD PAGE'S IMAGERY IS THE HOUSE RECORD PLATE — the design's
     * `.pp-main > .c.plate-fill`, whose viewer is `flex: none; height:
     * min(58vh, 560px)` in the one column under the index. A map is a
     * fixed-size plate of imagery, not a column of facts.
     *
     * `--map-plate-height` sizes the PLATE, which is the imagery plus everything
     * the plate stacks above it, so the design's one number is not the whole
     * height: a bare height is that much plate and less than that of map. The
     * height therefore adds the caption row this module puts in the plate's
     * filter slot and the offset the atlas sets the imagery frame at — each a
     * named part, so a reader can see what the sum is made of.
     */
    public function testTheRecordPlateIsTheHeightTheDesignStates(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression(
            '/\.onpage-main > \.patrol-plate-fill \{[^}]*--patrol-plate-imagery: '.preg_quote(self::RECORD_HEIGHT, '/').'/',
            $sheet,
            'the design\'s imagery height is stated, and by name.',
        );
        self::assertMatchesRegularExpression(
            '/\.onpage-main > \.patrol-plate-fill \{[^}]*--map-plate-height: calc\('
            .'[^)]*var\(--patrol-plate-imagery\)'
            .'[^;]*var\(--patrol-cap-row\)'
            .'[^;]*var\(--patrol-plate-frame-offset\)/',
            $sheet,
            'the plate is as tall as the imagery plus everything drawn above it.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.onpage-main > \.patrol-plate-fill \{[^}]*min-height/',
            $sheet,
            'A floor under a fixed height is a floor that can only fight it.',
        );
    }

    /**
     * AND THE CARD NEVER ALIGNS ITSELF IN A COLUMN. The plate card stacks in the
     * record's left column with whatever the record lists under its map, and
     * that column is a flex COLUMN: `align-self: start` there is a cross-axis
     * instruction, so it would shrink the card to the width of its own content
     * instead of keeping it the column's width.
     */
    public function testTheRecordPlateCardDoesNotAlignItselfAcrossItsColumn(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\.patrol-plate-fill[^{]*\{[^}]*align-self/',
            self::stylesheet(),
        );
    }

    /**
     * THE CAPTION ROW'S HEIGHT IS STATED, NEVER INHERITED from whichever font
     * the host resolved: the plate height above adds that row, and a row whose
     * height comes from a font's default line box makes the sum a guess that is
     * wrong by a pixel or two on every machine.
     */
    public function testTheCaptionRowStatesItsOwnHeight(): void
    {
        self::assertMatchesRegularExpression(
            '/\.patrol-cap \{[^}]*(line-height: *\d+px|font:[^;]*\d+px\/\d+px)/',
            self::stylesheet(),
        );
    }

    /**
     * And BOTH record pages wear the hook, or the rule above dresses one screen
     * and the design's other map is a different size from its own twin.
     *
     * @return iterable<string, array{string}>
     */
    public static function recordPages(): iterable
    {
        yield 'patrol' => ['patrol/show.html.twig'];
        yield 'observation' => ['observation/show.html.twig'];
    }

    #[DataProvider('recordPages')]
    public function testBothRecordPagesWearThatHook(string $template): void
    {
        $markup = file_get_contents(\dirname(__DIR__, 3).'/templates/'.$template);
        self::assertIsString($markup);

        self::assertStringContainsString('patrol-plate patrol-plate-fill', $markup);
    }

    /**
     * THE PLATE'S OWN LAYOUT IS THE ATLAS'S. A module that sizes it any other
     * way is the module whose map reads differently from every other one — and
     * with a real height on the plate, a min-height or a flex here does nothing
     * except mislead the next reader.
     */
    public function testThisSheetSizesNoPlateAnyOtherWay(): void
    {
        $sheet = self::stylesheet();

        self::assertDoesNotMatchRegularExpression('/\.map-plate[^{]*\{[^}]*min-height/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\.map-plate[^{]*\{[^}]*flex:/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\.map-plate[^{]*\{[^}]*height: *[^v]/', $sheet);
    }

    /**
     * A sibling module's plate height appears nowhere here: a screen sized
     * against another module's screen drifts while every test stays green.
     */
    public function testNoSiblingModulesPlateHeightIsStatedHere(): void
    {
        self::assertStringNotContainsString('min(46vh,440px)', self::stylesheet());
        self::assertStringNotContainsString('min(46vh, 440px)', self::stylesheet());
    }

    /**
     * THE PLATE'S INTERNALS ARE THE ATLAS'S TOO — the imagery frame, the
     * chrome, the legend and Leaflet's own controls.
     */
    public function testThisSheetStatesNothingAboutThePlatesInternals(): void
    {
        // Rules, not prose: the sheet's own header names host vocabulary it
        // deliberately leaves alone, and naming it is the opposite of styling it.
        foreach (['viewer', 'leaflet', 'map-chrome', 'map-legend'] as $theirs) {
            self::assertDoesNotMatchRegularExpression(
                '/^[^\/\n][^\n]*\.'.preg_quote($theirs, '/').'[^\n]*\{/m',
                self::stylesheet(),
                \sprintf('.%s is the atlas\'s; a module that styles it makes its own map the odd one out.', $theirs),
            );
        }
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/patrol.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
