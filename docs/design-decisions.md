# Design decisions

Deliberate modeling choices, their reasoning, and the trigger that should
reopen each one. Read this before "fixing" any of them — none of these is an
oversight.

## Contents

- [1 · A station is a record the area keeps](#1--a-station-is-a-record-the-area-keeps)
- [2 · Team is free text](#2--team-is-free-text)
- [3 · Observation photos are deferred — SETTLED](#3--observation-photos-are-deferred--settled-this-decision-has-fallen)
- [4 · Sources are honest: sketch ≠ track](#4--sources-are-honest-sketch--track)
- [5 · Live tracking is v2, and it is a third door](#5--live-tracking-is-v2-and-it-is-a-third-door)
- [6 · The maps are the atlas's, and the module writes no map JavaScript](#6--the-maps-are-the-atlass-and-the-module-writes-no-map-javascript)
- [7 · The filter is a query, not a conversation](#7--the-filter-is-a-query-not-a-conversation)
- [8 · A patrol being written is a row](#8--a-patrol-being-written-is-a-row)
- [9 · Adding an observation is a submit, not a clone](#9--adding-an-observation-is-a-submit-not-a-clone)
- [10 · The charts are the atlas's too, and the module states them](#10--the-charts-are-the-atlass-too-and-the-module-states-them)

## 1 · A station is a record the area keeps

`Patrol.stationRecord` is a `ManyToOne` to the AREA's `Station` — the core's
own record (`Uhifadhi\Bundle\AreaBundle\Entity\Station`), recorded, moved and
retired under the area's Configure › Stations — and `Patrol.patrolType` a
`ManyToOne` to `PatrolType`, the module's own word list edited on its configure
page's Patrol types section. This module keeps no station list: a station is a
place the area runs, and one area's post is not one module's word.

**Why the area's record and not the module's:** a station has a point, a code,
a zone derived from where it stands, the people stationed at it and a duty
roster around it, and every module that says "at the gate" means the same gate.
A second list of the same places, spelt slightly differently, is exactly what
counted patrols nowhere on the station's own dock; one record is what makes the
count honest.

**What the wire carries:** the station's uuid is the key a saved filter, an
export column and the handset hold; the label is its name. The handset reads
the area's stations through `GET /api/patrols/vocabulary`, ordered as the
register orders them.

**A word the area does not keep stays a word.** A handset that names a place the
area has no station for is neither refused nor obeyed: the patrol is kept, the
word is kept on it (`Patrol::$station`), and no station is made of it — a
station needs a point nobody on a handset was asked for, and the handset
collects while the office configures. The office sees the word on the patrol
and records the station if it is real.

**On the map:** a station is drawn where the area put it. A word with no record
is drawn at the FIRST recorded point of the patrol that carried it
(`PatrolDashboardService::coveragePayload`) — the best evidence the module holds,
never an invented position — and a word whose patrol recorded no track gets no
marker at all.

**Retire, never delete.** Patrols are filed against types, so a type has no
delete control: retiring dims the row, takes the word off the handset at the
next sync, and leaves every record intact. A station's life is the area's to
decide, on the area's own screens.

**`patrol.types` is a SEED, not a source.** An area with no types yet is given
the installation's configured list the first time its Settings section or its
log form is opened. After that the two are unrelated, and a config change never
reaches back into a list somebody has curated.

**What 0.8 left for a later release:** `patrol_patrol.station_id` and the
`patrol_station` table it points at. Nothing writes them; the version that drops
them carries an `@destructive` marker.

## 2 · Team is free text

`Patrol.team` is a comma-separated roster string; only `lead` is a real
relation to the person contract.

**Why:** matches the settled designs (the roster is display information), and
many patrol members will not have accounts at all. One accountable relation
(the lead) is enough for v1.

**Revisit when:** per-member accountability is needed (who logged which
observation, per-ranger effort stats). Then: a `patrol_member` join table to
`User`, keeping the free-text field for non-account members.

## 3 · Observation photos are deferred — SETTLED, this decision has fallen

**Kept for the record.** It said photos needed a storage decision the bundle
could not make alone (local filesystem vs object storage, sizing, retention),
and that blocking the whole domain layer on that call was the wrong trade. It
was the first of these decisions expected to fall, and it did.

**What it became:** `uhifadhi/storage-module` owns the mechanism —
named Flysystem storages (a local directory or Hetzner object storage, one
config key apart), a detected-MIME allowlist, a size cap, ~400px previews and
ONE authenticated route by which anything comes back out. This module keeps what
only it can know: the `ObservationPhoto` row (evidence key, nullable thumb key,
detected type, byte size, takenAt), when to store, and — through
`PatrolEvidenceVoter` — who may read.

Two consequences worth stating plainly:

- **`mimeType` is the DETECTED type.** The column holds what the bytes are,
  never what the *client claimed* — a type a handset writes into a header is a
  claim, and evidence read out later must not rest on one.
- **`thumbKey` is nullable and must stay so.** No GD build decodes HEIC and an
  ImageMagick without libheif cannot either, so an iPhone photograph is
  routinely stored with no preview. Recording that honestly beats failing the
  upload — losing a ranger's photograph to a missing image library would be an
  absurd trade — and the page falls back to the original.

**Revisit when:** photographs need to be attached from the WEB as well as from
the handset. The detail screens are view-only, so today every
photograph arrives through the sync endpoint.

## 4 · Sources are honest: sketch ≠ track

`PatrolSourceEnum` (gpx | manual | api) is load-bearing, not bookkeeping: a
hand-sketched manual route must never render or aggregate as if it were a
recorded track, and GPS gaps are flagged and stored, never smoothed.
Consumers branch on the source; do not "simplify" this away.

## 5 · Live tracking is v2, and it is a third door

A patrol in progress is a stream of positions; a GPX file is a finished
artifact. Live tracking therefore does NOT extend `TrackIngestService` — it
adds a `PositionIngestService`: the tracker app POSTs batched positions
(store-and-forward, deduped by device + timestamp), the server publishes to a
Mercure topic per area, the coverage map subscribes, and closing the patrol
assembles the streamed positions into the same stored LineString with the
same honesty metadata as an import. Wildlife-collar feeds (vendor APIs) are
consumers of the same pipeline shape, on sibling topics.

**Sequencing:** after the v1 screens are ported and installed.

## 6 · The maps are the atlas's, and the module writes no map JavaScript

A patrol map is stated in PHP and drawn by the atlas. `Service/PatrolMapService` builds
an `AtlasMap` — the layers, the boundary, the legend rows — and the template
calls `render_map()`. The imagery, the control stack, the base-layer menu, the
scale bar, fullscreen and the legend's layout are the platform's; this module
defines no boundary colour, weight or opacity anywhere, and ships no map
controller, no Leaflet and no chrome markup.

```twig
{{ render_map(map, {'role': 'img', 'aria-label': 'Patrol tracks'}, patrolFilters) }}
```

The filter row goes in the plate's filter slot — one row above the map and
inside the plate — so the chips come along into fullscreen. Every chip is a
LINK: type, station, zone and month are query parameters, read once into a
`Model/PatrolFilter`, so the map, the log and the charts are three readings of
one answer (§7). The legend switches a whole patrol type on the map, in the
deployment's colour for that type.

What a mark MEANS is stated as data too. A track layer declares the property a
hover reads (`ref · type`) and the property that identifies a feature, so a log
row beside the map spotlights its own track by wearing
`data-atlas-highlight="<layer>:<ref>"` — no module JavaScript for any of it.

HOW TALL a plate is comes from one custom property, `--map-plate-height`, set on
the card the plate sits in (`.patrol-coverage-plate` carries the coverage map's
own number). A plate has a real height and refuses to stretch, so it is never as
tall as the longest column beside it; the patrol detail plate states nothing,
because the design's height for it is already the plate's default.

THE AREA'S GROUND IS THE ATLAS'S. Every patrol plate but the station picker
stands on the area's boundary and zones: the controller takes the area's answer
from `AreaMapPayload::forArea()` (service `area.map_payload`) and
`PatrolMapService` hands it to the atlas as a `Ground`. The atlas draws the
zones as one quiet line layer under every patrol mark and opens the legend on
"The area" — the Boundary row, then "Zones · N", present at nought — and
patrol's own stations row joins that group. This module names no zone colour
and draws no zone layer. The station picker keeps its own quiet boundary and
no zones: it is a plate for placing one point.

WHICH IMAGERY a satellite layer draws is the deployment's configuration
(`atlas.satellite.provider`: esri, google or its own source), read by the atlas
from the document. This module neither knows nor needs to.

The one thing each map decides for itself is whether the outside-the-area SCRIM
is drawn. The coverage map shows it, like the area map: it frames the whole
area, and dimming the outside is what makes the boundary read at a glance. The
detail and observation plates do NOT: they open deep inside the area at close
zoom, where "outside" is not in frame at all and the scrim would only darken
imagery for no gain. Both draw the identical casing and jade line. The scrim is
the one parameter this module hands the ground.

**Why:** the platform rule is that the same layer renders identically wherever
it appears — a patrol map and an area map must not disagree about what
"satellite" means, and two Leaflets on one page are two module namespaces whose
objects each other refuses. This module is a uhifadhi module: `AreaBundle` and
`AtlasBundle` ship in the one core package it already requires, so drawing on
the atlas costs nothing extra. No CDN, and never MapLibre (raster tiles +
GeoJSON need no WebGL).

## 7 · The filter is a query, not a conversation

The patrols dashboard filters on four axes — type, station, zone and month — and
all four are **query parameters**:

```
GET /areas/{uuid}/modules/patrols?type=&station=&zone=&month=YYYY-MM
```

`Model/PatrolFilter` reads them once from the request, and
`Service/PatrolDashboardService` narrows on it once. Every figure on the screen
is then a reading of one set: the map's tracks, the log's rows, the KPIs, the
five-week and per-station charts, and the calendar.

**Why not filter in the browser.** Narrowing client-side makes the map and the
log agree with each other and with nothing else. The link cannot be shared or
bookmarked, a reload loses the choice, the server-rendered counts describe a
month nobody is looking at, the charts do not narrow at all, and the map cannot
narrow by anything the payload does not already carry. One request removes the
whole class of disagreement, and it is the idiom the incidents register uses.

**Counts and menus differ on purpose.** The counts on the chips are the narrowed
view's — click a chip and you get that many. The station and zone MENUS list the
whole month, so a station you chose is never the only one still on offer; a
filter must not be a door that locks behind you.

**What is left in JavaScript** is a dropdown's manners — one panel open at a
time, closed by Escape or an outside click — which a link cannot express.

**Reopen if:** a surface needs live narrowing without a round trip (the v2 live
tracking of §5 is the candidate), at which point the answer is a frame or a
stream that re-renders the same server-computed view, never a second filter that
only some widgets obey.


## 8 · A patrol being written is a row

**The decision.** The one entry flow carries a `patrol_draft` — a real table, one
row per open form — rather than a bare uuid minted in the page and used as a key
prefix with nothing behind it.

**Why.** Files arrive through the platform's upload component on the way IN, so
they arrive before the patrol exists, and the thing they file against has to
answer two questions a bare id cannot:

- **Who may.** `patrols.record` is a pair about an AREA. An id with no row
  names no area, so the target would either trust the browser for the one fact
  the decision rests on, or fall back to a global check that lets a recorder in
  one area file evidence against another's.
- **What is abandoned.** Most drafts are never saved. The storage publishes no
  listing, so without a row there is nothing to date and nothing can say which
  bytes are leftovers. With one, `patrol:purge-discarded` sweeps drafts on the
  same `discard_retention_days` window it sweeps discarded patrols on — one
  answer in this module to "how long do we keep something nobody wants?", not
  two.

**The cost, accepted.** Two thin tables and a migration, and a row per page view
that is usually deleted minutes later. The alternative's cost is a permission
decided from a value the browser supplied.

**Reopen if:** the storage grows a listing API AND the permission model stops
being per-area — both, not either. One without the other leaves one of the two
questions unanswered.

## 9 · Adding an observation is a submit, not a clone

**The decision.** `+ Add observation` posts the form and the page comes back with
one more grid. It is not a `<template>` cloned by a Stimulus controller.

**Why.** The thing being repeated contains the upload component, and the
component is addressed by `data-upl-target` — `observation:{draft}-{n}`. A clone
carries its sibling's target, so a photograph dropped on the second grid would be
filed against the first observation. Fixing that means rewriting the component's
own attributes in JavaScript, which is exactly the bespoke upload code the
storage module exists to abolish.

The round trip costs nothing a person would notice losing: every file already
received is on the draft, and everything typed is posted and rendered back.

**Reopen if:** the storage module publishes a supported way to mint a component
at a new target from the page — then the clone becomes the component's business
rather than this module's, and the trip can go.

## 10 · The charts are the atlas's too, and the module states them

**The decision.** The three chart widgets — "Patrols per week", "Patrols by
station" and "Effort by ranger" — state an `AtlasChart` and call
`atlas_chart()`. `Model/PatrolDashboard` says which series there are, what each
is called, which category it wears and what the figures are; the box, the axis,
the gridlines, the bar geometry, the colours, the legend and the height are the
component's. The module's stylesheet says nothing about any of them.

```twig
<div class="c" data-patrol-weekly>
    <span class="tab">Patrols per week<span class="src">· by type</span></span>
    {{ atlas_chart(dashboard.weeklyChart(types, typeCat)) }}
</div>
```

**Why.** Each of the three used to be a hand-built `<svg>` with its own axis
rule, its own gridline geometry and its own annotation styles — three private
answers to a question the platform had already settled, and three sheets of
label rules this module had to ship to dress them. None of them turned over with
the palette the way the atlas's charts do, and a fourth chart added here would
have been a fourth answer again. The atlas is the component library for EVERY
module visual: maps (§6), months, and charts. A module feeds it data.

A series is a CATEGORY, not a colour, for the same reason a track is: the
position comes from `PatrolDashboardService::typePositions()`, the one map the
chips, the legend, the tracks and now the charts all read, so nothing on the
screen can disagree about what a patrol type looks like.

**What the rule is enforced by.** `Tests\Unit\VocabularyConformanceTest::
testNoTemplateDrawsAVisualOfItsOwn` fails on any template that contains an
`<svg>`, names Leaflet, or builds a month grid of its own. A mark comes from
lucide through `ux_icon()` and a chart from `atlas_chart()`; neither leaves
markup in a template, so the rule is exact.

**What the design draws, and how each is stated.** The design draws these
three as SVG: a horizontal ranked bar for the station and effort charts with the
figure written at each bar's end, an axis rounded up to the smallest covering
multiple of three on all three, and a row of type chips under the weekly chart.
Each is a statement on the `AtlasChart` now, not a drawing here: "By station"
and "Effort" are `ChartKind::Ranked` with `ChartFigures` (bare for a count, `h`
for hours); every one of the three carries `AxisScale::covering($largest, 3)`,
the design's rule stated once in `PatrolDashboard::axis()`; "Per week" takes
`ChartLegend::Chips`, the atlas drawing the type pills from the series through
the same `data-cat` door the filter chips use. The component owns what each of
those looks like, for every module at once.

**What still differs from the drawing.** The design plots at 209px and fades a
ranking's bars from 0.95 to 0.47 opacity down the list; the platform's box is
196px and a ranking's bars are one category at one weight. Both are the atlas's
to settle, not this module's.
