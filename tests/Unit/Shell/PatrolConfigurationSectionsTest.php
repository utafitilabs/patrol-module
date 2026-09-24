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

namespace Uhifadhi\Patrol\Tests\Unit\Shell;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolSettingsRepository;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Shell\PatrolConfigurationSections;

/**
 * WHAT IS ON THE PATROLS CONFIGURE PAGE, as a declaration — without the page,
 * the shell or a database behind it.
 */
final class PatrolConfigurationSectionsTest extends TestCase
{
    private function declaration(?Request $request = null, ?AreaOfInterest $area = null): PatrolConfigurationSections
    {
        $requests = new RequestStack();
        if (null !== $request) {
            $requests->push($request);
        }

        $areas = $this->createStub(AreaOfInterestRepository::class);
        $areas->method('findOneBy')->willReturn($area);

        // A REAL SETTINGS SERVICE OVER AN EMPTY REGISTRY. It is not reached on the
        // pages this test drives — the declaration answers before it queries — and
        // a repository over a registry that was never asked for a manager is
        // cheaper to build than a double of a final class.
        $registry = $this->createStub(ManagerRegistry::class);

        return new PatrolConfigurationSections(
            $requests,
            $areas,
            new PatrolSettingsService(
                $this->createStub(EntityManagerInterface::class),
                new PatrolSettingsRepository($registry),
                5.0,
                90,
            ),
            null,
        );
    }

    public function testItConfiguresItsOwnModule(): void
    {
        self::assertSame(PatrolModuleProvider::SLUG, $this->declaration()->slug());
    }

    /**
     * THE FIVE SECTIONS, in the ruled order and in the words this module chose:
     * the library, the types, the places, the words, the numbers.
     *
     * FOUR OF THEM KEEP AN ADDRESS OF THEIR OWN, and the stylesheet is why: a
     * section the shell renders as a body inside its own configure page can spend
     * only the vocabulary the SHELL's sheet ships, and each of those four draws
     * families of its own. Settings spends the shell's alone, so it stays a body.
     */
    public function testItDeclaresTheLibraryTheTypesTheKindsAndTheSettings(): void
    {
        $sections = $this->declaration()->sections();

        self::assertSame(
            [
                ConfigurationSection::WIDGETS,
                PatrolConfigurationSections::TYPES,
                'kinds',
                ConfigurationSection::SETTINGS,
            ],
            array_map(static fn (ConfigurationSection $s): string => $s->id, $sections),
        );
        self::assertSame(
            ['Widget library', 'Patrol types', 'Observation kinds', 'Settings'],
            array_map(static fn (ConfigurationSection $s): string => $s->label, $sections),
        );
        self::assertFalse($sections[0]->isRendered());
        self::assertFalse($sections[1]->isRendered());
        self::assertFalse($sections[2]->isRendered());
        self::assertTrue($sections[3]->isRendered(), 'Settings is the one body the shell renders.');
    }

    /**
     * A DECLARATION IS CONSULTED ON EVERY PAGE OF THE MODULE, so the bodies the
     * shell renders are gathered only where they are drawn.
     */
    public function testNoRenderedBodyIsBuiltAwayFromTheConfigurePage(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'patrol_dashboard');

        foreach ($this->declaration($request)->sections() as $section) {
            self::assertSame([], $section->variables, $section->id);
        }
    }

    /** A request that names no area gets an empty heading rather than a throw. */
    public function testAHeadingWithoutAnAreaIsEmpty(): void
    {
        self::assertSame('', $this->declaration()->heading());
    }

    /** Nothing this module calls a section says "register". */
    public function testNoSectionSaysRegister(): void
    {
        foreach ($this->declaration()->sections() as $section) {
            self::assertStringNotContainsStringIgnoringCase('register', $section->label);
            self::assertStringNotContainsStringIgnoringCase('register', $section->id);
        }
    }
}
