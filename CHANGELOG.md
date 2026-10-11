# Changelog

## Unreleased

 * THE PERFORMANCE TOPIC READS STORED FIGURES (#71): the fact provider files, per area and per month, the complete patrols, their kilometres and their observations (`patrols.area_patrols`, `patrols.area_distance_km`, `patrols.area_observations`, additive, a nought where nobody went out) and the organization's combined coverage (`patrols.installation_coverage_uniform`). `PatrolPerformanceTopic` reads them and the area coverage from the facts ledger, once a period for the whole page, instead of loading every patrol of every period; a figure of an open period prints when it was true, and one the worker has not filed yet is no figure. Needs core `^0.2.1`.

 * `EventListener\PatrolFactsListener`: recording, discarding, deleting or moving a complete patrol, or adding an observation to one, asks the core to file its months again (`RecomputeFacts`), since the schedule refiles only open periods. A patrol still recording asks for nothing.

## 0.11.0

 * the module runs on core 0.2 and storage 0.4 (`uhifadhi/uhifadhi` `^0.2`, `uhifadhi/storage-module` `^0.4`). Everybody but the tiers belongs to one department: a person reaches this module's records through the department they belong to or one they support, and somebody with no department reaches none.

 * `PatrolWriteVoter` names the one question it answers (`supportsAttribute()`, `supportsType()`) and answers for the account the token carries, so Team › Permissions can ask it about anybody.

 * the module declares its power over somebody else's record, `PatrolPowers` (tagged `uhifadhi.access.powers`): "add to or complete another ranger’s patrol", asked with `patrol.write` about a colleague's most recent patrol.

 * the authority table: every route of the module called as every kind of person, against the reviewed `tests/Functional/authority-table.md`.

 * a Super Admin deletes a patrol (ruled 28 Sep, #48): a Delete action on the patrol's page opens the core's delete page (`/areas/{uuid}/modules/patrols/{patrol}/delete`), which counts what goes - the track, its observations, events and photographs, whose files leave storage too - and names what stays. Deleting a person takes the patrols they led with them. `Patrol` lists its observations' ids (`LinkedRecordsInterface`), so an incident filed from one keeps its record and loses only the link. Needs core 0.1.24.

 * a patrol is written only by the ranger who recorded it (its lead) or by an Admin or a Super Admin (ruled 30 Sep, #67): `PatrolWriteVoter` (`patrol.write`) is asked by every handset write - track, observations, events, flights, photographs, completion, and re-sending a patrol's id. Before, any holder of `patrols.record` in the area could write to somebody else's patrol.

 * a configured patrol type may name its `base` (`surface` or `aerial`), and a new area's type arrives with it and the base's defaults; the shipped three are answered — Foot and Vehicle `surface`, Drone `aerial` — so a new area's Drone type records as aerial from its first patrol. The recipe's `0.10` config writes the three with their bases; an existing installation adds `base:` to its own `types` (see UPGRADE-0.10.md). Areas that already have types are untouched.

 * a buffered corridor asks the core (`RecomputeFacts`) to file the patrol's months again at once, so a completed or late-uploaded patrol reaches the figures on the worker's next turn rather than the schedule's next run

- A patrol's track is buffered once, by the queue worker, into `patrol_corridor`
  — at its type's width and at the module's 2 km — when the patrol settles: a
  completion from the handset, a patrol recorded from a GPX file, or a track
  batch that lands after completion. The request only sends
  `BufferPatrolCorridor`.
- `patrol:coverage:rebuild` buffers every complete patrol with no stored
  corridor, or a stale one, in batches; `--all` buffers every one again.
- `PatrolFactProvider` files the zone and area coverage figures, the zone entry
  counts and distances, and each zone's last entry on the core's facts ledger;
  the core's hourly schedule runs it, and a run buffers any patrol of its period
  still lacking a corridor first.
- The dashboard's Coverage KPI, "Where nobody has been" on the dashboard and the
  area overview, the zone attention rows, the zone figures and the performance
  zone map read the ledger and print "as of" on their caption line; a figure not
  computed yet reads "not computed yet · runs hourly", never 0 %.
- The coverage plate and every coverage figure over a window union the stored
  corridors; no page buffers a track.
- The module requires `symfony/messenger`.
