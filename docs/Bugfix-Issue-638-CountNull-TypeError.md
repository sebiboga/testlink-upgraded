# Issue #638 — count(null) TypeError in resultsTCAbsoluteLatest.php when test plan has no builds

**Issue:** [#638](https://github.com/sebiboga/testlink-upgraded/issues/638)
**Commit:** (pending)
**Status:** VERIFIED-FIXED (2026-08-26)

## Root Cause

`lib/results/resultsTCAbsoluteLatest.php:313` called `count($guiObj->buildInfoSet)` directly.
`testplan::get_builds()` returns `null` when no builds exist for the plan.
PHP 8.0+ treats `count(null)` as a fatal `TypeError`.

This is the identical bug as issue #634, which fixed the same pattern in
`resultsTC.php` and `resultsTCFlat.php` but missed this file.

## Fix

Applied the same ternary null guard already present in the sibling files:

```php
// Before (line 313):
$guiObj->activeBuildsQty = count($guiObj->buildInfoSet);

// After:
// issue #634: a plan without builds returns null here =>
// count(null) is a TypeError on PHP 8
$guiObj->activeBuildsQty = is_null($guiObj->buildInfoSet) ?
                           0 : count($guiObj->buildInfoSet);
```

## Files Changed

- `lib/results/resultsTCAbsoluteLatest.php` — line 313: added null guard

## Verification

- `php -l` — no syntax errors
- HTTP 200 on test plan with zero builds (was HTTP 500 before fix)
- Page renders correctly: heading + platform selector form
- No new errors in server log or Event Viewer
- Sibling pages (`resultsTC.php`, `resultsTCFlat.php`) return HTTP 200 (regression check passed)
