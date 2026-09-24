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
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * ONE CONFIGURE BUTTON, ONE CONFIGURE PAGE — the shell's page over this module's
 * five declared sections, and the one POST behind its Settings body.
 *
 * THE WORD-LISTS ARE NOT DRIVEN HERE. Each keeps a section of its own now, with a
 * case of its own beside this one ({@see PatrolTypesSectionTest},
 * {@see PatrolStationsSectionTest}); what this case is for is the PAGE — the
 * strip, which section is lit, the bare address, and the thresholds.
 */
final class ConfigurePageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

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
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 09:10')));

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
    }

    private function configureUrl(?string $section = null): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/configure'
            .(null === $section ? '' : '/'.$section);
    }

    /** One card of the section body, found by the word in its caption. */
    private static function card(Crawler $crawler, string $heading): Crawler
    {
        return $crawler->filter('.c')->reduce(
            static fn (Crawler $card): bool => str_starts_with(trim($card->filter('.tab')->text('')), $heading),
        );
    }

    private function signInAsManager(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);
    }

    /**
     * THE BARE ADDRESS BELONGS TO THE FIRST SECTION, and this module's first is
     * the widget library — a screen of its own, so the shell redirects there
     * rather than drawing a second-choice section.
     */
    public function testTheBareConfigureAddressGoesToTheFirstSection(): void
    {
        $this->signInAsManager();
        $this->client->request('GET', $this->configureUrl());

        self::assertResponseRedirects('/areas/'.$this->area->getUuidString().'/modules/patrols/widgets');
    }

    /** Settings is addressed by name, and the strip says which section is lit. */
    public function testTheSettingsSectionNamesThisModuleAndLightsItsOwnTab(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('settings'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('demo reserve — Patrols · configure', $crawler->filter('h1.pg')->text());
        self::assertSame(
            ['Widget library', 'Patrol types', 'Stations', 'Observation kinds', 'Settings'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Settings', trim($crawler->filter('.atabs a.on')->text()));
    }

    /**
     * THE SETTINGS SECTION IS THE TWO NUMBERS AND NOTHING ELSE, which is what the
     * settled design draws once the types, the stations and the observation kinds
     * each keep a section of their own. A word-list restated here would be a
     * second place to edit it and a second place for it to be out of date.
     */
    public function testTheSettingsBodyDrawsTheThresholdsAlone(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('settings'));

        self::assertSame(
            ['Thresholds'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $t): string => trim(str_replace((string) $t->filter('.src')->text(''), '', $t->text())),
            ),
        );

        // The same save row its two new siblings draw: a way out of the form that
        // is not "save".
        $row = $crawler->filter('.staddrow');
        self::assertSame('Cancel', trim($row->filter('a.tgl')->text()));
        self::assertSame('Save settings', trim($row->filter('button.cta')->text()));

        // Not the words, and not a link out to them either: the strip is the way.
        self::assertStringNotContainsString('North post', $crawler->filter('.c')->text());
        self::assertCount(0, $crawler->filter('.c .srow'));
    }

    /** Until an area saves, it runs on the installation's numbers. */
    public function testAnAreaThatHasNeverSavedShowsTheInstallationsNumbers(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('settings'));

        self::assertStringContainsString('the installation', self::card($crawler, 'Thresholds')->text());
    }

    /** One POST, and the area runs on its own numbers from then on. */
    public function testSavingTheSettingsWritesTheAreasOwnNumbers(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('settings'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl('settings'), [
            '_token' => $token,
            'gap_threshold_minutes' => '12',
            'discard_retention_days' => '30',
        ]);

        self::assertResponseRedirects($this->configureUrl('settings'));

        $crawler = $this->client->request('GET', $this->configureUrl('settings'));
        self::assertSame('12', $crawler->filter('input[name="gap_threshold_minutes"]')->attr('value'));
        self::assertSame('30', $crawler->filter('input[name="discard_retention_days"]')->attr('value'));
        self::assertStringContainsString('this area’s own', self::card($crawler, 'Thresholds')->text());
    }

    /** A form is not a security boundary: a hand-posted number is clamped. */
    public function testAPostedNumberOutsideTheDesignsBoundsIsClamped(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('settings'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl('settings'), [
            '_token' => $token,
            'gap_threshold_minutes' => '99999',
            'discard_retention_days' => '0',
        ]);

        $crawler = $this->client->request('GET', $this->configureUrl('settings'));
        self::assertSame(
            (string) PatrolSettingsService::MAX_GAP_MINUTES,
            $crawler->filter('input[name="gap_threshold_minutes"]')->attr('value'),
        );
        self::assertSame(
            (string) PatrolSettingsService::MIN_RETENTION_DAYS,
            $crawler->filter('input[name="discard_retention_days"]')->attr('value'),
        );
    }

    /**
     * A CLEARED THRESHOLD IS TOLD WHAT TO TYPE, not handed a 400.
     *
     * Both rows are `<input type="number">`, and selecting one and pressing
     * delete — the commonest way there is to change a number in one — posts an
     * empty string. `InputBag::getInt()` throws a BadRequestException on that, so
     * the form answered `400 Input value "gap_threshold_minutes" cannot be
     * converted to "int"`: a stack trace in place of the one sentence that would
     * have said what was wrong.
     *
     * NOTHING IS SAVED when either is unreadable. Writing one threshold because
     * the other was blank would leave the area running on a number nobody chose.
     *
     * @param array<string, string> $posted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableThresholds')]
    public function testAnUnreadableThresholdIsRefusedWithASentenceAndSavesNothing(array $posted, string $says): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('settings'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl('settings'), ['_token' => $token, ...$posted]);

        self::assertResponseRedirects($this->configureUrl('settings'));

        $crawler = $this->client->followRedirect();
        // THE FRAME SAYS IT, not this module: the sentence rides the shell's
        // flash socket like every other refusal on the section.
        self::assertSelectorTextContains('[data-shell-flash]', $says);
        // And the area still runs on the installation's numbers, because the
        // save was refused before anything was written.
        self::assertStringContainsString('the installation', self::card($crawler, 'Thresholds')->text());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function unreadableThresholds(): iterable
    {
        yield 'the gap cleared' => [
            ['gap_threshold_minutes' => '', 'discard_retention_days' => '30'],
            'gps gap needs a whole number of minutes',
        ];
        yield 'the gap is not a number' => [
            ['gap_threshold_minutes' => 'soon', 'discard_retention_days' => '30'],
            'gps gap needs a whole number of minutes',
        ];
        yield 'retention cleared' => [
            ['gap_threshold_minutes' => '12', 'discard_retention_days' => ''],
            'Discard keeps needs a whole number of days',
        ];
        yield 'retention is a fraction' => [
            ['gap_threshold_minutes' => '12', 'discard_retention_days' => '30.5'],
            'Discard keeps needs a whole number of days',
        ];
    }

    /** Changing what an area runs on rides on `patrols.manage`. */
    public function testSomebodyWhoMayNotManageCannotSaveTheSettings(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);

        $this->client->request('POST', $this->configureUrl('settings'), [
            'gap_threshold_minutes' => '12',
            'discard_retention_days' => '30',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /** The word "register" is nowhere on the configure page either. */
    public function testNothingOnTheConfigurePageSaysRegister(): void
    {
        $this->signInAsManager();
        $this->client->request('GET', $this->configureUrl('settings'));

        self::assertStringNotContainsStringIgnoringCase(
            'register',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
