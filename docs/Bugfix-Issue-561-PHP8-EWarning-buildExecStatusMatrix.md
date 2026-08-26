# Bugfix — Issue #561: PHP8 E_WARNINGs in buildExecStatusMatrix on Metrics Dashboard

## Problem

Loading the Metrics Dashboard (legacy `metricsDashboard.php` or modernized
`gui/templates/results/metricsDashboard.html`) triggered multiple PHP 8
`E_WARNING` entries in the Event Viewer:

```
E_WARNING: Trying to access array offset on null
lib/functions/tlTestPlanMetrics.class.php lines 1637 / 1639 / 1641
```

## Root Cause

`get_full_path_verbose($keySet, array('output_format' => 'stairway2heaven'))`
returns `NULL` when `$keySet` is empty or paths are unresolvable (e.g. test
suites without resolvable hierarchy). The NULL result was dereferenced directly
to access `$dx['flat']` and `$dx['staircase']`.

Additionally, `$keySet` was never initialized before the for-loop, causing an
`E_WARNING: Undefined variable $keySet` when `get_testsuites()` returned an
empty array.

In the API layer (`api/metrics/index.php`), `getExecCountersByPlatformExecStatus()`
could return NULL/non-array, and the `foreach` on `$neurus['with_tester']` was
unguarded.

## Fix

Three changes applied:

1. **`lib/functions/tlTestPlanMetrics.class.php:1688`** — Initialize `$keySet = array()`
   before the loop to prevent undefined variable warning on empty test suites.

2. **`lib/functions/tlTestPlanMetrics.class.php:1693-1701`** — Guard `get_full_path_verbose()`
   return value against NULL, and use `isset()` for array offset access on `flat`
   and `staircase`.

3. **`api/metrics/index.php:149`** — Guard `foreach` with
   `is_array($neurus) && isset($neurus['with_tester']) && is_array($neurus['with_tester'])`.

## Files Changed

- `lib/functions/tlTestPlanMetrics.class.php` — 2 lines added (null guard + keySet init)
- `api/metrics/index.php` — 2 lines changed (foreach guard)

## Verification

- Metrics Dashboard loads with no PHP warnings for project with platform-linked test plan
- API endpoint `GET /api/metrics/index.php/dashboard?tproject_id=1` returns `{"status":"ok"}`
- Event Viewer: 0 errors, 0 warnings after dashboard load
- PHP server log: all requests [200], no E_WARNING entries
- PHP syntax check: `php -l lib/functions/tlTestPlanMetrics.class.php` — no errors
