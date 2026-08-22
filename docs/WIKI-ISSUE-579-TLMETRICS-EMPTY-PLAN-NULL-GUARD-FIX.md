# tlTestPlanMetrics Empty-Plan Null Guard Fix

## Issue #579

Fixed: opening `lib/results/resultsGeneral.php?tplan_id=<plan-without-linked-test-cases>&format=0`
logged **three** E_WARNING events and, on PHP 8.3, escalated into an
**uncaught TypeError → HTTP 500**:

```
E_WARNING  Undefined array key "tsuites"        - tlTestPlanMetrics.class.php - Line 1259
E_WARNING  Attempt to read property "colDefinition" on null - Line 1383
E_WARNING  Attempt to read property "info" on null          - Line 1393
PHP Fatal: Uncaught TypeError: array_keys(): Argument #1 ($array) must be of
type array, null given - tlTestPlanMetrics.class.php:1393
```

## Root Cause

Chain of two unguarded contracts on a plan whose `testplan_tcversions`
table has zero rows:

1. `getExecCountersByTestSuiteExecStatus()` builds `$exec['tsuites']`
   inside a loop driven by `testplan::get_testsuites($id)`. For an empty
   plan that list is `array()`, so `$loop2do = 0` and the `'tsuites'`
   key is **never created**, while `'with_tester'`, `'tsuites_full'`
   and `'staircase'` always are.
2. `getStatusTotalsByItemForRender(,'tsuite')` then evaluated
   `!is_null($metrics[$setKey])` with `$setKey='tsuites'` → warning #1,
   condition false, `$renderObj` stays null, method returns
   `[null, $metrics['staircase']]`.
3. The caller `getStatusTotalsByTopLevelTestSuiteForRender()`
   dereferenced `$rx->colDefinition` (:1383) and — because
   resultsGeneral.php passes `groupByPlatform=1` —
   `array_keys($rx->info)` (:1393): warning #2, warning #3, and on PHP 8
   `array_keys(null)` is a fatal TypeError, not just a warning.

The same legacy pattern assumed "no linked test cases" could never reach
this code; the #577 fix (null-safe `get_testsuites()`) made exactly that
path reachable and silent again.

## The Fix — approach

Two minimal guards in `lib/functions/tlTestPlanMetrics.class.php`,
plus one defensive guard at the chart caller:

```php
// getStatusTotalsByItemForRender(), line ~1259 (was !is_null($metrics[$setKey]) > 0)
if( !is_null($metrics) && isset($metrics[$setKey]) && !is_null($metrics[$setKey])) {

// getStatusTotalsByTopLevelTestSuiteForRender(), right after the list() call
if( is_null($rx) ) {
  return null;
}
```

Why this shape:

* `isset()` first makes the key read warning-free while keeping the old
  truth table byte-for-byte for keyword/platform/priority_level paths,
  whose metrics arrays always define their keys (`keywords`,
  `platforms`, `priority_levels`). The odd legacy `> 0` comparison was
  folded away — `(true > 0)` was always true anyway.
* Returning `null` matches the method's existing contract: both real
  callers already handle it — `resultsGeneral.php:30`
  (`is_null($tsInf)` → renders the "no test cases" report branch) and
  `lib/general/mainPage.php` (`is_null($rx) → return null`). An early
  return is preferred over fabricating an empty render object because
  every consumer treats null as "nothing to draw".
* `topLevelSuitesBarChart.php` read `$dummy->info` unguarded; it now
  checks `!is_null($dummy) &&` first so the new null return cannot move
  the warning onto that screen.

Alternatives rejected: making
`getExecCountersByTestSuiteExecStatus()` always set
`$exec['tsuites'] = array()` (touches a hot metrics path used by many
reports for a purely cosmetic key), or returning an empty stdClass from
the top-level renderer (would flip resultsGeneral into its statistics
branch with nothing to render).

## Verification

| Step | Result |
|------|--------|
| Pre-fix repro on fixture plan (build present, zero linked TCs) | 500 fatal + exactly the 3 E_WARNINGs above in Event Viewer |
| Post-fix same URL | HTTP 200, "There are no Test Suites defined…" branch, zero new events |
| Positive regression: link one TC, reload | full "Results by Top Level Test Suite" table renders (Total=1, Not Run=1, 100%) |
| `mainPage.php` dashboard (third caller) | 200, zero new events |
| Code review subagent | APPROVE — condition truth table unchanged for all four item types |

Regression suite: `Regression — Issue #579` in `tmp/TLU_Test_Cases.md`.

## Follow-up bugs discovered during verification

Each filed separately with repro steps, root cause and suggested fix:

| Issue | Where | Problem |
|-------|-------|---------|
| #580 | `testplan::getRootTestSuites()` :862 | `array_keys(null)` TypeError → topLevelSuitesBarChart 500 on case-less plans |
| #581 | `resultsGeneral.php` :248 | `testPriorityEnabled on false` when `testprojects.options` is an empty string |
| #582 | `resultsGeneral.php` :63 + Dashio template :179/:247 | undefined `$items2loop` foreach + template/controller name mismatch (`tprojOpt` vs `testprojectOptions`) |
| #583 | `getStatusTotalsTSuiteDepth2ForRender()` :3389/:3403 | identical null-deref pattern on the resultsByTSuite.php path |

## Files changed

* `lib/functions/tlTestPlanMetrics.class.php` — isset() key guard (~1259), early null return in `getStatusTotalsByTopLevelTestSuiteForRender()` (~1377)
* `lib/results/topLevelSuitesBarChart.php` — canDraw null guard (:60)
