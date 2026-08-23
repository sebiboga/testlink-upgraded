# resultsByTSuite Flat-Spec Fatal TypeError Fix

## Issue #425

**Reports and Metrics → Metrics by Level 1 & Level 2 Test Suites**
(`lib/results/resultsByTSuite.php`) died with a raw fatal on any test project
whose specification is *flat* — one or more top level suites, no sub-suites:

```
Fatal error: Uncaught TypeError: current(): Argument #1 ($array) must be of
type array, bool given ... in lib/results/resultsByTSuite.php:47
```

A flat spec is the default shape of every new project, so the report was
unusable out of the box on PHP 8 (HTTP 500 / blank page). On PHP 7 this was
only a warning, making it a migration regression.


## Root Cause

Chain, each hop verified in source:

1. `tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender()`
   (`lib/functions/tlTestPlanMetrics.class.php`) fills `infoL2` only for suites
   nested at least one level below a top level suite
   (`count($staircase[$tsuite_id]) > 1`); for a top level TC `$l2id` stays `-1`.
2. Flat spec ⇒ `$renderObj->infoL2` remains `array()`.
3. Pre-fix `resultsByTSuite.php:46-51` guarded only with
   `!is_null($gui->statistics->$item)` — an **empty array is not null**, so the
   branch ran and `current(current($gui->statistics->testsuites))`
   evaluated `current(array()) → false`, then `current(false)`.
4. PHP 8 escalates that to an uncaught `TypeError` → HTTP 500.

Mechanism proven standalone on PHP 8.3:
`php -r 'var_dump(current(current([])));'` → same `TypeError`.

## The Fix — approach

The same fix proposed by the issue reporter landed on the default branch in
commit **`764d625e1`**: treat "nothing to report on" exactly like the existing
"no test cases / no suites" path instead of entering the table-rendering loop:

```php
// lib/results/resultsByTSuite.php:33
if(is_null($tsInf) || empty($tsInf->infoL2)) {
    // no level 2 test suites -> no report
    $gui->do_report['status_ok'] = 0;
    $gui->do_report['msg'] = lang_get('report_tspec_has_no_tsuites');
    tLog('Overall Metrics page: no test cases defined');
} else {
```

Alternatives rejected:

* fabricating L1-only rows inside the metrics method — changes report semantics;
* guarding every `current()` individually — treats the symptom and would still
  render an empty table shell.

The copy of the same `current(current($x))` shape in
`lib/results/resultsGeneral.php` got an identical `!empty()` guard at line 83
in the same commit, closing off the sibling crash path for priorities /
keywords / testsuites sections.

## Verification (regression matrix, Suite 65 in `tmp/TLU_Test_Cases.md`)

| # | Case | Result |
|---|---|---|
| R1 | Flat spec + linked/executed TCs (`RPT425`, plan 9) | ✅ HTTP 200, graceful *"There are no Test Suites defined…"*, zero Fatal/TypeError |
| R2 | Nested control (`RPT425N`, plan 19) | ✅ HTTP 200, full L1/L2 table |
| R3a | Empty plan WITH build, no links | ✅ graceful no-data branch |
| R3b | Plan with ZERO builds | ⚠️ separate defect → [#634](https://github.com/sebiboga/testlink-upgraded/issues/634) |
| R4 | Event Viewer after valid-input requests | ✅ no new Error/Warning |

Pre-fix binary reproduced from worktree at `764d625e1^` (`857555dad`) against
the same DB: HTTP 500 + exact `TypeError ... resultsByTSuite.php:47`.


## Remaining (deliberately out of scope)

* Cosmetic: the reused message says *"There are no Test Suites defined…"*
  although suites exist (just none at level 2). A dedicated
  `report_has_no_l2_tsuites` key across all locale bundles was deferred by the
  issue body itself.
* Plans with zero builds still 500 via a different throw
  ([#634](https://github.com/sebiboga/testlink-upgraded/issues/634)); invalid
  `tplan_id` URLs warn + 500 via displayMgr.php:72
  ([#635](https://github.com/sebiboga/testlink-upgraded/issues/635)).

**Refs:** GitHub issues #425 · Files: `lib/results/resultsByTSuite.php`,
`lib/results/resultsGeneral.php` (fix commit `764d625e1`),
`tmp/fixtures_425.php`, `tmp/fixtures_425n.php`,
`tmp/TLU_Test_Cases.md` (Suite 65).
