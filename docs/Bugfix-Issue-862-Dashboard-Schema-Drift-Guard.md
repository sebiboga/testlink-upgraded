# Bugfix Issue #862 — "Failed to load dashboard." (schema-drift guard, Refs #841)

## Problem
The dashboard (modernized screen) showed the warn box **"Failed to load dashboard."**
on a reportback. The BFF `GET /api/mainpage/index.php?tproject_id=1&tplan_id=12`
returned HTTP 200 but the body was **raw HTML** (`DB Access Error -
debug_print_backtrace() OUTPUT START`), not JSON.

## Root cause (file:line chain, reproduced with a renamed column)
1. `api/mainpage/index.php:94` `getDashboardData()` -> `:148`
   `$tplanMgr->getNumberOfBuilds($tplanID)`.
2. `lib/functions/testplan.class.php:2429-2431` builds
   `SELECT count(id) ... FROM builds WHERE builds.testproject_id = <projectOfPlan>`.
3. `builds.testproject_id` is a recent additive migration (`14b6f673d`, Refs #826
   "scope builds to test project"). A 1.9.20-era database has only
   `builds.testplan_id`, so the SQL fails: `Unknown column 'builds.testproject_id'`.
4. `lib/functions/database.class.php:190-214` `exec_query()` **echoes an HTML
   "DB Access Error" trace and calls `die()`** on any SQL failure. The process
   dies mid-request.
5. `mainPage.html` `loadData()` requests with jQuery `dataType:'json'`; the HTML
   body fails to parse -> `error:` callback -> `$t('dash.errLoad')`
   ("Failed to load dashboard.") — `gui/templates/mainpage/mainPage.html:166-170`.

This is the identical mechanism as the earlier #841 (same collapses on the same
SQL). #841 hotfixed by flipping the query to `builds.testplan_id` (commit
`eb4df8315`); the migration `14b6f673d` + reader rewrites (`6b50b996c`) moved the
code back to `testproject_id`. Correct on a migrated DB, fatal on any instance
that has not run the migration — the reporter's instance.

## Fix (commit `ec457b9c6`) — graceful degradation in the BFF
In `api/mainpage/index.php`:
- New helper `bffBuildsSchemaOk($db)` probes
  `INFORMATION_SCHEMA.COLUMNS` for `{DB_TABLE_PREFIX}builds.testproject_id`.
  The probe query itself references no migrated column, so it can never fail and
  cannot `die()`.
- The `GET /` route runs `getDashboardData()` only when the probe returns true;
  otherwise the execution-status widget is skipped (`dashboard: null` in the
  payload) and the rest of the dashboard still renders.

Rationale: `die()` inside `exec_query()` cannot be caught with try/catch, so the
BFF must prevent the failing query from running. The probe mirrors the existing
graceful-degradation pattern of the ITS widgets (a missing/down tracker never
kills the whole payload).

### Why not other approaches
- Change `getNumberOfBuilds()` back to `builds.testplan_id` (as #841 did):
  rejected — project-scoping is the deliberate direction (Refs #826/#503/#834);
  reverting shifts semantics for 6 call sites (reports, mainPage, frmWorkArea,
  xmlrpc, resultsByTesterPerBuild).
- Make `exec_query()` throw instead of `die()`: rejected — global legacy
  behaviour change, huge blast radius.

## Verification (CI: MariaDB 127.0.0.1:3306, TestLink @ http://localhost:8082)
1. Restored schema: full render — "Test Plan: DASH Plan · Completed: 100.0%
   (3/3)" (1 passed / 1 failed / 1 blocked), bugs + growth + Project open issues
   widgets render, all API calls 200 JSON, no warn box.
2. Drifted schema (column renamed to `testproject_id_missing`): HTTP 200 with
   valid JSON `{"status":"ok","dashboard":null,...}`, header populates, the
   execution-status widget degrades to "No records found / Total 0", the other
   widgets still render, no "Failed to load dashboard." warn box.
3. Async `/bugsTested` route still returns 200 valid JSON on both schemas.
4. Event Viewer: zero new Error/Warning entries (also fixed a malformed ITS
   `cfg` in the repro fixture that emitted E_WARNINGs from `simplexml_load_string`).