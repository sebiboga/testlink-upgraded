# Bugfix: Issue #519 — E_WARNING Undefined array key "testprojectOptions"

## Problem

PHP 8 generates E_WARNING "Undefined array key 'testprojectOptions'" in `printDocOptions.php:156` and `resultsNavigator.php:100`, with approximately 12 warnings across both files.

## Root Cause

`$_SESSION['testprojectOptions']` was a legacy session cache key that was **never populated** anywhere in the codebase. All 7 access sites across 6 files consumed this missing key.

## Fix

Replace all `$_SESSION['testprojectOptions']` reads with live `testproject::getOptions()` DB queries.

## Files Changed

| File | Change |
|------|--------|
| `lib/results/printDocOptions.php` | `$tprojMgr->getOptions($args->tproject_id)` via `$dbHandler` param |
| `lib/results/resultsNavigator.php` | Moved DB query to main scope (init_args has no `$db`) |
| `lib/functions/common.php` | `$tprojMgr->getOptions($_SESSION['testprojectID'])` in `initTopMenu(&$db)` |
| `lib/general/mainPage.php` | `$tprojMgr->getOptions(intval($_SESSION['testprojectID']))` |
| `lib/general/asideMenu.php` | `$tproject_mgr->getOptions($tprojectID)` (reuse existing manager) |
| `lib/testcases/tcEdit.php` | `$tprojOptMgr->getOptions($args->tproject_id)` via `$tcaseMgr->db` |
| `lib/testcases/tcAssignedToUser.php` | Reuse `$args->testprojectOptions` from `get_by_id()` |
