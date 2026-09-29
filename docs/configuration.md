# Configuration

Every key `config/packages/patrol.yaml` accepts, and what each one decides.

## Contents

- [The file](#the-file)
- [Deployment vocabulary](#deployment-vocabulary)
- [`types` is a seed, not a source](#types-is-a-seed-not-a-source)
- [Retention](#retention)

## The file

```yaml
# config/packages/patrol.yaml
patrol:
    types:
        walk: { label: Walking round, base: surface }
        boat: { label: Boat, base: surface }
        uav:  { label: UAV }            # no base: each area answers it
    observation_categories:
        maintenance: { label: Maintenance need }
    gap_threshold_minutes: 5
    # How long a discarded patrol is kept before patrol:purge-discarded deletes
    # it and its photographs. Measured from the discard; stopped while held.
    discard_retention_days: 90
```

## Deployment vocabulary

Types and observation categories are deployment vocabulary, never hardcoded: one
deployment walks and drives, another patrols by boat.

## `types` is a seed, not a source

`patrol.types` is the list a **new area** starts from, and nothing else. An area
with no patrol types yet is given a copy of it — as its own records — the first
time somebody opens that area's `Patrol types` section or its log form. From then
on the area owns its list: renaming, retiring, adding a type and choosing what it
records all happen on that section, per area, and changing this file never reaches
back into an area somebody has curated.

A type's BASE — `surface` or `aerial` — may be named here, and a new area's type
then arrives with it and the base's own defaults (pace, coverage width, where an
observation is placed). The module's three answer theirs: Foot and Vehicle are
`surface`, Drone is `aerial`, so a new area's drone patrol records as a flight from
its first patrol. A type configured without a base arrives unanswered, and the area
answers it on the section. The tunables have no configuration key: they are the
area's to tune once the base has prefilled them.

Stations have no configuration key at all. They are written on the `Stations`
section, or created retired by the handset sync when a phone reports a word the
area has not heard of — see [design-decisions.md
§1](design-decisions.md#1--a-station-is-a-record-the-area-keeps).

## Retention

What `discard_retention_days` measures, and what stops its clock, is in
[discarded-patrols.md](discarded-patrols.md).
