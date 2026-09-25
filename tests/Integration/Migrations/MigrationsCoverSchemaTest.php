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

/**
 * THE DRIFT LOCK. On an empty database, the core's history plus this module's
 * builds the whole schema — and `diff` then has nothing left to say.
 *
 * A missing column here is not a test failure in the abstract: it is an
 * installation whose `doctrine:migrations:diff` — the command it runs for the
 * entities IT writes — hands it SQL for tables this module owns.
 *
 * `--namespace` is passed deliberately and always. With several namespaces
 * registered the command's default target is whichever the configuration hands
 * back first, so a diff run without it can write a version for this module's
 * entities into somebody else's directory.
 *
 * `--allow-empty-diff` turns "nothing to do" from a thrown NoChangesDetected
 * into exit 0 with the message, which is the assertion this test wants.
 *
 * @see vendor/doctrine/migrations/src/Tools/Console/Command/DiffCommand.php
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 */
final class MigrationsCoverSchemaTest extends MigrationsTestCase
{
    private const NAMESPACE = 'Uhifadhi\\Patrol\\Migrations';

    /** Every table this module owns, created by nothing but `migrate`. */
    private const OWNED_TABLES = [
        'patrol_patrol',
        'patrol_event',
        'patrol_track_batch',
        'patrol_track_point',
        'patrol_observation',
        'patrol_observation_photo',
        'patrol_observation_amendment',
        'patrol_taxonomy_kind',
        'patrol_taxonomy_subcategory',
        'patrol_launch_point',
        'patrol_flight',
        'patrol_settings',
        'patrol_corridor',
    ];

    public function testMigrateBuildsEveryTableThisModuleOwns(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        $tables = $this->tableNames();

        foreach (self::OWNED_TABLES as $table) {
            self::assertContains($table, $tables, $table.' was not created by the migrations.');
        }
    }

    public function testAfterMigratingThereIsNothingLeftToDiff(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        $before = $this->shippedVersionFiles();

        $output = $this->console('doctrine:migrations:diff', [
            '--namespace' => self::NAMESPACE,
            '--allow-empty-diff' => true,
            '--no-interaction' => true,
        ]);

        // A diff that found something WROTE it. Take the file back out of the
        // shipped directory and fail with the SQL it wanted, which is the only
        // useful thing to read here.
        $written = array_diff($this->shippedVersionFiles(), $before);
        foreach ($written as $file) {
            $sql = file_get_contents($file);
            unlink($file);
            self::fail("diff found schema the migrations do not cover:\n".(\is_string($sql) ? $sql : ''));
        }

        self::assertStringContainsString('No changes detected', $output);
    }

    /** @return list<string> */
    private function shippedVersionFiles(): array
    {
        $directories = $this->dependencyFactory()->getConfiguration()->getMigrationDirectories();
        self::assertArrayHasKey(self::NAMESPACE, $directories);

        $files = glob($directories[self::NAMESPACE].'/Version*.php');

        return \is_array($files) ? $files : [];
    }
}
