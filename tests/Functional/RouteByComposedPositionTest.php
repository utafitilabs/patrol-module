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
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Patrol\Access\PatrolConcerns;

/**
 * THE PAIR IS NOT THE WHOLE ANSWER — the ground is the other half, and this is
 * the test that can prove it.
 *
 * The rest of the suite decides access with a fixture voter, which is honest
 * about tiers and says nothing about WHERE somebody is placed. So the second
 * of the three questions a check asks — does the placement cover the area —
 * is proved here instead, against the real machinery: a real position holding
 * exactly the right pairs, a real placement at ONE area, and the core's own
 * GrantVoter answering.
 *
 * WHY IT MATTERS. `#[IsGranted('patrols.read')]` without `subject: 'area'`
 * asks the voter with a null subject, and a null subject means "no area in
 * context", which ANY placement reaching any ground at all satisfies. Every
 * page of this module would then open for somebody placed at a different
 * park, and every server-side test would still pass. That is the defect this
 * drives out, page by page.
 *
 * THE FIXTURE VOTER STANDS ASIDE for somebody holding a position, which is
 * what lets these people be decided by the real voter inside a suite that
 * otherwise short-circuits it.
 */
final class RouteByComposedPositionTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $theirs;
    private AreaOfInterest $nextDoor;

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

        $this->theirs = new AreaOfInterest()->setSource('test fixture')->setName('their reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->nextDoor = new AreaOfInterest()->setSource('test fixture')->setName('the next reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->theirs);
        $this->em->persist($this->nextDoor);
        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
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
     * The right pair, at the wrong park. Every page this module ships must
     * refuse, and the refusal is 403 rather than 404: the module is running
     * in that area, it is this reader who does not reach it.
     *
     * @param list<string> $pairs the position must hold for the page to be worth asking for
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testARouteRefusesTheRightPositionInTheWrongArea(string $path, array $pairs): void
    {
        $this->client->loginUser($this->placedAt($this->theirs, $pairs));

        $this->client->request('GET', $this->url($this->nextDoor, $path));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * …and opens on the park they ARE placed at, so the refusal above is
     * about the ground and nothing else.
     *
     * @param list<string> $pairs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testTheSamePersonOpensTheSamePageOnTheirOwnArea(string $path, array $pairs): void
    {
        $this->client->loginUser($this->placedAt($this->theirs, $pairs));

        $this->client->request('GET', $this->url($this->theirs, $path));

        self::assertResponseIsSuccessful();
    }

    /**
     * NO PLACEMENT REACHES NO GROUND. Unplaced is not "everywhere", and the
     * model failing closed is the whole reason the second question is asked
     * at all.
     */
    public function testSomebodyWithThePairAndNoPlacementReachesNothing(): void
    {
        $this->client->loginUser($this->placedAt(null, [self::pair(PatrolConcerns::PATROLS, Verb::Read)]));

        $this->client->request('GET', $this->url($this->theirs, ''));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Every GET this module ships, with the pair its gate names.
     *
     * @return \Generator<string, array{string, list<string>}>
     */
    public static function pages(): \Generator
    {
        yield 'the dashboard' => ['', [self::pair(PatrolConcerns::PATROLS, Verb::Read)]];
        yield 'the register' => ['/patrols', [self::pair(PatrolConcerns::PATROLS, Verb::Read)]];
        yield 'the calendar' => ['/calendar', [self::pair(PatrolConcerns::PATROLS, Verb::Read)]];
        yield 'the widget library' => ['/widgets', [self::pair(PatrolConcerns::PATROLS, Verb::Read)]];
        yield 'the export' => ['/export.csv', [self::pair(PatrolConcerns::PATROLS, Verb::Export)]];
        yield 'the patrol types section' => ['/types', [self::pair(PatrolConcerns::TYPES, Verb::Read)]];
        yield 'the observation kinds section' => ['/kinds', [self::pair(PatrolConcerns::OBSERVATION_KINDS, Verb::Configure)]];
    }

    private function url(AreaOfInterest $area, string $path): string
    {
        return '/areas/'.$area->getUuidString().'/modules/patrols'.$path;
    }

    /**
     * Somebody holding a position that grants exactly these pairs, placed at
     * one named area — or at none at all.
     *
     * @param list<string> $pairs
     */
    private function placedAt(?AreaOfInterest $area, array $pairs): User
    {
        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        \assert($catalogue instanceof ConcernCatalogue);

        $position = new Position()
            ->setName('Composed '.bin2hex(random_bytes(4)))
            ->setAllowedKinds([ScopeKind::Area])
            ->setGrantValues($pairs, $catalogue->pairs());
        $this->em->persist($position);

        $user = new User()->setPassword('x')
            ->setEmail('composed-'.bin2hex(random_bytes(4)).'@example.test')
            ->setFirstName('Pia')->setLastName('Placed')
            ->setPosition($position);

        if (null !== $area) {
            $placement = new Placement()->inAreas([$area])->acrossAllDepartments();
            $this->em->persist($placement);
            $user->setPlacement($placement);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private static function pair(string $concern, Verb $verb): string
    {
        return (string) Grant::of($concern, $verb);
    }
}
