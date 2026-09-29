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

namespace Uhifadhi\Patrol\Tests\Integration\Migrations;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Patrol\Devkit\PatrolContentProvider;
use Uhifadhi\Patrol\Devkit\PatrolSeedMonth;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\CollectedContentProviders;

/**
 * A PATROL RECORDED BEFORE AN UPGRADE IS STILL THERE AFTER IT.
 *
 * THE ROWS ARE NOT WRITTEN HERE. They are seeded by this module's OWN content
 * provider ({@see PatrolContentProvider}), through the services a person's
 * screens and a ranger's handset use — so what is asserted to survive is shaped
 * the way real content is: tracks with real LINESTRINGs, observations positioned
 * on them, stored photographs, and an area taxonomy. A hand-written fixture
 * would never have produced that spread.
 *
 * HOW THE SEEDING IS DRIVEN, said plainly, because the provider needs two things
 * it does not create:
 *
 *   PEOPLE — {@see PatrolContentProvider::dependsOn()} returns `['team']`, and
 *   the observations are recorded against accounts the installation already has.
 *   So TeamBundle's own content provider is run first, reached through
 *   {@see CollectedContentProviders} — devkit's collector, played by a fixture —
 *   which is the same door devkit uses and keeps the dependency honest.
 *
 *   AN AREA — nothing installed ships area seed content, which is why the
 *   provider takes the first area the installation has and seeds nothing when
 *   there is none. There is no provider to drive, so the area is the smallest
 *   honest fixture: one persisted AreaOfInterest with the boundary its NOT NULL
 *   columns require.
 *
 * Five of the eleven tables carry rows this way; the other six are locked by
 * {@see MigrationsCoverSchemaTest}, which asserts the SCHEMA rather than rows.
 *
 * WHAT down() IS FOR, HONESTLY. A version that creates a table has a `down()`
 * that drops it, and dropping a table drops its rows. The round-trip below is
 * therefore a SCHEMA rehearsal, not a data-safe undo: it proves this module's
 * history unwinds and comes back to a schema `diff` still has nothing to say
 * about. Data survives `up()`, which is the case this test asserts first.
 *
 * The unwind stops at the last core version rather than at `first`: the core's
 * earliest `down()` drops the PostGIS extension, which belongs to whoever
 * provisioned the database and is not this module's to take with it.
 */
final class MigrationsUpgradeKeepsDataTest extends MigrationsTestCase
{
    private const NAMESPACE = 'Uhifadhi\\Patrol\\Migrations';

    /** The last version before the words became records — where the old world stops. */
    private const BEFORE_VOCABULARY = self::NAMESPACE.'\\Version20260911090000';

    public function testRowsWrittenBeforeAnUpgradeSurviveIt(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();
        $this->seedAMonth();

        $before = $this->counts();
        self::assertSame(PatrolSeedMonth::PATROLS, $before['patrol_patrol'], 'The seeding has to have left something to protect.');
        self::assertGreaterThan(0, $before['patrol_observation']);
        self::assertGreaterThan(0, $before['patrol_observation_photo']);

        // Today this re-runs a history already at its head; the moment a second
        // version ships it is the upgrade itself, and this assertion is the one
        // that catches a version that rebuilds a table instead of altering it.
        $this->migrateToLatest();

        self::assertSame($before, $this->counts());

        // The geometry column survived too, which no count shows.
        $trackless = $this->connection->fetchOne("SELECT COUNT(*) FROM patrol_patrol WHERE source = 'gpx' AND track IS NULL");
        \assert(is_numeric($trackless));
        self::assertSame(0, (int) $trackless, 'A recorded patrol lost its track across the migrate.');
    }

