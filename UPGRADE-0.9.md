# Upgrading to 0.9

Coverage is computed by the queue worker from now on. An installation runs the
worker, migrates, and fills the corridors and facts once.

## 1. Run the worker

The core's recipe routes every message carrying its queue marker to `async`, and
its schedule runs on `scheduler_default`; this module's `BufferPatrolCorridor`
needs no routing line of its own. The worker consumes both:

```console
php bin/console messenger:consume async scheduler_default
```

Without it the pages show "not computed yet · runs hourly" where the coverage
figures go, and nothing is wrong with the data.

## 2. Migrate

```console
php bin/console cache:clear --no-warmup
php bin/console doctrine:migrations:migrate
php bin/console registry:sync
php bin/console cache:warmup
```

`Version20260925230000` creates `patrol_corridor`, empty.

## 3. Fill the corridors and the facts, once

```console
php bin/console patrol:coverage:rebuild
php bin/console uhifadhi:facts:rebuild --module=patrols --from=<first month with patrols>
```

The first buffers every complete patrol's track, 100 at a time
(`--batch-size`). The second files each month from `--from` to now, with the
quarters and years they touch. The hourly schedule keeps the month open now
current from then on.

## What changes on the pages

| Where | Now |
|---|---|
| Coverage KPI on the patrols dashboard | the month's filed figure, "as of" on its caption line |
| "Where nobody has been", dashboard and area overview | filed zone figures; the footer's caption says "as of"; a zone not computed yet says so and sorts last |
| Attention rows about zones | raised only for zones the worker has measured |
| Zone figures on the zones pages | filed; a rolling window is answered by the calendar period of about its length that holds its end, and the answer names it |

## For code that called the repository

`PatrolRepository::coverageFractionWithin()`, `coverageFractionAcrossAreas()`,
`coverageBufferGeoJson()`, `zoneAbsenceForArea()` and `zoneFiguresFor()` are
gone. Read the facts through `Uhifadhi\Contracts\Facts\FactReaderInterface` with
the keys in `Uhifadhi\Patrol\Facts\PatrolFactProvider`, or union the stored
corridors through `PatrolCorridorRepository::fractionWithin()`,
`fractionAcrossAreas()` and `coveredGeoJson()`.
