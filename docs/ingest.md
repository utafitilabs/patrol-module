# Ingest — one service, two doors

## Contents

- [One parsing path](#one-parsing-path)
- [What the upload does](#what-the-upload-does)
- [The third door](#the-third-door)
- [What the handset is allowed to say](#what-the-handset-is-allowed-to-say)
- [What an observation is filed under](#what-an-observation-is-filed-under)

## One parsing path

`TrackIngestService` is the single parsing/validation path. The entry flow's
step 1 feeds it today; a mobile tracker app POSTs to the same service via the API
endpoint later. Neither door re-implements parsing.

## What the upload does

A track is dropped on **the platform's upload component** — step 1 of the one
entry flow, described in [screens.md](screens.md#the-one-entry-flow). This module
writes no dropzone, no file input and no upload JavaScript; it implements
`UploadTargetInterface` as `Upload\PatrolTrackTarget` (kind `patrol-track`) and
answers the four things the storage cannot know.

The bytes land, and the target parses them there and then: points, time span,
distance and GPS gaps (flagged and stored, never smoothed). What comes back is
the chip the design draws on a finished row — `parsed · 14.2 km · 2 h 05 ·
3 gaps` — in the module's own words. `gap_threshold_minutes` in
[configuration.md](configuration.md) is what counts as a gap.

The file itself is **kept**, not discarded after parsing: a patrol's GPX is the
source of a field record, and the receipt says `stored` rather than `parsed` for
that reason. On save it is re-homed under the patrol's own prefix and named by
`patrol.track_file_key`.

**The deployment's allowlist has to accept it.** The storage validates every file
against `storage.evidence.allowed_mime_types`, and a target may narrow that list
but never widen past it. A GPX is detected from its BYTES, which on most
platforms reads as generic XML, so an installation that wants step 1 to work adds
the three spellings:

```yaml
# config/packages/storage.yaml
storage:
    evidence:
        allowed_mime_types:
            ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp',
             'application/gpx+xml', 'application/xml', 'text/xml']
```

The rest of the form confirms type/station/lead — chosen from the AREA's own
patrol types and stations, as chip rows.

## The third door

Live tracking is a v2 third door, recorded with its revisit trigger in
[design-decisions.md](design-decisions.md) §5.

## What the handset is allowed to say

`GET /api/patrols/vocabulary?areaId={uuid}` — the sync's one READ, beside the
seven writes. It answers with the words one area lets a field client use, so a
new station is on the phones at the next sync rather than at the next store
release:

```json
{
  "areaId": "…",
  "generatedAt": "2026-09-11T09:00:00+00:00",
  "patrolTypes": [
    {
      "key": "drone", "label": "Drone sortie", "active": true, "position": 2, "updatedAt": "…",
      "base": "aerial", "paceMinKmh": 15, "paceMaxKmh": 70, "coverageBufferM": 400,
      "observationPlacement": "on_map", "glyph": "truck"
    },
    {
      "key": "walk", "label": "Walking round", "active": true, "position": 0, "updatedAt": "…",
      "base": null, "paceMinKmh": null, "paceMaxKmh": null, "coverageBufferM": null,
      "observationPlacement": null, "glyph": null
    }
  ],
  "stations": [
    { "key": "river-post", "label": "River Post", "active": true, "position": 0, "updatedAt": "…", "point": null }
  ],
  "observationKinds": [
    { "key": "…", "label": "…", "active": true, "position": 0, "updatedAt": "…", "subcategories": [ … ] }
  ]
}
```

- **The `key` is the wire value** the client sends back on every write, and a
  rename never changes it. Only `label` changes.
- **A patrol type's `base` is what a client builds its screen from**, and it is a
  fixed key rather than a word somebody chose. `surface` means the recorder's own
  position IS the track — walking, riding or driving — and an observation is
  filed where they stand. `aerial` means a flight log, where the operator's
  position is not the coverage and a sighting has to be marked on the map. There
  are two and a new vehicle never makes a third: a motorbike patrol is a NAME
  with the surface base.
- **The four beside it are this area's numbers for that type.** `paceMinKmh` /
  `paceMaxKmh` are the band a patrol of it is expected to keep, `coverageBufferM`
  how wide its track counts as covered ground, `observationPlacement` where an
  observation goes (`at_position` or `on_map`), and `glyph` the mark it wears.
  Each is seeded from the base when the base is chosen and is the type's own
  afterwards, so an installation tuning a default never re-tunes an area that had
  already chosen.
- **All six are nullable, and null means "nobody has said".** A type carried over
  from before bases existed has none, and a client reading null falls back to
  whatever it did before — for the app that shipped these lists compiled in, to
  reading the name. A guessed `surface` would be worse than a null: it would tell
  a handset that a drone sortie records the operator's own position as its
  coverage.
- **`active: false` means "stop offering it, keep what you already hold".** A
  retired word is SENT, not withheld: a handset holding a patrol filed under one
  still has to be able to print it.
- **`updatedAt` makes a delta possible.** Pass the newest one held back as
  `&since=<ISO-8601>` and only what has changed since comes back.
- It requires `patrols.record` **on the area it names**, like every other
  endpoint here, and refuses an unknown `areaId` with the contract's
  `unknown_area`.

**A word the handset sends that this area has never heard of is never a
refusal.** The contract names no error code for an unknown type or an unknown
station, and refusing would throw away a real patrol because a settings screen
and an app build disagreed about a word. The sync keeps the word ON THE PATROL
instead: the patrol is kept, no station is made of a word (a station is the
area's record, and needs a point the handset was never asked for), and the
disagreement shows up on the patrol for somebody in the office to settle by
recording the station under the area's Configure › Stations.

## What an observation is filed under

`POST /api/patrols/{uuid}/observations` reads the same two levels the vocabulary
endpoint publishes — an observation kind, and optionally one of its
sub-categories — by **key**:

```json
{
  "observations": [
    {
      "clientUuid": "e1000000-0000-4000-8000-000000000001",
      "category": "carcass",
      "subcategory": "poached-carcass",
      "note": "open water, no landmark",
      "position": { "lat": -3.1966, "lon": -29.5661, "accuracyM": 4.0, "satellites": 9 },
      "positionSource": "gps",
      "loggedAt": "2026-08-23T07:02:00Z",
      "photoCount": 1
    }
  ]
}
```

- **`category` is resolved against THIS AREA's observation kinds first** — the
  list the client was handed — by wire-code, then by label.
- **A deployment-wide `patrol.observation_categories` word is still accepted.**
  That flat list is a parallel model still in service, and a handset built
  against it keeps working; such a word is stored as it arrived and is *not*
  copied into the area's taxonomy.
- **A key neither model knows is kept, never refused** — created as a **retired**
  kind in the area, the same rule an unknown station gets, for the same reason: a
  422 here would strand a real patrol on a handset over a disagreement about a
  word.
- **`subcategory` is optional** and is resolved *under the kind the category
  landed on*, by wire-code then label; an unknown one arrives **retired** under
  that kind. Omitted means null, and null is never backfilled with the kind
  itself — a kind with no sub-categories offers the ranger no second chip.
- **Both obey the clientUuid rule (§1).** A re-sent observation adds nothing and
  changes nothing, whatever words the second copy carries.
