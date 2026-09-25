# Coverage: stored corridors and filed facts

How this module measures the ground its patrols covered, and why no page
measures it.

## Contents

- [The rule](#the-rule)
- [A corridor per patrol](#a-corridor-per-patrol)
- [Facts on the ledger](#facts-on-the-ledger)
- [What a page shows](#what-a-page-shows)
- [What an installation runs](#what-an-installation-runs)

## The rule

A request never computes over a set that grows with time. Coverage is a union
of buffered tracks over a month — every track, against every zone — so it is
computed by the queue worker and filed on the core's facts ledger, and a page
reads one stored row per figure with the time it was computed. See the core's
`module-development.md`, "Facts a module computes on a schedule".

## A corridor per patrol

When a patrol settles — the handset completes it, a GPX file records it, a track
batch lands after completion — the request records it and sends
`Uhifadhi\Patrol\Message\BufferPatrolCorridor`, which carries the core's queue
marker (`Uhifadhi\Contracts\Queue\AsyncMessageInterface`) and so goes to the
installation's `async` transport. The worker buffers the track once and stores
it in `patrol_corridor`:

| Column | What |
|---|---|
| `geom` | the track buffered at its patrol type's own width (the module's 2 km where the type sets none) |
| `uniform_geom` | the track buffered at the module's one width, 2 km, for every patrol |
| `width_m`, `uniform_width_m` | the widths the two shapes were buffered at |
| `point_count` | the patrol's fix count when it was buffered |
| `computed_at` | when |

Both widths are kept because the module reports coverage both ways: the zone
figures and the coverage plate at each type's width, the "within 2 km" KPI and
the gaps card at 2 km. The shapes are stored as measured; only the union a plate
draws is simplified, at 0.0001°.

A corridor is **stale** when its patrol's type now has another width, or the
patrol holds a different number of fixes than it was buffered from.
`patrol:coverage:rebuild` and the hourly run find both.

## Facts on the ledger

`Uhifadhi\Patrol\Facts\PatrolFactProvider`, tagged `uhifadhi.facts`, files:

| Key | Subject | What | Additive |
|---|---|---|---|
| `patrols.zone_coverage` | zone | % of the zone within its patrols' type widths of a complete track | no |
| `patrols.zone_coverage_uniform` | zone | % of the zone within 2 km of a complete track | no |
| `patrols.zone_patrols` | zone | complete patrols whose track entered the zone | yes |
| `patrols.zone_distance_km` | zone | kilometres those tracks ran inside it | yes |
| `patrols.zone_entered_ever` | zone | 1 when a complete track has ever entered it, 0 when none has | no |
| `patrols.zone_last_entered_at` | zone | when the last one started, as a Unix time | no |
| `patrols.zone_last_patrol` | zone | that patrol's id | no |
| `patrols.area_coverage_uniform` | area | % of the area within 2 km of a complete track | no |

A zone is covered by its whole area's union, clipped to the zone: a round walked
along a zone's edge covers ground inside it. Null is unknown — an area that
recorded no track in the period has no share to state. "Ever" is before the
period ends, so a closed month keeps the answer of its last day.

The core's schedule asks the provider for the month, quarter and year open now,
hourly 06:00–20:00 and at 02:00 (`registry.facts.schedule`). Before it measures,
a run buffers the period's complete patrols that have no corridor yet — the
safety net for a completion whose message no worker consumed.

## What a page shows

| Page | Reads |
|---|---|
| the module dashboard's Coverage KPI | `patrols.area_coverage_uniform` for the month on screen |
| "Where nobody has been" (dashboard and area overview) | the zone facts of this month, and the area's share in the footer |
| the attention rows on the area overview and the organization dashboard | the same zone facts |
| the zone figures (`uhifadhi.zone_kpi`) | `patrols.zone_patrols`, `patrols.zone_distance_km`, `patrols.zone_coverage` |
| the performance zone map | `patrols.zone_coverage` |

The time a figure is true as of is printed on its caption line through the
shell's `shell_as_of()`: "as of 13:00". A figure nobody has computed yet reads
"not computed yet · runs hourly" and is never drawn as 0 %; a zone in that state
raises no attention row. Days since a patrol entered a zone are counted by the
page from the filed instant, so they move with the clock between two runs.

The coverage plate and the performance and department figures over arbitrary
windows union the stored corridors when asked; they buffer nothing.

## What an installation runs

The worker, which the core's recipe already names:

```console
php bin/console messenger:consume async scheduler_default
```

`async` buffers each settled patrol; `scheduler_default` carries the hourly facts
run. Without the worker nothing is buffered and nothing is filed, and the pages
say "not computed yet".

After the deploy that brings stored corridors, once:

```console
php bin/console patrol:coverage:rebuild
php bin/console uhifadhi:facts:rebuild --module=patrols --from=<first month with patrols>
```

After a patrol type's coverage width changes, the same two, from the first month
the new width should apply to.
