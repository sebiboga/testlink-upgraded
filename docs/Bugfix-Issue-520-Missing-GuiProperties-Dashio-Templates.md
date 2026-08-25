# Bugfix — Issue #520: E_WARNING Multiple Missing $gui Properties in Dashio Templates

## Problem

PHP 8.x emits `E_WARNING: Undefined property` on every page load for several
dashio templates that reference `$gui` or `$tlCfg` properties that were never
initialized by their PHP controllers.

## Root Cause

When dashio templates were ported from the classic theme, some property
references were carried over but the corresponding PHP initializations were
missed:

| Property | Template | PHP Controller | Cause |
|---|---|---|---|
| `$gui->doViewReload` | `plan/planView.tpl:204` | `lib/plan/planView.php::initializeGui()` | Never set (set in planEdit.php and projectView.php but not planView.php) |
| `$tlCfg->layout->cellContent` | `plan/planEdit.tpl:31` | Entire codebase | `$tlCfg->layout` was never defined; phantom config reference |
| `$tlCfg->layout->cellLabel` | `plan/planEdit.tpl:32` | Entire codebase | Same |
| `$gui->tprojOpt` | `plan/tc_exec_assignment_flat.tpl:236,288,296` | `lib/plan/tc_exec_assignment.php:222-224` | PHP computed `$tprojOpt` locally but assigned `$gui->testPriorityEnabled` (scalar) instead of `$gui->tprojOpt` (object) |
| `$gui->tplan_id` | `testcases/containerNew.tpl:44` | `lib/functions/testsuite.class.php::viewer_edit_new()` | No safety guard; callers set it but function itself doesn't ensure it exists |

## Fix

Four minimal changes:

1. **`lib/plan/planView.php:187`** — Added `$gui->doViewReload = false;` in `initializeGui()`, matching the pattern from `planEdit.php:460` and `projectView.php:127`.

2. **`gui/templates/dashio/plan/planEdit.tpl:31-32`** — Replaced `$tlCfg->layout->cellContent` and `$tlCfg->layout->cellLabel` with hardcoded Bootstrap grid defaults (`col-sm-9` and `col-sm-3`), matching the `form-horizontal` pattern used throughout dashio.

3. **`lib/plan/tc_exec_assignment.php:224`** — Added `$gui->tprojOpt = is_object($tprojOpt) ? $tprojOpt : new stdClass();` to properly assign the already-computed `$tprojOpt` object to the gui variable that the template expects.

4. **`gui/templates/dashio/testcases/containerNew.tpl:44`** — Added `isset()` guard: `{if isset($gui->tplan_id)}{$gui->tplan_id}{else}0{/if}`, consistent with the existing `property_exists` guard pattern used elsewhere (e.g., `keywords.inc.tpl`).

## Verification

- All 3 modified PHP files pass `php -l` syntax check.
- planView page renders correctly, no console errors.
- planEdit form renders correctly with Bootstrap grid layout, no console errors.
- containerNew form renders correctly, no errors.
- Screenshot: `docs/screenshots/issue-520-planEdit-no-warnings.png`
