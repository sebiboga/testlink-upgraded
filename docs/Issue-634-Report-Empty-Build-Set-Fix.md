# Issue 634 — Report controllers HTTP 500 on test plans without builds

**Issue:** [#634](https://github.com/sebiboga/testlink-upgraded/issues/634)
**Branch:** `fix/issue-634` · **Commits:** `4bf7c9166`, `46f6998dc`, `8db3b9c47`
**Status:** FIXED & VERIFIED (2026-08-23)

## Symptom

Opening any report screen (`lib/results/resultsByTSuite.php` first observed) for a
test plan that has **zero builds** died with:

```
PHP Fatal error:  Uncaught Exception: tlTestPlanMetrics::helperGetExecCounters - Can not work with empty build set
in lib/functions/tlTestPlanMetrics.class.php:1806
```

→ raw HTTP 500 / blank page. A freshly created plan has no builds until someone
creates one, so every report was a guaranteed 500 for such plans.

## Root cause chain

1. Report controllers call `getStatusTotals*ForRender()` unconditionally.
2. These funnel into `helperGetExecCounters()` (`tlTestPlanMetrics.class.php`).
3. When the plan's build set is empty the helper executed a legacy
   "Emergency Exit" `throw new Exception("Can not work with empty build set")`.
4. No caller caught it → uncaught → PHP fatal → HTTP 500.

Meanwhile fixes #579/#583 had already established a *null contract* at the
ForRender layer ("nothing to report ⇒ return null ⇒ controller renders its
no-data branch"). The exception was the single remaining point breaking that
contract. Blast radius: 12 call sites — every `getExecCountersBy*ExecStatus()`,
the SQL-builder helpers and `getExecutionsByStatus()` / `getNotRun*()` — i.e.
resultsGeneral, resultsByStatus, resultsTC(+Flat), charts, metricsDashboard and
the XMLRPC API all inherited the same fatal on zero-build plans.

## Fix approach (and rejected alternatives)

**Chosen:** extend the existing null contract to its source.
- `helperGetExecCounters()` now returns `null` instead of throwing when the
  effective build set is empty — "no builds" is legitimately *empty data*, not an
  exceptional state. This matches upstream's own precedent: the internal
  `helperBuildSQLExecCounters()` already wrapped the helper in try/catch → null.
- Every direct caller got a mechanical 3-line guard: destructure via a temp
  variable, early-return null when the helper returned null. Zero behavior
  change for plans WITH builds.
- `getStatusTotalsByItemForRender()` staircase return made null-safe.
- Controllers that dereferenced null afterwards were hardened:
  - `resultsTC.php` / `resultsTCFlat.php`: `count(null)` on build set,
    `end(null)` for latest-build pointer, null `buildIDSet` iterations.
  - `getExecStatusMatrix()` / `getExecStatusMatrixFlat()`: return the same
    empty-matrix shape that plan-with-builds-but-no-TCs produces, so existing
    controller branches work unchanged.
- Charts honor their existing `canDraw` flag (`keywordBarChart`,
  `topLevelSuitesBarChart`) or exit gracefully on null counters
  (`overallPieChart`) instead of feeding empty data into pChart.

**Rejected:** catching the exception inside every report controller (~10 screens,
duplicated boilerplate, leaves the trap armed for API callers) and returning
fake empty structures from the helper itself (its callers immediately read
`$my['opt']` / `$builds->inClause`, so null must be checked before use anyway).

## Regression matrix result (plan 9002 = zero builds, plan 9003 = control with one build)

| screen | 9002 | 9003 |
|---|---|---|
| resultsByTSuite | 200 graceful | 200 |
| resultsGeneral | 200 | 200 |
| resultsByTesterPerBuild | 200 | 200 |
| resultsByStatus type=notrun | 200 | 200 |
| resultsTC / resultsTCFlat | 200 | 200 |
| metricsDashboard | 200 | 200 |
| keywordBarChart / overallPieChart | 200 empty image | 500 = #586 only |
| topLevelSuitesBarChart | 500 = #580 only | 500 = #580 only |

Event Viewer: zero new entries from the verification session.

## Known independent failures (NOT part of this fix)

- **#586** — pChart PHP4-style constructor never runs under PHP 8; chart images
  fatal even with data (plans WITH builds fail identically).
- **#580** — `testplan::getRootTestSuites()` array_keys(null); called
  unconditionally by topLevelSuitesBarChart regardless of build sets.
