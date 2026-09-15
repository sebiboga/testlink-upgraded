# Bugfix — Issue #1505: requirement_mgr.class.php E_WARNING "array offset on null" on version-less requirement hit

## Problem

Importing a Mantis bug-tracker XML (modern BFF `api/reqfromissues` — the #1503
screen — or the legacy `reqCreateFromIssueMantisXML.php` / `reqImport.php` paths)
whose issue docid hits an existing requirement that has **no** `req_versions`
child row logs a spurious warning into the Event Viewer / `events` table on every
import:

```
E_WARNING
Trying to access array offset on null - in .../lib/functions/requirement_mgr.class.php - Line 1624
```

(observed in-prod as events 34/35 while testing #1503). The import itself still
completes — the warning is emitted by the frozen-requirement check dereferencing a
`null` returned by `get_last_child_info()`.

## Environment / Repro

- App `http://localhost:8082` (PHP 8.3), admin/admin; MariaDB `testlink`.
- Fixture: testproject id=1, req spec id=2 (`req_specs_revisions` id=3), and a
  **version-less** requirement id=4 under spec 2 with `req_doc_id = 'Mantis Task ID:20'`
  and NO `req_versions` child row (the half-created state a legacy import fatal can leave behind).
- `POST /api/reqfromissues/?action=import&req_spec_id=2` with a Mantis XML whose
  `<issue><id>20</id>` maps to `docid 'Mantis Task ID:20'` (multipart field
  `uploadedFile`, header `X-Requested-With: XMLHttpRequest`).

## Root Cause

Chain (each hop file:line):

1. `api/reqfromissues/index.php:388-389` and legacy `reqCreateFromIssueMantisXML.php:205`
   enqueue one requirement per `<issue>` into `requirement_mgr::createFromMap()`
   (`lib/functions/requirement_mgr.class.php:1480`) with `docid='Mantis Task ID:<id>'`.
2. `createFromMap()` finds an existing hit inside the spec branch via
   `getByAttribute(target, tproject_id, parent_id, ['output'=>'id'])` (line 1580).
3. On a hit, line 1620 requests the last version:
   `get_last_child_info($reqID, ['child_type'=>'version', 'output'=>' CHILD.is_open, CHILD.id '])`
   (options from line 1517).
4. `get_last_child_info()` (lines 2896-2945) runs
   `SELECT COALESCE(MAX(version),-1) FROM req_versions CHILD, nodes_hierarchy NH
    WHERE NH.id = CHILD.id AND NH.parent_id = {id}`. A requirement owning no
   `req_versions` child gives `MAX = -1`; the `if ($max_verbose >= 0)` branch
   (line 2919) is skipped and the function returns **`null`**.
5. Pre-fix line 1624 read `$last_version['is_open'] == 1` unconditionally. PHP 8
   emits `E_WARNING Trying to access array offset on null`. Because `null == 1`
   is `false`, the expression fell through to the `skipFrozenReq` default (`true`,
   line 1530) → the requirement is skipped as "is FROZEN" — the warning is pure
   noise (Event Viewer pollution), no flow/correctness impact.

**Blast radius:** `createFromMap()` is the single choke-point for every requirement
import surface (BFF `reqfromissues` + `reqimport`, legacy `reqImport` CSV/DocBook/
XML) — any import hitting an existing docid whose requirement owns no `req_versions`
child triggers the warning.

## Fix (minimal — landed on the default branch in commit `681d39dbc`, shipped with the #1504 locale-key fix)

`lib/functions/requirement_mgr.class.php:1624` — guard the dereference:

```php
if( (is_array($last_version) && isset($last_version['is_open']) && $last_version['is_open'] == 1)
    || !$my['options']['skipFrozenReq']) {
```

A null / non-array `$last_version` now short-circuits to the skip path (under the
default `skipFrozenReq = true`, line 1530) with zero warnings; a real open-version
hit keeps its exact previous semantics.

## Verification (A/B, identical fixture + identical XML)

| variant | result |
|---|---|
| PRE-FIX (guard removed) | HTTP 200 import + **new event row** `E_WARNING Trying to access array offset on null - requirement_mgr.class.php - Line 1624` (events.id=2) |
| POST-FIX (guard present) | HTTP 200, result `Skipped - Requirement - Doc ID:Mantis Task ID:20 - is FROZEN`, **events table unchanged** (0 new ERROR/WARNING) |

![Event Viewer showing the captured PRE-FIX E_WARNING row + post-fix audit](docs/screenshots/issue-1505-events-table-ewarning.png)

`events` table after all verification (post-fix imports added nothing):
`id=1 AUDIT login succeeded` (1789449314), `id=2 WARNING E_WARNING ... Line 1624`
(1789449341 — the deliberately captured PRE-FIX A/B repro).

## Regression Suite

`Regression — Issue #1505` in `tmp/TLU_Test_Cases.md` — 6/6 PASS (skip behavior,
events clean, repeat import, lint + guard presence, A/B pre/post, final Event
Viewer check).

## Files Changed

- `lib/functions/requirement_mgr.class.php` (guard at line 1624 — landed in
  commit `681d39dbc` with `Fixes #1505`)
- `tmp/TLU_Test_Cases.md` (regression suite 1505 appended)
- this doc + `docs/screenshots/issue-1505-events-table-ewarning.png` + wiki mirror

Refs #1505.