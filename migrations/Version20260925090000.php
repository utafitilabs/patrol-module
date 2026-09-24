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

namespace Uhifadhi\Patrol\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A PATROL POINTS AT THE AREA'S STATION.
 *
 * Stations are the area module's records (ruled 2026-09-18); this module kept a
 * word list of its own, `patrol_station`, from before. Expand: a patrol gains
 * `area_station_id`. Backfill: every patrol that pointed at one of the module's
 * stations now points at the area station of the same name in the same area,
 * and where the area has none, one is made from the module's record — name and
 * point — because the point is the one thing the area's station cannot do
 * without, and a module station with no point becomes a word on the patrol and
 * nothing more. Contract later: `station_id` and `patrol_station` stay for one
 * release, unwritten, and go under an `@destructive` marker.
 *
 * The station word column grows to the area station's name width.
 */
final class Version20260925090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A patrol points at the area\'s station; the module\'s own stations are moved onto it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_patrol ADD area_station_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE patrol_patrol ALTER station TYPE VARCHAR(128)');
        // The mapping-derived names, as the sibling tables carry them: a
        // hand-picked name here is a name `migrations:diff` renames back.
        $this->addSql('ALTER TABLE patrol_patrol ADD CONSTRAINT FK_D10A8EB8AE4D508F FOREIGN KEY (area_station_id) REFERENCES station (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D10A8EB8AE4D508F ON patrol_patrol (area_station_id)');

        // The area's stations that already carry a module station's name.
        $this->addSql(<<<'SQL'
            UPDATE patrol_patrol p
               SET area_station_id = s.id
              FROM patrol_station ps
              JOIN station s ON s.area_id = ps.area_id AND LOWER(BTRIM(s.name)) = LOWER(BTRIM(ps.label))
             WHERE p.station_id = ps.id
               AND p.area_station_id IS NULL
            SQL);

        // Module stations with a point and no area station of that name become
        // one — active as the module had them, code left for the office.
        $this->addSql(<<<'SQL'
            INSERT INTO station (area_id, name, point, active, uuid, created_at, updated_at)
            SELECT ps.area_id, BTRIM(ps.label), ps.point, ps.active, gen_random_uuid(), NOW(), NOW()
              FROM patrol_station ps
             WHERE ps.point IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM station s
                    WHERE s.area_id = ps.area_id AND LOWER(BTRIM(s.name)) = LOWER(BTRIM(ps.label))
               )
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE patrol_patrol p
               SET area_station_id = s.id
              FROM patrol_station ps
              JOIN station s ON s.area_id = ps.area_id AND LOWER(BTRIM(s.name)) = LOWER(BTRIM(ps.label))
             WHERE p.station_id = ps.id
               AND p.area_station_id IS NULL
            SQL);

        // A patrol whose module station had no point keeps its word.
        $this->addSql(<<<'SQL'
            UPDATE patrol_patrol p
               SET station = ps.label
              FROM patrol_station ps
             WHERE p.station_id = ps.id
               AND p.area_station_id IS NULL
               AND (p.station IS NULL OR p.station = '')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_patrol DROP CONSTRAINT FK_D10A8EB8AE4D508F');
        $this->addSql('DROP INDEX IDX_D10A8EB8AE4D508F');
        $this->addSql('ALTER TABLE patrol_patrol DROP area_station_id');
        $this->addSql('ALTER TABLE patrol_patrol ALTER station TYPE VARCHAR(80)');
    }
}
