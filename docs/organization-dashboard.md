# What patrols puts on the organization dashboard

`AreaBundle` ships a dashboard at **`/`** — the area overview one scope wider,
composed from contributors exactly as an area's is. This module answers there
through a **second seam beside the area's**, opted into deliberately.

## Contents

- [The one contribution point](#the-one-contribution-point)
- [The figure and the cell](#the-figure-and-the-cell)
- [Why it is the area reading added up](#why-it-is-the-area-reading-added-up)
- [Where the door goes](#where-the-door-goes)
- [What differs from the design, and why](#what-differs-from-the-design-and-why)

## The one contribution point

Tagged explicitly in the bundle extension, because a reusable bundle is not
autoconfigured:

| Contribution point | Tag | Class |
|---|---|---|
| Organization cells + figures | `uhifadhi.overview.org_widget_provider` | `Org\PatrolOrgWidgets` |

It carries the same slug as `Overview\PatrolOverviewContributor` (`patrols`), so
the figure and the cell leave together the day the module is uninstalled — and
the cell's `.ao-by` tag is what makes that disappearance read as the system
working rather than as a bug.

The cell's template is this bundle's:
`templates/org/_w_patrols.html.twig`, named through
`partialPattern()`. The bundle's own stylesheet arrives with it, through
`ContributesStylesheetInterface`.

## The figure and the cell

| | id | what it says |
|---|---|---|
| Figure (the four-to-a-row strip) | `PL·G1` | **Patrols this week** — how many the organization has logged since monday, with how many are out right now beside it. |
| Cell | `patrols` | **Patrols out right now** — who is out, in which area, since when, on what kind of round, and how old their last ping is. |

The row is the **modules'** — the host's own "Areas" tile only fills a slot no
module wanted — so this module states the priority the design gives its figure
(after the roster's people on duty, before the incidents module's open count)
and never assumes a position in the row.

Both are absent-honest. An installation where no area has ever opened a patrol
has not walked nought patrols: the figure keeps its slot, reads `—` and says
*nothing measured*, and the cell says no area has opened a patrol yet rather
than claiming nobody is out.

The cell is **bounded**: it draws at most `PatrolOrgWidgets::ROWS` rows,
longest out first, and states the total under them where there are more. A
dashboard cell's height may not grow with its data.

## Why it is the area reading added up

`Service\PatrolOrgOverviewService` counts nothing. It resolves the
`Contracts\Shell\Scope` to areas and then asks the two places this module's
readings are actually made — `PatrolOverviewService` for the morning and
`PatrolFigureService` for the week — once per area, and adds the answers up.

That is the contract's own rule, and writing it this way is the only way to
keep it: the organization's "3 out" cannot disagree with the areas' because it
**is** the areas', concatenated. `tests/Integration/Org/PatrolOrgReadingTest`
asserts that property against the per-area service rather than against a number
written into the test.

## Where the door goes

The design's cell carries a `Patrols →` door. This module offers it **only
where one page answers for every row on the cell** — an organization patrolling
in a single area. An organization patrolling in four has four such pages and
none above them, so the cell states how many areas it is reading instead of
picking one.

**Reopen when** this module ships its organization-level page set through
`Contracts\Shell\OrgPagesInterface` (ruled, not built). That page is the door's
unconditional target, and this branch goes away.

## What differs from the design, and why

- **The cell's spans are `[12, 6]`**, not the design's `[12, 9, 6, 3]` — the
  same narrowing the host took for its own cells. A table of five columns has
  no honest reading at a quarter of the page, and no shipped preset asks for
  one.
- **No widget index is rendered.** The design prints `PL·G2` in the cell's tab;
  that is the workspace's referencing system and not product
  (`tests/Unit/Template/NoWorkshopLabelsTest`). The reference is carried on the
  figure and in the comments.
