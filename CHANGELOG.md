# Changelog

## Unreleased

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