    /**
     * THE UPGRADE THAT TURNS WORDS INTO RECORDS, RUN OVER ROWS WRITTEN BEFORE IT.
     *
     * This is the case the whole expand → backfill → contract rule exists for,
     * and the only honest way to test it is to stop the history one version
     * SHORT, write patrols the old way — a `type` string, a `station` string, no
     * foreign keys, because those columns do not exist yet — and then run the
     * version and read what it made.
     *
     * The rows are written in raw SQL for exactly that reason: the ORM's mapping
     * is the NEW one, so persisting through it would write columns the schema at
     * that moment does not have. An installation's rows were written by the old
     * code, and this is what the old code wrote.
     *
     * What is asserted is what the migration promises. Every patrol matched — a
     * blank type included, which lands under `unspecified` rather than being
     * left behind — every distinct string became one record per area, the two
     * areas' vocabularies stayed apart, a patrol that named no station still
     * names none, and the label is the string exactly as it was typed.
     */
    public function testPatrolsWrittenBeforeTheVocabularyExistedAreMappedOntoIt(): void
    {
        $this->emptyDatabase();
        $this->console('doctrine:migrations:migrate', [
            'version' => self::BEFORE_VOCABULARY,
            '--no-interaction' => true,
        ]);

        [$first, $second] = [$this->rawArea('First Area'), $this->rawArea('Second Area')];
        $this->rawPatrol($first, 'walk', 'River Post');
        $this->rawPatrol($first, 'walk', 'Ridge Camp');
        $this->rawPatrol($first, 'boat', 'River Post');
        // No station named — a real state, and it must stay one.
        $this->rawPatrol($first, 'boat', null);
        // A blank type, which the old column permitted and nothing wrote.
        $this->rawPatrol($first, '', null);
        // Another area, with a station spelled the same way. The two must not
        // merge into one record.
        $this->rawPatrol($second, 'walk', 'River Post');

        $this->rebootKernel();
        $this->migrateToLatest();

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM patrol_patrol WHERE patrol_type_id IS NULL'), 'A patrol was left without a type.');
        self::assertSame(6, $this->scalar('SELECT COUNT(*) FROM patrol_patrol'), 'The upgrade lost or duplicated a patrol.');

        // Three types in the first area (walk, boat, unspecified), one in the second.
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM patrol_type WHERE area_id = '.$first));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM patrol_type WHERE area_id = '.$second));
        self::assertSame(
            ['boat', 'unspecified', 'walk'],
            $this->column('SELECT type_key FROM patrol_type WHERE area_id = '.$first.' ORDER BY type_key'),
        );

        // Two stations in the first area, one in the second — each its own row.
        self::assertSame(
            ['Ridge Camp', 'River Post'],
            $this->column('SELECT label FROM patrol_station WHERE area_id = '.$first.' ORDER BY label'),
        );
        self::assertSame(['river-post'], $this->column('SELECT station_key FROM patrol_station WHERE area_id = '.$second));

        // Every patrol points at the record its own string named, and the one
        // that named nothing still names nothing.
        self::assertSame(2, $this->scalar(<<<SQL
            SELECT COUNT(*) FROM patrol_patrol p
            JOIN patrol_station s ON s.id = p.station_id
            WHERE p.area_id = {$first} AND s.label = 'River Post'
            SQL));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM patrol_patrol WHERE station_id IS NULL'));
        self::assertSame(1, $this->scalar(<<<SQL
            SELECT COUNT(*) FROM patrol_patrol p
            JOIN patrol_type t ON t.id = p.patrol_type_id
            WHERE t.type_key = 'unspecified'
            SQL));

        // The shadow columns are deliberately still there and still true: a
        // rollback finds its values where it left them.
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM patrol_patrol WHERE station = 'River Post'"));
    }

    public function testThisModulesHistoryUnwindsAndComesBack(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        $versions = $this->ownVersions();
        self::assertNotSame([], $versions, 'The module ships no migration.');

        // A migration instance is FROZEN once it has run, and the dependency
        // factory hands out one instance per version, so up() and down() cannot
        // be asked of the same booted kernel.
        // @see vendor/doctrine/migrations/src/AbstractMigration.php
        $this->rebootKernel();

        $this->console('doctrine:migrations:execute', [
            'versions' => array_reverse($versions),
            '--down' => true,
            '--no-interaction' => true,
        ]);

        $tables = $this->tableNames();
        foreach ($tables as $table) {
            self::assertStringStartsNotWith('patrol_', $table, $table.' survived down().');
        }

        $this->rebootKernel();
        $this->migrateToLatest();

        $output = $this->console('doctrine:migrations:diff', [
            '--namespace' => self::NAMESPACE,
            '--allow-empty-diff' => true,
            '--no-interaction' => true,
        ]);
        self::assertStringContainsString('No changes detected', $output);
    }

    /** @return list<string> */
    private function ownVersions(): array
    {
        $versions = [];
        foreach ($this->dependencyFactory()->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $version = (string) $migration->getVersion();
            if (str_starts_with($version, self::NAMESPACE.'\\')) {
                $versions[] = $version;
            }
        }
        sort($versions);

        return $versions;
    }

    /**
     * A month of patrolling, seeded the way devkit seeds it: the providers are
     * collected off the tag, team's runs first because patrol depends on it, and
     * the area is the one fixture nothing installed ships a provider for.
     */
    private function seedAMonth(): void
    {
        /** @var CollectedContentProviders $providers */
        $providers = static::getContainer()->get('test_public.devkit.content_providers');
        $byKey = $providers->byKey();

        self::assertArrayHasKey('team', $byKey, 'The people this module records observations against come from team.');
        $byKey['team']->load();

        $this->anArea();

        self::assertArrayHasKey('patrol', $byKey);
        $patrol = $byKey['patrol'];
        self::assertInstanceOf(ContentProviderInterface::class, $patrol);
        $patrol->load();

        $this->entityManager()->clear();
    }

    private function anArea(): void
    {
        $em = $this->entityManager();

        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Sample Area')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $em->persist($area);
        $em->flush();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ([
            'patrol_patrol',
            'patrol_observation',
            'patrol_observation_photo',
            'patrol_taxonomy_kind',
            'patrol_taxonomy_subcategory',
        ] as $table) {
            $counts[$table] = $this->rowCount($table);
        }

        return $counts;
    }

    /** An area, written the way the core's own migration made the table. */
    private function rawArea(string $name): int
    {
        $this->connection->executeStatement(
            "INSERT INTO area_of_interest (uuid, name, source, geom, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 'test fixture', ST_GeomFromGeoJSON(?), NOW(), NOW())",
            [$name, '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}'],
        );

        return (int) $this->connection->lastInsertId();
    }

    /** A patrol as the OLD code wrote one: two strings and no foreign keys. */
    private function rawPatrol(int $areaId, string $type, ?string $station): void
    {
        $this->connection->executeStatement(
            "INSERT INTO patrol_patrol (uuid, area_id, type, station, team_ranger_ids, source, status, gap_count, created_at, updated_at, started_at)
             VALUES (gen_random_uuid(), ?, ?, ?, '[]', 'manual', 'complete', 0, NOW(), NOW(), NOW())",
            [$areaId, $type, $station],
        );
    }

    private function scalar(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        \assert(is_numeric($count));

        return (int) $count;
    }

    /** @return list<string> */
    private function column(string $sql): array
    {
        return array_map(static function (mixed $value): string {
            \assert(\is_string($value));

            return $value;
        }, $this->connection->fetchFirstColumn($sql));
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    private function rowCount(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->quoteSingleIdentifier($table));
        \assert(is_numeric($count));

        return (int) $count;
    }
}
