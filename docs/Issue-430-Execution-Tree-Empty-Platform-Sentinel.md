# Issue 430 — Execution tree shows empty when platform filter is -1 sentinel

Issue: https://github.com/sebiboga/testlink-upgraded/issues/430
Status: FIXED (commit `01b11e300`, verified live in this run) · branch `fix/issue-430` (verification docs)

## Symptom

On a test plan without platforms, the execution tree hid every executed test case:
the latest-execution subquery was filtered with `AND EE.platform_id = -1` (the "no
platform selected" sentinel), which can never match because `executions.platform_id`
stores `0` for platform-less plans. The exec union INNER JOINs that empty subquery and
returns nothing, while the not-run union is suppressed by the existence of real
executions — so executed cases vanished from the tree.

## Root cause chain

1. `tlTestCaseFilterControl::init_setting_platform()`
   (`lib/functions/tlTestCaseFilterControl.class.php:1312-1320`) — when the plan has no
   linked platforms it sets `settings['setting_platform']['selected'] = -1` and returns.
2. The tree builder maps the setting verbatim into filters
   (`lib/functions/execTreeMenu.inc.php:330`: `'platform_id' => 'setting_platform'`) and
   calls `testplan::getLinkedForExecTree()` (`execTreeMenu.inc.php:146`).
3. Pre-fix `getLinkedForExecTree()` concatenated the filter unconditionally:
   `AND EE.platform_id = -1` → subquery `$sqlLEBBP` empty → exec union yields zero rows.

## The fix (already merged: 01b11e300)

`lib/functions/testplan.class.php:6122-6126`: filter only on real platform ids —
`if (!is_null($my['filters']['platform_id']) && $my['filters']['platform_id'] > 0)` —
the same `> 0` convention `initGetLinkedForTree()` already used for TPTCV.
Second defense layer (added under #420): `lib/execute/execSetResults.php:2318-2320`
normalizes any negative platform value to the DB convention `0`.

Why this method and not alternatives: normalizing at the controller alone would leave a
raw `-1` landmine for any future caller of `getLinkedForExecTree()`; guarding inside the
query builder fixes every current and future caller at once with one condition, matching
the existing internal convention. Clamping the sentinel to `0` inside SQL strings was
rejected as string-munging magic that hides intent.

## Verification (this run, fresh DB + fixtures via XMLRPC API)

Fixtures: project Proj430(1)/P430, plan TP430(2) with zero platforms, build B430(1),
TC430-A(ext P430-1) executed Passed, TC430-B(ext P430-2) executed Failed
(`executions.platform_id = 0` on both rows).

| # | Case | Expected | Result |
|---|------|----------|--------|
| 1 | DB mechanism check | subquery with verbatim `-1` → EMPTY SET; without clause → 2 rows | PASS |
| 2 | Exec navigator tree | counters `(2)(0,1,1,0)`, both leaves visible | PASS |
| 3 | Exec form via leaf click (`setting_platform=-1` in URL) | form renders with latest execution data, not "No data available" | PASS |
| 4 | Event Viewer delta from the exec-tree path | no new Error/Warning attributable to this path | PASS |

Regression suite: `tmp/TLU_Test_Cases.md`, Suite 430.
