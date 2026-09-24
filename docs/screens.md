# Screens

## Contents

- [The routes](#the-routes)
- [The one entry flow](#the-one-entry-flow)
- [The dashboard's filter](#the-dashboards-filter)
- [The module frame](#the-module-frame)
- [Patrol types](#patrol-types)
- [Stations](#stations)
- [Observation kinds](#observation-kinds)
- [Settings](#settings)
- [Export](#export)

## The routes

| Screen | Route |
|---|---|
| Widget dashboard (Overview tab) | `patrol_dashboard` |
| Every patrol (Patrols tab) | `patrol_list` |
| Widget library (configure section) | `patrol_widgets` |
| Patrol detail | `patrol_show` |
| Observation detail | `patrol_observation_show` |
| Export a recorded track as GPX | `patrol_export_gpx` |
| Export the filtered log (`csv`) or its tracks (`gpx`) | `patrol_export` |
| Log a patrol — the one entry flow | `patrol_log` |
| Patrol types (configure section) | `patrol_types` |
| Observation kinds (configure section) | `patrol_kinds` |
| Save the Patrol types section | `patrol_types_save` |
| Rename / retire / reactivate one patrol type | `patrol_type_act` |
| Save the module's thresholds | `patrol_settings_save` |

`patrol_taxonomy` (`…/patrols/taxonomy`) still answers, permanently redirecting to
`patrol_kinds` (`…/patrols/kinds`) — a saved link does not become a 404 over a
rename. `patrol_import` (`…/patrols/import`) does the same, into `patrol_log`:
importing a GPX is step 1 of logging a patrol, not a screen of its own.

The dashboard and the widget library are compositions on the shell's widget
framework — see [what-it-stands-on.md](what-it-stands-on.md).

## The one entry flow

**Every patrol this module holds was written by one page.** There used to be two
— import a GPX, or log a patrol by hand — and they were the same screen with one
card missing. A patrol somebody walked with a handset and a patrol somebody
walked with a flat battery are the same record; the only difference is whether
step 1 was used. `patrol_log` at `/areas/{uuid}/modules/patrols/log` is that
page: `GET` renders it, `POST` saves it, under the CSRF token `patrol_log`.

| Step | What it is |
|---|---|
| 1 · The track | The platform's upload component in its **dropzone** presentation, target `patrol-track:{draft}`. May be skipped. |
| 2 · Patrol details | Type and station as chip rows over the AREA's own records, lead, team, started / ended, distance. With a track the span, the route and the distance are the file's and are shown read-only; without one the distance is typed and the (deferred) route sketch is offered. |
| 3 · Observations | Repeatable records. Kind and sub-category chips from the area's own observation kinds, a time, a position, a note, and an evidence grid — the same upload component in its **tile** presentation, target `observation:{draft}-{n}`. |

### The draft, and why it is a row

Files arrive through the upload component on the way IN, which means they arrive
**before the patrol exists**. The page therefore opens a `patrol_draft` — a uuid
v7 minted server-side, carried in a hidden field — and the two upload targets
file against it.

It is a ROW rather than a keyed prefix with nothing behind it, because a bare id
answers neither question the seam has to answer:

- **Who may.** The pair is `patrols.record` **on an area**, and an id with
  no row behind it names no area. The target would have to trust the browser for
  the one fact the decision rests on.
- **What is abandoned.** Most drafts are never saved — a page opened and closed
  is a normal event — and the storage publishes no listing to sweep. With a row
  there is something to date, so `patrol:purge-discarded` collects abandoned
  drafts on the same `discard_retention_days` window it collects discarded
  patrols on.

A draft also belongs to **one person**: two recorders working the same area at
once cannot drop files into each other's form.

### Saving re-homes every byte

The storage has no rename. On save each key the draft holds is **read back,
stored again** under the patrol's own prefix (`patrol/{uuid}/…`, the prefix the
voter claims and the Files hub lists on) and only then **deleted** from the
draft's — one file per transaction. An interrupted save leaves at most one
duplicate under a draft the sweep will collect, which is recoverable; the other
orderings lose bytes or leave a row pointing at a key that is not there.

The GPX itself is kept as the patrol's **source file** (`patrol.track_file_key`)
rather than discarded after parsing: it is the one artefact that can be handed to
somebody who disputes a coverage figure.

### "+ Add observation" is a submit, not a clone

The repeater is **server-side**. The button posts, and the page comes back with
one more grid; every file already received is on the draft, so the round trip
loses nothing a person typed except what the form carries back anyway.

The alternative — a `<template>` cloned by a Stimulus controller — was rejected
because the thing being cloned is the upload component: a clone carries its
sibling's `data-upl-target`, and a photograph would be filed against the wrong
observation. Rewriting that attribute in JavaScript is exactly the bespoke upload
code the storage module exists to abolish.

### What is partial, and why

- **The sub-category row is not narrowed to the chosen kind.** Every live
  sub-category is offered, each carrying its kind on `data-patrol-under`. A
  sub-category identifies its kind by itself, so the pair a person submits is
  always coherent; narrowing the row as the kind changes needs either a reload
  per chip or JavaScript of this module's own.
- **The route sketch is deferred**, as it was before — but it is now drawn only
  where the design offers it, which is where step 1 was skipped.
- **"Move on the map" is deferred with it**: with a track the position is
  prefilled from the fix nearest the time given, and without one the observation
  is recorded with no position rather than dropped on the area's centre.
- **The design's `PL·01` card indices do not ship.** They are the design
  workspace's referencing system; see `NoWorkshopLabelsTest`. The card tab says
  `step 1` instead.

## The module frame

This module draws no navigation of its own. It declares two lists and the shell
draws both:

- **Two data tabs** — `Overview` and `Patrols` — through `ModuleTabsInterface`
  (`Uhifadhi\Patrol\Shell\PatrolModuleTabs`). A tab is a place where DATA lives;
  the patrol and observation detail screens keep the `Patrols` tab lit, because
  opening a record does not leave the place the record lives in.
- **Five configure sections** — `Widget library`, `Patrol types`, `Stations`,
  `Observation kinds`, `Settings` — through `ConfigurationSectionsInterface`
  (`Uhifadhi\Patrol\Shell\PatrolConfigurationSections`). The order is the
  platform's: the library opens every configure page and the settings close it, and
  what a module files between them keeps the order it declared.

  **Four of the five keep an address of their own, and the stylesheet is why.** A
  section the shell renders as a BODY inside its own configure page can spend only
  the vocabulary the SHELL's sheet ships — that page links the shell's sheet and no
  module's, and it is not the shell's business to know which sheets a module's
  section needs. `Patrol types` draws `.stype`, `.stun`, `.sbase`, `.sbpick` and
  `.sbicon`; `Stations` draws `.spoint`, `.sppick` and the atlas's map plate; both
  draw `.tx-say`. So each is a `ConfigurationSection::screen()` at
  `…/modules/patrols/types` and `…/modules/patrols/stations`, whose template extends
  `@UhifadhiPatrol/base.html.twig` and links what it draws — exactly as
  `Observation kinds` does. `Settings` spends the shell's vocabulary alone (`.c`,
  `.frow`, `.fld`, `.save-row`), so it stays the one body the shell renders.

  A section that keeps an address still belongs to the configure page: it wears the
  page's heading and the page's strip, and the `Configure` action stays lit on it.

There is one configuration entry per surface — the shell's `Configure` action —
and no `Settings`, `Patrol types`, `Stations`, `Observation kinds` or
`Widget library` button anywhere else,
and no "Back to dashboard": the first data tab, the lit `Configure` and the crumb
are the three ways back.

## The dashboard's filter

`patrol_dashboard` reads four query parameters, and every widget on the screen
reads the one answer they select:

| Parameter | Value | Absent means |
|---|---|---|
| `type` | one configured patrol type key | every type |
| `station` | one station's key | every station |
| `zone` | one zone's name, as the spatial join reports it | every zone |
| `month` | `YYYY-MM` | the month containing today |

Every chip and dropdown option in the filter bar is a link carrying exactly
these, so the map, the log and the charts can never be answering different
questions, and the screen somebody is looking at is the screen they can send
somebody else. The reasoning is [design-decisions.md
§7](design-decisions.md#7--the-filter-is-a-query-not-a-conversation).

Two things are deliberately NOT narrowed by it: the KPI strip's coverage figure
and the coverage layer drawn under the tracks. Both are the whole month's.

The layer is buffered **per type** — each track by the width its own type carries
(`coverageBufferM`), falling back on the module's 2 km where a type carries none —
and its legend row says the width the shape was measured at rather than a fixed
number. The KPI beside it still reads the module's one distance, so on an area
whose types carry their own the two are no longer the same measurement. PL·03's
caption needs a verdict before that is closed.

## Patrol types

`patrol_types` (`/areas/{uuid}/modules/patrols/types`), saved by one POST to the
same address. Reading it is open to anybody the installation lets onto the
configure page — reading what an area patrols on is not a privilege — and the
write controls are drawn only for somebody who may `patrol-types.configure`, so a reader
gets the section read-only rather than a form that answers 403. One row per type
the area keeps: its label, its wire key, what it RECORDS, how many patrols are
filed under it, and Rename / Retire — or Reactivate on a retired one, drawn
dimmed with a `retired` chip. A retired type stays listed.

**A type carries a base, and the base is a fixed key.** `surface` means the
recorder's own position IS the track, whether they walk, ride or drive, and an
observation is filed where they stand; `aerial` means a flight log, where the
operator's position is not the coverage and a sighting is marked on the map.
There are two, and a new vehicle never makes a third — a motorbike patrol is a
NAME with the surface base.

**The base prefills three numbers and a glyph, and the type then owns them.**
Choosing a base seeds the pace band the patrol is expected to keep, how wide its
track counts as covered, and where an observation goes; each is then editable per
type behind the row's own `Tunables` disclosure, and re-saving the section never
writes a default back over one somebody tuned. Which is why they are columns on
the type and not a lookup: an installation that tunes a default later never
re-tunes an area that had already chosen.

| Base | Pace | Coverage buffer | Observations | Glyph |
|---|---|---|---|---|
| `surface` | 2–45 km/h | 150 m | at the recorder's position | `route` |
| `aerial` | 15–70 km/h | 400 m | marked on the map | `aerial` |

**A type with no base is a valid state the section draws.** Its row asks for one
(`choose base`) and nothing is blocked while it has none — which is where every
type carried over from before bases existed starts. Nothing guesses `surface` on
its behalf: that would tell a handset that a drone sortie records the operator's
own position as its coverage.

The pace bounds are 0–120 km/h and the buffer 5–2 000 m, as the design bounds
them; a hand-posted value outside either is clamped rather than refused, and a
glyph name outside the five the strip offers is ignored rather than written.

**The section saves in one POST** — every base, glyph and tunable on it is a
field of the section, and the add panel's own button submits the same form. A
row's rename, retire and reactivate are each their own POST (`patrol_type_act`),
because each is a decision on its own.

## Stations

There is no stations section here. A station is the AREA's record, recorded and
moved under the area's own Configure › Stations, and a patrol points at one of
those; the log form's station chips are the area's active stations, keyed by
their uuid. See [design-decisions.md](design-decisions.md) § 1.

## Observation kinds

`patrol_kinds` (`/areas/{uuid}/modules/patrols/kinds`) is the area-scoped
section for the observation vocabulary a ranger logs against: **kinds** on top (the
chips on the handset) and a level of **sub-categories** under each. It rhymes with
the incident module's own kinds section and shares a stylesheet vocabulary, but is **shallow**
— sub-categories are labels only: no behaviour blocks, no colour per kind, no
term, no money. The moment an observation needs a structured question, a clock or
a fine, the right control is "File as incident".

- **Area-scoped (option B):** keyed to the current area; each area owns its own
  list, and the two never merge. Empty by default — the platform ships and
  suggests nothing; the empty screen shows an inert ghost sketch of the shape and
  a "write the first kind" path.
- **Deactivate, never delete:** a retired row is dimmed and reactivatable; the
  observations filed under it keep it. No delete control exists.
- **Wire-codes are per-area and frozen:** a label is unique within its scope (kind
  in the area, sub in its parent kind); a wire-code is unique within the area and
  never changes across renames, so a saved filter, an export column and an offline
  handset can hold it.
- **Gated by `observation-kinds.configure` + CSRF:** naming the words everybody
  else logs against is a separate authority from `patrols.record` — logging a
  patrol is not enough to choose them — and separate again from naming the types
  and the stations, which an organization may hand to different people. The
  controller exists only where SecurityBundle can enforce it; its logic
  (`patrol.taxonomy_admin`) is unconditional.

**This is a parallel model.** `Observation::$category` still reads the flat
`patrol.observation_categories` deployment config; wiring observation capture (the
web module and the field app) onto this area-scoped list is a **follow-up**, not
part of this section.

## Settings

The last section of the configure page, at
`/areas/{uuid}/modules/patrols/configure/settings`, saved by one POST to
`patrol_settings_save`. The BARE configure address belongs to the surface's
first section — the widget library, which keeps a screen of its own — so the
shell redirects it there rather than drawing a second-choice section. It reads and writes one row per area
(`patrol_settings`), and an area that has never saved runs on the installation's
own `patrol:` configuration — so an untouched default and a chosen number stay
distinguishable.

**It is the two thresholds and nothing else**: the GPS-gap threshold and how long
a discarded patrol stays recoverable, bounded as the design bounds them and
clamped on the way in. The words a ranger picks from — the types, the stations,
the observation kinds — each keep a section of their own in the strip, so none of
them is restated here and nothing here links out to them: the strip is the way.

Every POST behind every section rides on the pair its own section names and a
CSRF token, and each exists only where SecurityBundle can enforce it —
`patrol-types.configure` for the types, `patrol-stations.configure` for the
stations, `observation-kinds.configure` for the kinds, and `patrols.configure`
for the two thresholds:

| Route | Method | Path |
|---|---|---|
| `patrol_types_save` | POST | `…/patrols/types` |
| `patrol_type_act` | POST | `…/patrols/types/{uuid}/{rename\|retire\|reactivate}` |
| `patrol_settings_save` | POST | `…/configure/settings` |

**Nothing is ever deleted:** retiring flips a flag, and the patrols filed under a
retired word keep it.

**The installation's `patrol.types` is the SEED for a NEW area and nothing
else.** An area with no types yet is given the configured list the first time
its Patrol types section or its log form is opened; from then on the area's list
is its own, and a config change never reaches back into it.

The **copy-from-another-area** first-setup gesture is **deferred**: it needs to
enumerate areas and read their names, which requires an area-directory contract
that is not yet ruled. The empty-state template marks where it will attach; the
first-kind start is complete without it.

## Export

`patrol_export` at `/areas/{uuid}/modules/patrols/export.{_format}` —
`_format` is `csv` or `gpx`, and nothing else is an address.

**The file always carries the filter on screen.** It reads the same four query
parameters the dashboard and the log read (`type`, `station`, `zone`, `month`,
plus `q`) and narrows through the same predicate the log page is built from
(`PatrolListService::filtered()`), so the file and the table above it can never
be answering different questions. That is why there is no export screen: there
is nothing to choose.

| Format | What it holds |
|---|---|
| `csv` | The log's own columns in the log's own order: `ref, name, type, type_label, station, station_label, zone, lead, team, started_at, ended_at, distance_km, observations, source, status, note`. Both the wire KEY and the label are present, because they are different things — a key is what a saved filter holds, a label what a person reads. |
| `gpx` | One `<trk>` per patrol that actually RECORDED a route, written by the same `GpxWriter` the per-patrol export uses. A hand-logged patrol has no geometry and is absent: a sketch handed out as a `.gpx` would re-enter the world as a recording ([design-decisions.md §4](design-decisions.md#4--sources-are-honest-sketch--track)). A month with no recorded track is an empty document, never a 404. |

The design's `Export` page action sits beside `Configure` on the dashboard and
on the log, and links the CSV; the dashboard's `Export & reporting` widget
(PL·18) links both files. The monthly PDF report the same widget describes is
not built.
