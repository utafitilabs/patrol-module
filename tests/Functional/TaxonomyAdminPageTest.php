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
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * THE AREA-SCOPED PATROL TAXONOMY ADMIN, over HTTP. One route, two data
 * conditions — the empty first-run start and the populated two-pane manager —
 * plus the ruled invariants: area scope, deactivate-never-delete, per-area
 * uniqueness, the "observation-kinds.configure" gate on every write, and CSRF. The
 * copy-from-another-area picker is asserted ABSENT, since it is deferred.
 */
final class TaxonomyAdminPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

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

    // ── the empty start ──────────────────────────────────────────────────────

    public function testTheEmptyStartRendersTheGhostAndTheWriteFirstKindPath(): void
    {
        $area = $this->anArea('Southern Reserve');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->kindsUrl($area));

        self::assertResponseIsSuccessful();
        // The empty condition, not the manager.
        self::assertCount(1, $crawler->filter('.tx-empty'));
        self::assertCount(0, $crawler->filter('.tx-mgr'));
        // The inert ghost sketch of a taxonomy's shape.
        self::assertCount(1, $crawler->filter('.tx-sketch'));
        self::assertStringContainsString('Southern Reserve has no observation kinds yet', $crawler->text());
        // One honest way in: a real form that writes the first kind.
        self::assertCount(1, $crawler->filter('.tx-empty form[action$="/patrols/kinds"]'));
    }

    /** The copy-from-area picker is deferred, so the empty start offers no such live control. */
    public function testTheEmptyStartDoesNotShipACopyFromAreaPicker(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->kindsUrl($area));

        self::assertCount(0, $crawler->filter('.tx-copy'));
        self::assertCount(0, $crawler->filter('.tx-area'));
    }

    // ── the manage gate ────────────────────────────────────────────────────────

    public function testManagingTheTaxonomyNeedsTheManagePermission(): void
    {
        $area = $this->anArea();
        // A recorder may log a patrol, and may NOT manage the taxonomy — the
        // split the screen rests on.
        $this->client->loginUser($this->aRecorder());

        $this->client->request('GET', $this->kindsUrl($area));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * THE PAGE NAMES THE PERMISSION EVERY WRITE ASKS, in the words the
     * Permissions screens use for it: the "Configure" column of the
     * "Observation kinds" row.
     */
    public function testThePageNamesThePermissionEditingNeeds(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->kindsUrl($area));

        $say = $crawler->filter('.tx-say')->text();
        self::assertStringContainsString('Editing needs Configure on Observation kinds', $say);
        self::assertStringContainsString('Being able to log a patrol (Record on Patrols) is not enough.', $say);
        self::assertStringNotContainsString('patrols.manage', $say);
    }

    // ── the populated manager ────────────────────────────────────────────────

    public function testCreatingTheFirstKindMovesTheScreenToTheManager(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();
        $this->client->request('POST', $this->kindsUrl($area), [
            '_token' => $this->tokenFrom($html),
            'label' => 'Wildlife',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('.tx-mgr'));
        self::assertCount(0, $crawler->filter('.tx-empty'));
        self::assertStringContainsString('Wildlife', $crawler->filter('.tx-kinds')->text());
        // The wire-code chip the register and exports hold.
        self::assertStringContainsString('wildlife', $crawler->filter('.tx-detail')->text());
    }

    public function testTheManagerRendersSubcategoriesAsLabelsOnly(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createSubcategory($kind, 'Sighting');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122());

        self::assertStringContainsString('Sighting', $crawler->filter('.tx-sub')->text());
        // SHALLOW: no behaviour-block chips, no toggle editor anywhere.
        self::assertCount(0, $crawler->filter('.tx-blk'));
        self::assertCount(0, $crawler->filter('.tx-blockedit'));
        // …and the screen says so plainly.
        self::assertCount(1, $crawler->filter('.tx-shallow'));
    }

    public function testCreatingASubcategoryPersistsUnderTheKind(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area).'?kind='.$kind->getUuid()->toRfc4122())->html();
        $this->client->request('POST', $this->kindsUrl($area).'/'.$kind->getUuid()->toRfc4122().'/subcategories', [
            '_token' => $this->tokenFrom($html),
            'label' => 'Spoor / Tracks',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Spoor / Tracks', $crawler->filter('.tx-subs')->text());
        self::assertStringContainsString('spoor-tracks', $crawler->filter('.tx-subs')->text());
    }

    // ── deactivate never deletes ─────────────────────────────────────────────

    public function testDeactivatingAKindDimsItInPlaceAndReactivateBringsItBack(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Fire Scar');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();
        $this->client->request('POST', $this->kindsUrl($area).'/'.$kind->getUuid()->toRfc4122().'/deactivate', [
            '_token' => $this->tokenFrom($html),
        ]);
        $crawler = $this->client->followRedirect();

        // Still there, dimmed — never hidden, never deleted.
        self::assertCount(1, $crawler->filter('.tx-kind.off'));
        self::assertSame(1, $this->kindCount($area));

        // And it reactivates in one click.
        $this->client->request('POST', $this->kindsUrl($area).'/'.$kind->getUuid()->toRfc4122().'/reactivate', [
            '_token' => $this->tokenFrom($crawler->html()),
        ]);
        $back = $this->client->followRedirect();
        self::assertCount(0, $back->filter('.tx-kind.off'));
    }

    /** There is no delete control anywhere on the page. */
    public function testThereIsNoDeleteControlAnywhere(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createSubcategory($kind, 'Sighting');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();

        self::assertStringNotContainsStringIgnoringCase('/delete', $html);
        self::assertStringNotContainsStringIgnoringCase('>Delete<', $html);
    }

    // ── area scope ───────────────────────────────────────────────────────────

    public function testOneAreasKindsNeverAppearInAnother(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $this->admin()->createKind($northern, 'Wildlife');
        $this->client->loginUser($this->aManager());

        // The southern area sees its own (empty) list, not the northern one's kind.
        $crawler = $this->client->request('GET', $this->kindsUrl($southern));
        self::assertCount(1, $crawler->filter('.tx-empty'));
        self::assertStringNotContainsString('Wildlife', $crawler->filter('.tx-empty')->text());
    }

    /** A kind of another area is a 404 on this area's write route — no reach across. */
    public function testWritingToAnotherAreasRowIs404(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $kind = $this->admin()->createKind($northern, 'Wildlife');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($southern))->html();
        // The northern area's kind uuid, posted at the southern area's URL.
        $this->client->request('POST', $this->kindsUrl($southern).'/'.$kind->getUuid()->toRfc4122().'/deactivate', [
            '_token' => $this->tokenFrom($html),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── per-area uniqueness, at the door ─────────────────────────────────────

    public function testADuplicateKindLabelIsRefusedWithoutCreatingASecondRow(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Wildlife');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->kindsUrl($area))->html();
        $this->client->request('POST', $this->kindsUrl($area), [
            '_token' => $this->tokenFrom($html),
            'label' => 'wildlife',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        // The refusal is flashed, and no second row was written.
        self::assertStringContainsString('already has a kind called', $crawler->text());
        self::assertSame(1, $this->kindCount($area));
    }

    // ── CSRF ───────────────────────────────────────────────────────────────────

    public function testAWriteWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->kindsUrl($area), [
            'label' => 'Wildlife',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->kindCount($area));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function kindsUrl(AreaOfInterest $area): string
    {
        $uuid = $area->getUuidString();
        self::assertNotNull($uuid, 'A persisted area always has a uuid.');

        return \sprintf('/areas/%s/modules/patrols/kinds', $uuid);
    }

    private function admin(): TaxonomyAdminService
    {
        $admin = static::getContainer()->get('test_public.'.TaxonomyAdminService::class);
        self::assertInstanceOf(TaxonomyAdminService::class, $admin);

        return $admin;
    }

    private function kindCount(AreaOfInterest $area): int
    {
        return \count($this->em->getRepository(TaxonomyKind::class)->findBy(['area' => $area]));
    }

    /**
     * An area running Patrols — persisted, then switched on in the registry so
     * its module routes are not 404 (see EveryAreaRunsPatrols).
     */
    private function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name)->setSource('test fixture')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}',
        );
        $this->em->persist($area);
        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);

        return $area;
    }

    /** Somebody who may manage this area's taxonomy — the supervisor. */
    private function aManager(): User
    {
        return $this->aUser(FixedRecordVoter::MANAGER_EMAIL, 'Sara', 'Laizer');
    }

    /** Somebody who may record a patrol and may NOT manage the taxonomy. */
    private function aRecorder(): User
    {
        return $this->aUser(FixedRecordVoter::RECORDER_EMAIL, 'Joseph', 'Mollel');
    }

    private function aUser(string $email, string $first, string $last): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User();
        $user->setEmail($email)->setFirstName($first)->setLastName($last)->setPassword('not-used-by-these-tests');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tokenFrom(string $html, string $name = '_token'): string
    {
        preg_match(\sprintf('/name="%s" value="([^"]+)"/', preg_quote($name, '/')), $html, $matches);
        if (!isset($matches[1])) {
            self::fail('The page rendered no CSRF token, so its form could never be submitted.');
        }

        return $matches[1];
    }
}
