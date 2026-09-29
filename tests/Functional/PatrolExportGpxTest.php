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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * "Export GPX" on the patrol detail page: the recorded track back out as a
 * real GPX file — one trkpt per stored coordinate, the observations as
 * waypoints, download headers — and the honesty rule that a patrol with no
 * recorded route has nothing to export (no button, and a 404 URL).
 */
final class PatrolExportGpxTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $patrol;
    private Patrol $manualPatrol;

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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->otherArea = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->otherArea);

        $this->patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('2026-03-01T06:10:00+00:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-03-01T12:30:00+00:00'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.3,-5.7],[12.35,-5.68],[12.4,-5.66]]}');
        $this->em->persist($this->patrol);

        $this->em->persist(
            new Observation($this->patrol, 'maintenance')
                ->setNote('Culvert washed out on the ridge track.')
                ->setPosition('{"type":"Point","coordinates":[12.28,-5.72]}')
                ->setLoggedAt(new \DateTimeImmutable('2026-03-01T06:48:00+00:00')),
        );
        // A category the deployment never configured, and no position: the
        // waypoint list carries what can be placed, and nothing it cannot.
        $this->em->persist(
            new Observation($this->patrol, 'unlisted')
                ->setNote('Second note.')
                ->setLoggedAt(new \DateTimeImmutable('2026-03-01T08:15:00+00:00')),
        );

        $this->manualPatrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'boat'))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('2026-03-01T07:20:00+00:00'));
        $this->em->persist($this->manualPatrol);

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
        // Exporting is its own act: the bystander reads the register and
        // cannot carry it out of the building, so these sign in as the tier
        // that may.
        $this->signIn($this->client, $this->em, FixedRecordVoter::RECORDER_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testTheDetailPageLinksTheExportAtItsRealRoute(): void
    {
        $crawler = $this->client->request('GET', $this->patrolUrl($this->area, $this->patrol));

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('.pgact a.patrol-act');
        self::assertCount(1, $link);
        self::assertStringContainsString('Export GPX', $link->text());
        self::assertSame($this->exportUrl($this->area, $this->patrol), $link->attr('href'));
    }

    public function testTheExportStreamsAGpxDocumentWithATrkptPerPointAndTheObservationsAsWaypoints(): void
    {
        $this->client->request('GET', $this->exportUrl($this->area, $this->patrol));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/gpx+xml; charset=UTF-8');
        self::assertStringContainsString(
            'attachment; filename='.$this->patrol->getRef().'.gpx',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );

        // The response STREAMS, so its body exists only once sent — BrowserKit
        // buffers it, and getContent() on the StreamedResponse itself is false.
        self::assertInstanceOf(StreamedResponse::class, $this->client->getResponse());
        $xml = $this->client->getInternalResponse()->getContent();
        $document = simplexml_load_string($xml);
        self::assertNotFalse($document, 'The export must be well-formed XML.');
        $document->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
        self::assertSame('1.1', (string) $document['version']);

        // One trkpt per stored coordinate, in recorded order.
        $trkpts = $document->xpath('//g:trk/g:trkseg/g:trkpt') ?: [];
        self::assertCount(4, $trkpts);
        self::assertEqualsWithDelta(-5.75, (float) $trkpts[0]['lat'], 1e-9);
        self::assertEqualsWithDelta(12.25, (float) $trkpts[0]['lon'], 1e-9);
        self::assertEqualsWithDelta(-5.66, (float) $trkpts[3]['lat'], 1e-9);

        // The track names the patrol, and the metadata carries its real start.
        self::assertSame('Patrol '.$this->patrol->getRef(), (string) ($document->xpath('//g:trk/g:name')[0] ?? ''));
        self::assertSame(
            '2026-03-01T06:10:00Z',
            (string) ($document->xpath('//g:metadata/g:time')[0] ?? ''),
        );
        self::assertStringContainsString(
            'North post',
            (string) ($document->xpath('//g:trk/g:desc')[0] ?? ''),
        );

        // Only the positioned observation can become a waypoint; it carries the
        // number + the deployment's category WORD, the note and the log time.
        $waypoints = $document->xpath('//g:wpt') ?: [];
        self::assertCount(1, $waypoints);
        self::assertSame('1 · Maintenance need', (string) $waypoints[0]->name);
        self::assertSame('Culvert washed out on the ridge track.', (string) $waypoints[0]->desc);
        self::assertSame('2026-03-01T06:48:00Z', (string) $waypoints[0]->time);
        self::assertEqualsWithDelta(-5.72, (float) $waypoints[0]['lat'], 1e-9);
        self::assertEqualsWithDelta(12.28, (float) $waypoints[0]['lon'], 1e-9);
    }

    public function testAHandLoggedPatrolHasNothingToExport(): void
    {
        $crawler = $this->client->request('GET', $this->patrolUrl($this->area, $this->manualPatrol));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pgact a.patrol-act'));

        $this->client->request('GET', $this->exportUrl($this->area, $this->manualPatrol));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheExportIsGatedByTheAreaExactlyAsTheDetailPageIs(): void
    {
        $this->client->request('GET', $this->exportUrl($this->otherArea, $this->patrol));

        self::assertResponseStatusCodeSame(404);
    }

    private function patrolUrl(AreaOfInterest $area, Patrol $patrol): string
    {
        return '/areas/'.$area->getUuidString().'/modules/patrols/'.$patrol->getUuid()->toRfc4122();
    }

    private function exportUrl(AreaOfInterest $area, Patrol $patrol): string
    {
        return $this->patrolUrl($area, $patrol).'/export.gpx';
    }
}
