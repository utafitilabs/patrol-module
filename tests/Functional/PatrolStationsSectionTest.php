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

namespace Uhifadhi\Patrol\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Patrol\Entity\Station;

/**
 * THE STATIONS SECTION — its own entry in the configure strip, its own address,
 * and the writes behind it.
 *
 * A station is never deleted: a patrol filed against one keeps it, so retiring
 * takes it off the handset at the next sync and leaves the row, its wire key and
 * every record intact.
 */
final class PatrolStationsSectionTest extends ConfigureSectionTestCase
{
    protected function section(): string
    {
        return 'stations';
    }

    private function northPost(): Station
    {
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'north-post');
        self::assertInstanceOf(Station::class, $station);

        return $station;
    }

    /**
     * THE PAGE LINKS THE SHEETS THAT SHIP WHAT IT DRAWS — this module's own for
     * `.spoint` and `.sppick`, and the atlas's for the plate the picker is. A
     * section rendered as a body inside the shell's configure page could link
     * neither, and would draw an unstyled box where the map is.
     */
    public function testTheSectionLinksTheModulesOwnStylesheetAndTheAtlass(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->sectionUrl());

        self::assertResponseIsSuccessful();
        $this->assertLinksTheModulesSheet($crawler);
    }

    /** The section is lit in the strip and its body is a body. */
    public function testTheSectionIsLitAndDrawsOneCard(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->sectionUrl());

        self::assertResponseIsSuccessful();
        self::assertSame('Stations', trim($crawler->filter('.atabs a.on')->text()));
        self::assertSame(
            ['Stations'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $t): string => trim(str_replace((string) $t->filter('.src')->text(''), '', $t->text())),
            ),
        );
        $row = $crawler->filter('.staddrow');
        self::assertSame('Cancel', trim($row->filter('a.tgl')->text()));
        self::assertSame('Save stations', trim($row->filter('button.cta')->text()));
        self::assertSame('Add a station', trim($crawler->filter('.saddcard > .hd')->text()));
        self::assertSame('+ Add station', trim($crawler->filter('.saddcard .sadd')->text()));
    }

    /**
     * A ROW IS THE NAME, THE WIRE KEY, THE COUNT AND ITS ACTIONS, as the design
     * draws it.
     */
    public function testARowCarriesItsCountAndItsActions(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->sectionUrl());

        $row = $crawler->filter('.srow')->first();
        self::assertSame('North post', trim($row->filter('.nm')->text()));
        self::assertSame('north-post', trim($row->filter('.cd')->text()));
        self::assertSame('1 patrol', trim($row->filter('.n')->text()));
        self::assertSame(
            ['Rename', 'Save', 'Retire'],
            $row->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    /**
     * THE FIELD IS NOT ON THE ROW UNTIL RENAME IS PRESSED. The design's row is
     * text and two buttons; an input sitting open on every row turns a list of
     * words into a page of form controls. It is disclosed by the row's own Rename
     * control and by nothing else — HTML's own disclosure.
     */
    public function testTheRenameFieldIsDisclosedByTheRenameControl(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->sectionUrl());

        $row = $crawler->filter('.srow')->first();
        self::assertCount(0, $row->filter('.acts > .fld'));

        $rename = $row->filter('.acts details');
        self::assertCount(1, $rename);
        self::assertNull($rename->attr('open'), 'The row opens closed.');
        self::assertSame('Rename', trim($rename->filter('summary')->text()));
        self::assertCount(1, $rename->filter('input.fld[name="label"]'));
    }

    /** A new station, then renamed, then retired — and never deleted. */
    public function testAStationIsAddedRenamedAndRetiredWithoutLosingAPatrol(): void
    {
        $this->signInAsManager();

        $this->post($this->sectionUrl(), ['label' => 'Ridge Camp']);
        self::assertResponseRedirects($this->sectionUrl());

        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);

        $this->post($this->sectionUrl($station->getUuid()->toRfc4122().'/rename'), ['label' => 'Lake Post']);
        $this->em->clear();
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);
        self::assertSame('Lake Post', $station->getLabel());
        self::assertSame('ridge-camp', $station->getKey(), 'A rename never touches the wire value.');

        $this->post($this->sectionUrl($station->getUuid()->toRfc4122().'/retire'), []);
        $this->em->clear();
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);
        self::assertFalse($station->isActive());

        // Dimmed and pilled on the page, never gone.
        $crawler = $this->client->request('GET', $this->sectionUrl());
        $retired = $crawler->filter('.srow.gone');
        self::assertSame('Lake Post', trim($retired->filter('.nm')->text()));
        self::assertSame('retired', trim($retired->filter('.chip.idle')->text()));
        self::assertSame(
            ['Rename', 'Save', 'Reactivate'],
            $retired->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    /** Two words the same is refused rather than quietly made twice. */
    public function testASecondStationWithTheSameNameIsRefused(): void
    {
        $this->signInAsManager();

        $this->post($this->sectionUrl(), ['label' => 'north POST']);

        self::assertResponseRedirects($this->sectionUrl());
        self::assertCount(1, $this->stations()->findByArea($this->area));
    }

    /**
     * A STATION WITH NO POINT ASKS FOR ONE ON ITS ROW, and nothing is blocked
     * while it has none — which is the state every station carried over from
     * before points existed is in.
     */
    public function testAStationWithNoPointAsksForOneOnItsRow(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->sectionUrl());

        $asking = $crawler->filter('.srow .spoint.ask');
        self::assertCount(1, $asking);
        self::assertSame('set point', trim($asking->text()));
        self::assertStringContainsString('point=', (string) $asking->attr('href'));
    }

    /**
     * A POINT SAVED ON THE SECTION IS THE STATION'S, AND THE ROW READS IT BACK in
     * the degrees-minutes-seconds every other coordinate in this module is printed
     * in.
     */
    public function testAPointPickedOnThePlateIsStoredAndReadBackOnTheRow(): void
    {
        $this->signInAsManager();
        $station = $this->northPost();

        $this->post($this->sectionUrl(), [
            'point' => $station->getUuid()->toRfc4122(),
            'point_lat' => '-5.65',
            'point_lng' => '12.35',
        ]);
        self::assertResponseRedirects($this->sectionUrl());

        $this->em->clear();
        $stored = $this->northPost()->getPoint();
        self::assertIsString($stored);
        self::assertStringContainsString('"type":"Point"', $stored);
        self::assertStringContainsString('12.35', $stored);

        $crawler = $this->client->request('GET', $this->sectionUrl());
        $pill = $crawler->filter('.srow .spoint')->first();
        self::assertStringNotContainsString('ask', (string) $pill->attr('class'));
        self::assertSame('5°39\'00"S 12°21\'00"E', trim($pill->text()));
    }

    /** A coordinate outside the world is not a place, so nothing is written. */
    public function testACoordinateOffTheWorldIsRefusedAndWritesNothing(): void
    {
        $this->signInAsManager();
        $station = $this->northPost();

        $this->post($this->sectionUrl(), [
            'point' => $station->getUuid()->toRfc4122(),
            'point_lat' => '-91.4',
            'point_lng' => '12.35',
        ]);

        $this->em->clear();
        self::assertNull($this->northPost()->getPoint());
    }

    /** The point the add panel carries belongs to the station it creates. */
    public function testANewStationIsCreatedWithThePointOnThePlate(): void
    {
        $this->signInAsManager();

        $this->post($this->sectionUrl(), [
            'label' => 'Ridge Camp',
            'point_lat' => '-5.7',
            'point_lng' => '12.4',
        ]);

        $this->em->clear();
        $created = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $created);
        self::assertIsString($created->getPoint());
    }

    /**
     * THE PLATE IS THE HOUSE PLATE, and one marker on it is the only thing being
     * changed. The area's boundary is under it and the stations that already have
     * a point are drawn quietly for bearings.
     */
    public function testTheAddPanelCarriesTheHousePlateWithOneMarkerOnIt(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->sectionUrl());

        $picker = $crawler->filter('.sppick');
        self::assertCount(1, $picker);

        // The atlas's plate, not a map of this module's own.
        $plate = $picker->filter('.map-plate');
        self::assertCount(1, $plate);
        self::assertStringContainsString('atlas-bundle--map-plate', (string) $plate->attr('data-controller'));

        // ONE marker, and it is the one the picker drags. The value is read off
        // whichever attribute the configured UX Map bridge named it with, because
        // the bridge is a deployment's choice and this assertion is about the map
        // carrying exactly one marker.
        $canvas = $picker->filter('.map-canvas')->getNode(0);
        self::assertInstanceOf(\DOMElement::class, $canvas);

        $markers = null;
        foreach ($canvas->attributes as $attribute) {
            if (str_ends_with($attribute->name, '-markers-value')) {
                $markers = json_decode($attribute->value, true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        self::assertIsArray($markers);
        self::assertCount(1, $markers);
        self::assertStringContainsString('drag to place', json_encode($markers, \JSON_THROW_ON_ERROR));

        // The legend says what the two point layers mean, as every plate must.
        self::assertStringContainsString('stations with a point', $picker->filter('.map-legend')->text());
        self::assertStringContainsString('the point being placed', $picker->filter('.map-legend')->text());

        // The coordinate it reads out, and the two fields it travels in.
        self::assertCount(1, $picker->filter('.ft .co'));
        self::assertCount(1, $crawler->filter('input[name="point_lat"]'));
        self::assertCount(1, $crawler->filter('input[name="point_lng"]'));
    }

    /**
     * A ROW THAT ASKED IS WHAT THE PLATE IS THEN PLACING, named on it — the state
     * the design draws, where the marker's own title says whose point it is.
     */
    public function testARowThatAskedBindsThePlateToThatStation(): void
    {
        $this->signInAsManager();
        $station = $this->northPost();

        $crawler = $this->client->request(
            'GET',
            $this->sectionUrl().'?point='.$station->getUuid()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            $station->getUuid()->toRfc4122(),
            $crawler->filter('input[name="point"]')->attr('value'),
        );
        self::assertStringContainsString('North post', $crawler->filter('.sppick .ft')->text());
    }

    /** Editing the words rides on the same authority the numbers do. */
    public function testSomebodyWhoMayNotManageCannotAddAStation(): void
    {
        $this->signInAsRecorder();

        $this->client->request('POST', $this->sectionUrl(), ['label' => 'Ridge Camp']);

        self::assertResponseStatusCodeSame(403);
    }
}
