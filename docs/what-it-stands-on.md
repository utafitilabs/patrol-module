# What this module stands on

This module ships domain capability, not platform mechanics. Everything that is
not about patrols arrives with the core (`uhifadhi/uhifadhi`, five bundles in one
package) or with the evidence store, and each is a composer requirement rather
than something an installation is expected to have written.

## Contents

- [The page frame](#the-page-frame)
- [The widget framework](#the-widget-framework)
- [The area](#the-area)
- [The evidence store](#the-evidence-store)
- [The maps](#the-maps)
- [The per-area catalogue](#the-per-area-catalogue)
- [The generic component vocabulary](#the-generic-component-vocabulary)

## The page frame

Every patrol screen extends `@Shell/page.html.twig` and fills sockets:
the breadcrumb, the title, the subtitle, the actions and the body. It types no
page furniture of its own — no `.page` wrapper, no crumb markup, no flash loop —
so a saved patrol reads exactly like a saved anything else in the installation.

Patrols is **area-scoped**: every route sits under `/areas/{uuid}/modules/patrols`,
so the frame's default tab strip (the area's own) is left in place. An org-wide
hub blanks that socket; this module deliberately does not.

`ShellBundle` arrives with the core, so there is nothing to leave out: the frame
is present wherever this module is.

## The widget framework

The dashboard **is** a widget dashboard, not a page with widgets on it, and the
widget framework is the shell's. This module ships a catalogue
(`Uhifadhi\Patrol\Widget\PatrolWidgets`) and seven Twig partials; it ships no
widget mechanics at all. The library screen hands the shell's own component the
whole contract, and every write is answered by `shell.widget.endpoint` — this
module validates no token and chooses no status code.

`PatrolWidgets` implements `WidgetSurfaceInterface` and is **tagged**
(`uhifadhi.widget_surface`). Being findable is the half a catalogue alone cannot
do: the shell's pruner walks the registry, and layouts keyed to a surface no
service claims are exactly what it deletes.

Layouts are keyed by `(surface, person, area)`, so the same person may lay one
area's patrols out one way and another area's another.

## The area

`AreaBundle` owns the place a patrol happens in — `AreaOfInterest`, the zones the
gap card reads, the KPI contract the department figures are reported through and
the six overview contribution points this module fills. Every one of those tags
is applied by hand in this bundle's extension, because a reusable bundle is not
autoconfigured.

Two things about the KPI contract are worth stating plainly:

- **A department arrives as a `DepartmentRef`** — id, uuid and name — never as an
  entity. Departments belong to `TeamBundle` and nothing publishes a contract for
  one, so a signature typed against that class would bind every module that
  reports a figure to that bundle's entity.
- **An area always has a boundary.** `area_of_interest.geom` is NOT NULL, so the
  boundaryless area this module's coverage query guards against is unreachable —
  the guard costs nothing and keeps the query honest if that ever changes.

**Why the concrete class, not the `AreaInterface` contract.** The core publishes
`Uhifadhi\Contracts\Entity\AreaInterface` so a module can point at an area
*without* naming the bundle that owns one — the way `TeamBundle`'s `Department`
does, and the mechanism is documented in
[the area contract](https://github.com/utafitilabs/uhifadhi/blob/main/src/Uhifadhi/Contracts/docs/area-contract.md).
This module takes the other path deliberately: `Patrol::$area` and the
area-scoped `TaxonomyKind` are mapped to the concrete `AreaOfInterest`, because a
patrol is drawn on the area's boundary and reads its zones. Type-hinting the
interface here would lose the boundary and zone accessors the concrete entity
carries and buy nothing, since both classes ship in the one package this module
already requires.

## The evidence store

Observation photographs are stored by `uhifadhi/storage-module`, a hard
requirement, and this module fills both halves of its permission contract: a
voter that says who may read a photograph, and a file source that says which
photographs exist. Where the bytes go is in [photo-storage.md](photo-storage.md).

## The maps

Patrols' maps are the atlas's, not a second copy of a map layer (see
[design-decisions.md](design-decisions.md) §6):

- **The map builder**, `Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface`,
  which hands back a map already carrying the deployment's imagery, the
  platform's control stack and its fullscreen behaviour.
- **`render_map(map, attrs, filters)`**, the Twig function that puts a plate on
  a page: the filter row above the map, the map element, and the legend floating
  over it.
- **The map stylesheet**, `AtlasBundle::STYLESHEET`, linked by this module's base
  template. Leaflet itself arrives with UX Map's Leaflet bridge, so there is one
  Leaflet on the page and this module links none.

## The per-area catalogue

`RegistryBundle` holds the catalogue an area switches modules on in, and reconciles
it on a cache warm-up — there is no command to run. This bundle registers one
`ModuleProviderInterface` (`uhifadhi.module`) declaring the slug `patrols`, its
category, its icon and its entry route — and, through the access seam
(`uhifadhi.access.concerns`), the four concerns it enforces. The registry resolves
that route, so an area's module grid opens the patrol dashboard directly rather
than a generic module page.

The grid itself is `AreaBundle`'s screen (`area_modules`). Where an installation
has not mounted it the breadcrumb's "modules" step renders as plain text rather
than a link — see `PatrolTrailExtension`, which answers null for a route the
installation did not mount instead of throwing the page away.

## The generic component vocabulary

`.c`, `.tab`, `.kpi`, `.tbl`, `.chip`, `.mchip`, `.crumb`, `.pghead`,
`.open-btn`, `.tgl` — the way back off every screen — and the page scaffold are
the **shell's** design-system
stylesheet; `.w-grid`, `.w-cell` and `.w-span-*` are the shell's widget sheet.
This bundle's
`public/patrol.css` adds only what a patrol screen needs and no other surface
has — the coverage viewer, the type chips, the calendar, the track plate — and
never restates a rule it did not invent.

The `.ao-*` vocabulary the area overview's plates are painted in belongs to
`AreaBundle`; see [area-overview.md](area-overview.md), which also lists the
contribution points this module fills on that page.
