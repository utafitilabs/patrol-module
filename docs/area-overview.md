# What patrols puts on an area's pages

`AreaBundle`'s `/areas/{uuid}` is **composed from module-contributed widgets**:
that bundle owns the surface, the grid, the preset framework and the area's
identity, and every operational number arrives through a contribution point.

## Contents

- [The five this module fills](#the-five-this-module-fills)
- [What the area provides for these plates](#what-the-area-provides-for-these-plates)
- [Three figures for every zone](#three-figures-for-every-zone)
- [One headline for every station](#one-headline-for-every-station)
- [What this module cannot tell that page](#what-this-module-cannot-tell-that-page)

## The five this module fills

All tagged explicitly in the bundle extension, because a reusable bundle is not
autoconfigured:

| Contribution point | Tag | Class |
|---|---|---|
| Widgets + their templates | `uhifadhi.overview.widget_provider` | `Overview\PatrolOverviewContributor` |
| Tiles in the right-now strip | `uhifadhi.overview.now_tile` | `Overview\PatrolNowTiles` |
| Rows in "needs attention" | `uhifadhi.overview.attention` | `Overview\PatrolAttention` |
| Layers + legend on the plate | `uhifadhi.map.layer` | `Overview\PatrolMapLayers` |
| Moves in the area pulse | `uhifadhi.overview.pulse` | `Overview\PatrolPulse` |

Five widgets — `pl_now`, `pl_today`, `pl_gaps`, `pl_obsq` and `pl_column` (the
module's whole section as one widget, which **includes** the first three rather
than restating them). Two tiles, `PL·N1` and `PL·N2`. Three layers:
`patrols.live`, `patrols.today` and `patrols.buffer`, the last off by default.

**Today, not the month.** None of these numbers reconciles with the module's own
dashboard, and none should: the overview answers who is out and how today is
going. `PatrolOverviewService` measures that morning once and all five providers
read it, so the strip's count and the live card's rows cannot disagree.

## What the area provides for these plates

The `.ao-*` vocabulary (`.ao-by`, `.ao-live`, `.ao-col`, `.ao-colstack`,
`.ao-att`, `.ao-legend`, `.ao-move`, `.ao-dot`) belongs to `AreaBundle`'s
overview and is **not** shipped here. That bundle paints every contributor dot a
neutral fog and names no module in a rule; `public/patrol.css` paints this
module's own six selectors with the accent its tracks already wear.

## Three figures for every zone

A zone is the area module's ground and every count over it is whichever module
recorded it, so `Module\PatrolZoneFigureProvider` (tagged `uhifadhi.zone_kpi`)
publishes three `DepartmentKpi` figures for each zone of an area, read in one
batch from the facts ledger where the worker filed them
([coverage.md](coverage.md)) and keyed by the zone's uuid; each carries the time
it is true as of in `DepartmentKpi::$asOf`. **Patrols logged** is every complete patrol in the period whose track
entered the ring — the track, never the station, which is a free-text word and
no evidence anybody crossed anything. **Distance patrolled** is the length of
those tracks *inside* the ring, in kilometres. **Covered** — the key the
contract names, a share in points — is how much of the zone's surface lies
under the period's tracks buffered each at its own type's width, falling back
on the module's two kilometres where a type sets none; the union is built for
the whole area and then clipped to the zone, because a round walked along the
fence covers the ring's edge without ever crossing it. The answer states the
period it read: the one asked for where it is a calendar month, quarter or year,
otherwise the calendar period of about its length that holds its last day. A
zone no track entered, in a period whose area recorded no track at all, is left
out of the answer entirely: unknown is not zero, and the zones surfaces say so
in their own words. A zone the worker has not measured yet gets its three plates
with no value and the caption "not computed yet · runs hourly".

## One headline for every station

A station is the area module's post and the counts about it are whichever
module recorded them, so `Module\PatrolStationFigureProvider` (tagged
`uhifadhi.station_kpi`) publishes **one** `DepartmentKpi` for each station of
an area — the key the contract names, `HEADLINE` — answered for the whole set
in one query and keyed by the station's uuid. The value is the number of
**complete patrols in the period that started at that post**, and the caption
is `out of here · <km> km`, the total distance those patrols recorded. One
figure and not three: a station's dock draws one row per module, so anything
more this module has to say is said in the caption.

**What "started here" means today.** A patrol still points at this module's own
station vocabulary rather than at the area's station record, so the attribution
is the honest join the module can make now, in this order:

| Rule | When it applies | What it matches |
|---|---|---|
| By name | the patrol names a station | the patrol's station text equals the post's name, both trimmed and case-folded |
| By the first fix | the patrol names none | the track's first point lies within 300 m of the post's point |

A patrol that named a post is attributed to THAT post even when it set off
beside another, because a person's word beats a coordinate; the two rules
therefore cannot both fire for one patrol, and a patrol matching neither is
counted at no post at all. Three hundred metres is the yard rather than the
neighbourhood: a fix taken at the barrier or across the compound is inside it,
the next post along a road is not.

**Since 0.8 the join is by id.** A patrol points at the area's `Station`, and
that is what a post counts. The two rules above are what remains for a patrol
that carries no id — a row recorded before 0.8 whose module station had no point,
or a row whose handset named a place the area does not keep: the word on the
patrol, matched to the post's name, else the track's first fix within the yard.
`patrol_station` stays for one release, unwritten, and goes with a later,
marked version.

**Zero and unknown are different facts.** A post that launched nothing in a
month its area patrolled reads zero — the month was measured there. Every post
of an area that recorded no complete patrol at all in the period is left out of
the answer entirely, and the station surfaces say so in their own words. The
period answered is the period asked for, and no figure carries a URL: the core
resolves the dock's link from the module's slug.

## What this module cannot tell that page

**Whether an observation has been filed as an incident.** The incidents module
records the observation's uuid on its own side (`Incident::sourceRecordUuid`) and
nothing here mirrors it. So `pl_obsq` shows no unfiled count, marks no row as
unfiled, offers no "file as incident" action and raises no attention row about
one — it says so in its own copy instead. A flag on this side, or a contract to
read back from incidents, is what would change that.
