# Issue 1115 — Custom field values collapse when a plan has multiple custom fields (planView)

**Issue:** [#1115](https://github.com/sebiboga/testlink-upgraded/issues/1115)
**Branch:** `fix/issue-1115`
**Status:** VERIFIED-FIXED (2026-09-07)

## Symptom

On the modernized screen `gui/templates/plans/planView.html` (Test Plan Management list,
BFF `api/plans/index.php` list action), when a test plan has **more than one** custom-field
value linked at testplan-design time (`cfield_testplan_design_values`), only the **LAST**
field's value is displayed. The other CF columns render blank. The column headers are all
present — only the cells are empty.

Example: a plan with `release_tag = 'v1.5.0'` (string) and `signoff_date = '2026-09-01'`
(date) shows `2026-09-01` in the Signoff Date column and an EMPTY Release Tag column.

## Repro steps

1. Log in as admin; open `gui/templates/plans/planView.html?tproject_id=1` for a project
   where two active CFs are linked at testplan-design (`enable_on_testplan_design=1`,
   `cfield_testprojects.active=1`) and a plan has `cfield_testplan_design_values` rows for
   both fields.
2. Inspect the plan's row: the string CF column is empty; only the date CF value shows.

**Expected:** every CF column is populated with its own value.

**Actual (pre-fix):** only the last value per plan survives; the earlier ones are dropped
silently at the API level (no error, no warning).

## Root cause

`api/plans/index.php`, CF batch-fetch block (lines ~228-229):

```php
$cfRows = $db->fetchRowsIntoMap($sql, 'plan_id');
```

`database::fetchRowsIntoMap()` (`lib/functions/database.class.php:655-702`) with the default
`$cumulative = 0` runs `$items[$row[$column]] = $row;` for every row, so each new row for the
same plan **overwrites** the previous one. The batch SQL intentionally returns **one row per
(plan, field)** — with N fields per plan, N-1 rows are silently lost. The surviving row is
the last one the DB returned (in our fixture, `signoff_date`), so all earlier CF columns
render blank.

This is a refactor regression: the legacy screen `lib/plan/planView.php:75` fetched CF values
**per plan** via `getCustomFieldsValues($idk, ...)` which retains every field; the
modernization (refs #584) batched the query into one round-trip and collapsed the map.

Blast radius: only the `api/plans/index.php` list action. The same SQL shape has no other
caller (grep: only this API references `cfield_testplan_design_values` in the batch pattern).
The front-end (`planView.html`) needs no change — it renders a column for every
`cfields_columns` entry and already tolerates a missing key.

## Fix

Enable the cumulative branch of `fetchRowsIntoMap()` and iterate the resulting per-plan row
LIST:

```php
$cfRows = $db->fetchRowsIntoMap($sql, 'plan_id', 1);
if (!is_null($cfRows)) {
    foreach ($cfRows as $planId => $rowsByPlan) {
        foreach ($rowsByPlan as $row) {
            $cfValues[intval($planId)][$row['name']] =
                $row['value'] ?? '';
        }
    }
}
```

With `$cumulative = 1` the method returns `plan_id => [row0, row1, ...]`
(`database.class.php:684-687`), so every `(plan_id, field_name)` value lands in
`$cfValues[$planId][$name]`. A duplicated field name keeps its last occurrence — identical
to the pre-fix per-key semantics.

### Why this method

`fetchRowsIntoMap(..., 1)` is the same helper already used across the codebase for
one-to-many fetches — no new query, no new table scan, no other file touched. The alternative
`fetchArrayRowsIntoMap()` returns a numerically-keyed map and would need an access-key change;
the cumulative form keeps the `plan_id` key semantics. Memory cost is marginal (rows are
bounded by plans × visible CF columns).

## Files changed

- `api/plans/index.php` — list action, CF batch-fetch block (lines 228-239): cumulative fetch
  + nested loop.

## Verification

Regression suite **1115.1–1115.7** (7 cases, 7/7 PASS) executed in the browser against
fixtures on a fresh empty install (project 1 "CF Proj", plans 2 "CF Plan" [2 CFs] /
3 "Plan Solo" [1 CF] / 4 "Plan None" [0 CFs] / 5 "Plan Dual2" [2 CFs, distinct values]):

| Case | Result |
|------|--------|
| PRIMARY — multi-CF plan shows ALL CF values (v1.5.0 AND 2026-09-01) | PASS |
| API response retains every (plan, field) value (`cf` = both keys) | PASS |
| Single-CF plan still populated (solo-tag) | PASS |
| No-CF plan renders blank, no error | PASS |
| Multi-plan multi-CF — no cross-plan bleed (v2.0.0/2026-10-01 vs v1.5.0/2026-09-01) | PASS |
| Event Viewer — no new Error/Warning rows | PASS |
| Browser console — no JS Error/Warning | PASS |

Screenshot: `docs/screenshots/issue-1115-planview-multiple-cf-values-fixed.png`.

Commits on `fix/issue-1115`: `03488ed26` (fix), `e1f6952d8` (regression suite). Refs #1115.