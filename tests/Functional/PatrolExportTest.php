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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * THE DESIGN'S `Export` ACTION — the filtered log as CSV, the filtered tracks as
 * GPX, at one address in two formats.
 *
 * WHAT IS ACTUALLY BEING ASSERTED is that the file answers the SAME question the
 * page does. Both tests narrow the request the way a chip narrows the page and
 * then read what came back: a file that quietly exported the whole month while
 * the screen showed one station is the defect this exists to prevent, and it is
 * invisible to a test that only checks the status code.
 */
final class PatrolExportTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private const string MONTH = '2026-03';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[-30.1,-1.1],[-29.9,-1.1],[-29.9,-0.9],[-30.1,-0.9],[-30.1,-1.1]]]]}',
        );
        $this->em->persist($this->area);

        // Two recorded patrols from two stations, and one hand-logged with no
        // route at all — the three cases the two files have to tell apart.
        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'River Post'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setTrack('{"type":"LineString","coordinates":[[-30.0,-1.0],[-29.95,-1.02]]}')
            ->setDistanceKm(6.1)
            ->setStartedAt(new \DateTimeImmutable('2026-03-04 06:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-03-04 09:00')));

        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'boat'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'Ridge Camp'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setTrack('{"type":"LineString","coordinates":[[-30.05,-1.05],[-29.98,-1.01]]}')
            ->setDistanceKm(9.4)
            ->setStartedAt(new \DateTimeImmutable('2026-03-06 06:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-03-06 10:00')));

        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'River Post'))
            ->setStartedAt(new \DateTimeImmutable('2026-03-08 06:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-03-08 08:00')));

        $this->everyAreaRunsPatrols($this->em);
        // Exporting is its own act: the bystander reads the register and
        // cannot carry it out of the building, so these sign in as the tier
        // that may.
        $this->signIn($this->client, $this->em, FixedRecordVoter::RECORDER_EMAIL);
    }

    private function exportUrl(string $format, string $query = ''): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/export.'.$format
            .'?month='.self::MONTH.$query;
    }

    /**
     * A streamed response's bytes. BrowserKit buffers the stream as it plays
     * the kernel, so the INTERNAL response is where the content is; the
     * framework Response object holds a callback and no string.
     */
    private function body(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    public function testTheCsvIsTheLogsOwnColumnsForTheMonthOnScreen(): void
    {
        $this->client->request('GET', $this->exportUrl('csv'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('patrols-2026-03.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", trim($this->body()))));
        self::assertSame(
            'ref,name,type,type_label,station,station_label,zone,lead,team,started_at,ended_at,distance_km,observations,source,status,note',
            $lines[0],
        );
        // Three patrols, newest first — the hand-logged one included: it is a
        // row of the log, and the CSV is the log.
        self::assertCount(4, $lines);
        self::assertStringContainsString('walk,"Walking round",'.Vocabulary::stationKey('river-post').',"River Post"', $lines[1]);

        // The WIRE key and the LABEL are both there, and they are not the same
        // thing: the key is what a saved filter holds, the label what a person
        // reads.
        self::assertStringContainsString('boat,Boat,'.Vocabulary::stationKey('ridge-camp').',"Ridge Camp"', $lines[2]);
    }

    public function testTheCsvCarriesTheFilterOnScreenAndNothingElse(): void
    {
        $this->client->request('GET', $this->exportUrl('csv', '&station='.Vocabulary::stationKey('ridge-camp')));

        self::assertResponseIsSuccessful();
        $lines = array_values(array_filter(explode("\n", trim($this->body()))));
        self::assertCount(2, $lines, 'The file exported a different month than the page was showing.');
        self::assertStringContainsString('Ridge Camp', $lines[1]);
    }

    public function testTheGpxBundlesOneTrackPerRECORDEDPatrol(): void
    {
        $this->client->request('GET', $this->exportUrl('gpx'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/gpx+xml; charset=UTF-8');
        self::assertStringContainsString('patrols-2026-03.gpx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $document = $this->body();
        // The hand-logged patrol has no recorded route, so it is not in here —
        // a sketch handed out as a .gpx would re-enter the world as a recording.
        self::assertSame(2, substr_count($document, '<trk>'));
        self::assertStringContainsString('<desc>walking round patrol · River Post</desc>', $document);
        self::assertStringContainsString('<desc>boat patrol · Ridge Camp</desc>', $document);
        self::assertStringContainsString('<trkpt lat="-1" lon="-30"', $document);
    }

    public function testTheGpxCarriesTheFilterToo(): void
    {
        $this->client->request('GET', $this->exportUrl('gpx', '&type=boat'));

        self::assertResponseIsSuccessful();
        $document = $this->body();
        self::assertSame(1, substr_count($document, '<trk>'));
        self::assertStringContainsString('Ridge Camp', $document);
    }

    /** A month nobody patrolled is an empty document, never a 404. */
    public function testAMonthWithNoRecordedTrackIsAnEmptyGpxRatherThanAnError(): void
    {
        $this->client->request(
            'GET',
            '/areas/'.$this->area->getUuidString().'/modules/patrols/export.gpx?month=2026-01',
        );

        self::assertResponseIsSuccessful();
        self::assertSame(0, substr_count($this->body(), '<trk>'));
    }

    /** Only the two formats the design names have an address. */
    public function testAFormatNobodyDrewIsNotAnAddress(): void
    {
        $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols/export.pdf');

        self::assertResponseStatusCodeSame(404);
    }
}
