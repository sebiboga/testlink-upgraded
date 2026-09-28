# Issue #1698 — `executions.status` code outside the config became a bogus `""` metric

## Symptom

`tlTestPlanMetrics::getExecCountersByExecStatus()` resolved the
`executions.status` code → verbose status map **without a guard**, so any row whose status
code is not a key of `config results.status_code` (`cfg/const.inc.php:376`) was written under
the **empty-string array key `''`** and raised one `E_WARNING Undefined array key` per call.
The modern Metrics Dashboard BFF counts every key that is not `total`/`not_run` as *executed*,
so the bogus bucket inflated `executed`/`progress` while no status column could explain it —
a payload that contradicts itself, published with `200 {"status":"ok"}` and no user-visible error.

Measured pre-fix (project 1, plan 2, 8 active TCs, 4 executions of which 2 truncated to `''`):

```
getExecCountersByExecStatus() = {"total":8,"":"2","blocked":"1","failed":"1","not_run":"4"}
overall.statuses             = {"":2,"blocked":1,"failed":1,"not_run":4,"passed":0}   executed:4  progress:50%
events                       = E_WARNING Undefined array key "" - tlTestPlanMetrics.class.php - Line 1083
```

## Root cause chain

1. `lib/functions/tlTestPlanMetrics.class.php:1078` builds `array_flip($this->map_tc_status)`.
2. `:1083` `$statusCounters[$codeVerbose[$code]] = $elem['exec_qty'];` — `$code` is whatever the
   SQL `GROUP BY status` (`:1070-1073`) found in the DB, so an unknown code yields `null` → key `''`.
   The `=` also let two bad codes overwrite each other instead of aggregating.
3. `api/metrics/index.php:281-292` treats every non-`total`/`not_run` key as executed.
4. The same unguarded lookup existed on the platform branch (`api/metrics/index.php:248`).

## Fix

- **Metric layer** (`tlTestPlanMetrics.class.php:1077-1110`): an unknown code resolves to the
  **explicit `unknown` verbose status** (a real status in `$tlCfg->results['status_label']`, code `u`),
  accumulated with `+=` after an `isset()` init; `total` keeps counting every row so `active` still
  matches the plan's assigned TC count. The anonymous E_WARNING is replaced by **one aggregated**
  `logWarningEvent()` per call, naming the offending codes and quantities
  (`activity=EXEC_STATUS`, `object=testplans/<id>`).
- **BFF** (`api/metrics/index.php`): platform branch guarded identically; the project total for
  non-renderable rows is accumulated in `$unknownTotal` (`$total` has no `unknown` key until the
  display set gains it) and a per-plan `$planUnknown` feeds the PLAN level `overall.statuses`; the
  `unknown` status is appended to the payload's `status_set` **only** when some plan really reports
  such rows, so a healthy project keeps byte-identical output.
- **Client** (`gui/templates/results/metricsDashboard.html` + all 10 `gui/templates/i18n/*.json`):
  `STATUS_COLORS`/`STATUS_KEYS` gained `unknown` and one new key `md.statusUnknown` (legacy
  `$TLS_test_status_unknown` wording) — the grid and the progress bars are data-driven off
  `status_set`, so no restructure was needed.
- A code review of the first attempt added the case that the reviewer found: codes whose verbose
  status is **not** in `status_label_for_exec_ui` (`x` not_available, `a` all) are *also* not
  renderable, so they are collapsed into the same `unknown` counter. Before that, `x` still raised
  `E_WARNING Undefined array key "not_available"` at `api/metrics/index.php:276`.

## Post-fix result

```
status_set  = [not_run, passed, failed, blocked, unknown]
statuses    = {unknown:2, blocked:1, failed:1, not_run:4, passed:0}   executed:4   (sum 4 == executed 4)
progress bars: Overall 50% [4/8] · Not Run 50% · Passed 0% · Failed 12.5% · Blocked 12.5% · Unknown 25%
Event Viewer : one aggregated "METRICS: 1 execution status code(s) not defined in config
               results.status_code, counted as unknown on test plan 2: '' => 2" (activity EXEC_STATUS)
healthy data : identical payload to pre-fix, 0 new events rows
```

## Known follow-ups (measured, deliberately out of scope of this single-bug run)

- `lib/results/metricsDashboard.php:228-238` (legacy Smarty twin) has the same unguarded pattern.
- `getStatusForReports()` (`tlTestPlanMetrics.class.php:1337-1349,1372-1384`) builds its map from
  `status_label_for_exec_ui` only, so the per-platform report charts
  (`api/reports/index.php?action=charts_data`) still drop such a row while
  `helperCompleteStatusDomain()` counts it in `total`.
- `lib/results/overallPieChart.php:39-47` iterates `$series_color` without initialising it — a
  result set whose statuses all lack a chart colour raises
  `foreach() argument must be of type array|object, null given`.
