# testplan::get_testsuites() Null Recordset Guard Fix

## Issue #577

Fixed: generating any report/document for a **test plan with no linked test
cases** (e.g. a freshly created empty plan, on-build report) logged 2 PHP
E_WARNINGs into the Event Viewer — and the same unguarded code path could
hard-crash the request:

```
E_WARNING
foreach() argument must be of type array|object, null given - in .../lib/functions/testplan.class.php - Line 2147

E_WARNING
foreach() argument must be of type array|object, null given - in .../lib/functions/testplan.class.php - Line 2154
```

Direct invocation additionally died with an **uncaught TypeError** one line
later: `usort(): Argument #1 ($array) must be of type array, null given` at
line 2164 — truncating the HTTP response (500) because `$finalset` was never
initialized when both dedup loops skipped.


## Root Cause

`testplan::get_testsuites($id)` builds its suite list from
`testplan_tcversions`:

* `$recordset = $this->db->get_recordset($sql);` → `get_recordset()`
  initializes its output to `null` and only appends rows while fetching, so it
  returns **NULL when the plan has zero linked tcversions**
  (`lib/functions/database.class.php:776`).
* Line 2146 assigned that null to `$superset`, then `foreach($recordset ...)`
  (2147) and `foreach($superset ...)` (2154) iterated `null` → 2× E_WARNING
  under PHP 8.
* Because both loops skipped, `$finalset[] = $value;` never executed, leaving
  `$finalset` undefined for `usort($finalset, ...)` on line 2164 → uncaught
  `TypeError` under PHP 8.

Callers affected: `tlTestPlanMetrics::getExecCountersByTestSuiteExecStatus()`
(report screens / charts), XML-RPC `getTestSuitesForTestPlan`.

## Fix Approach — cast at fetch + initialize accumulator

Minimal change inside `get_testsuites()` (`lib/functions/testplan.class.php`):

```php
// issue #577: get_recordset() returns NULL when the test plan has no
// linked tcversions => foreach() on null + undefined $finalset for usort().
// Cast to array (same pattern as get_parenttestsuites()).
$recordset = (array)$this->db->get_recordset($sql);
...
$dup_track = array();
$finalset = array();
foreach($superset as $value)
```

Why this way:

* **Matches house style**: the sibling method `get_parenttestsuites()` in the
  same class already uses `(array)$this->db->get_recordset($sql)` for exactly
  this reason; metrics helpers do the same for their fetchers.
* **Behavior identical for populated plans**: `get_recordset()` returns a
  numerically indexed row list or NULL — casting NULL→`[]` is a no-op for real
  result sets, so nothing changes except the absence of warnings.
* **Kills all three symptoms at once**: no foreach-on-null warnings AND
  `$finalset` is always an array for `usort()`, so the empty plan now returns
  `array()` instead of crashing.
* **Alternatives rejected**: early-returning `array()` before the loops would
  duplicate the guard logic and leave the `$finalset` hazard latent for future
  edits; changing `get_recordset()` globally was too broad for this bug.

## Verification

Reproduction fixture: project + plan with a build but **zero linked test
cases**.

| Step | Pre-fix | Post-fix |
|------|---------|----------|
| Direct `get_testsuites(emptyPlan)` | 2× E_WARNING + TypeError crash | returns `array(0) {}`, clean |
| `printDocument.php?type=testreport_onbuild` via browser | 2× E_WARNING events | document renders, 0 new events |
| Metrics chain `getExecCountersByTestSuiteExecStatus()` | fatal before reaching suites | completes, `tsuites_full=[]` |
| Plan WITH linked case (positive regression) | works | still returns `[Root Suite, TS-A]` name-sorted |

Fix commit: branch `fix/issue-577`. Regression suite: `tmp/TLU_Test_Cases.md`
("Regression — Issue #577").
