# Bugfix — Issue #773: exec.inc.php:182 E_WARNING on every execution save (testprojects.options NULL/empty)

**Issue:** [#773](https://github.com/sebiboga/testlink-upgraded/issues/773)
**Branch:** `fix/issue-773-exec-options-warning` · **Commits:** `9469bc3f5` (fix), `1b9eecd23` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-29)

## Symptom

Every `write_execution()` save (Execute Tests → Save Steps WIP / any manual single
execution save, incl. `POST /api/execute/?action=save`) writes one `E_WARNING` event into
the Event Viewer (`events` table) whenever the owning test project has **no options blob**:

```
E_WARNING
Undefined property: stdClass::$requirementsEnabled - in lib/functions/exec.inc.php - Line 182
```

## Root cause chain

1. `api/execute/index.php:874` (and legacy `lib/execute/execSetResults.php`) call
   `write_execution()` → `lib/functions/exec.inc.php:181`:
   `$topt = $tprojectMgr->getOptions($execSign->tproject_id);`
2. `lib/functions/exec.inc.php:182`: `if( $topt->requirementsEnabled ) {` — **unguarded**
   property read.
3. `lib/functions/testproject.class.php:3904 getOptions()` returns `(object)[]` — an
   object with **zero properties** — whenever `testprojects.options` is NULL, an empty
   string, not serialized PHP, or `unserialize()` fails (guards at lines 3911-3924, added
   by the #581/#564 hardening). `requirementsEnabled` only exists when the persisted blob
   actually contains it.
4. Reading a non-existent property on a plain object raises the PHP `E_WARNING`, which the
   framework's handler writes to the `events` table (Event Viewer).

Triggering DB state: `testprojects.options IS NULL` — legacy projects, projects created
through paths that never wrote an options blob, DB restored from an old dump.

**Why only this call site breaks:** every sibling read of `requirementsEnabled` already
guards it (`common.php:361-362` `isset(...) &&`, `resultsNavigator.php:24-25` `isset`,
`printDocOptions.php:159-160` `isset`, `tcEdit.php:409` `!empty(...) && isset(...)`).
`exec.inc.php:182` was the single unguarded read in the executor family.

## Approach — guard the read (minimal fix)

`lib/functions/exec.inc.php:182` now reads:

```php
$topt = $tprojectMgr->getOptions($execSign->tproject_id);
if( isset($topt->requirementsEnabled) && $topt->requirementsEnabled ) {
```

Missing `requirementsEnabled` ⇒ treated as **requirements disabled**, i.e. the req-link
freeze branch is skipped — exactly the legacy semantics for a project whose blob is NULL
(the `option_reqs` column defaults 0 anyway).

**Alternatives rejected:**
- Make `getOptions()` return a fully-defaulted options object: larger blast radius (every
  caller iterates the returned object; `setOptions()` at `testproject.class.php:3929`
  `foreach ($itemOpt as $prop => $value)` would then persist defaults on unrelated
  updates) and does not remove the need for the same guard pattern at the other call sites.

## Files changed

| File | Change |
|---|---|
| `lib/functions/exec.inc.php` | +1: guard `requirementsEnabled` read in `write_execution()` with `isset()` |
| `tmp/TLU_Test_Cases.md` | +Suite 773 (4 test cases, 4/4 PASS) |

## Verification

- `php -l lib/functions/exec.inc.php` → no syntax errors.
- **Primary:** truncated `events`; with `testprojects.options=NULL` ran
  `POST /api/execute/?action=save` → HTTP 200 `saved:true`, `SELECT COUNT(*) FROM events`
  = **0** (pre-fix: exactly the `exec.inc.php Line 182` E_WARNING row).
- **Regression matrix:**
  1. options=NULL → save → 0 events ✅
  2. full blob `requirementsEnabled=0` → save → 0 events, freeze branch skipped ✅
  3. full blob `requirementsEnabled=1` + real `req_coverage` row with `link_status=1`
     (OPEN) linked to the tcversion → save → `req_coverage.link_status` 1→2
     (CLOSED_BY_EXEC) and `req_versions.is_open` 1→0 (freeze works ⇒ branch NOT skipped) ✅
  4. Event Viewer shows no new Error/Warning (only the login AUDIT row) ✅
