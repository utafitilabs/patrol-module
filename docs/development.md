# Development

## Contents

- [The one command](#the-one-command)
- [The test database](#the-test-database)
- [The authority table](#the-authority-table)
- [Migrations](#migrations)
- [Upgrading to 0.7](#upgrading-to-07)
- [Upgrading to 0.6](#upgrading-to-06)

## The one command

```bash
composer check       # cs:check → phpstan (max) → phpunit
```

## The test database

Integration tests run against a real PostGIS database — never SQLite — named by
`PATROL_TEST_DATABASE_URL` in `phpunit.dist.xml`. The local one is the PostGIS
test container on port 5434; CI runs the same image as a service on 5432.

## The authority table

`tests/Functional/AuthorityTableTest.php` calls every route of this module as
every kind of person — signed out, deactivated, Staff with no position, Staff
holding exactly the pair the route checks, holding all but it, holding it at
another area, an Admin and a Super Admin — and compares the outcomes with
`tests/Functional/authority-table.md`. It boots the test kernel's `authority`
environment, where the core's voters decide alone and a stranger is sent to
sign in. A change in who may open what fails the build until the table is
recorded again and its diff is read:

```bash
UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
```

A new route needs a probe in that test, or a stated reason, or the build fails.

## Migrations

This module owns eleven `patrol_*` tables and ships the SQL that creates and
changes them, in `migrations/` under `Uhifadhi\Patrol\Migrations`. The path is
registered from `UhifadhiPatrolBundle::prependExtension()`, so an installation
configures nothing and writes no version for these tables.

**Every entity change lands with its migration, in the same commit.** Generate
it against a database the whole history has already been applied to, and always
name the namespace — with several namespaces registered the command's default
target is not this one:

```bash
php bin/console doctrine:migrations:diff --namespace='Uhifadhi\Patrol\Migrations'
```

Then read the generated SQL and make it obey the three rules:

1. **Expand, backfill, contract — in one version.** A required column on a table
   an earlier version created is added nullable, filled by this version, then
   tightened. One transaction, so a failure leaves the table untouched and
   nobody edits a vendor file.

   ```php
   $this->addSql('ALTER TABLE patrol_patrol ADD sector VARCHAR(64) DEFAULT NULL');
   $this->addSql("UPDATE patrol_patrol SET sector = 'unassigned' WHERE sector IS NULL");
   $this->addSql('ALTER TABLE patrol_patrol ALTER sector SET NOT NULL');
   ```

2. **A column no backfill can fill for everyone ships nullable**, and the form
   enforces "required". Only the installation knows the value; the database
   stays permissive and the rule lives where the rule is. A later release may
   tighten it, and must refuse with a clear message while null rows remain.

3. **A destructive statement rides a LATER release** than the code that stopped
   using the column or table, and the file says which:

   ```php
   /**
    * @destructive 1.4 — patrol_patrol.legacy_grid stopped being read in 1.3
    */
   ```

A released version is never edited; a mistake in one is corrected by a new
version.

### The versions this module ships

| Class | What it does |
|---|---|
| `Uhifadhi\Patrol\Migrations\Version20260910044923` | The module's eleven tables. |
| `Uhifadhi\Patrol\Migrations\Version20260911090000` | `patrol_settings` — what one area runs patrols on. |
| `Uhifadhi\Patrol\Migrations\Version20260911120000` | `patrol_type` and `patrol_station`, with every existing patrol carried onto them. |

**What an existing installation runs:** `doctrine:migrations:migrate`, and
nothing else. `Version20260911120000` creates the two vocabulary tables, fills
them from the DISTINCT type and station strings the installation's own patrols
carry (one set per area), adds `patrol_patrol.patrol_type_id` and
`station_id` nullable, matches every row, and only then makes the type key NOT
NULL — all in one transaction. It cannot leave a patrol behind: the vocabulary
is read out of the very strings it then matches, and a blank type lands under a
type keyed `unspecified` rather than being skipped. `station_id` stays nullable,
because a patrol that named no station always was one.

`patrol_patrol.type` and `patrol_patrol.station` are deliberately **left in
place** for this release and written from the relations on every save, so a
rollback finds its values where it left them. The version that drops them rides
a later release and will carry the `@destructive` marker.

Back it up first, read it with `--dry-run`, and if a run has to be undone the
hatches are `doctrine:migrations:version --add` and `migrate --write-sql`.

`tests/Integration/Migrations` holds the four locks, and they run in
`composer check`: the path is registered and resolves, a fresh database that has
been migrated has nothing left to `diff`, rows written through this module's own
services survive a further migrate and the history round-trips, and the lint
reads the SQL each version plans and rejects rules 1 and 3 being broken. The
lint's own fixtures live in `tests/Integration/Migrations/Fixtures/Migrations` —
two that break one rule each, two that keep them.

## Upgrading to 0.7

**The patrols month is the atlas's component now.** `atlas_calendar()` draws
the grid, the day heads, the cells and their fixed height, the day numbers, the
marks, the "+N more" and the month stepper; this module says only what happened
on which day (`Uhifadhi\Patrol\Service\PatrolCalendar`). Nothing in an
installation changes for it — the calendar's stylesheet travels with the
component through the shell's head contract — but two things follow.

**`/areas/{uuid}/modules/patrols/calendar` serves one shape, not two.** It used
to answer a bare month fragment to an XHR request and a framed page to a
browser; the stepper is links now, so there is no fetch and the route always
renders the page. A caller that asked for the fragment gets the page.

**Drop the `calendar` Stimulus controller from `assets/controllers.json`.** It
fetched and swapped those month fragments and has nothing left to do. It still
ships in 0.7 — emptied, and disabled for fresh installations — because
`assets/controllers.json` is the INSTALLATION's file and keeps naming whatever
it was given: StimulusBundle resolves every name in it against the package and
throws `Controller "calendar" does not exist in the "@uhifadhi/patrol-module"
package.` at render, on every page, when the file behind a name is gone.

Delete this block from `assets/controllers.json`:

```json
"@uhifadhi/patrol-module": {
    "calendar": { "enabled": true, "fetch": "eager" }
}
```

(keeping the package's other controllers — `disclose`, `point` — and the
surrounding JSON valid), then `php bin/console asset-map:compile` and
`cache:clear`. **The file is removed in 0.8**, so an installation that has not
dropped the entry by then gets the 500 this release exists to prevent.

**A patrol type is one of the house's nine categories.** The module no longer
names a colour anywhere: a type carries `data-cat` and the shell decides what
that category looks like, in the page and again on imagery. Map layers name
`PlatePalette` tokens. Nothing to do in an installation, but a deployment that
overrode `--patrol-track` in its own sheet is overriding a property nothing
reads any more — the category tokens are `--cat-1` … `--cat-9`.

## Upgrading to 0.6

**BREAKING — the PostGIS bundle is `utafitilabs/postgis-bundle` now.** Spatial
columns ride on `utafitilabs/postgis-bundle` (`^0.1`) instead of
`fundistadi/postgis-bundle`; both register the same Doctrine DBAL type names, so
the old one must leave the tree. In the installation, swap the line in
`config/bundles.php` to
`UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle::class => ['all' => true]`,
rename any `fundi_stadi_post_gis` config key to `utafiti_labs_post_gis`, then
`cache:clear`.

**Drop the `filters` Stimulus controller from `assets/controllers.json`.** The
filter bar's dropdowns are the shell's `<details>` grouped dropdown now, so the
controller that opened this module's own panels has nothing left to do. It
still ships in 0.6 — emptied, and disabled for fresh installations — because
`assets/controllers.json` is the INSTALLATION's file and keeps naming whatever
it was given: StimulusBundle resolves every name in it against the package and
throws `Controller "filters" does not exist in the "@uhifadhi/patrol-module"
package.` at render, on every page, when the file behind a name is gone.

Delete this block from `assets/controllers.json`:

```json
"@uhifadhi/patrol-module": {
    "filters": { "enabled": true, "fetch": "eager" }
}
```

(keeping the package's other controllers — `calendar`, `disclose`, `point` —
and the surrounding JSON valid), then `php bin/console asset-map:compile` and
`cache:clear`. **The file is removed in 0.7**, so an installation that has not
dropped the entry by then gets the 500 this release exists to prevent.
