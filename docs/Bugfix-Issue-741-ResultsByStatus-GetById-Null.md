# Bugfix Issue #741 — resultsByStatus.php: E_WARNING get_by_id(NULL)

## Summary

Accessing the Blocked/Failed/Not Run Test Cases report via the legacy PHP controller (`lib/results/resultsByStatus.php`) without `tproject_id`/`tplan_id` parameters in the request triggered an `E_WARNING: testproject->get_by_id(NULL)`.

## Root Cause

In `resultsByStatus.php:initializeGui()`, the function calls `initUserEnv()` which resolves `tproject_id` and `tplan_id` to valid values (from `$_REQUEST` or by falling back to the first available project/plan). However, `initUserEnv()` creates a **local** `$args` object and does not modify the caller's `$argsObj` by reference. The function then continues using the raw `$argsObj` — which still has NULL values — to call `get_by_id(NULL)`, producing the E_WARNING.

The bug affected all three status report variants (Failed, Blocked, Not Run) since they share `initializeGui()`.

## Fix

After `initUserEnv()` returns at line 378, the resolved `tproject_id`/`tplan_id` values from the local `$add2args` are synced back into the caller's `$argsObj`:

```php
list($add2args,$guiObj) = initUserEnv($dbh,$argsObj);
$argsObj->tproject_id = $add2args->tproject_id;
$argsObj->tplan_id = $add2args->tplan_id;
```

## Files Changed

- `lib/results/resultsByStatus.php` — 5 lines added (sync block + comment)

## Testing

1. Legacy URL access without params (`resultsByStatus.php?type=b`): 0 occurrences of `get_by_id(NULL)`
2. XLS export via BFF API: works correctly with proper params
3. Modernized screen (`resultsByStatus.html`): loads correctly
4. Event Viewer: no new Error/Warning entries introduced

## Related Issues

- Issue #740 (same root cause for Failed Test Cases)
