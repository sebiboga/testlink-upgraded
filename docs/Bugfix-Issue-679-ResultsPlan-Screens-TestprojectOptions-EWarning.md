# Issue 679 — E_WARNING "Undefined array key testprojectOptions" on results/plan screens

**Issue:** [#679](https://github.com/sebiboga/testlink-upgraded/issues/679)
**Branch:** `fix/issue-679`
**Status:** VERIFIED-FIXED (2026-08-25)

## Symptom

Loading **Free Test Cases** (`lib/results/freeTestCases.php`) produced 2 PHP warnings per request
into the Event Viewer (`events` table, `log_level=2`):

```
E_WARNING Undefined array key "testprojectOptions" - in .../lib/results/freeTestCases.php - Line 25
E_WARNING Attempt to read property "testPriorityEnabled" on null - in .../lib/results/freeTestCases.php - Line 25
```

The same unguarded session key read existed in two more screens (confirmed by static analysis):
- `lib/results/tcNotRunAnyPlatform.php:116,216,228`
- `lib/plan/tc_exec_assignment.php:222`
- `gui/templates/dashio/plan/tc_exec_assignment.tpl:175,221,229`

## Root cause

`$_SESSION['testprojectOptions']` is **never written anywhere in this codebase**. Every unguarded
read throws `Undefined array key` followed by `Attempt to read property on null`. The priority-gated
UI blocks can never activate because the condition can never be true.

This is the same root cause as [#495](https://github.com/sebiboga/testlink-upgraded/issues/495) (the
`mainPage.php` variant), which was fixed by switching to `testproject::getOptions($tproject_id)`.

## Fix

Replaced all unguarded `$_SESSION['testprojectOptions']->testPriorityEnabled` reads with live DB
lookups via `testproject::getOptions($tproject_id)->testPriorityEnabled`, guarded by `isset()`:

1. `lib/results/freeTestCases.php:25` — `$tproject_mgr->getOptions($args->tproject_id)` (mgr already existed)
2. `lib/results/tcNotRunAnyPlatform.php:116` — same pattern in global scope
3. `lib/results/tcNotRunAnyPlatform.php buildMatrix()` — added `$tproject_id` parameter, creates its own `testproject($db)` instance
4. `lib/plan/tc_exec_assignment.php:222` — new `testproject($db)` instance; stores result in `$gui->testPriorityEnabled`
5. `gui/templates/dashio/plan/tc_exec_assignment.tpl:175,221,229` — changed `$session['testprojectOptions']->testPriorityEnabled` to `$gui->testPriorityEnabled`

Remaining `$_SESSION['testprojectOptions']` reads in `printDocOptions.php:156` and
`resultsNavigator.php:100` are already guarded with `isset()` and never warn — left as-is.

## Files changed

- `lib/results/freeTestCases.php`
- `lib/results/tcNotRunAnyPlatform.php`
- `lib/plan/tc_exec_assignment.php`
- `gui/templates/dashio/plan/tc_exec_assignment.tpl`

## Verification

- PHP syntax check: all files pass `php -l`
- `freeTestCases.php` page load: 0 new E_WARNING events
- `tcNotRunAnyPlatform.php` page load: 0 new E_WARNING events
- `tc_exec_assignment.php` page load: 0 new E_WARNING events
- `SELECT description FROM events WHERE description LIKE '%testprojectOptions%'` → 0 rows

Regression suite: `tmp/TLU_Test_Cases.md` → **Suite 679 — 4/4 PASS**.
