# Bugfix — Issue #1257: Legacy resultsGeneral — 4x E_WARNING (null get_by_id) on anonymous invalid-apikey path

## Problem

A crafted anonymous `?apikey=INVALID...&tproject_id=N&tplan_id=M` request to a
legacy report URL (whose apikey matches no entity and whose ids match no DB
row) logs multiple PHP 8 `E_WARNING` rows into the `events` table
(`activity=PHP`, `log_level=2`) from `initializeGui()` in
`lib/results/resultsGeneral.php`:

```
PHP E_WARNING Trying to access array offset on null - resultsGeneral.php Line 255
PHP E_WARNING Trying to access array offset on null - resultsGeneral.php Line 252
PHP E_WARNING Attempt to read property "testPriorityEnabled" on null - resultsGeneral.php Line 251
PHP E_WARNING Trying to access array offset on null - resultsGeneral.php Line 251
```

The HTTP response stays 200 (warnings are logged, not thrown), so the symptom
is only visible in the Event Viewer / `events` table.

**Reproduce at HEAD (measured on the fresh-import CI box):** with a fresh
session (no cookies) call

```
curl ".../lib/results/resultsGeneral.php?apikey=INVALIDKEY…63char…&tplan_id=2&tproject_id=1&sendByMail=1"
```

→ HTTP 200, and four E_WARNING rows (lines 251/251/252/255) appear in `events`
(the issue title counts "5x" because PHP 8 fires two warnings for the single
`$dummy['opt']->testPriorityEnabled` deref; the stored count is 4).

This is the sibling of the already-fixed #1248 (guarded
`displayMgr.php:102-103` / `resultsGeneral.php:270`) and #1249 (guarded
`displayMgr.php:121`); the null `get_by_id` derefs at
`resultsGeneral.php:249-256` are a separate, pre-existing unguarded site (#1249
explicitly deferred it to this issue).

## Root Cause

`initArgsForReports()` (lib/results/displayMgr.php:16) handles the apikey via
two branches:

- **Authenticated branch** (displayMgr.php:67-80): `testlinkInitPage` +
  `get_by_id($args->tplan_id)` with a null-tplan guard that renders
  `error_print_doc_missing_testplan` via `displayInfo()` and exits
  (displayMgr.php:72-78).
- **Anonymous branch** (displayMgr.php:61-66): calls
  `setUpEnvForAnonymousAccess()` (common.php:1311), whose return value
  (`status_ok`) is **discarded**, and the caller-supplied `tproject_id` /
  `tplan_id` are **never validated against the DB**.

`initializeGui()` (resultsGeneral.php:229) then dereferences the two lookups
unconditionally:

- resultsGeneral.php:249 `$dummy = $mgr->get_by_id($argsObj->tproject_id)` →
  returns `null` when the row is missing (testproject.class.php:341-351).
  - :251 `$dummy['opt']->testPriorityEnabled` → 2 WARNINGs (array-offset on
    null + property-read on null).
  - :252 `$dummy['name']` → 1 WARNING.
- resultsGeneral.php:254 `$info = $tplanMgr->get_by_id($argsObj->tplan_id)` →
  `null`; :255 `$info['name']` → 1 WARNING.

**Why it breaks now:** the anonymous apikey path validates only the apikey's
ownership, never the ids in the URL. Fabricated/invalid ids sail through into
`initializeGui()`, where PHP 8 null-deref semantics emit E_WARNINGs that the
events logger persists into `events`.

**Blast radius:** the `initializeGui` at resultsGeneral.php:229 is file-local
(invoked once at resultsGeneral.php:23). The shared `initArgsForReports()` is
used by many results screens, so the fix deliberately stays at the only
deref site to avoid ripple effects.

## Fix

Committed on branch `fix/issue-1257`, commit `ead56d793` (fix):

Defensive guards on the two lookups inside `initializeGui()`:

```php
$mgr = new testproject($dbHandler);
$dummy = $mgr->get_by_id($argsObj->tproject_id);
$gui->testprojectOptions = new stdClass();
$gui->testprojectOptions->testPriorityEnabled =
  !is_null($dummy) && !empty($dummy['opt']) ? $dummy['opt']->testPriorityEnabled : false;
$gui->tproject_name = !is_null($dummy) ? $dummy['name'] : '';

$info = $tplanMgr->get_by_id($argsObj->tplan_id);
$gui->tplan_name = !is_null($info) ? $info['name'] : '';
$gui->tplan_id = intval($argsObj->tplan_id);
```

**Why this approach:** the smallest change that makes every null-deref in the
flagged block null-safe on every path (invalid apikey + fabricated ids, valid
project-level anonymous apikey with `tplan_id=0`, etc.). Invalid input now
falls through to the existing graceful branch
`report_tspec_has_no_tsuites` (resultsGeneral.php:30-34) → HTTP 200 with a
clean "no test cases" report instead of E_WARNING spam. Valid rows behave
byte-identically: `get_by_id()` returns the full row map with `opt`/`name` for
existing test projects/plans, so the guards never trip. No new user-facing
strings, so no locale-bundle additions. Rejected alternatives: (a) adding a
`displayInfo()`+exit guard inside `initArgsForReports()`'s anonymous branch —
larger blast radius (that helper is shared by every results screen), breaks
project-level anonymous apikeys where `tplan_id=0` is legitimate, and is a
behavior change beyond a log-noise bug; (b) validating ids in
`setUpEnvForAnonymousAccess()` — changes shared access semantics used by many
screens.

## Files Changed

- `lib/results/resultsGeneral.php` — `initializeGui()`, lines 249-256:
  `is_null`/`empty` guards with `false`/`''` fallbacks (3 expressions).
- `tmp/fixtures_1257.php` — new regression fixture (project RF1257 id 1,
  plan "RF Plan 1257" id 6, 64-char plan api_key, 32-char admin script_key,
  one suite/TC/build/execution).
- `tmp/TLU_Test_Cases.md` — regression suite "Issue #1257", 7/7 PASS.

## Verification

All checks on branch `fix/issue-1257`, PHP 8.3, MySQL 11.4 `testlink`
fresh-import schema, fixture `php tmp/fixtures_1257.php`:

- Invalid-apikey anonymous + fabricated ids + `sendByMail=1` (pre-fix: 4
  E_WARNINGs at resultsGeneral.php:251/252/255) → HTTP 200, **0** new
  `activity='PHP' log_level=2` rows.
- Invalid apikey + missing `tplan_id` / fabricated `tproject_id` → HTTP 200,
  **0** new PHP-warning rows.
- Valid anonymous 64-char plan key + valid ids → HTTP 200, full "General Test
  Plan Metrics" page with `RF1257` / `RF Plan 1257` names and a Results-by-
  priority section (proves `testPriorityEnabled` still read from a valid row).
- Authenticated admin + valid plan → HTTP 200, names + priority metrics intact;
  no JS console errors.
- Authenticated + bogus `tplan_id` → clean "Document generation error… the
  test plan is missing" info page via the existing displayMgr.php:72-78 guard,
  zero PHP-warning rows.
- `php -l lib/results/resultsGeneral.php` → clean.
- Event Viewer: after the whole suite, `SELECT COUNT(*) FROM events WHERE
  activity='PHP' AND log_level=2` shows **0 new** rows (the pre-fix rows from
  HEAD are wiped by the fresh-import cycle; the #1257 fixture/audit rows are
  `log_level=16`).

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1257", 7/7 PASS.
Screenshot: `docs/screenshots/issue-1257-results-general-report.png` (take it
into the GitHub wiki page).