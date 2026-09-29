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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE FILE-AS-INCIDENT LINK CARRIES THE REAL PLACE.
 *
 * The button hands the incidents module a prefill query string, and the
 * incidents module reads `lat`/`lng` and builds a GeoJSON Point in `[lon, lat]`
 * order from them. So the two keys must arrive as their names promise: `lat` the
 * latitude, `lng` the longitude. Getting them the wrong way round does not fail
 * loudly — it silently relocates the incident by thousands of kilometres, to
 * wherever the mirrored pair happens to land, and the record still looks
 * perfectly well formed.
 *
 * The fixture sits in open water on purpose: the numbers are here to be read
 * back in the order they were written, and a synthetic coordinate cannot be
 * mistaken for somebody's deployment.
 *
 * This suite boots the one environment where a stubbed `incident_new` route
 * exists (see {@see \Uhifadhi\Patrol\Tests\Integration\TestKernel}), so the
 * button renders and its href can be read.
 */
final class ObservationFileAsIncidentTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    /**
     * A point whose two halves cannot be confused for each other: different
     * magnitudes, different signs, so a swap is visible rather than plausible.
     */
    private const float OBSERVATION_LAT = -20.1620;
    private const float OBSERVATION_LNG = 5.6735;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $patrol;
    private Observation $observation;
    private User $ranger;

    protected function setUp(): void
    {
        // The environment whose kernel registers a stub `incident_new`, so the
        // File-as-incident button is present and its href can be inspected.
        $this->client = self::createClient(['environment' => 'incident_contract']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[5.4,-20.4],[5.9,-20.4],[5.9,-19.9],[5.4,-19.9],[5.4,-20.4]]]]}',
        );
        $this->em->persist($this->area);

        $this->ranger = new User()->setPassword('x')->setEmail('ranger@example.test')
            ->setFirstName('Suzan')->setLastName('Laizer');
        $this->em->persist($this->ranger);

        $this->patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'north gate'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setSource(PatrolSourceEnum::Manual);
        $this->em->persist($this->patrol);

        // GeoJSON is [lon, lat] — the order the geometry column stores.
        $this->observation = new Observation($this->patrol, 'maintenance')
            ->setNote('Snare line found on the valley floor.')
            ->setPosition(\sprintf(
                '{"type":"Point","coordinates":[%F,%F]}',
                self::OBSERVATION_LNG,
                self::OBSERVATION_LAT,
            ))
            ->setLoggedAt(new \DateTimeImmutable('today 08:15'))
            ->setRecordedBy($this->ranger);
        $this->em->persist($this->observation);

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
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

    public function testTheLinkCarriesLatitudeAndLongitudeUnswapped(): void
    {
        $query = $this->linkQuery();

        self::assertArrayHasKey('lat', $query);
        self::assertArrayHasKey('lng', $query);

        // The keys must mean what they say — latitude in lat, longitude in lng —
        // so the incidents module rebuilds the SAME point and not its mirror.
        self::assertSame(self::OBSERVATION_LAT, (float) $query['lat'], 'lat must carry the latitude, not the longitude.');
        self::assertSame(self::OBSERVATION_LNG, (float) $query['lng'], 'lng must carry the longitude, not the latitude.');
    }

    /**
     * WHICH PATROL AND WHOSE EYES TRAVEL WITH THE RECORD.
     *
     * The report form's rail states the record being filed about, and the two
     * halves of that statement only this module knows: the patrol the
     * observation was logged on and the person who logged it. They are prose,
     * composed here in the same words the observation's own page prints, so a
     * filer reads one description of the record on both screens.
     */
    public function testTheLinkCarriesThePatrolAndTheRecordingRanger(): void
    {
        $query = $this->linkQuery();

        self::assertSame(
            $this->patrol->getRef().' · walking round patrol · north gate',
            $query['patrol'] ?? null,
            'patrol must carry the reference, the type as a phrase and the station.',
        );
        self::assertSame(
            'S. Laizer',
            $query['ranger'] ?? null,
            'ranger must carry the recorder in the short form the observation page prints.',
        );
    }

    /**
     * The prefill query the button hands over.
     *
     * @return array<string, string>
     */
    private function linkQuery(): array
    {
        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/patrols/%s/observations/%s',
            $this->area->getUuidString(),
            $this->patrol->getUuid()->toRfc4122(),
            $this->observation->getUuid()->toRfc4122(),
        ));

        self::assertResponseIsSuccessful();

        $button = $crawler->filter('a.cta:contains("File as incident")');
        self::assertCount(1, $button, 'The File-as-incident button must render when incident_new exists.');

        $query = [];
        parse_str((string) parse_url((string) $button->attr('href'), \PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
