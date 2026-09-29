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

namespace Uhifadhi\Patrol\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;

/**
 * The bundle's semantic configuration — how a host declares its patrol
 * vocabulary in config/packages/patrol.yaml:
 *
 *   patrol:
 *     types:                            # default: foot, vehicle, drone
 *       foot:    { label: Foot }
 *       vehicle: { label: Vehicle }
 *       drone:   { label: Drone }
 *     observation_categories:           # default: wildlife, sign, infrastructure
 *       wildlife: { label: Wildlife sighting }
 *       sign:     { label: Sign / evidence }
 *     discard_retention_days: 90        # how long a discarded patrol survives
 *
 * The words are host config, never code: a deployment names its own patrol
 * types and observation categories (the taxonomy pattern). Static so the tree
 * is testable with a plain Processor and shared verbatim by configure().
 */
final class PatrolConfiguration
{
    /** @var array<string, string> */
    public const array DEFAULT_TYPES = ['foot' => 'Foot', 'vehicle' => 'Vehicle', 'drone' => 'Drone'];

    /**
     * THE BASE EACH SHIPPED TYPE ARRIVES WITH. Walking and driving put the
     * ranger's own position on the ground; a drone flies, and its operator's
     * position is not the coverage. Answered here so a new area's types record
     * the right way from their first patrol — the handset never guesses a base
     * from a name.
     */
    public const array DEFAULT_BASES = ['foot' => 'surface', 'vehicle' => 'surface', 'drone' => 'aerial'];

    /**
     * Ninety days — long enough that a discard made in error can still be found
     * and held for review after somebody notices in the next reporting cycle,
     * short enough that a deployment is not storing a year of throwaway field
     * photographs. A deployment with a different retention policy sets its own.
     */
    public const int DEFAULT_DISCARD_RETENTION_DAYS = 90;

    /** @var array<string, string> */
    public const array DEFAULT_OBSERVATION_CATEGORIES = [
        'wildlife' => 'Wildlife sighting',
        'sign' => 'Sign / evidence',
        'infrastructure' => 'Infrastructure',
    ];

    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The patrol root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('module_category')
                    // OPERATIONS, because a patrol is the rangers' OWN work. The
                    // default was 'pressure' — which in the registry's catalogue means
                    // human pressure ON the ecosystem, and so filed the people
                    // doing the protecting alongside the thing they protect
                    // against. A deployment may still override it.
                    ->info('Catalogue category the Patrols module is filed under in each area.')
                    ->defaultValue('operations')->cannotBeEmpty()
                ->end()
                ->arrayNode('types')
                    ->info('Patrol types this deployment uses (key = stored value, label = what the UI shows).')
                    ->useAttributeAsKey('key')
                    ->defaultValue(array_map(
                        static fn (string $key): array => ['label' => self::DEFAULT_TYPES[$key], 'base' => self::DEFAULT_BASES[$key]],
                        array_combine(array_keys(self::DEFAULT_TYPES), array_keys(self::DEFAULT_TYPES)),
                    ))
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            // How the type records, copied to a new area with the
                            // base's own defaults; left out, the area answers it.
                            ->enumNode('base')
                                ->values(array_map(static fn (PatrolBaseEnum $base): string => $base->value, PatrolBaseEnum::cases()))
                                ->defaultNull()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('observation_categories')
                    ->info('Observation categories this deployment uses (key = stored value, label = what the UI shows).')
                    ->useAttributeAsKey('key')
                    ->defaultValue(array_map(static fn (string $label): array => ['label' => $label], self::DEFAULT_OBSERVATION_CATEGORIES))
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                /*
                 * There is deliberately NO photo_dir and NO photo_max_bytes here
                 * any more. Where field photographs live, how big they may be
                 * and which types count as a photograph are properties of the
                 * DEPLOYMENT's evidence storage, not of this module — every
                 * module that stores evidence must answer them the same way, and
                 * two modules with two size caps is a deployment that cannot say
                 * what it accepts. They are configured once, under `storage:`
                 * (uhifadhi/storage-module).
                 */
                ->integerNode('discard_retention_days')
                    ->info('How long a DISCARDED patrol is kept before patrol:purge-discarded deletes it and its photographs. Measured from the discard, and stopped entirely while the patrol is held for review.')
                    ->defaultValue(self::DEFAULT_DISCARD_RETENTION_DAYS)
                    ->min(0)
                ->end()
                ->floatNode('gap_threshold_minutes')
                    ->info('A pause between consecutive GPX points longer than this counts as a GPS gap — flagged on import, stored with the track, never smoothed.')
                    ->defaultValue(5.0)
                ->end()
            ->end();
    }
}
