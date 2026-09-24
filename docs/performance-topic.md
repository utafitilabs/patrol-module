# What patrols publishes on the performance page

The organization's performance page is not a board of everybody's columns
against everybody's departments. Each module publishes **a topic** — its own
five headline figures, its own charts and a matrix of only the departments that
read it — through `Contracts\Performance\PerformanceTopicProviderInterface`,
tagged `uhifadhi.performance_topic` explicitly in the bundle extension because a
reusable bundle is not autoconfigured. Adding this module adds a topic and
changes nothing the host owns.

`Module\PatrolPerformanceTopic` is that topic, and it answers under this
module's own slug, so the page orders it where the organization arranged the
module and drops it wherever the module is switched off.

## Contents

- [The five figures](#the-five-figures)
- [The two charts](#the-two-charts)
- [The matrix, and whose rows it holds](#the-matrix-and-whose-rows-it-holds)
- [A department is a lens over ground](#a-department-is-a-lens-over-ground)
- [Where the history comes from](#where-the-history-comes-from)
- [Three absences, kept apart](#three-absences-kept-apart)
- [The ground: coverage by area, and by zone](#the-ground-coverage-by-area-and-by-zone)
- [What this topic cannot say yet](#what-this-topic-cannot-say-yet)

## The five figures

Five, always — a topic with less to say fills the slot with a figure that
states its own absence, because a reader cannot tell a short row from a quiet
month. Each carries its movement on the compared period and six periods of
history for its sparkline.

| Key | Label | Unit | What it is |
|---|---|---|---|
| `patrols.patrols` | Patrols | | counted patrols that started in the period |
| `patrols.distance` | Distance | km | the kilometres they ran |
| `patrols.coverage` | Coverage | % | the share of the ground within 2 km of a recorded track |
| `patrols.observations` | Observations | | what was logged en route |
| `patrols.out_now` | Out right now | | patrols open at the instant the period closes |

A discarded outing and a patrol still arriving count towards none of the first
four, which is the one predicate every figure in this module goes through
(`PatrolStatusEnum::countsTowardsStatistics()`), applied in one place —
`Service\PatrolFigureService` — so these figures and the department KPI plates
cannot come to disagree about the same month.

"Out right now" is read from the clock and not from a status, because a status
only ever describes now: a patrol had opened at that instant and had not
closed. Asked of the present, that is exactly the set the overview's live card
draws; asked of a period that has closed, it is who was out when it ended.

## The two charts

A chart states what its series **is** and the atlas draws it; a module handing
over drawing options would be a module deciding what the platform's charts look
like. Both run over twelve periods of the page's own length, and both keep
their holes — a period nobody recorded is a gap, never a line drawn through it.

| Key | Title | Kind | Series |
|---|---|---|---|
| `patrols.distance_by_department` | Distance, by department, per period | line | one per department in the matrix |
| `patrols.coverage` | Coverage over time | line | the scope's share of its ground |

**Neither draws a target.** A target line is a fact — what somebody committed
to — and this module records no commitment, so inventing a round number to draw
against would be the chart asserting a promise nobody made.

## The matrix, and whose rows it holds

Four columns, each stating which way is good, because a matrix tints a placing
and colours a movement and both are nonsense without it:

| Column | Unit | Polarity |
|---|---|---|
| `patrols.patrols` | | up |
| `patrols.distance` | km | up |
| `patrols.coverage` | % | up |
| `patrols.observations` | | up |

All four judge upwards: every one of them measures more of the work this module
exists to record. Each cell carries its value, its movement and six periods of
history.

The rows come from the host, in one read:
`Contracts\Performance\DepartmentDirectoryInterface::forScope($scope)
->answeringFor('patrols')`. That is the published way to enumerate departments
— who they are, what each is placed among, what each attaches, and since when
each of those modules has been running somewhere that department can see it —
and it is why this module reads neither the team bundle's entities nor the
registry's ledger.

**The rows are the departments that attach this module**, and `canAnswerFor()`
decides their cells rather than their existence:

| The department | Is | Because |
|---|---|---|
| attaches nothing of this module's | not a row | the topic is not about it — which is the whole difference between a topic and the board it replaces, where one module's columns were imposed on every department |
| attaches it, and something on its ground runs it | a row of figures | it was asked, and it answered |
| attaches it, and nothing on its ground runs it | a **row of dashes** (`MatrixCell::notMine()` throughout) | leading with a module and running it nowhere is a fact a director acts on; a page that dropped the row would hide it. It did not fail to answer — nobody asked it |

A row of dashes is still a row, but it contributes no line to a chart and no
department to the headline's "across N", because it has nothing to
contribute.

The row's two letters (`mark`) and the band it is placed among come from the
same read, so this module invents neither.

## A department is a lens over ground

**Never a filter on records.** Who led a patrol, whether they hold a position
today and which department that position is filed under change no figure here —
the same rule `Module\PatrolDepartmentKpiProvider` states for the KPI plates,
and the same rule the product's department model rules. All a department
contributes to a row is how much ground it reads.

So a row's figures are the intersection of the page's scope with the
department's (`Model\PatrolTopicSlice`):

| Page | Department | Reads |
|---|---|---|
| organization | org-wide | every area that runs the module |
| organization | one area | that area |
| one area | org-wide | the page's area |
| one area | the same area | that area |
| one area | another area | nothing — it is not a row of that page |

Two departments reading the same ground read the same figures, and no surface
adds them together.

## Where the history comes from

**Computed from this module's own records, not read back from the core's period
ledger.** The host's own topics have to read theirs — how many seats were
filled in July cannot be recomputed from people who have since moved — but a
patrol carries the instant it started and the kilometres it ran, so every past
period is still here and re-measuring it today gives the answer it gave then.
Nothing in this topic writes a figure down, and nothing in it can go stale.

## Three absences, kept apart

The page draws each of them differently, so collapsing any two would turn "no
module" into "no work".

| Absence | Here it means |
|---|---|
| a null **value** | this module cannot measure that figure for that ground in that period — coverage where no track was recorded, and every figure of a scope where no area runs the module |
| a hole in a **history** | the period falls before the entry's `runningSince`: the module was not yet switched on anywhere that department reads, so nobody was recording, and a nought there would draw a collapse that never happened. Running since nobody knows when (the contract's epoch) dates no holes |
| `MatrixCell::notMine()` | the department leads with this module and nothing on its ground runs it, so nobody ever put the question — a row of dashes, not an empty figure and never a nought |

A measured nought is none of the three: an area that ran the module and
recorded no patrol reads zero, because the month was measured there.

## The ground: coverage by area, and by zone

Figures about WHERE are a separate, optional seam —
`Contracts\Performance\PerformanceGeoProviderInterface`, tagged
`uhifadhi.performance_geo`. Most topics have nothing to say about the ground:
staffing does not, goals do not, and a `geo()` on the topic contract would make
every module answer a question it has no answer to. This module does have one,
so `Module\PatrolPerformanceGeo` publishes it beside the topic and the page
draws it on the atlas plate — the same plate, the same chrome and the same
legend as every other map in the product.

It is registered **unguarded**, unlike the topic: a matrix is rows of
departments and needs TeamBundle, but an area and its zones are AreaBundle's,
which this module requires outright. An installation running no departments at
all still gets the plate.

| Series | Over | Published on | Figures |
|---|---|---|---|
| `patrols.coverage_by_area` | areas | every page | one per area of the scope that runs this module |
| `patrols.coverage_by_zone` | the zones of one area | an area's page only | one per zone of that area, carrying the area's uuid |

Both are shares in points (54.0 for 54 %) and both judge **upwards**: a plate
hues a placing, and hue without polarity is a plate claiming that more is
better when the figure is incidents. The organization's page gets no zone
series — "the zones of one area" has no answer where there is no one area —
and an area with no zones publishes none rather than an empty one.

**The ground is named by its identifier, never by its geometry.** The area
module owns the shapes and draws them; a uuid and a name are the whole of what
leaves this module, and nothing here reads a polygon out to hand over.

**Each area's figure is a share of its own boundary**, not of the scope's
boundaries combined — which is what the headline Coverage card reads, and is a
different number. A plate compares one piece of ground with the next, and a
figure that was a share of everything would rank them all identically. The
measurement itself is the topic's: one `Service\PatrolFigureService`, so the
card and the plate cannot come to quote two shares for one month.

A zone's share is read with each track at its own type's width, the module's
2 km standing in where a type sets none — the same reading
`Module\PatrolZoneFigureProvider` publishes for the zone cards.

### Two absences, and they are different facts

| Absence | Here it means |
|---|---|
| **an area is not a figure at all** | it does not run this module. Nobody was recording there, so there is nothing to shade and no blank to draw; a null would say "we looked and found nothing", which is not what happened. Whether a module is on is the registry's ledger, and this asks it rather than inferring it from whether a patrol happens to have been recorded — an area that switched Patrols on last week and has not been out yet is ground with nothing measured on it |
| **a null value** | ground this module was asked about and cannot measure: no track was recorded over it in the period, or the area has no boundary stored yet. For a zone, that means the area recorded no track at all |

A **measured nought** is neither: a zone the month's tracks ran nowhere near,
in an area that recorded tracks elsewhere, reads 0 — the ground was looked at
and none of it was covered.

**A series of nothing is still published.** Whether a plate of blanks is drawn
at all is the page's call, and the contract hands it `GeoSeries::isEmpty()` to
make it with; a module that pre-filtered would be deciding a picture it cannot
see. `geo()` returns nothing only where no area of the scope runs the module.

## What this topic cannot say yet

- **No coverage target.** The design's chart draws a declared target line and
  its Coverage card reads "target 60 % — short". Nothing in this module or in
  the core declares a coverage target, so no target is published and no line is
  drawn. A target belongs with whoever records the commitment — the department
  goals the host already owns are the likeliest home — and this topic will
  publish it the day there is one to read.
- **No row URL.** `MatrixRow` carries the destination of its `Open →`; it is a
  host route, and this module leaves it null rather than building a path it
  does not own. The row's `mark` is no longer empty — the directory publishes
  it.
