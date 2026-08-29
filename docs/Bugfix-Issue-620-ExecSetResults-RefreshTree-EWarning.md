# Issue #620 — E_WARNING Undefined property $refreshTree on execSetResults.php:2344

**Issue:** [#620](https://github.com/sebiboga/testlink-upgraded/issues/620)
**Status:** VERIFIED-FIXED (2026-08-29)

## Symptom

When `lib/execute/execSetResults.php` is reached **without** a prior `execNavigator`
load (e.g. opening the URL directly, bypassing the Execute Tests → tree-click flow),
the Event Viewer records:

```
E_WARNING: Undefined property: stdClass::$refreshTree - execSetResults.php - Line 2344
```

The warning is logged to the Event Viewer (`events` table, log_level=2). Note: E_WARNING
is logged, not thrown — it does not by itself abort the request. (In the initial
empty-DB reproduction the direct GET returned HTTP 500, but that was caused by an
unrelated `tplan_id` the database did not contain, throwing "Bad Test Project ID",
not by this warning.)

## Root cause

`init_args()` (`lib/execute/execSetResults.php:546`) calls
`getSettingsAndFilters($args)` at **line 556** — *before* `$args->refreshTree` is first
assigned at **line 589-590**:

```php
// 556 (inside init_args, runs EARLY)
getSettingsAndFilters($args);

// 589-590 (runs LATER in init_args)
if(is_null($args->refreshTree)) {
    $args->refreshTree = isset($_REQUEST['refresh_tree']) ? intval($_REQUEST['refresh_tree']) : 0;
}
```

Inside `getSettingsAndFilters()` the ternary at line 2343-2344 read the
`refreshTree` property back as a fallback:

```php
$argsObj->refreshTree = isset($cache['setting_refresh_tree_on_action']) ?
                            $cache['setting_refresh_tree_on_action'] : $argsObj->refreshTree;
```

When the session cache has no `setting_refresh_tree_on_action` key, PHP evaluates
`$argsObj->refreshTree` as the fallback value. Because `init_args` has not yet
assigned it at that moment, the property is **undefined** → PHP 8.3 (with
`error_reporting(E_ALL)`) raises the E_WARNING.

`execNavigator.php` (the normal navigation path) seeds `$_SESSION['setting_refresh_tree_on_action']`
before `execSetResults.php` runs, so the `isset()` at line 2343 is true and the
undefined property is never read on that path. That is why the warning is only
reproducible on direct access.

## Fix

**Minimal one-line change** at `lib/execute/execSetResults.php:2343-2344` — the ternary
fallback now defaults to `0` instead of reading the (possibly undefined) property:

```php
$argsObj->refreshTree = isset($cache['setting_refresh_tree_on_action']) ?
                            $cache['setting_refresh_tree_on_action'] : 0;
```

`0` matches `line 590`'s default when `$_REQUEST['refresh_tree']` is absent (which is
always the case for the direct-access path that triggers this warning). When the
session cache key is present, behavior is byte-for-byte identical. Note: a request
carrying both a missing cache key *and* `refresh_tree=1` would pre-fix have surfaced
`1` via line 590; post-fix it defaults to `0` — inert in practice (the navigation path
always has the cache key, and direct-access URLs do not carry the param). No
reordering of `init_args` was done (out of scope, riskier). This matches the issue's
own suggested fix.

### Why this method (and what was rejected)

- **Chosen:** default the fallback to `0` at the read site. Minimal, surgical, no
  behavioral change on the normal path.
- **Rejected — reorder `init_args`** to move the `refreshTree` assignment before
  `getSettingsAndFilters()`: larger diff, could affect other early reads, unnecessary.
- **Rejected — initialize in `initContext()`**: more invasive, wider blast radius.

## Files changed

| File | Change |
|---|---|
| `lib/execute/execSetResults.php` | line 2344: `: $argsObj->refreshTree;` → `: 0;` |

## Verification (A/B)

Environment: app http://localhost:8082 (PHP 8.3), MariaDB testlink (fresh import),
login admin/admin. Minimal fixtures created via SQL: testproject node 100, testsuite
node 150, testplan node 200, testcase node 300 + tcversion 301, `testplan_tcversions`
(200,301), build 1. `events` table cleared before each run.

Repro (fresh session, direct GET bypassing execNavigator):
`execSetResults.php?level=testcase&tproject_id=100&id=300&version_id=301&tplan_id=200&build_id=1&platform_id=0`

| # | Check | Expected | Result |
|---|---|---|---|
| 1 | `php -l lib/execute/execSetResults.php` | No syntax errors | PASS |
| 2 | Direct GET, **original** code | `E_WARNING ... refreshTree ... Line 2344` present (event id 15) | reproduced |
| 3 | Direct GET, **fixed** code | HTTP 200; zero `refreshTree` E_WARNING rows | PASS |
| 4 | `events` table filtered `%refreshTree%` | 0 rows | PASS |
| 5 | Pre-existing downstream warnings (`tsuite_id`@950, keywordIsLinked SQL) identical in both A & B runs | proven fixture artifacts, unrelated to fix | PASS |

## Regression test case

Appended to `tmp/TLU_Test_Cases.md` — "Regression — Issue #620", suite 620.1–620.5,
all PASS.

## RESUME

Re-test in one command (fresh shell):
`mysql ... -e "DELETE FROM events;"`, fresh `curl` login admin/admin, then
`curl "http://localhost:8082/lib/execute/execSetResults.php?level=testcase&tproject_id=100&id=300&version_id=301&tplan_id=200&build_id=1&platform_id=0"`
and assert no `%refreshTree%` row in `events`.
