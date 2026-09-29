# Upgrading to 0.10

A patrol type may carry its base in `config/packages/patrol.yaml`, so a new area's
types record the right way from their first patrol.

## Contents

- [1. Name the bases](#1-name-the-bases)
- [2. Update](#2-update)
- [What it does not change](#what-it-does-not-change)

## 1. Name the bases

The recipe wrote the three types without one. Add `base:` to each:

```yaml
patrol:
    types:
        foot:    { label: Foot, base: surface }
        vehicle: { label: Vehicle, base: surface }
        drone:   { label: Drone, base: aerial }
```

`surface`: the ranger's own position is the track, walking, riding or driving.
`aerial`: a flight log, where the operator's position is not the coverage. A type
left without one arrives unanswered, and the area answers it on its Patrol types
section, as before.

## 2. Update

Widen the constraint to `^0.10` and update:

```console
composer update uhifadhi/patrol-module
php bin/console cache:clear
```

No migration.

## What it does not change

An area that already has patrol types keeps them exactly as they are: the
configuration is the seed a NEW area starts from, never a source an area is
re-read from. An existing area's type with no base is still answered on its
section.
