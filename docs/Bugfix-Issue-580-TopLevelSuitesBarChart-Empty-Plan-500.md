# Bugfix Issue #580 — topLevelSuitesBarChart 500 on plan without linked test cases

## Symptom

`lib/results/topLevelSuitesBarChart.php?tplan_id=<empty-plan>&tproject_id=<pid>` dies with HTTP 500:

```
Uncaught TypeError: array_keys(): Argument #1 ($array) must be of type array, null given
in lib/functions/testplan.class.php:862
```

The chart page fails to render when the test plan has no linked test cases.

## Root Cause

`testplan::getRootTestSuites()` at `lib/functions/testplan.class.php:859` calls `fetchRowsIntoMap()` which returns `NULL` (not empty array) when the SQL returns zero rows on PHP 8. The next line calls `array_keys($items)` where `$items = NULL`, which throws a TypeError on PHP 8 (PHP 7 would emit a warning).

## Fix

Three guards added in `lib/functions/testplan.class.php`:

1. **Line 860-862**: `if (empty($items)) { return array(); }` — early return when `fetchRowsIntoMap()` returns NULL
2. **Line 870**: `if (!empty($xmen))` — guard `foreach($xmen ...)` against null map
3. **Line 890-892**: `if (empty($tlnodes)) { return array(); }` — guard `array_keys($tlnodes)` against empty map

Applied by CI bot commits `1082c2fe3` and `686c2bc24` (referencing issue #690).

## Verification

- Browser loads `topLevelSuitesBarChart.php?tplan_id=<empty-plan>` → HTTP 200 (was 500)
- No TypeError/Fatal in PHP server log
- No new Error entries in Event Viewer
- Regression test suite: `tmp/TLU_Test_Cases.md` — TC-580-01, TC-580-02, TC-580-03

## Files Changed

- `lib/functions/testplan.class.php` — three null-guard additions in `getRootTestSuites()`
