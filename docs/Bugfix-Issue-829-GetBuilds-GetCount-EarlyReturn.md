# Bugfix — Issue #829: get_builds getCount early-return regression

## Summary

Two bugs in `testplan::get_builds()` caused the `getCount` path (used by
`planView`, `planEdit`, dashboard) to return `null` instead of build counts,
and the non-getCount path to query by the wrong column after the builds schema
migration landed `testproject_id`.

## Root Cause

1. **Hotfix regression (commit `eb4df8315`, Fixes #841):**
   `get_builds()` non-getCount branch and `getNumberOfBuilds()` were reverted
   from `testproject_id` to `testplan_id` because the `builds.testproject_id`
   column did not yet exist. After the schema migration (#834) added the column,
   the revert was never undone.

2. **Missing early return in getCount branch (original commit `5d52c08c8`):**
   The `getCount` branch set `$rs = $mapped` but did not return. Execution fell
   through to line 2348 (`fetchRowsIntoMap`), which overwrote `$rs` with a query
   on undefined `$sql`, `$accessField`, `$groupBy` — producing null/empty results
   and PHP warnings logged to the Event Viewer.

## Fix

**File:** `lib/functions/testplan.class.php`

| Line | Before | After |
|------|--------|-------|
| ~2319 | `}` (end of getCount branch, no return) | `return $rs;` (prevent fall-through) |
| ~2324 | `WHERE testplan_id = ...` | `WHERE testproject_id = ...` via `getProjectIdOfPlan()` |
| ~2433 | `WHERE builds.testplan_id = ...` | `WHERE builds.testproject_id = ...` via `getProjectIdOfPlan()` |

## Blast Radius

- `get_builds()` getCount branch (planView, planEdit, dashboard build counters)
- `get_builds()` non-getCount branch (build selection, build management)
- `getNumberOfBuilds()` (build counters in various views)

All other #829 fixes (get_builds_for_html_options, get_max_build_id,
get_build_by_name, get_build_by_id, check_build_name_existence,
get_build_id_by_name, tlTestPlanMetrics methods) were intact.

## Verification

- `php tmp/repro_829.php` → 12/12 PASS
- `php tmp/repro_829_nr.php` → 4/4 PASS
- Dashboard loads without error
- Plan View loads showing both plans with correct build counts
- Event Viewer: no new Error/Warning entries
