# uhifadhi/patrol-module

Field patrol effort as first-class records: GPX track ingest, en-route
observations with photos, coverage mapping and a per-user widget dashboard.
A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## Contents

- [What it is](#what-it-is)
- [Installation](#installation)
- [Upgrading](#upgrading)
- [Learn more](#learn-more)
- [License](#license)

## What it is

- **Patrols** — a patrol is a typed, timed record (who led it, which station,
  when, how far) with an optional geometry track. The type and the station are
  each one of the AREA's own records, added, renamed and retired on the module's
  configure page — never hardcoded, and never deleted, because patrols are filed
  against them. `patrol.types` is the list a new area starts from.
- **A type says what it records** — `surface`, where the recorder's own position
  IS the track, or `aerial`, a flight log where it is not. The base prefills the
  pace band the patrol is expected to keep, how wide its track counts as covered
  and where an observation goes, each then the type's own; all of it travels on
  the vocabulary read, so a field client builds its screen from the base instead
  of guessing at the name. A **station** carries a point, picked on the area's own
  map plate, which is what a track is measured against.
- **One entry flow** — every patrol is written by one page, in three steps: drop
  the track if there is one, confirm what the patrol was, record what was seen.
  A patrol somebody walked with a handset and a patrol somebody walked with a
  flat battery are the same record; the only difference is whether step 1 was
  used.
- **GPX ingest** — the track is dropped on the platform's upload component; the
  bundle parses points, time span, distance and GPS gaps (flagged and stored,
  never smoothed) and keeps the file as the patrol's source.
- **Observations** — georeferenced field notes logged en route (category from
  `patrol.observation_categories`, note, photos), each with its own detail
  screen and an audit trail.
- **Coverage** — every track drawn over the area boundary; the dashboard is a
  per-user widget composition (KPIs, map, log, feed, charts, calendar).
- **Export** — the filtered log as CSV and its recorded tracks as GPX, at one
  address in two formats; the file always carries the filter on screen.

## Installation

```bash
composer require uhifadhi/patrol-module
```

The bundle maps its own entities and ships its own assets (AssetMapper), so
there is no doctrine block and no asset wiring to write.

### The tables

```console
php bin/console cache:clear --no-warmup
php bin/console doctrine:migrations:migrate
php bin/console cache:warmup
```

Those are the installation's three commands, the same three after every change to it. The middle one is the whole of this module's schema step. This module ships the SQL for the eleven `patrol_*`
tables it owns, under its own namespace, and registers the path itself — an
installation writes no version for them, exactly as it writes none for the core.

`doctrine:migrations:diff` stays what you run for the entities **you** write,
and it is run against your own namespace:

```bash
php bin/console doctrine:migrations:diff --namespace=DoctrineMigrations
```

Pass `--namespace` every time. Several namespaces are registered in an
installation — the core's bundles, this module's, yours — and the command's
default target is not necessarily yours. After installing or updating this
package that command must report no changes; if it wants to create a
`patrol_*` table, the migrate above has not been run.

### What it requires

This module requires the core (`uhifadhi/uhifadhi`) and the evidence store (`uhifadhi/storage-module`); both are on Packagist and resolve from the caret constraints in this package's manifest, so an installation names nothing.

### Switching it on

A module is installed but **parked**: every page of it answers 404 in an area that has not taken it. An administrator switches it on per area from that area's module grid, and grants the module's permissions to the positions that need them from the positions screen. Reading needs the module's `read` grant; nothing else is required to see it.

### Who the records point at

Five columns name a person — who led the patrol, who put it on hold, who
recorded the observation, who acted on the event, who signed the amendment —
and none of them names an account class. They are mapped to
`Uhifadhi\Contracts\Entity\UserInterface`, and the installation resolves
that interface to whatever it calls its people. The core's `TeamBundle` states
that resolution from its own bundle, so an ordinary installation writes nothing;
you write a line only to **disagree**, naming your own class under the `orm:`
key already in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Uhifadhi\Contracts\Entity\UserInterface: App\Entity\Person
```

Until something answers it, the bundle installs and the kernel boots, but
anything that walks the metadata — `doctrine:migrations:diff` included — stops
on the unresolved interface. The recipe's `config/packages/patrol.yaml` says the
same at length.

Deleting an account sets those five columns null and leaves the records
standing: removing somebody from the team does not un-walk the patrol they led.

### The storage bundles

Observation photos are stored by `uhifadhi/storage-module`, a hard dependency.
Register both bundles it needs:

```php
League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
Uhifadhi\Storage\UhifadhiStorageBundle::class => ['all' => true],
```

An installation that forgets says so at compile time, not on the first upload. Where the
bytes go — and how a pre-storage-module deployment keeps the photographs it
already has — is in [docs/photo-storage.md](docs/photo-storage.md).

Every file this module takes through a browser — the track and every photograph —
goes through that bundle's one upload component; the module's whole side of it is
two `UploadTargetInterface` implementations in `src/Upload/`.

**One line of storage configuration is not optional.** The deployment's own
allowlist is what the storage validates against, and a GPX is detected from its
bytes, which on most platforms reads as generic XML. An installation that wants
step 1 to accept a track names the three spellings beside the photograph types:

```yaml
# config/packages/storage.yaml
storage:
    evidence:
        allowed_mime_types:
            ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp',
             'application/gpx+xml', 'application/xml', 'text/xml']
```

### Stimulus controllers

Nothing to do. The package declares `symfony-ux`, so Flex reads
`assets/package.json` and maintains the two controllers — `filters` and
`calendar` — in the application's `assets/controllers.json` for you, and removes
them again on uninstall.

Neither draws a map, and neither filters anything: `filters` is a dropdown's
manners (open one panel at a time, close on Escape or an outside click) and
`calendar` is the month grid's navigation. Every map on a patrol screen is the
atlas's plate — the module states what is on it in PHP and the template calls
`render_map()` — and the filter itself is a query, so every chip is a link.

### Deployment vocabulary

Name the patrol types and observation categories this deployment uses in
`config/packages/patrol.yaml`; the full key list is in
[docs/configuration.md](docs/configuration.md). `types` is the SEED a NEW area
starts from — after that each area owns its own list, edited on the configure
page's own `Patrol types` and `Stations` sections.

```yaml
patrol:
    types:
        walk: { label: Walking round }
        boat: { label: Boat }
    observation_categories:
        maintenance: { label: Maintenance need }
```

### What this module stands on

Everything that is not about patrols arrives with the core (`uhifadhi/uhifadhi`)
or the evidence store: the page frame and the dashboard mechanics from
`ShellBundle`, the area and its overview contribution points from `AreaBundle`,
the maps from `AtlasBundle`, the per-area catalogue from `RegistryBundle`, the
people from `TeamBundle`, and the photographs from `uhifadhi/storage-module`.
This bundle ships none of them, and each is a composer requirement rather than
something an installation is expected to have written. What each one carries is
in [docs/what-it-stands-on.md](docs/what-it-stands-on.md).

## Upgrading

**Upgrading from 0.5 to 0.6 changes who may do what.** Every gate in this
module is a `<concern>.<verb>` pair now, reading included, and no migration
re-ticks anybody's grants. Read [UPGRADE-0.6.md](UPGRADE-0.6.md) before the
update — it carries the route-by-route mapping and the table of what each
existing position must also be granted.

```bash
# 1. back up the database first — a migration is not a transaction on every engine
pg_dump ... > backup.sql

# 2. read what it plans to do before it does it
php bin/console doctrine:migrations:migrate --dry-run

# 3. run it
php bin/console doctrine:migrations:migrate
```

`composer update uhifadhi/patrol-module` brings new versions with the code that
needs them; the migrate is what applies them. `--write-sql=upgrade.sql` writes
the statements to a file instead of running them, for a database somebody else
applies changes to.

**If this installation already created the `patrol_*` tables itself** — with its
own `doctrine:migrations:diff`, before this module shipped a history — the
tables are already there and the shipped version must not run. Mark it executed
without running it:

```bash
php bin/console doctrine:migrations:version --add \
    'Uhifadhi\Patrol\Migrations\Version20260910044923'
```

Then run `doctrine:migrations:diff --namespace=DoctrineMigrations` and delete
whatever version of yours creates a `patrol_*` table — those tables are this
module's, and from here on it is the module that changes them.

**The versions this module ships**, in the order they run:

| Class | What it does |
|---|---|
| `Uhifadhi\Patrol\Migrations\Version20260910044923` | The module's eleven tables. |
| `Uhifadhi\Patrol\Migrations\Version20260911090000` | `patrol_settings` — what one area runs patrols on. |
| `Uhifadhi\Patrol\Migrations\Version20260911120000` | `patrol_type` and `patrol_station`, with every existing patrol carried onto them. |

The last one is the only one an existing installation has to think about, and
the answer is still `doctrine:migrations:migrate`: it reads each area's patrol
types and stations out of the strings that area's own patrols already carry, so
every row matches and nothing is left behind. `patrol_patrol.type` and
`patrol_patrol.station` stay in place for this release; the version that drops
them rides a later one. See [docs/development.md](docs/development.md).

## Learn more

- [docs/what-it-stands-on.md](docs/what-it-stands-on.md) — the frame, the widget
  framework, the area, the evidence store and the maps these screens draw on.
- [docs/configuration.md](docs/configuration.md) — every `patrol.yaml` key.
- [docs/screens.md](docs/screens.md) — the screens this module adds, by route.
- [docs/ingest.md](docs/ingest.md) — one parsing service, two doors into it.
- [docs/photo-storage.md](docs/photo-storage.md) — where photographs live, the
  upgrade path from per-module storage, and patrol's entries on the Files hub.
- [docs/discarded-patrols.md](docs/discarded-patrols.md) — what a discard means,
  what it is counted in, the retention clock and how a review hold stops it.
- [docs/organization-dashboard.md](docs/organization-dashboard.md) — the one
  contribution point on `/`, the figure and the cell it puts there.
- [docs/area-overview.md](docs/area-overview.md) — the five contribution points
  patrols fills on an area's overview page, the three figures it publishes for
  every zone, the headline it publishes for every station, and the one thing it
  cannot tell that page.
- [docs/performance-topic.md](docs/performance-topic.md) — the topic this module
  publishes on the organization's performance page: its five figures, its two
  charts, the departments its matrix holds, the coverage it publishes over the
  ground beside it, and the absences it keeps apart.
- [docs/design-decisions.md](docs/design-decisions.md) — deliberate modeling
  choices (per-area type and station records, free-text team, how photos are
  stored, honest sources, live tracking as a v2 third door) recorded with their
  revisit triggers. **Read it before changing the model** — none of them is an
  oversight.
- [docs/development.md](docs/development.md) — `composer check`, the PostGIS
  test container, and the rules a shipped migration obeys.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi platform this module is part of. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
