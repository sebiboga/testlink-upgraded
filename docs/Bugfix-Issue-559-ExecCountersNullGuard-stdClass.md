# Bugfix — Issue #559: getExecCountersByExecStatus() Always Returns Null (Plans w/o Platforms Show Zero Metrics)

**Date:** 2026-08-26 · **Branch:** `sebiboga` · **Commit:** `d91446a74`

## Summary

`getExecCountersByExecStatus()` in `tlTestPlanMetrics.class.php` always returned `null` due to a type mismatch in the guard condition, causing every test plan to show zero metrics on the Metrics Dashboard.

## Symptom

- On both the modernized Metrics Dashboard (`metricsDashboard.html`) and the legacy Smarty template (`metricsDashboard.php`), every test plan showed Active TCs=0 and Progress=0%, even when it had linked/executed test cases.
- Affected ALL test plans, not just those without platforms.

## Root Cause

In `lib/functions/tlTestPlanMetrics.class.php:1056`, the original guard was:

```php
if(is_null($builds) || is_array($builds) == false || count($builds) <= 0) {
    return null;
}
```

The `$builds` variable is a `stdClass` object built by `helperGetExecCounters()` (line 1837), containing properties `idSet`, `inClause`, `infoSet`, etc. Since `stdClass` is not an array, `is_array($builds)` was **always false**, which short-circuited the entire condition to `true`, causing the method to always return `null` — regardless of whether builds existed.

**Root cause chain:**
1. `helperGetExecCounters()` returns `array($my, $bi, $sql)` where `$bi` is a `stdClass` (line 1953)
2. `helperBuildSQLExecCounters()` destructures as `list($my, $builds, $sqlStm)` — `$builds` = the stdClass (line 1997)
3. `helperBuildSQLExecCounters()` returns `array($my, $builds, $sqlStm, $union, $platformSet)` (line 2094)
4. `getExecCountersByExecStatus()` destructures again — `$builds` is still the stdClass
5. `is_array($builds)` → always `false` → guard always true → method always returns `null`

**Blast radius:**
- All 5 callers of `getExecCountersByExecStatus()` were affected
- Every test plan (with or without platforms) showed zero metrics
- Callers of `helperBuildSQLExecCounters()` directly were unaffected (they access `$builds` properties correctly)

## Fix

Replaced the guard with correct `stdClass` property access matching upstream TestLink:

```php
// issue #559: $builds is the stdClass built by helperGetExecCounters(),
// so is_array() was always false and this method always returned null
if(is_null($builds->idSet) || count($builds->idSet) <= 0) {
    return null;
}
```

**Approach:** Minimal one-line fix (plus comment). No refactoring, no unrelated files changed. This matches the pattern used by `helperGetExecCounters()` itself (line 1859) which also checks `is_null($bi->idSet)`.

## Files Changed

- `lib/functions/tlTestPlanMetrics.class.php` — lines 1054-1056: replaced `is_array($builds)` guard with `$builds->idSet` property check

## Verification

- Created project "Bug559 Test" + test plan "Plan No Platform" (no platforms) + build + TC + execution
- Modernized dashboard: Active TCs=1, Passed=100%, Progress=100%
- Legacy dashboard: Active TCs=1, Passed=100%, Progress=100%
- Event Viewer: 0 new Error/Warning entries
- Screenshot: `docs/screenshots/issue-559-metrics-dashboard-fixed.png`

## Regression Matrix

| Case | Result |
|------|--------|
| Plan without platforms, with builds + executions | ✅ Metrics show correct counts |
| Plan with builds but zero executions | ✅ 0% progress, no crash |
| Plan with zero builds | ✅ Returns null gracefully (issue #634 guard) |
| Event Viewer | ✅ No new errors/warnings |
