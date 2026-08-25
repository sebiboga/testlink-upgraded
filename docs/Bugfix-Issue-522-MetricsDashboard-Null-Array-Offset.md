# Bugfix — Issue #522: E_WARNING Trying to access array offset on null in metricsDashboard.php:245

## Problem

PHP 8.x emits `E_WARNING: Trying to access array offset on null` when loading the
Metrics Dashboard (`lib/results/metricsDashboard.php`) for a test plan that has no
builds (or no active TC versions). The warning occurs on every page load of the
dashboard under this condition.

## Root Cause

In `lib/results/metricsDashboard.php`, the `getMetrics()` function iterates over
accessible test plans. When a test plan has no platforms (`$platformSet` is null),
the `else` branch at line 240 is taken. This branch calls
`$metricsMgr->getExecCountersByExecStatus()` which returns `null` when:

- `helperBuildSQLExecCounters()` returns null (plan without builds)
- `$builds->idSet` is null/empty (no active builds)

The original code at line 245 directly accessed the null return:

```php
$mm[$key]['overall']['active'] = $mm[$key]['overall']['total'];
```

Under PHP 8, accessing an array offset on `null` triggers `E_WARNING`.

## Fix

Applied in commit `d3200a115` (Aug 19, 2026). Two guards added at lines 245-248:

1. **`is_array()` guard** — If `getExecCountersByExecStatus()` returns null or a
   non-array, replace with an empty array.
2. **`isset()` ternary** — When reading `$mm[$key]['overall']['total']`, use
   `isset()` to safely default to 0 if the key is missing.

```php
if (!is_array($mm[$key]['overall'])) {
    $mm[$key]['overall'] = array();
}
$mm[$key]['overall']['active'] = isset($mm[$key]['overall']['total'])
    ? $mm[$key]['overall']['total'] : 0;
```

## Files Changed

- `lib/results/metricsDashboard.php` (lines 245-248)

## Verification

- Metrics Dashboard renders correctly for test plans with no builds (zero progress, no warnings).
- Metrics Dashboard renders correctly for test plans with builds and executed TCs (full data).
- Mixed scenarios (some plans with builds, some without) work correctly.
- Event Viewer shows no new Error/Warning entries from metricsDashboard.php.
- PHP syntax check (`php -l`) passes on the modified file.
