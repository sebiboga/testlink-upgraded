# Bugfix — Issue #1558: PHP 8 E_WARNING in helperConcatTCasePrefix() when test plan id does not exist

## Problem

Every Dashboard load for a non-existent / stale test plan id wrote **two**
`E_WARNING` rows to the `events` table:

```
E_WARNING
Trying to access array offset on null - in .../lib/functions/testplan.class.php - Line 5877
source: PHP
```

HTTP stays 200 — the symptom only shows up in the Event Viewer / `events`
table (log_level 2, activity=PHP).

**Reproduce at HEAD** (measured on the fresh-import CI box, zero
`nodes_hierarchy` / `testplans` / `testprojects` rows): with an authenticated
session, one browser load of `index.php?tproject_id=1&tplan_id=2` — the
Dashboard main frame (`gui/templates/mainpage/mainPage.html`) fires
`GET /api/mainpage/` and `GET /api/mainpage/bugsTested`, and each request
appends one E_WARNING row:

- `curl -b <cookies> "http://localhost:8082/api/mainpage/index.php?tproject_id=1&tplan_id=2"` → HTTP 200, 1 new row
- `curl -b <cookies> "http://localhost:8082/api/mainpage/index.php/bugsTested?tproject_id=1&tplan_id=2"` → HTTP 200, 1 new row

## Root Cause

- `lib/functions/tree.class.php:220` `get_node_hierarchy_info()` returns
  `$result = !is_null($rs) ? $rs[0] : null;` — a node id with zero rows yields
  **`null`**, not an empty array.
- `lib/functions/testplan.class.php:5875-5877` `helperConcatTCasePrefix($id)`
  does `$io = $this->tree_manager->get_node_hierarchy_info($id);` then
  `list($prefix,$garbage) = $this->tcase_mgr->getPrefix(null,$io['parent_id']);`
  — dereferencing `['parent_id']` on `null` throws the PHP 8 warning.
- `lib/functions/logger.class.php:1435-1460` `watchPHPErrors()` persists every
  E_WARNING into `events` (`log_level=2`).

**Trigger path:** `mainPage.html` → `getBugsTestedData()`
(`api/mainpage/index.php:316`) → `getAllExecutionsWithBugs()`
(`testplan.class.php:7575`) → `getLinkedTCVersionsSQL()`
(`testplan.class.php:7066`, callsite 7184) → `helperConcatTCasePrefix()`.

**Blast radius:** single shared choke point — all 8 `helperConcatTCasePrefix`
callsites (`tlTestPlanMetrics.class.php:2293,2414,2557,3065,3220,3286`;
`testplan.class.php:6003,7184`) are covered by the one guard. This is the
testplan-side twin of the fixed #1557
(`testproject::isIssueTrackerEnabled()`).

## Fix

Committed on branch `fix/issue-1558-helperconcat-null-guard`, commit
`0f6d0550c`:

```php
// Get test case prefix
$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
$io = $this->tree_manager->get_node_hierarchy_info($id);

// A node id that does not exist (stale/deep-link request) makes
// get_node_hierarchy_info() return null; without a reset guard the
// $io['parent_id'] dereference raises a PHP 8 E_WARNING that lands in
// the events table (api/mainpage dashboard passes stale tplan ids).
// Bailing out with a null prefix yields the same empty-prefix concat the
// code emits for a project with no prefix, and the SQL stays valid.
// Refs #1558
$prefix = null;
if( !is_null($io) && isset($io['parent_id']) )
{
  list($prefix,$garbage) = $this->tcase_mgr->getPrefix(null,$io['parent_id']);
}
$prefix .= $this->tcaseCfg->glue_character;
$concat = $this->db->db->concat("'{$prefix}'",'TCV.tc_external_id');
```

Guard semantics: `$prefix` is initialised to `null`; `getPrefix()` is only
called when the node exists. On the skip path `$prefix .= $glue_character`
produces the same glue-only prefix string the code already emits for a
project with no prefix configured, and ADOdb `Concat()` always returns a
string (`implode($concat_operator, ...)`), so the caller's SQL
(`$fullEID AS full_external_id`) stays valid in all cases. Valid ids resolve
their prefix exactly as before (`getPrefix(null,$io['parent_id'])` unchanged).

**Rejected alternatives:** (a) returning a bare `null` / empty-string `$concat`
— would break the SQL-string callers on the stale-id path; (b) throwing a
user-visible "unknown plan" dialog — out of scope, the stale-id request is
log-noise only; (c) changing `get_node_hierarchy_info()` / `get_recordset()`
to return `[]` — a database-layer contract change with a far wider blast
radius. The fix is minimal and mirrors the already-shipped #1011 `getPrefix()`
guard and the #1557 `isIssueTrackerEnabled()` guard.

## Files Changed

- `lib/functions/testplan.class.php` — `helperConcatTCasePrefix()`, lines
  5871-5887: null-safe guard around the `getPrefix` call.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1558",
  4/4 PASS.

No user-facing strings touched → no i18n bundle changes (i18n not applicable).

## Verification

All checks on branch `fix/issue-1558-helperconcat-null-guard`, PHP 8.3,
MySQL `testlink` fresh-import schema.

- **R1/R2** — stale id 2 (zero-row DB), `DELETE FROM events` first:
  `GET /api/mainpage/index.php?tproject_id=1&tplan_id=2` and
  `GET /api/mainpage/index.php/bugsTested?...` → HTTP 200 each; events count
  **0** (pre-fix: 1 E_WARNING per call — liverepro events ids 3..8).
- **R3** — full browser page load `index.php?tproject_id=1&tplan_id=2`
  (navbar + aside + Dashboard main frame: GET /, /bugsTested, /assigned,
  /notifications/count) → HTTP 200 frameset, Dashboard renders its empty
  state, events **0** (pre-fix: 2 E_WARNING rows per load).
- **R4** — valid-plan smoke: seeded `nodes_hierarchy` (project id 1, plan
  id 2) + `testprojects` prefix `PROJ` + `testplans`; CLI harness with
  `error_reporting(E_ALL)` and endpoint curls → valid id yields
  `CONCAT('PROJ-',TCV.tc_external_id)`, stale id 999 yields
  `CONCAT('-',TCV.tc_external_id)` with **zero** PHP warnings; endpoints
  HTTP 200, events 0. Fixture removed afterwards (DB back to fresh).
- **R5** — Event hygiene: after the whole matrix `events` = 0 Error/Warning
  rows; Event Viewer screen shows all levels 0 (`issue-1558-eviewer-clean.png`);
  `php -l lib/functions/testplan.class.php` → `No syntax errors detected`.
- Code review (subagent) over commit `0f6d0550c`: **APPROVE** — `$concat`
  remains a valid SQL expression in all three paths (valid id /
  non-existent id / null parent_id), no new warnings introduced.