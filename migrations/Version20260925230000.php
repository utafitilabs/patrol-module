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
 * A PATROL'S TRACK, BUFFERED ONCE AND KEPT: `patrol_corridor`.
 *
 * One row per patrol with a track — the track buffered at its type's width
 * and at the module's one width, the widths and the fix count it was
 * measured from, and when. Coverage figures union these shapes instead of
 * buffering tracks. The statements are the ones `doctrine:migrations:diff`
 * writes for {@see \Uhifadhi\Patrol\Entity\PatrolCorridor}, the GiST index on
 * each shape included (postgis-bundle's SpatialSchemaListener adds them to
 * the mapped schema).
 *
 * THE TABLE STARTS EMPTY. The rows are the worker's, not a migration's: a
 * buffer of every historical track in one transaction would hold the deploy
 * for as long as the longest history takes. After migrating, an installation
 * runs `patrol:coverage:rebuild`, then
 * `uhifadhi:facts:rebuild --module=patrols --from=<first month>`.
 *
 * NOTHING IS CONTRACTED: down drops the table, and the next rebuild writes
 * it again from the tracks, which are the record.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-migrations/en/current/reference/generating-migrations.html
 * @see vendor/utafitilabs/postgis-bundle/src/EventListener/SpatialSchemaListener.php
 */
final class Version20260925230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A patrol\'s track, buffered once and kept, for coverage to union';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE patrol_corridor (geom geometry(MULTIPOLYGON,4326) NOT NULL, width_m INT NOT NULL, uniform_geom geometry(MULTIPOLYGON,4326) NOT NULL, uniform_width_m INT NOT NULL, point_count INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, patrol_id INT NOT NULL, PRIMARY KEY (patrol_id))');
        $this->addSql('CREATE INDEX idx_patrol_corridor_geom_sp ON patrol_corridor USING gist (geom)');
        $this->addSql('CREATE INDEX idx_patrol_corridor_uniform_geom_sp ON patrol_corridor USING gist (uniform_geom)');
        $this->addSql('ALTER TABLE patrol_corridor ADD CONSTRAINT FK_64818EF6A7B49BA9 FOREIGN KEY (patrol_id) REFERENCES patrol_patrol (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE patrol_corridor');
    }
}
