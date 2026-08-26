# Issue 582 — resultsGeneral.php: foreach on null + tprojOpt mismatch (E_WARNINGs)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/582
Fixed by: https://github.com/sebiboga/testlink-upgraded/issues/633 (commit `73d9c7c97`)

## Symptom

Viewing **General Test Plan Metrics** for a test plan **without platforms**
generated 3 E_WARNINGs per page load:

1. `foreach() argument must be of type array|object, null given` at
   `lib/results/resultsGeneral.php:63`
2. `Undefined property: stdClass::$tprojOpt` + `Attempt to read property
   "testPriorityEnabled" on null` from the compiled Dashio template
   (`gui/templates/dashio/results/resultsGeneral.tpl`, lines 179 and 247)

## Root Cause

Two independent bugs:

1. **Uninitialized `$items2loop`** — `$items2loop[] = 'platform'` was only
   assigned inside `if($gui->showPlatforms)`, but `foreach($items2loop)` at
   line 63 ran unconditionally. When `showPlatforms=false`, `$items2loop` was
   undefined → PHP 8 E_WARNING.

2. **Template property name mismatch** — `initializeGui()` assigns
   `$gui->testprojectOptions` (`lib/results/resultsGeneral.php:250-251`), but
   the Dashio template read `$gui->tprojOpt` — a property that was never set.
   The tl-classic template used the correct name.

## Fix (applied in commit 73d9c7c97, ref #633)

- `lib/results/resultsGeneral.php:50`: added `$items2loop = array();`
  initialization before the `showPlatforms` block
- `gui/templates/dashio/results/resultsGeneral.tpl:179,247`: changed
  `$gui->tprojOpt` → `$gui->testprojectOptions`

## Verification

- Load General Test Plan Metrics on plan without platforms → page loads cleanly
- Zero `resultsGeneral.php` E_WARNINGs in Event Viewer
- No console errors
- Regression test cases in `tmp/TLU_Test_Cases.md` (TC-582-01 through TC-582-03)
