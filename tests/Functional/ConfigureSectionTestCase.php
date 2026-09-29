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
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;

/**
 * ONE AREA WITH ONE PATROL IN IT, AND A WAY TO POST TO A SECTION OF THE CONFIGURE
 * PAGE — shared by the sections that are a form rather than a screen.
 *
 * THE TOKEN IS READ OFF THE SECTION BEING TESTED, never hardcoded: every form on
 * every section of this module's configure page carries the same id, and a test
 * that spelled it out would still pass the day the page stopped rendering one.
 */
abstract class ConfigureSectionTestCase extends WebTestCase
{
    use EveryAreaRunsPatrols;
    use SomebodyIsSignedIn;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected AreaOfInterest $area;

    /** The section this case drives, as it is addressed in the URL. */
    abstract protected function section(): string;

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

        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 09:10')));

        $this->everyAreaRunsPatrols($this->em);
        $this->signIn($this->client, $this->em);
    }

    protected function configureUrl(?string $section = null): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/configure'
            .(null === $section ? '' : '/'.$section);
    }

    /**
     * A SECTION THAT KEEPS AN ADDRESS OF ITS OWN, which is what a section has to
     * do to link a stylesheet: a body the shell renders inside its own page can
     * only spend the vocabulary the shell's sheet ships.
     */
    protected function sectionUrl(string $tail = ''): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$this->section()
            .('' === $tail ? '' : '/'.$tail);
    }

    /**
     * THE SHEETS THE RENDERED PAGE ACTUALLY LINKS. A section drawing `.stun`,
     * `.sbase` or `.sppick` over a page that links only the shell's sheet is a
     * page of unstyled markup that still answers 200 — which no structural
     * assertion catches, so the links themselves are asserted.
     *
     * @return list<string>
     */
    protected function linkedStylesheets(Crawler $crawler): array
    {
        return $crawler->filter('link[rel="stylesheet"]')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        );
    }

    protected function assertLinksTheModulesSheet(Crawler $crawler): void
    {
        $links = $this->linkedStylesheets($crawler);
        $wanted = [UhifadhiPatrolBundle::STYLESHEET, AtlasBundle::STYLESHEET];

        foreach ($wanted as $sheet) {
            self::assertNotEmpty(
                array_filter($links, static fn (string $href): bool => str_contains($href, self::basename($sheet))),
                \sprintf('the page links %s — it draws classes only that sheet ships. Linked: %s', $sheet, implode(', ', $links)),
            );
        }
    }

    /** The file name inside an asset path, which is what a digested href keeps. */
    private static function basename(string $path): string
    {
        return pathinfo($path, \PATHINFO_FILENAME);
    }

    protected function signInAsManager(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);
    }

    protected function signInAsRecorder(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);
    }

    /** The token this section's forms carry, read off the section itself. */
    protected function token(): string
    {
        $crawler = $this->client->request('GET', $this->sectionUrl());

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function post(string $url, array $fields): void
    {
        $this->client->request('POST', $url, ['_token' => $this->token(), ...$fields]);
    }

    protected function types(): PatrolTypeRepository
    {
        $repository = $this->em->getRepository(PatrolType::class);
        self::assertInstanceOf(PatrolTypeRepository::class, $repository);

        return $repository;
    }

    protected function stations(): StationRepository
    {
        $repository = $this->em->getRepository(Station::class);
        self::assertInstanceOf(StationRepository::class, $repository);

        return $repository;
    }

    protected function firstType(): PatrolType
    {
        $type = $this->types()->findOneByAreaAndKey($this->area, 'walk');
        self::assertInstanceOf(PatrolType::class, $type);

        return $type;
    }
}
