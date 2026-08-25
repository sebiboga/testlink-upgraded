# Bugfix Issue #517 — buildEdit.php E_WARNING on get_by_id() False Returns

**Issue:** [#517](https://github.com/sebiboga/testlink-upgraded/issues/517)  
**Branch:** `fix/issue-517`  
**Commit:** `e1470e507`  
**Date:** 2026-08-25

## Problem

Under PHP 8.x, `buildEdit.php` generated multiple `E_WARNING` ("Trying to access array offset on false") errors when `build::get_by_id()` returned `false` for a non-existent or invalid build ID. This affected three code paths:

1. **`edit()`** (line 224): 10+ array key accesses on `$binfo` after `get_by_id()` returned false
2. **`doDelete()`** (line 293): `$build['name']` accessed on false result
3. **`doUpdate()`** (line 565): `$oldObjData['name']` accessed on false result

Two additional warnings from the original report (trim(null) at line 642 and undefined `$col2hide`) were already fixed in prior commits.

## Root Cause

`build::get_by_id()` uses `fetch_array()` which returns `false` when no row matches the given ID. The callers in `edit()`, `doDelete()`, and `doUpdate()` accessed array keys on this boolean result without checking whether the lookup succeeded.

## Fix

Added `if (!$result)` early-return guards after each `get_by_id()` call:

- **`edit()`**: If `$binfo` is false, returns an `$op` with `status_ok = 0` and `user_feedback = lang_get("cannot_update_build")`
- **`doDelete()`**: If `$build` is false, returns an `$op` with `user_feedback = lang_get("cannot_delete_build")`
- **`doUpdate()`**: If `$oldObjData` is false, returns an `$op` with `user_feedback = lang_get("cannot_update_build")`

All three guards use existing i18n keys — no new locale strings needed.

## Files Changed

| File | Change |
|------|--------|
| `lib/plan/buildEdit.php` | +20 lines, 3 early-return false guards |

## Testing

- PHP syntax check: no errors
- Browser: edit with invalid build_id=99999 → "Error while updating build!" displayed, no warnings
- Browser: edit with valid build_id=1 → form loads correctly
- Browser: create build without release date → no trim(null) deprecation warning
- Console: zero JS errors on all tested pages
