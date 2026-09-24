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

use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE WIRE IS FROZEN — the eight addresses, the five documents and the statuses,
 * held as literals so nothing can change them by accident.
 *
 * There is a field application already built and already on handsets. It reads
 * these key names, these status codes and these error codes; a serializer
 * setting, a naming convention or a platform listener added for some other
 * feature must not be able to rename `acceptedUuids` on a Tuesday, and the
 * difference between 201 and 200 is a distinction the app acts on rather than
 * an incidental of how the endpoint was written.
 *
 * IT IS A PIN, NOT A SPECIFICATION, and that is the honest description: it was
 * written against behaviour that already existed and passed the moment it was
 * saved. There is no red-first step to claim for it. What it buys is the next
 * change — the one that would otherwise alter the wire while every behavioural
 * test stayed green, because each of those asserts what an endpoint DID rather
 * than what it SAID.
 *
 * The behaviour behind each of these lives in its own test: FieldSyncFlowTest,
 * FieldSyncDiscardTest, FieldSyncDroneTest, FieldSyncPhotoPositionTest and
 * FieldSyncAuthorizationTest. This one asserts only the shapes.
 */
final class FieldSyncWireContractTest extends FieldSyncTestCase
{
    /**
     * Every address the handset knows, with its method. A route added here is a
     * new endpoint and a route missing is one the app can no longer reach, so
     * the set is compared whole rather than searched.
     */
    public function testTheEightAddressesAreExactlyTheseWithTheseMethods(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $found = [];
        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/patrols') && !str_starts_with($path, '/api/observations')) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $found[] = $method.' '.$path;
            }
        }

        sort($found);

        self::assertSame([
            // The sync's one READ: what an area lets a handset say. Everything
            // else here is the handset handing work in.
            'GET /api/patrols/vocabulary',
            'POST /api/observations/{uuid}/photos',
            'POST /api/patrols',
            'POST /api/patrols/{uuid}/complete',
            'POST /api/patrols/{uuid}/events',
            'POST /api/patrols/{uuid}/flights',
            'POST /api/patrols/{uuid}/observations',
            'POST /api/patrols/{uuid}/track',
        ], $found);
    }

    /**
     * THE VOCABULARY DOCUMENT — the read the handset replaces its compiled-in
     * lists with. Every row carries the five fields a delta sync needs: the
     * stable key it sends back, the label it prints, whether the word is still
     * offered, the order to draw it in, and when it last changed.
     */
    public function testTheVocabularyDocumentCarriesKeysLabelsActivePositionAndUpdatedAt(): void
    {
        $this->actingAs($this->recorder);
        // THE STATION IS THE AREA'S: the office records it, the handset reads it.
        $riverPost = Vocabulary::station($this->em, $this->area, 'River Post', 'river-post');
        $this->em->flush();
        $retired = $this->vocabulary()->addType($this->area, 'Horseback');
        $this->vocabulary()->retireType($retired);
        $this->vocabulary()->addType($this->area, 'Drone sortie', base: PatrolBaseEnum::Aerial, glyph: 'truck');

        $this->client->request('GET', '/api/patrols/vocabulary?areaId='.$this->area->getUuidString(), server: $this->apiHeaders());

        self::assertResponseIsSuccessful();
        $document = $this->payload();
        self::assertSame(
            ['areaId', 'generatedAt', 'observationKinds', 'patrolTypes', 'stations'],
            self::sortedKeys($document),
        );
        self::assertSame($this->area->getUuidString(), $document['areaId']);

        self::assertIsArray($document['patrolTypes']);
        $rows = [];
        foreach ($document['patrolTypes'] as $type) {
            self::assertIsArray($type);
            // WHAT IT RECORDS AND WHAT FOLLOWS FROM THAT ride with every row, so a
            // handset builds its screen from the base rather than guessing at the
            // name it happens to have been given.
            self::assertSame([
                'active', 'base', 'coverageBufferM', 'glyph', 'key', 'label',
                'observationPlacement', 'paceMaxKmh', 'paceMinKmh', 'position', 'updatedAt',
            ], self::sortedKeys($type));
            self::assertIsString($type['key']);
            $rows[$type['key']] = $type;
        }

        // A RETIRED WORD IS SENT, NOT WITHHELD: a handset holding a patrol filed
        // under it still has to be able to print it.
        self::assertIsArray($rows['horseback'] ?? null);
        self::assertFalse($rows['horseback']['active']);

        // A TYPE NOBODY HAS GIVEN A BASE SENDS NULL rather than a guess, and its
        // tunables are null with it: the handset falls back to reading the name,
        // which is what it did before bases existed.
        self::assertNull($rows['walk']['base']);
        self::assertNull($rows['walk']['paceMinKmh']);
        self::assertNull($rows['walk']['coverageBufferM']);
        self::assertNull($rows['walk']['observationPlacement']);

        // One that HAS a base sends the base's own numbers, in the wire values the
        // handset switches on.
        self::assertSame('aerial', $rows['drone-sortie']['base']);
        self::assertSame(15, $rows['drone-sortie']['paceMinKmh']);
        self::assertSame(70, $rows['drone-sortie']['paceMaxKmh']);
        self::assertSame(400, $rows['drone-sortie']['coverageBufferM']);
        self::assertSame('on_map', $rows['drone-sortie']['observationPlacement']);
        self::assertSame('truck', $rows['drone-sortie']['glyph']);

        self::assertIsArray($document['stations']);
        self::assertNotSame([], $document['stations']);
        $station = $document['stations'][0];
        self::assertIsArray($station);
        self::assertSame(['active', 'key', 'label', 'point', 'position', 'updatedAt'], self::sortedKeys($station));
        self::assertSame($riverPost?->getUuid()?->toRfc4122(), $station['key'], 'the wire key is the area station\'s uuid');
        self::assertSame('River Post', $station['label']);
    }

    /** Nothing has changed since a moment in the future, and the answer says so. */
    public function testASinceInTheFutureAnswersAnEmptyDelta(): void
    {
        $this->actingAs($this->recorder);
        Vocabulary::station($this->em, $this->area, 'River Post', 'river-post');
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/patrols/vocabulary?areaId='.$this->area->getUuidString().'&since=2099-01-01T00:00:00Z',
            server: $this->apiHeaders(),
        );

        self::assertResponseIsSuccessful();
        $document = $this->payload();
        self::assertSame([], $document['patrolTypes']);
        self::assertSame([], $document['stations']);
    }

    /**
     * AN UNKNOWN STATION IS NEVER A REFUSAL, AND NEVER A STATION EITHER. The
     * contract names no error code for one, so a word this area has not heard
     * of is kept ON THE PATROL as the word it is, and the patrol is kept; no
     * station is made of it, because a station is the area's record — it needs
     * a point and it is the office that records one (ruled 2026-09-18), while
     * the handset collects and never configures.
     */
    public function testAStationTheAreaHasNeverHeardOfIsKeptAsAWordOnThePatrol(): void
    {
        $this->actingAs($this->recorder);

        $clientUuid = $this->createPatrol(['stationId' => 'somewhere-nobody-configured']);

        self::assertResponseStatusCodeSame(201);

        $patrol = $this->em->getRepository(Patrol::class)->findOneBy(['clientUuid' => $clientUuid]);
        self::assertInstanceOf(Patrol::class, $patrol);
        self::assertNull($patrol->getStationRecord(), 'no station is invented for a word the area does not keep');
        self::assertSame('somewhere-nobody-configured', $patrol->getStation(), 'the word itself is kept on the patrol');
        self::assertSame('somewhere-nobody-configured', $patrol->getStationKey(), 'the word is what a filter and an export key it by');
    }

    private function vocabulary(): PatrolVocabularyService
    {
        $vocabulary = static::getContainer()->get('test_public.'.PatrolVocabularyService::class);
        self::assertInstanceOf(PatrolVocabularyService::class, $vocabulary);

        return $vocabulary;
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<string>
     */
    private static function sortedKeys(array $document): array
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($document));
        sort($keys);

        return $keys;
    }

    /**
     * A create answers 201 and a re-send of the same clientUuid answers 200 with
     * the identical body plus `duplicate: true`. Both carry the reference the
     * SERVER assigned — the app prints "P-????" until it has one and never
     * invents one.
     */
    public function testThePatrolDocumentIsFourKeysAnd201ThenThe200OfAReSend(): void
    {
        $this->actingAs($this->recorder);

        $this->createPatrol();

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = $this->payload();
        self::assertSame(['uuid', 'reference', 'status', 'duplicate'], array_keys($created));
        self::assertFalse($created['duplicate']);
        self::assertIsString($created['reference']);
        self::assertNotSame('', $created['reference']);

        $this->createPatrol();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $resent = $this->payload();
        self::assertSame(['uuid', 'reference', 'status', 'duplicate'], array_keys($resent));
        self::assertTrue($resent['duplicate']);
        self::assertSame($created['uuid'], $resent['uuid']);
        self::assertSame($created['reference'], $resent['reference']);
        self::assertSame($created['status'], $resent['status']);
    }

    /**
     * Every batch part — track, observations, flights, events — answers the same
     * three keys and 200. `acceptedUuids` is a LIST, so it encodes as a JSON
     * array even when it holds one element.
     */
    public function testEveryBatchPartAnswersTheSameThreeKeys(): void
    {
        $this->actingAs($this->recorder);
        $uuid = $this->createPatrol();

        $this->postJson('/api/patrols/'.$uuid.'/track', [
            'batchUuid' => $uuid.':track:0',
            'points' => [
                ['lat' => -3.2014, 'lon' => -29.5377, 'recordedAt' => '2026-08-23T06:44:17Z'],
                ['lat' => -3.2020, 'lon' => -29.5370, 'recordedAt' => '2026-08-23T06:45:17Z'],
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $ack = $this->payload();
        self::assertSame(['accepted', 'acceptedUuids', 'duplicate'], array_keys($ack));
        self::assertTrue($ack['accepted']);
        self::assertIsArray($ack['acceptedUuids']);
        self::assertSame(array_keys($ack['acceptedUuids']), range(0, \count($ack['acceptedUuids']) - 1));
    }

    /** `complete` makes nothing new, so it is always 200 and `duplicate` alone says what happened. */
    public function testTheCompleteDocumentIsFourKeysAndAlways200(): void
    {
        $this->actingAs($this->recorder);
        $uuid = $this->createPatrol();

        $this->postJson('/api/patrols/'.$uuid.'/complete', []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['uuid', 'reference', 'status', 'duplicate'], array_keys($this->payload()));
    }

    /**
     * THE REFUSAL, AND THE CODE THAT SURVIVES IT.
     *
     * `details` is an OBJECT even when empty, so a client's parser meets one
     * shape rather than an object on some failures and an array on others. And
     * `code` is the CONTRACT's word — the platform gives every /api failure the
     * same four keys by replacing the body of any 4xx, and a document already in
     * that shape must reach the handset with its own code rather than the status
     * word.
     */
    public function testTheErrorDocumentIsFourKeysAndKeepsTheContractsOwnCode(): void
    {
        $this->actingAs($this->recorder);
        $uuid = $this->createPatrol();

        $this->postJson('/api/patrols/'.$uuid.'/complete', ['status' => 'discarded']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $error = $this->payload();
        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($error));
        self::assertSame('discard_reason_required', $error['code']);
        self::assertFalse($error['retryable']);
        self::assertIsArray($error['details']);
        self::assertStringContainsString(
            '"details":{',
            (string) $this->client->getResponse()->getContent(),
            'details must encode as an object, so a parser meets one shape on every failure.',
        );
    }

    /**
     * A request with no credential is 401 and not 403, because a client shows a
     * person different things for the two — "sign in again" against "you may not
     * do that".
     */
    public function testNoCredentialIs401(): void
    {
        $this->postJson('/api/patrols', []);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
