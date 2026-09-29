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
use Uhifadhi\Patrol\Controller\ObservationAmendmentController;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationAmendment;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\ObservationAmendmentKindEnum;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * AMENDMENTS (settled design: observation.html PL·06–PL·09).
 *
 * A synced observation is CLOSED. The field record is what the ranger typed,
 * where they typed it, and it is never rewritten. A correction is a NEW entry
 * underneath it — who, when, what was corrected, in their own words — and the
 * original stays visible above it.
 *
 * WHY, in one line: the observation is the provenance of any incident filed from
 * it, and an incident can be read out in a hearing. A note somebody could quietly
 * change afterwards proves nothing there.
 *
 * PL·09 states the rules and every one of them is a test below: the original is
 * never edited, an amendment is never deleted, nothing goes in unsigned, nothing
 * is back-dated, and a wrong amendment is corrected by another amendment.
 */
final class ObservationAmendmentTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private const string ORIGINAL_NOTE = 'Fresh lion tracks 400 m from River Post, heading south-east. Two sets, likely adult + subadult.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $patrol;
    private Observation $observation;
    private User $recorder;
    /** Somebody who may act on records other people made — `patrols.manage`, which is what an amendment is. */
    private User $keeper;
    private User $bystander;

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

        $this->recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Sara')->setLastName('Laizer');
        $this->keeper = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mary')->setLastName('Mollel');
        $this->bystander = new User()->setPassword('x')->setEmail('bystander@example.test')
            ->setFirstName('Ben')->setLastName('Bystander');
        $this->em->persist($this->recorder);
        $this->em->persist($this->keeper);
        $this->em->persist($this->bystander);

        $this->patrol = new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($this->recorder)
            ->setSource(PatrolSourceEnum::Api)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 07:31'))
            ->setDistanceKm(10.0)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70]]}');
        $this->em->persist($this->patrol);

        $this->observation = new Observation($this->patrol, 'maintenance')
            ->setNote(self::ORIGINAL_NOTE)
            ->setPosition('{"type":"Point","coordinates":[12.26,-5.74]}')
            ->setLoggedAt(new \DateTimeImmutable('today 06:44'))
            ->setRecordedBy($this->recorder);
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

    /**
     * PL·07 — BEFORE THE FIRST AMENDMENT. Said out loud rather than shown as an
     * empty box: on a record that may be read back to somebody, "never
     * corrected" is a fact about the record, not an absence of one.
     */
    public function testTheEmptyStateSaysNothingHasBeenCorrected(): void
    {
        $this->client->loginUser($this->keeper);
        $crawler = $this->client->request('GET', $this->observationUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing has been corrected.', $crawler->html());
        // And PL·03 does not claim a correction that never happened.
        self::assertStringNotContainsString('Corrected', $crawler->filter('[data-patrol-note]')->html());
    }

    /** PL·06 — the correction is APPENDED and the original is left where it was. */
    public function testAnAmendmentIsAppendedAndTheOriginalIsUntouched(): void
    {
        $this->amend('note', 'Three sets of tracks, not two. I found a third on the far side of the drainage.');

        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', $this->observationUrl());
        $html = $crawler->html();

        // The original words, exactly as typed, still on the page.
        self::assertStringContainsString(self::ORIGINAL_NOTE, $html);
        // The correction, underneath.
        self::assertStringContainsString('Three sets of tracks, not two.', $html);
        // Signed and filed under what it corrects.
        self::assertStringContainsString('M. Mollel', $html);
        self::assertStringContainsString('the note', $html);

        // And in the database the note itself was never written to.
        $this->em->clear();
        self::assertSame(self::ORIGINAL_NOTE, $this->reloadObservation()->getNote());
    }

    /**
     * THE SUPERSEDED VALUE IS QUOTED, NEVER STRUCK. A strikethrough reads as
     * "this was wrong and has been removed"; the point of the trail is that the
     * original is still the record, so the old value is quoted under a plain
     * label instead.
     */
    public function testASupersededValueIsQuotedRatherThanStruckThrough(): void
    {
        $this->amend('note', 'Three sets, not two.', supersedes: 'Two sets, likely adult + subadult.');

        $crawler = $this->client->request('GET', $this->observationUrl());
        $was = $crawler->filter('.patrol-amd-was');

        self::assertCount(1, $was, 'The superseded value is not shown.');
        self::assertStringContainsString('as it was recorded', $was->text());
        self::assertStringContainsString('Two sets, likely adult + subadult.', $was->text());

        // Never struck: not by tag and not by inline style.
        self::assertCount(0, $crawler->filter('.patrol-amd s'));
        self::assertCount(0, $crawler->filter('.patrol-amd del'));
        self::assertStringNotContainsString('line-through', $crawler->filter('.patrol-amd')->html());
    }

    /**
     * PL·03 — the note carries the correction count itself, so a reader who
     * never scrolls to the trail is still told the words were added to.
     */
    public function testTheNoteSaysHowManyTimesItWasCorrectedAndThatItIsUnchanged(): void
    {
        $this->amend('note', 'First correction.');
        $once = $this->client->request('GET', $this->observationUrl())->filter('[data-patrol-note]')->text();
        self::assertStringContainsString('Corrected once since.', $once);
        self::assertStringContainsString('These words are unchanged', $once);

        $this->amend('photo', 'Second correction.');
        $twice = $this->client->request('GET', $this->observationUrl())->filter('[data-patrol-note]')->text();
        self::assertStringContainsString('Corrected twice since.', $twice);
    }

    /** PL·04 — the history carries the amendments, in the design's own wording. */
    public function testTheHistoryCarriesEachAmendment(): void
    {
        $this->amend('note', 'A correction.');

        $history = $this->client->request('GET', $this->observationUrl())
            ->filter('[data-patrol-history]')->text();

        self::assertStringContainsString('amended by M. Mollel', $history);
        self::assertStringContainsString('the note', $history);
    }

    /**
     * THE FORM IS INLINE, NEVER A MODAL. You have to be able to read the
     * original while you write the correction, so the form opens BELOW the trail
     * on the same page rather than over the top of it.
     */
    public function testTheAmendFormOpensInPlaceAndIsNotAModal(): void
    {
        $this->client->loginUser($this->keeper);
        $crawler = $this->client->request('GET', $this->observationUrl().'?amend=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-patrol-amend-form]'));
        // Not a dialog, and not hidden behind one.
        self::assertCount(0, $crawler->filter('form[data-patrol-amend-form] dialog'));
        self::assertCount(0, $crawler->filter('dialog form[data-patrol-amend-form]'));
        // The original note is readable on the same screen as the form.
        self::assertStringContainsString(self::ORIGINAL_NOTE, $crawler->html());

        // Every kind the design offers, as the design words them.
        $chips = $crawler->filter('form[data-patrol-amend-form] [name="kind"]')->each(
            static fn ($node): string => (string) $node->attr('value'),
        );
        self::assertSame(
            array_map(static fn (ObservationAmendmentKindEnum $k): string => $k->value, ObservationAmendmentKindEnum::cases()),
            $chips,
        );
        $formText = $crawler->filter('form[data-patrol-amend-form]')->text();
        foreach (['the note', 'what it was', 'where it was', 'when it was', 'adding a photo', 'something else'] as $label) {
            self::assertStringContainsString($label, $formText);
        }
    }

    /** The form says whose name goes on it, before it is signed. */
    public function testTheFormSaysWhatItWillNotTouchAndWhoSignsIt(): void
    {
        $this->client->loginUser($this->keeper);
        $text = $this->client->request('GET', $this->observationUrl().'?amend=1')
            ->filter('form[data-patrol-amend-form]')->text();

        self::assertStringContainsString('M. Mollel', $text);
        self::assertStringContainsString('are not touched', $text);
    }

    /** PL·09 — "amend an amendment: yes — a new entry." Nothing is ever edited. */
    public function testAWrongAmendmentIsCorrectedByAnotherAmendment(): void
    {
        $this->amend('note', 'Three sets, not two.');
        $this->amend('other', 'The previous correction said the drainage; it was the ridge.');

        $crawler = $this->client->request('GET', $this->observationUrl());

        self::assertCount(2, $crawler->filter('.patrol-amd-item:not(.patrol-amd-orig)'));
        self::assertStringContainsString('it was the ridge', $crawler->html());
    }

    /** PL·09 — "delete an amendment: never." There is no route to try. */
    public function testNothingOnThisPageHasADelete(): void
    {
        $this->amend('note', 'A correction.');
        $amendment = $this->reloadObservation()->getAmendments()->first();
        self::assertInstanceOf(ObservationAmendment::class, $amendment);

        // The collection URL exists, and answers only the one verb that appends.
        foreach (['DELETE', 'PUT', 'PATCH'] as $method) {
            $this->client->request($method, $this->amendUrl());
            self::assertResponseStatusCodeSame(405, $method.' on the trail was allowed.');
        }

        // And an individual amendment has no URL AT ALL — nothing addresses one,
        // so nothing can edit or remove one. 404 is the route table saying so.
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->client->request($method, $this->amendUrl().'/'.$amendment->getUuid()->toRfc4122());
            self::assertResponseStatusCodeSame(404, $method.' reached an individual amendment.');
        }
    }

    /**
     * PL·09 — "go in unsigned: never." A signature is not decoration on an
     * evidence trail, so an unauthenticated write is refused.
     */
    public function testAnAmendmentCannotGoInUnsigned(): void
    {
        $this->signOut($this->client);

        $this->client->request('POST', $this->amendUrl(), [
            'kind' => 'note',
            'body' => 'Anonymous correction.',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertCount(0, $this->reloadObservation()->getAmendments());
    }

    /**
     * PL·09 — "who may amend: anyone who may edit the patrol", and nobody
     * else. An amendment is `patrols.manage`: acting on a record, which is
     * not the same power as making one. So the RANGER WHO RECORDED IT is a
     * refusal here too, and that is the sharper half of the proof.
     */
    public function testSomebodyWhoMayNotManageMayNotAmend(): void
    {
        // A VALID token, minted for somebody who may. The point is that holding
        // a good token is not the same as holding the permission — this must be
        // refused by the permission check, not incidentally by CSRF.
        $this->client->loginUser($this->keeper);
        $token = $this->token();

        foreach ([$this->bystander, $this->recorder] as $refused) {
            $this->client->loginUser($refused);
            $this->client->request('POST', $this->amendUrl(), [
                'kind' => 'note',
                'body' => 'Not mine to correct.',
                '_token' => $token,
            ]);

            self::assertResponseStatusCodeSame(403);
            self::assertCount(0, $this->reloadObservation()->getAmendments());
        }
    }

    /** And the affordance is not offered to somebody who may not use it. */
    public function testTheAmendButtonIsOfferedOnlyToSomebodyWhoMayAmend(): void
    {
        $this->client->loginUser($this->keeper);
        self::assertCount(1, $this->client->request('GET', $this->observationUrl())->filter('[data-patrol-amend]'));

        foreach ([$this->bystander, $this->recorder] as $withoutIt) {
            $this->client->loginUser($withoutIt);
            self::assertCount(0, $this->client->request('GET', $this->observationUrl())->filter('[data-patrol-amend]'));
        }
    }

    /** Every write on this page carries a token. */
    public function testAnAmendmentWithoutACsrfTokenIsRefused(): void
    {
        $this->client->loginUser($this->keeper);
        $this->client->request('POST', $this->amendUrl(), ['kind' => 'note', 'body' => 'No token.']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->reloadObservation()->getAmendments());
    }

    /** An amendment with nothing written in it corrects nothing, and is refused. */
    public function testAnEmptyAmendmentIsRefused(): void
    {
        $this->client->loginUser($this->keeper);
        $this->client->request('POST', $this->amendUrl(), [
            'kind' => 'note',
            'body' => '   ',
            '_token' => $this->token(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->reloadObservation()->getAmendments());
    }

    /** A kind the module does not ship is refused rather than filed under a guess. */
    public function testAnUnknownKindIsRefused(): void
    {
        $this->client->loginUser($this->keeper);
        $this->client->request('POST', $this->amendUrl(), [
            'kind' => 'nonsense',
            'body' => 'A correction.',
            '_token' => $this->token(),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** The area nesting is the access rule here as on every patrol screen. */
    public function testAnObservationReachedThroughTheWrongAreaIsNotFound(): void
    {
        $other = new AreaOfInterest()->setSource('test fixture')->setName('elsewhere')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[1.0,1.0],[1.1,1.0],[1.1,1.1],[1.0,1.1],[1.0,1.0]]]]}',
        );
        $this->em->persist($other);
        $this->em->flush();
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        $this->client->loginUser($this->keeper);
        $this->client->request('POST', \sprintf(
            '/areas/%s/modules/patrols/%s/observations/%s/amendments',
            $other->getUuidString(),
            $this->patrol->getUuid()->toRfc4122(),
            $this->observation->getUuid()->toRfc4122(),
        ), ['kind' => 'note', 'body' => 'x', '_token' => $this->token()]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * PL·09 — "edit the original: never", enforced by the TYPE and not only by
     * the controller. The entity has no setter to call, which is the same
     * guarantee the incidents timeline gives, and it is asserted here so a later
     * convenience setter cannot be added without this failing.
     */
    public function testTheEntityOffersNoWayToRewriteAnAmendment(): void
    {
        $reflection = new \ReflectionClass(ObservationAmendment::class);

        $setters = array_values(array_filter(
            array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)),
            static fn (string $name): bool => str_starts_with($name, 'set'),
        ));

        self::assertSame([], $setters, 'An append-only record grew a setter: '.implode(', ', $setters));
        self::assertFalse(
            $reflection->hasProperty('updatedAt'),
            'An immutable row has no honest updatedAt.',
        );
    }

    /**
     * NOT BACK-DATED, and again by construction: the moment is taken from the
     * server's own clock inside the entity, so there is no parameter for a
     * caller to lie in.
     */
    public function testAnAmendmentIsDatedByTheServerAndCannotBeBackDated(): void
    {
        $before = new \DateTimeImmutable();
        $this->amend('note', 'A correction.');

        $amendment = $this->reloadObservation()->getAmendments()->first();
        self::assertInstanceOf(ObservationAmendment::class, $amendment);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $amendment->getWrittenAt()->getTimestamp());

        // There is no parameter for a caller to lie in.
        $constructor = new \ReflectionClass(ObservationAmendment::class)->getConstructor();
        self::assertNotNull($constructor);
        $moments = array_values(array_filter(
            $constructor->getParameters(),
            static function (\ReflectionParameter $parameter): bool {
                $type = $parameter->getType();

                return $type instanceof \ReflectionNamedType
                    && \in_array($type->getName(), [\DateTimeImmutable::class, \DateTimeInterface::class], true);
            },
        ));
        self::assertSame([], $moments, 'The constructor takes a moment, so an amendment could be back-dated.');
    }

    /** The token is scoped to the one observation it corrects. */
    public function testTheTokenIsScopedToTheObservation(): void
    {
        self::assertSame(
            'observation_amend_'.$this->observation->getUuid()->toRfc4122(),
            ObservationAmendmentController::csrfTokenId($this->observation),
        );
    }

    /**
     * A photograph attached to an amendment belongs to THAT amendment, not to
     * the field photographs above it — the design is explicit, and the two are
     * different evidence with different provenance.
     */
    public function testAPhotoAttachedToAnAmendmentIsNotAFieldPhotograph(): void
    {
        $this->client->loginUser($this->keeper);
        $this->client->request(
            'POST',
            $this->amendUrl(),
            ['kind' => 'photo', 'body' => 'Photograph of the third set.', '_token' => $this->token()],
            ['photo' => $this->jpegUpload()],
        );
        self::assertResponseRedirects();

        $observation = $this->reloadObservation();
        // PL·05 still shows the field photographs it always did — none.
        self::assertCount(0, $observation->fieldPhotos());
        self::assertSame(0, $observation->heldPhotoCount(), 'An amendment photo was counted as a field photograph.');

        $amendment = $observation->getAmendments()->first();
        self::assertInstanceOf(ObservationAmendment::class, $amendment);
        self::assertNotNull($amendment->getPhoto());

        // And it is shown, in the trail, under the amendment that carries it.
        $crawler = $this->client->request('GET', $this->observationUrl());
        self::assertCount(1, $crawler->filter('.patrol-amd-item .patrol-ph'));
    }

    /** The trail opens with the record as it was taken — the anchor everything else hangs under. */
    public function testTheTrailOpensWithTheRecordAsItWasTaken(): void
    {
        $this->amend('note', 'A correction.');

        $original = $this->client->request('GET', $this->observationUrl())->filter('.patrol-amd-orig');

        self::assertCount(1, $original);
        self::assertStringContainsString('as recorded', $original->text());
        self::assertStringContainsString(self::ORIGINAL_NOTE, $original->text());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function amend(string $kind, string $body, ?string $supersedes = null): void
    {
        $this->client->loginUser($this->keeper);
        $this->client->request('POST', $this->amendUrl(), array_filter([
            'kind' => $kind,
            'body' => $body,
            'supersedes' => $supersedes,
            '_token' => $this->token(),
        ], static fn (?string $value): bool => null !== $value));
    }

    /**
     * The token the PAGE rendered, scraped off the open form — so the test
     * proves the screen carries one a person could actually submit, rather than
     * minting a private one the form may never have shown.
     */
    private function token(): string
    {
        $html = $this->client->request('GET', $this->observationUrl().'?amend=1')->html();
        preg_match('/name="_token" value="([^"]+)"/', $html, $matches);
        if (!isset($matches[1])) {
            self::fail('The amend form rendered no CSRF token, so no correction could ever be made.');
        }

        return $matches[1];
    }

    private function observationUrl(): string
    {
        return \sprintf(
            '/areas/%s/modules/patrols/%s/observations/%s',
            $this->area->getUuidString(),
            $this->patrol->getUuid()->toRfc4122(),
            $this->observation->getUuid()->toRfc4122(),
        );
    }

    private function amendUrl(): string
    {
        return $this->observationUrl().'/amendments';
    }

    private function reloadObservation(): Observation
    {
        $this->em->clear();
        $observation = $this->em->getRepository(Observation::class)
            ->findOneBy(['uuid' => $this->observation->getUuid()]);
        self::assertInstanceOf(Observation::class, $observation);

        return $observation;
    }

    private function jpegUpload(): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'patrol-amend').'.jpg';
        $image = imagecreatetruecolor(8, 8);
        self::assertNotFalse($image);
        imagejpeg($image, $path);

        return new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'IMG_0912.jpg', 'image/jpeg', null, true);
    }
}
