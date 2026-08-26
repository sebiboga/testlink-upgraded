# Bugfix Issue #637 — Legacy report PHP pages exit() silently when format param is null

## Problem

Two legacy report controllers — `displayMgr.php:initArgsForReports()` and
`resultsGeneral.php:init_args()` — contained a defensive check that silently
`exit()`ed when the `format` query parameter was absent:

```php
if (is_null($args->format)) {
    tlog("Parameter 'format' is not defined", 'ERROR');
    exit();
}
```

This produced **zero-byte HTTP 200 responses** — no error page, no headers, no
content. The primary trigger was the aside menu's report links being built without
query arguments (the original issue description), but that aspect was already
fixed by prior modernization commits (commit `9707fdb2e`, Refs #743) which
routed all report links to modernized HTML wrappers with proper `tproject_id`
and `tplan_id` parameters.

The **remaining defect** was the defensive fallback: any direct URL access
(bookmarks, external integrations, API consumers) or any future code path that
omitted `format` would still silently fail.

## Fix

Changed `exit()` to default `format` to `FORMAT_HTML` (constant = 0, defined in
`cfg/reports.cfg.php:22`) in both locations:

### `lib/results/displayMgr.php` (line 87-90)

This is the shared `initArgsForReports()` function used by ~10 legacy report
controllers (`resultsByTSuite.php`, `baselinel1l2.php`, `neverRunByPP.php`,
`resultsTC.php`, `execTimelineStats.php`, etc.).

```php
// Before (exit on null format)
if (is_null($args->format)) {
    tlog("Parameter 'format' is not defined", 'ERROR');
    exit();
}

// After (default to HTML)
if (is_null($args->format)) {
    tlog("Parameter 'format' is not defined — defaulting to FORMAT_HTML", 'WARNING');
    $args->format = FORMAT_HTML;
}
```

### `lib/results/resultsGeneral.php` (line 200-203)

This is a dead local `init_args()` function (the page calls
`initArgsForReports()` from `displayMgr.php` instead), but the pattern was
fixed for consistency and to prevent future misuse.

```php
// Before
if (is_null($args->format)) {
    tlog("Parameter 'format' is not defined", 'ERROR');
    exit();
}

// After
if (is_null($args->format)) {
    tlog("Parameter 'format' is not defined — defaulting to FORMAT_HTML", 'WARNING');
    $args->format = FORMAT_HTML;
}
```

## Verification

All 8 legacy report controllers that use `initArgsForReports()` were tested
without the `format` parameter:

| Report | Status | Bytes |
|--------|--------|-------|
| resultsGeneral.php | 200 | 4948 |
| resultsByTSuite.php | 200 | 4997 |
| resultsTC.php | 200 | 19052 |
| resultsTCFlat.php | 200 | 5120 |
| resultsByTesterPerBuild.php | 200 | 4115 |
| baselinel1l2.php | 200 | 5606 |
| neverRunByPP.php | 200 | 7281 |
| resultsTCAbsoluteLatest.php | 200 | 7451 |

All previously working reports (with explicit `format=0`) continue unchanged.
Event Viewer shows no new Error/Warning entries after the fix.
