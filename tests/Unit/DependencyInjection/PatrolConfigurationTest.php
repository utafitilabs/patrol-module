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

namespace Uhifadhi\Patrol\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;

final class PatrolConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $builder = new TreeBuilder('patrol');
        PatrolConfiguration::define($builder->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($builder->buildTree(), ['patrol' => $config]);

        return $processed;
    }

    public function testDefaultsShipAGenericVocabulary(): void
    {
        $config = $this->process([]);

        self::assertSame('operations', $config['module_category']);
        self::assertSame(['foot', 'vehicle', 'drone'], array_keys((array) $config['types']));
        self::assertSame(
            ['wildlife', 'sign', 'infrastructure'],
            array_keys((array) $config['observation_categories']),
        );
        self::assertSame(5.0, $config['gap_threshold_minutes']);
        // The retention window the field app's discard sheet promises rangers.
        self::assertSame(90, $config['discard_retention_days']);
    }

    public function testADeploymentSetsItsOwnRetentionWindow(): void
    {
        self::assertSame(30, $this->process(['discard_retention_days' => 30])['discard_retention_days']);
        // Zero is legal and means "purge on the next sweep" — a deployment that
        // keeps nothing is a policy, not a mistake.
        self::assertSame(0, $this->process(['discard_retention_days' => 0])['discard_retention_days']);
    }

    public function testANegativeRetentionWindowIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['discard_retention_days' => -1]);
    }

    public function testAHostNamesItsOwnVocabulary(): void
    {
        $config = $this->process([
            'types' => ['boat' => ['label' => 'Boat'], 'horseback' => ['label' => 'Horseback']],
            'observation_categories' => ['maintenance' => ['label' => 'Maintenance need']],
            'gap_threshold_minutes' => 7.5,
        ]);

        self::assertSame(['boat', 'horseback'], array_keys((array) $config['types']));
        $types = (array) $config['types'];
        self::assertSame(['label' => 'Boat', 'base' => null], $types['boat']);
        self::assertSame(['maintenance'], array_keys((array) $config['observation_categories']));
        self::assertSame(7.5, $config['gap_threshold_minutes']);
    }

    /**
     * THE THREE THE MODULE SHIPS ARRIVE ANSWERED: walking and driving put the
     * ranger's own position on the ground, a drone flies. A new area's Drone
     * type records as aerial from its first patrol, not after somebody finds
     * "Choose base" on its row.
     */
    public function testTheShippedTypesCarryTheirBases(): void
    {
        $types = (array) $this->process([])['types'];

        self::assertSame(
            ['foot' => 'surface', 'vehicle' => 'surface', 'drone' => 'aerial'],
            ['foot' => self::baseOf($types, 'foot'), 'vehicle' => self::baseOf($types, 'vehicle'), 'drone' => self::baseOf($types, 'drone')],
        );
    }

    public function testAHostMayAnswerTheBaseOfItsOwnTypes(): void
    {
        $types = (array) $this->process(['types' => ['boat' => ['label' => 'Boat', 'base' => 'surface'], 'uav' => ['label' => 'UAV', 'base' => 'aerial']]])['types'];

        self::assertSame('surface', self::baseOf($types, 'boat'));
        self::assertSame('aerial', self::baseOf($types, 'uav'));
    }

    /** @param array<mixed> $types */
    private static function baseOf(array $types, string $key): mixed
    {
        $type = $types[$key] ?? null;
        self::assertIsArray($type);

        return $type['base'] ?? null;
    }

    public function testABaseThePlatformDoesNotKnowIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['types' => ['boat' => ['label' => 'Boat', 'base' => 'underwater']]]);
    }

    public function testATypeWithoutALabelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['types' => ['boat' => []]]);
    }
}
