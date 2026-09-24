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

namespace Uhifadhi\Patrol\Access;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;

/**
 * WHAT THIS MODULE LETS SOMEBODY ACT ON — the patrols themselves, and the
 * three word-lists an area patrols by.
 *
 * WHOEVER ENFORCES A CONCERN DECLARES IT, so these are here rather than in a
 * list in the middle of the product: a power of this module cannot appear on
 * the positions page without this file saying so, and it leaves with the
 * module on uninstall.
 *
 * FOUR CONCERNS, NOT ONE, because they are four things an organization
 * plausibly hands over separately. Recording a patrol is the daily work of a
 * ranger; naming the types an area patrols by, the stations it patrols from
 * and the kinds a ranger logs against are three decisions somebody makes once
 * and everybody else then lives inside. A single "may configure patrols" row
 * would hand all three over together or none of them.
 *
 * A VERB IS DECLARED WHERE SOMETHING ENFORCES IT, and the module's own
 * conformance walks the routes in both directions. Two consequences worth
 * stating, because both look like omissions:
 *
 *   - THERE IS NO `patrols.delete`. Nothing in this module deletes a patrol.
 *     A discarded one is swept by `patrol:purge-discarded` on the retention
 *     window, which is a scheduled command and not somebody's click, and
 *     `patrols.manage` is what holds it back from that sweep. A delete row
 *     nobody enforces is a box an administrator can tick that changes
 *     nothing.
 *   - THERE IS NO `own` SCOPE. "My patrols" is a filter this module draws,
 *     not a reach it enforces; scope is the ground and the department a
 *     record lies in, never whose name is on it.
 *
 * THEY OFFER ORGANIZATION OR AREA. Every patrol, every type, every station
 * and every observation kind belongs to exactly one area, and each of these
 * screens is drawn under one.
 *
 * NOTHING HERE IS SENSITIVE. A patrol is field effort the organization
 * recorded about its own work, not a fact about a person; the photographs an
 * observation carries are the storage module's concern and are withheld
 * there.
 */
final readonly class PatrolConcerns implements ConcernSourceInterface
{
    /** The keys, spelt once, so a gate, a door and a test cannot disagree. */
    public const string PATROLS = 'patrols';
    public const string TYPES = 'patrol-types';
    public const string OBSERVATION_KINDS = 'observation-kinds';

    public function declaredBy(): string
    {
        return 'Patrols';
    }

    public function concerns(): iterable
    {
        $ground = [ScopeKind::Organization, ScopeKind::Area];

        yield new Concern(
            key: self::PATROLS,
            label: 'Patrols',
            description: 'Patrol records: the tracks, the effort and the observations made along the way.',
            /*
             * RECORD is logging one. MANAGE is acting on one somebody else
             * recorded — holding a discarded patrol back from the purge, and
             * appending a signed correction to an observation. CONFIGURE is
             * the two thresholds an area runs patrols on. EXPORT is carrying
             * the log or a track out of the building, which is deliberately
             * not the same act as reading it on screen.
             */
            verbs: [Verb::Read, Verb::Record, Verb::Manage, Verb::Configure, Verb::Export],
            scopeKinds: $ground,
            moduleSlug: PatrolModuleProvider::SLUG,
        );

        yield new Concern(
            key: self::TYPES,
            label: 'Patrol types',
            description: 'The kinds of patrol an area runs — what each one is based on, and the tunables that go with it.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $ground,
            moduleSlug: PatrolModuleProvider::SLUG,
        );

        yield new Concern(
            key: self::OBSERVATION_KINDS,
            label: 'Observation kinds',
            description: 'The words a ranger logs an observation against, and the sub-categories under them.',
            // NO READ, because nothing reads the observation kinds as a thing
            // of their own: what a ranger may log here is drawn on the
            // dashboard's own read-only card, under `patrols.read`, and the
            // screen this concern guards is the one that CHANGES them. A read
            // row an administrator could tick would take nothing away and
            // give nothing.
            verbs: [Verb::Configure],
            scopeKinds: $ground,
            moduleSlug: PatrolModuleProvider::SLUG,
        );
    }
}
