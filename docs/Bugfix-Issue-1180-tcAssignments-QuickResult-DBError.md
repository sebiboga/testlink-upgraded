# Issue 1180 — tcAssignments quick result (P/F/B) always fails with DB error

**Issue:** [#1180](https://github.com/sebiboga/testlink-upgraded/issues/1180)
**Branch:** `fix/issue-1180`
**Status:** VERIFIED-FIXED (2026-09-07)

## Symptom

On the modernized screen `gui/templates/execute/tcAssignments.html`, clicking any of the
**Quick result** buttons (`passed` / `failed` / `blocked`) shows the error toast and
**nothing is saved**. The BFF answers HTTP 200 but the body is the `DB Access Error`
debug page, and a new Event Viewer row is logged:

```
ERROR ON exec_query() - database.class.php
1054 - Unknown column 'testplan_id' in 'WHERE' - SELECT id FROM builds
```

Measured on a fresh DB + fixture `tmp/fixtures_660.php` (project 1 LOC660, plan 16, build 1):
the POST created **no** `executions` row (count stayed 1).

## Repro steps

1. Create a project/plan with an execution assignment on an open build
   (fixture `tmp/fixtures_660.php`; row L660-1 / BUILD-OPEN).
2. Log in as admin; open `gui/templates/execute/tcAssignments.html?tproject_id=1`.
3. Click the green check (quick passed) on any row with a build assigned.
4. Toast "Error", Event Viewer logs `1054 Unknown column 'testplan_id'`, `executions` unchanged.

**Expected:** a new execution row with the chosen status (legacy quick & dirty exec works).

**Actual (pre-fix):** `POST /api/tcassignments/quick_result` → HTTP 200, body = `DB Access
Error backtrace` (`database.class.php(780) exec_query` ←
`api/tcassignments/index.php(400) get_recordset`); no INSERT.

## Root cause

1. The front-end (`tcAssignments.html:372-416`) posts every quick result to
   `POST /api/tcassignments/quick_result` with `{tproject_id, tplan_id, platform_id,
   build_id, tcversion_id, result}`.
2. The BFF validates three things before inserting: the `testplan_tcversions` link, the
   **build**, and the tcversion.
3. The build check ran
   `SELECT id FROM builds WHERE id = {build_id} AND testplan_id = {tplan_id}`
   (`api/tcassignments/index.php:400-402`).
4. Since **#503/#834** the `builds` table is **testproject-scoped** — there is no
   `testplan_id` column (verified `DESCRIBE builds` → `testproject_id`). A plan's builds
   resolve through `testplan::get_builds()` → `getProjectIdOfPlan()` →
   `WHERE testproject_id = <project>` (`lib/functions/testplan.class.php:2274+`).
   The unknown column aborts the request before the INSERT at line 421.

Why it breaks now: the quick-result route was written against the pre-#834 build/tplan
scoping. Sibling BFFs already account for project-scoped builds
(`api/execsetresults/index.php:186`, `api/execute/index.php:529`); the tcAssignments
build check was the last remaining straggler.

Blast radius: a single query in `api/tcassignments/index.php`. Grep over `api/**` and
`gui/**` shows no other active call site of `builds.testplan_id`. The legacy screen
`lib/testcases/tcAssignedToUser.php:42-56` inserts the execution with **no build
validation at all**, so nothing else shares this path.

## Fix

Validate the build against its real scope — the test project owning the already-validated
plan↔tcversion↔platform link:

```php
// build must belong to this test project (builds are project-scoped
// since #503/#834; a plan's builds resolve via builds.testproject_id)
$bTables = tlObjectWithDB::getDBTables(['builds']);
$brow = $db->get_recordset(
    "SELECT id FROM {$bTables['builds']} " .
    " WHERE id = {$build_id} AND testproject_id = {$tproject_id}");
if (!$brow) {
    http_response_code(400);
    out(['status' => 'error',
         'message' => 'Build does not belong to this test project']);
}
```

### Why this method

Minimal change that keeps the BFF's input-validation intent: the tcversion↔plan↔platform
link is still verified via `testplan_tcversions`, so a project-scoped build check is enough
to guarantee the execution is written inside the caller's project. The alternative
(reverting to legacy's completely unchecked INSERT) was rejected — the modern BFF
explicitly documents that it validates every id (`index.php` quick_result comment).

## Files changed

- `api/tcassignments/index.php` — lines ~398-408: build predicate `testplan_id = {tplan_id}`
  → `testproject_id = {tproject_id}`; error message text aligned
  ('does not belong to this test project').

## Verification

Regression suite **1180.1–1180.7** (7 cases, 7/7 PASS) executed against a fresh DB +
`tmp/fixtures_660.php` (plus a cross-project build `BUILD-X` id=30 under project 50 to
exercise the rejection):

| Case | Result |
|------|--------|
| PRIMARY — quick result succeeds; `executions` gains status='p' row (plan 16, build 1, tcversion 5, tester 1) | PASS |
| P→F→P full click cycle in the browser (toast + reload + chip flip) | PASS |
| Cross-project build (build 30 on plan of project 1) rejected — no insert, no new events | PASS |
| Non-linked tcversion 14 rejected ('Version not linked to this test plan/platform') | PASS |
| Rights gate intact — tester1 without `testplan_execute` gets 'Insufficient rights', no insert | PASS |
| Event Viewer clean — zero new Error/Warning from the fixed flow (only the pre-fix event id 7 remains) | PASS |
| Browser console — no JS errors during the click cycle | PASS |

The primary browser interaction: `tcAssignments.html?tproject_id=1` → click quick
passed/failed on L660-1 → success toast + status chip updates and the grid re-renders;
`executions` rows verified via SQL.

Screenshots: `docs/screenshots/issue-1180-tcassignments-quickresult-normal.png` (screen
loaded, pre-click), `docs/screenshots/issue-1180-tcassignments-quickresult-passed.png`
(status chip "Passed" after a quick-result click).

Commits on `fix/issue-1180`: `0c97bca0e` (fix), `fb0e8e6a4` (regression suite
`tmp/TLU_Test_Cases.md`), docs.
Refs #1180.