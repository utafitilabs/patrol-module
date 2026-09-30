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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * A PATROL IS WRITTEN BY WHOEVER RECORDED IT (ruled 30 Sep, #67, class 6).
 *
 * Holding patrols.record in the area is what lets somebody record a patrol;
 * it never let them write to somebody else's. A second ranger who records in
 * the same area is refused every write to the first ranger's patrol - more
 * track, observations, events, flights, a photograph, completing it, or
 * re-sending its id - and nothing changes. The lead writes; so do the tiers.
 */
final class FieldSyncOwnershipTest extends FieldSyncTestCase
{
    private const string PATROL = '8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111';

    private const string OBSERVATION = 'b23f0e77-0000-4000-8000-000000000001';

    private string $leadToken;

    private string $secondToken;

    private string $adminToken;

    /** Everybody the suite signs in exists before the first request, as the base case's people do. */
    protected function setUp(): void
    {
        parent::setUp();
        $second = new User()->setPassword('x')->setEmail(FixedRecordVoter::SECOND_RECORDER_EMAIL)
            ->setFirstName('Sam')->setLastName('Second')->setRangerCode('sl-0199');
        $admin = new User()->setPassword('x')->setEmail('admin@example.test')->setFirstName('Desta')->setLastName('Haile')
            ->setTeamRole(TeamRoleEnum::Admin);
        $this->em->persist($second);
        $this->em->persist($admin);
        $this->em->flush();

        // EVERY TOKEN BEFORE THE FIRST REQUEST, while the suite's manager is
        // the kernel's: a request reboots the kernel, and a person signed in
        // after it is somebody that manager has never met.
        $this->actingAs($second);
        $this->secondToken = (string) $this->bearer;
        $this->actingAs($admin);
        $this->adminToken = (string) $this->bearer;
        $this->actingAs($this->recorder);
        $this->leadToken = (string) $this->bearer;
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function writes(): iterable
    {
        yield 'append track' => ['/api/patrols/'.self::PATROL.'/track', ['batchUuid' => self::PATROL.':track:9', 'points' => [
            ['lat' => -3.2031, 'lon' => -29.5356, 'recordedAt' => '2026-08-23T06:46:17Z', 'accuracyM' => 6.0],
        ]]];
        yield 'append observations' => ['/api/patrols/'.self::PATROL.'/observations', ['observations' => [[
            'clientUuid' => 'b23f0e77-0000-4000-8000-0000000000aa', 'category' => 'maintenance', 'note' => 'Not mine to add.',
            'position' => null, 'positionSource' => 'none', 'loggedAt' => '2026-08-23T08:40:00Z',
            'launchPointUuid' => null, 'flightUuid' => null, 'photoCount' => 0,
        ]]]];
        yield 'append events' => ['/api/patrols/'.self::PATROL.'/events', ['events' => []]];
        yield 'append flights' => ['/api/patrols/'.self::PATROL.'/flights', ['flights' => []]];
        yield 'complete' => ['/api/patrols/'.self::PATROL.'/complete', []];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('writes')]
    public function anotherRangerWhoRecordsIsRefusedEveryWrite(string $uri, array $body): void
    {
        $this->recordedByTheLead();
        $this->bearer = $this->secondToken;

        $this->postJson($uri, $body);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('forbidden', $this->payload()['code']);
        $patrol = $this->reload();
        self::assertSame('recording', $patrol->getStatus()->value, 'nothing changed');
        self::assertCount(1, $this->em->getRepository(Observation::class)->findAll(), 'nothing changed');
    }

    #[Test]
    public function anotherRangerCannotAddAPhotographToTheLeadsObservation(): void
    {
        $this->recordedByTheLead();
        $this->bearer = $this->secondToken;

        $this->client->request('POST', '/api/observations/'.self::OBSERVATION.'/photos',
            parameters: ['clientUuid' => 'e77c0000-0000-4000-8000-0000000000aa', 'takenAt' => '2026-08-23T08:31:02Z'],
            files: ['file' => $this->jpegUpload('e77c0000-0000-4000-8000-0000000000aa.jpg')],
            server: $this->apiHeaders(),
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** Re-sending somebody else's patrol id is not a way in to it either. */
    #[Test]
    public function anotherRangerResendingThePatrolIdIsRefused(): void
    {
        $this->recordedByTheLead();
        $this->bearer = $this->secondToken;

        $this->createPatrol();

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function theLeadStillWritesToTheirOwnPatrol(): void
    {
        $this->recordedByTheLead();

        $this->postJson('/api/patrols/'.self::PATROL.'/complete', []);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function anAdminWritesToAnybodysPatrol(): void
    {
        $this->recordedByTheLead();
        $this->bearer = $this->adminToken;

        $this->postJson('/api/patrols/'.self::PATROL.'/complete', []);

        self::assertResponseIsSuccessful();
    }

    /** The lead records a patrol with one observation, signed in as themselves. */
    private function recordedByTheLead(): void
    {
        $this->bearer = $this->leadToken;
        $this->createPatrol(['clientUuid' => self::PATROL]);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/patrols/'.self::PATROL.'/observations', ['observations' => [[
            'clientUuid' => self::OBSERVATION, 'category' => 'maintenance', 'note' => 'Wire snare on the game trail.',
            'position' => null, 'positionSource' => 'none', 'loggedAt' => '2026-08-23T08:31:02Z',
            'launchPointUuid' => null, 'flightUuid' => null, 'photoCount' => 0,
        ]]]);
        self::assertResponseIsSuccessful();
    }

    private function reload(): Patrol
    {
        $this->em->clear();
        $patrol = $this->em->getRepository(Patrol::class)->findAll()[0] ?? null;
        self::assertInstanceOf(Patrol::class, $patrol);

        return $patrol;
    }
}
