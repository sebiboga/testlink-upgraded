# Bugfix / Completion — Issue #830: reporting joins scoped to plan's project

## Summary

Issue #830 is the "reports.class.php + testcase.class.php + object.class.php
reporting joins" sub-task of #503 (builds scoped to the Test Project). Its
implementation was landed in commit `eb5b4f8ef` but the issue was left OPEN.
This run re-verified the Definition of Done empirically and closed it.

## Scope (what #830 implemented)

1. **`lib/functions/reports.class.php` — `get_count_builds()`** now resolves the
   plan's project and counts `builds.testproject_id = {tproject}` instead of
   `builds.testplan_id = {plan}`. Builds are project-scoped, so a build shared
   across several plans of the same project is counted once, project-wide.

2. **`lib/functions/testcase.class.php` — `get_last_execution()`** dropped the
   now-harmful `AND builds.testplan_id = {tplan}` from the
   `LEFT OUTER JOIN builds` (the execution is already scoped via
   `e.testplan_id`).

3. **`lib/functions/object.class.php`** registers the `latest_exec_by_build`
   view (`'latest_exec_by_build' => null` → prefixed view name), used for the
   future #835 cross-plan per-build report.

## Root Cause

No new code defect was found in this run. The issue was simply never closed
after the fix (also verified in `eb5b4f8ef`) was pushed, so the issue remained
OPEN despite a complete Definition of Done.

## Why this fixes the plan-scope bug

Because builds no longer carry a meaningful `testplan_id` (they are scoped to
the project), any reporting join keyed on `builds.testplan_id` would miss builds
created for a sibling plan of the same project. `get_count_builds()` is used by
`resultsNavigator.php` build counters — plan-scoped counting would under-count
the total builds for a project.

## Files Changed (this run)

| File | Change |
|------|--------|
| `tmp/TLU_Test_Cases.md` | Added regression suite TC-830.1–830.7 (verification of #830 DoD) |
| `tmp/repro_830_verify.php` | Repro exercising `get_count_builds()` project scope via the real class |

Note: the implementation files themselves (`reports.class.php`,
`testcase.class.php`, `object.class.php`) were changed in the earlier commit
`eb5b4f8ef`; the remaining `builds.testplan_id` joins in `testplan.class.php`
(lines 4153–5677) and `tlTestPlanMetrics.class.php:2567` are explicitly
deferred to sub-task #835 (cross-plan per-build report) — not part of #830.

## Verification

- **DB/view:** `SHOW CREATE VIEW latest_exec_by_build` → exists, groups by
  `tcversion_id, build_id, platform_id`; registered at `object.class.php:341`.
- **Code grep:** no `builds.testplan_id` remains in `reports.class.php` or
  `testcase.class.php` (the #830 scope files).
- **Runtime (repro):** `php tmp/repro_830_verify.php` → 3/3 PASS; both plans of
  project 2000 correctly report the 2 project-scoped builds.
- **Event Viewer:** no new Error/Warning rows from the verified flows.

## Definition of Done — Confirmed

- reports resolve builds by project ✓
- `latest_exec_by_build` available to queries ✓
- no join reference dropped `testplan_id` column within the #830 scope files ✓
