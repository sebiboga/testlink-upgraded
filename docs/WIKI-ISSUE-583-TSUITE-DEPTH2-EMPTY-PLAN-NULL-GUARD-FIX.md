# tlTestPlanMetrics TSuite Depth-2 Empty-Plan Null Guard Fix

## Issue #583

Fixed: opening `lib/results/resultsByTSuite.php?tplan_id=<plan-without-linked-test-cases>&format=0`
on PHP 8.3 produced **HTTP 500 / zero-byte body**:

```
E_WARNING  Attempt to read property "colDefinition" on null - tlTestPlanMetrics.class.php - Line 3389
PHP Fatal: Uncaught TypeError: array_keys(): Argument #1 ($array) must be of
type array, null given - tlTestPlanMetrics.class.php Line 3403
```

## Root Cause

Exactly the pattern flagged during the #579 review, on the sibling method
that was not covered by that fix:

* `getStatusTotalsTSuiteDepth2ForRender()` calls
  `getStatusTotalsByItemForRender($id,'tsuite',…)`. For a plan whose
  builds exist but which has **zero linked test cases**, that inner
  method returns `[null, staircase]` (the null contract documented in
  #579).
* The depth-2 renderer then dereferenced `$rx->colDefinition` (:3389)
  and — because resultsByTSuite.php passes `groupByPlatform=1` —
  `array_keys($rx->info)` (:3403) without a null check. On PHP 8,
  reading a property on null is an E_WARNING and `array_keys(null)` is
  an uncaught TypeError → fatal.

Note: the build-less empty plan never reaches this code because
`helperGetExecCounters()` throws "Can not work with empty build set"
first; the reported path needs at least one build.

## The Fix — approach

One minimal guard in `lib/functions/tlTestPlanMetrics.class.php`,
identical in shape to the #579 fix:

```php
// getStatusTotalsTSuiteDepth2ForRender(), right after the list() call
if( is_null($rx) ) {
  return null;
}
```

Why this shape:

* Returning `null` matches the method's existing contract — its only
  caller `resultsByTSuite.php:33` already handles it:
  `if(is_null($tsInf) || empty($tsInf->infoL2))` renders the
  "no test suites defined" report branch.
* An early return is preferred over fabricating an empty render object
  because every consumer treats null as "nothing to report on".
* Alternatives rejected: always setting `$exec['tsuites'] = array()`
  inside the metrics hot path (cosmetic key, wide blast radius), or
  guarding only the two dereference sites (leaves a half-initialised
  stdClass flowing into the caller).

## Verification

| Step | Result |
|------|--------|
| Pre-fix repro on fixture plan (build present, zero linked TCs) | HTTP 500, harness shows exactly the warning + TypeError above |
| Post-fix same URL | HTTP 200, "There are no Test Suites defined…no report can be created" branch |
| Positive regression: link one TC under nested suites, reload | full "Metrics by Level 1 & Level 2 Test Suites" table (Top/Child rows, Not Run column) |
| Sibling screens sharing the inner metrics call (`resultsGeneral.php`, `mainPage.php`) | HTTP 200, zero new warnings/events |
| Event Viewer | no new Error/Warning events from the touched code path |

Regression suite: `Regression — Issue #583` in `tmp/TLU_Test_Cases.md`.

## Follow-up bug discovered during verification

Filed separately with repro steps, root cause and suggested fix:

* #586 pChart declares only a PHP4-style constructor
  (`function pChart()`), which PHP 8 no longer calls implicitly →
  `$this->Picture` stays null and every chart screen
  (topLevelSuitesBarChart etc.) fatals with an imagecolorallocate
  TypeError **even on fully populated plans**.

## Files changed

* `lib/functions/tlTestPlanMetrics.class.php` — early null return in
  `getStatusTotalsTSuiteDepth2ForRender()` (:3380-3382; the quoted
  :3389/:3403 are pre-fix line numbers)
