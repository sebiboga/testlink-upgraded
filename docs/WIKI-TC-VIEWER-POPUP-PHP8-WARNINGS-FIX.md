# TC Viewer Popup PHP8 Warnings Fix

## Issue #541

Fixed: opening the **legacy popups reachable from the Test Case Viewer**
wrote E_WARNING / SQL-error clusters into the Event Viewer. All pages still
returned HTTP 200 — the damage was log pollution plus, for `tcPrint.php`,
a broken print output when called with missing/invalid ids.

## Symptom (events #2–#13 on a fresh DB)

1. **Add to Test Plan** popup (`lib/testcases/tcAssign2Tplan.php`, opened by
   the modern viewer button *Add to Test Plan*) with a project that has **no
   active test plans**:

   ```
   E_WARNING Undefined array key "testplanID"      - lib/testcases/tcAssign2Tplan.php - Line 177
   E_WARNING Undefined property: stdClass::$tplans - gui/templates_c/...tcAssign2Tplan.tpl.php - Line 75
   ```

   The page also logged two warnings from the DataTables include
   (`Undefined array key "DataTablesLengthMenu"` + follow-up
   `Attempt to read property "value" on null`).

2. **Print preview** (`lib/testcases/tcPrint.php`) requested without its
   expected parameter set (e.g. `?show_mode=print&tcase_id=30&...` — note the
   script only ever read `testcase_id`):

   ```
   E_WARNING Undefined array key "testprojectName" - lib/testcases/tcPrint.php - Line 70
   E_WARNING Undefined array key "name"            - lib/testcases/tcPrint.php - Lines 92/93
   E_WARNING Undefined array key "id"              - lib/functions/print.inc.php - Lines 930/951
   E_WARNING Trying to access array offset on null - lib/functions/testcase.class.php - Line 2158
   ERROR ON exec_query() 1064 ... SELECT prefix FROM testprojects WHERE id =
   ```

## Root Cause

* PHP 8 turned silent notices into E_WARNINGs; the legacy controllers assumed
  parameters/session keys are always present and templates assumed `$gui`
  properties are always set.
* `tcAssign2Tplan.php`: `$gui->tplans` was only created inside the
  "project has active test plans" branch, but the template evaluates
  `{if $gui->tplans}` unconditionally; `init_args()` dereferenced
  `$_SESSION['testplanID']` / `$_SESSION['testprojectID']` without guards.
* `tcAssign2Tplan.tpl` passed the include parameter as
  `DataTableslengthMenu=$ll` (lower-case `l`) while `DataTables.inc.tpl`
  reads `$DataTablesLengthMenu` — Smarty variable names are case-sensitive.
* `tcPrint.php`: unguarded `$_SESSION['testprojectName']`; ignored the
  documented `tproject_id` request fallback; and when the test case id was
  invalid/missing it kept rendering with a **null node**, cascading into
  `print.inc.php` (`$node['id']`), `testcase.class.php::getPrefix()`
  (`$path2root[0]` on null) and finally
  `testproject::getTestCasePrefix(null)` → SQL syntax error.

## Fix Approach — validate input early, initialize everything the template touches

Considered alternatives:

1. Sprinkle `isset()`/null-coalescing at every downstream read site
   (`print.inc.php` lines 930/951, `getPrefix()`, …). Rejected: treats
   symptoms at many places while the real problem is one invalid entry point
   per popup.
2. **Validate at the controller entry point** (chosen): if the test case does
   not exist, render a minimal localized message instead of continuing with a
   null node; initialize every `$gui` property the template may read; guard
   session lookups in `init_args()`.

### Files changed

* `lib/testcases/tcAssign2Tplan.php`
  * `initializeGui()` now sets `$guiObj->tplans = null;`
  * `$version = null;` before the version-match loop (undefined-variable
    warning when the requested tcversion does not match)
  * `init_args()` uses isset-guards for `$_SESSION['testplanID']`
    / `$_SESSION['testprojectID']` (falls back to `0`)
* `gui/templates/dashio/testcases/tcAssign2Tplan.tpl`
  * include param typo fixed: `DataTableslengthMenu=` → `DataTablesLengthMenu=`
* `lib/testcases/tcPrint.php`
  * `init_args()`: guarded `$_SESSION['testprojectName']`; falls back to the
    documented `tproject_id` request param when the session has no project
  * validates `testcase_id` before use: unknown/missing test case → renders
    header + localized `testcase_does_not_exists` ("Test Case does not
    exist", existing i18n key reused; `lang_get()` falls back to en_GB for
    locales that lack it) and stops — the whole
    `renderTestCaseForPrinting()/getPrefix()/getTestCasePrefix(null)` cascade
    can no longer run

No new locale keys were needed (existing key reused), so no bundle changes.

## Verification

| # | Check | Result |
|---|-------|--------|
| 1 | Pre-fix repro of both popups reproduced exactly the reported event clusters | reproduced |
| 2 | Post-fix: tcAssign2Tplan with no test plans → clean "no test plans" state, title "Test Case:TC 541", zero events | PASS |
| 3 | Post-fix: tcPrint with bad params → HTTP 200 "Test Case does not exist", zero events | PASS |
| 4 | Positive path: plan TP 541 + platform P1 → checkbox row `add2tplanid[50][60]`, Add button, identity I541-100:TC 541 rendered | PASS |
| 5 | Positive path: tcPrint with correct `testcase_id=30&tcversion_id=40` → full printable TC page | PASS |
| 6 | Real flow via browser: modern TC viewer → *Add to Test Plan* popup renders assignment form (screenshot below) | PASS |
| 7 | Event Viewer after all flows: only audit-level login entries | PASS |



## Regression suite

`tmp/TLU_Test_Cases.md`, Suite 36 — Regression — Issue #541 (Suite ID: 43).
