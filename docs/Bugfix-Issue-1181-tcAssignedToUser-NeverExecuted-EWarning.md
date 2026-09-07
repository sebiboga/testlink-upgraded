# Issue 1181 — tcAssignedToUser legacy: E_WARNING on a row with no prior execution

**Issue:** [#1181](https://github.com/sebiboga/testlink-upgraded/issues/1181)
**Branch:** `fix/issue-1181`
**Status:** VERIFIED-FIXED (2026-09-07)

## Symptom

On the legacy screen `lib/testcases/tcAssignedToUser.php` (Test Case Assignment Overview /
Assigned to User / Assigned to Me), any assignment row whose test case has **no prior
execution** raises two PHP 8 E_WARNINGs per render, logged to the Event Viewer:

```
E_WARNING Trying to access array offset on null - in lib/testcases/tcAssignedToUser.php - Line 150
E_WARNING Trying to access array offset on null - in lib/testcases/tcAssignedToUser.php - Line 159
```

Measured on a fresh DB + fixture: 2 never-executed rows → events ids 9-12 (2× line 150,
2× line 159). The cells themselves render correctly (Status = "Not Run", Tester blank) —
only the warnings are spurious.

## Repro steps

1. Create a project/plan with a test case that has an assignment but **no execution**
   (fixture `tmp/fixtures_660.php` achieves this: TC-beta @ platform PLAT-X, TC-gamma on a
   closed build — neither ever executed).
2. Log in as admin; open:
   `http://localhost:8082/lib/testcases/tcAssignedToUser.php?tproject_id=1&show_all_users=1`.
3. Event Viewer (`events` table) shows 2 new E_WARNING rows per never-executed row
   (`log_level=2`).

**Expected:** the row renders (as it does) and no E_WARNING is raised.

**Actual (pre-fix):** 4 E_WARNING rows for two never-executed assignments.

## Root cause

1. `tcAssignedToUser.php:146-149` calls `get_last_execution($tcase_id, $tcversion_id,
   $tplan_id, build, platform, ['getSteps' => 0])` — the options do **not** enable
   `getNoExecutions`.
2. `testcase::get_last_execution()` (`lib/functions/testcase.class.php:4129`) defaults
   `'getNoExecutions' => 0`.
3. `testcase.class.php:4207-4217`: with `getNoExecutions=0` the executions join is an
   **INNER JOIN** — a tcversion with zero executions is dropped from the result set
   entirely.
4. `tcAssignedToUser.php:150` `$lexec[$tcversion_id]['status']` and `:159`
   `$lexec[$tcversion_id]['tester_login']` then dereference a null array offset → PHP 8
   E_WARNING.

Why it breaks now: PHP 8 raises a warning for null-offset access where PHP 5.x silently
returned null. The rendering code already guarded the display (`if(!$status)` → `not_run`,
blank tester), so behavior was always correct — only the warning was wrong.

Blast radius: only these two lines in `tcAssignedToUser.php`. The other callers of
`get_last_execution()` (`execSetResults.php:415/1812/1894`, `requirements.inc.php:731`)
either enable `getNoExecutions` or read the result defensively.

## Fix

Read the per-version entry once with a null-safe map access and default the fields:

```php
$lastExec = $lexec[$tcversion_id] ?? array();
$status   = $lastExec['status'] ?? '';
if (!$status) {
    $status = $statusGui->status_code['not_run'];
}
$current_row[] = $statusGui->definition[$status];

if ($args->show_user_column) {
    $current_row[] = htmlspecialchars($lastExec['tester_login'] ?? '');
}
```

### Why this method

Minimal and behavior-neutral: one `?? array()` guard removes the null dereference for both
reads; `''` is falsy so the existing `not_run` fallback is preserved; `?? ''` also avoids a
PHP 8.1 deprecation that `htmlspecialchars(null)` would emit. Alternative (adding
`getNoExecutions => 1` to the options) was rejected because it changes the SELECT shape and
adds work for an issue that only needs a guard.

## Files changed

- `lib/testcases/tcAssignedToUser.php` — lines 146-160: null-safe last-execution reads.

## Verification

Regression suite **1181.1–1181.7** (7 cases, 7/7 PASS) executed in the browser against
`tmp/fixtures_660.php` on a fresh empty install (project 1 LOC660, plan 16, closed + open
builds, platform PLAT-X; TC-alpha executed / TC-beta + TC-gamma never executed):

| Case | Result |
|------|--------|
| PRIMARY — no E_WARNING on never-executed rows (events clean) | PASS |
| Never-executed row cells: "Not Run" + blank tester (beta @ PLAT-X, gamma @ closed build) | PASS |
| Executed row unaffected (alpha: "Passed" / admin) | PASS |
| Assigned-to-User mode (`user_id=2&build_id=1`) | PASS |
| Assigned-to-Me mode (`?tproject_id=1`) | PASS |
| "Show also closed builds" toggle path | PASS |
| Browser console — no JS Error (only pre-existing deprecation + favicon 404) | PASS |

Screenshots: `docs/screenshots/issue-1181-tcassigned-nulloff-before.png` (pre-fix render),
`docs/screenshots/issue-1181-tcassigned-nulloff-after.png` (post-fix; visually identical —
the fix removed only the hidden warnings).

Commits on `fix/issue-1181`: `825064d15` (fix), `1055dd0ff` (regression suite + screenshots).
Refs #1181.