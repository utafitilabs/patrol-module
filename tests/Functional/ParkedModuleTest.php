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
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;

/**
 * PARKING PATROLS TAKES ITS PAGES WITH IT — the module's half of the registry's
 * route gate.
 *
 * The registry enforces it and the core tests the enforcement; what belongs here
 * is the two things only this bundle can be held to. First, that every route it
 * ships says which module it is — the `_uhifadhi_module` default, stamped once
 * per controller, which is what lets the registry recognise a patrol page
 * precisely rather than inferring it from a URL. Second, the whole round trip through a
 * real request: park, gone; unpark, back.
 *
 * The 404 is the point. A 403 would confirm the pages are there and being kept
 * from you; parking keeps nothing from anybody — this area is not running
 * Patrols, which is exactly what its own module screens say.
 */
final class ParkedModuleTest extends WebTestCase
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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

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

    private function areaModules(): AreaModuleService
    {
        $service = static::getContainer()->get('test_public.'.AreaModuleService::class);
        \assert($service instanceof AreaModuleService);

        return $service;
    }

    private function dashboardUrl(): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols';
    }

    /**
     * EVERY ROUTE THIS BUNDLE SHIPS SAYS WHOSE IT IS. One class-level default
     * per controller, so a controller added later without it fails here rather
     * than quietly leaning on the registry's path-shape fallback — which only holds
     * while the URL segment happens to match the slug.
     */
    public function testEveryRouteOfThisModuleDeclaresTheModule(): void
    {
        $router = static::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $undeclared = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!\is_string($controller) || !str_starts_with($controller, 'Uhifadhi\\Patrol\\Controller\\')) {
                continue;
            }
            if (PatrolModuleProvider::SLUG !== $route->getDefault(RegistryBundle::MODULE_ROUTE_DEFAULT)) {
                $undeclared[] = $name;
            }
        }

        self::assertSame([], $undeclared);
    }

    /**
     * PARK IT AND THE PAGE IS GONE — and the area's data is not. Unparking is
     * the same click backwards and the page is back, which is what makes 404
     * the honest answer rather than a demolition.
     */
    public function testParkingClosesTheDashboardAndUnparkingReopensIt(): void
    {
        $this->client->request('GET', $this->dashboardUrl());
        self::assertResponseIsSuccessful();

        $this->areaModules()->uninstall($this->area, PatrolModuleProvider::SLUG);

        $this->client->request('GET', $this->dashboardUrl());
        self::assertResponseStatusCodeSame(404);

        $this->areaModules()->install($this->area, PatrolModuleProvider::SLUG);

        $this->client->request('GET', $this->dashboardUrl());
        self::assertResponseIsSuccessful();
    }

    /**
     * NOT ONE PAGE — ALL OF THEM. The gate is not on the dashboard, it is on
     * the module, so a deep link nobody remembered to protect closes with it.
     */
    public function testParkingClosesEveryPageOfTheModule(): void
    {
        $this->areaModules()->uninstall($this->area, PatrolModuleProvider::SLUG);

        foreach ([
            $this->dashboardUrl(),
            $this->dashboardUrl().'/calendar?month='.date('Y-m'),
            $this->dashboardUrl().'/widgets',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url.' should be gone while Patrols is parked');
        }
    }
}
