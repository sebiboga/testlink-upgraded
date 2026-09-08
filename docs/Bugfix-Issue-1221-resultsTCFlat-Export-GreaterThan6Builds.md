# Issue 1221 — resultsTCFlat: Export as spreadsheet broken on plans with >6 active builds

**Issue:** [#1221](https://github.com/sebiboga/testlink-upgraded/issues/1221)
**Tracking:** [#1223](https://github.com/sebiboga/testlink-upgraded/issues/1223)
**Branch:** `sebiboga`
**Status:** VERIFIED-FIXED (2026-09-08)

## Symptom

On the modernized **Test Results Flat** screen
(`gui/templates/results/resultsTCFlat.html`, BFF `api/reports index.php action=results_flat`),
clicking **Export as spreadsheet** on a test plan with **more than 6 active builds**
(`resultMatrixReport.buildQtyLimit` default 6) downloaded an HTML launcher page instead of a
real XLS spreadsheet.

- Verified live: plan **RTCF8/Plan8** with 8 active builds → export returns `Content-Type
  text/html`, body = launcher markup.
- Same export URL with only 3 active builds → `Content-Type application/vnd.ms-excel`
  (valid XLS).

The **Send by email** path (`results_tc_flat_mail`) had the same defect.

## Repro steps

1. Create a project with a plan holding **7+ active builds** (fixture: `php
   tmp/fixtures_1223.php` → project RTCF8, plan Plan8 with 8 active builds B1..B8, 2 test
   cases, executions on every build).
2. Open `gui/templates/results/resultsTCFlat.html?tproject_id=1&tplan_id=9`; the launcher
   warns `Too many active builds. Please select which builds to include. (8 > 6)`.
3. Pick any build subset (e.g. B1+B2) and Apply Filter.
4. Click **Export as spreadsheet** → an HTML launcher page downloads instead of an `.xls`.

**Expected:** a real subgrouped XLS for exactly the selected builds.

**Actual (pre-fix):** the legacy controller returns its launcher HTML.

## Root cause

`api/reportsexport/index.php` (the report-export gateway) maps the screen's export action
to the legacy controller and forwards a whitelist of query parameters. The `results_tc_flat`
entry forwarded `format=FORMAT_XLS` + `exportSpreadSheet_x=1` + `build_set[]` but **not**
`do_action=result`. The legacy controller's guard at `lib/results/resultsTCFlat.php:42-43`
is:

```php
if( ($gui->activeBuildsQty <= $gui->matrixCfg->buildQtyLimit) || $args->do_action == 'result')
```

With 8 > 6 active builds and no `do_action=result`, the legacy controller enters its
**launcher branch** and renders the build-picker HTML page instead of generating the XLS.
The modern screen's own launcher posts `do_action=result` + `build_set[]` (matching the
legacy form), but the export gateway dropped that field.

## Fix

In `api/reportsexport/index.php`:

1. Add `'do_action' => 'result'` to the `results_tc_flat` and `results_tc_flat_mail`
   export-map entries — mirroring `results_matrix` / `assigned_tc_overview`, which already
   pass `doAction=result` for `resultsTC.php`.
2. Forward the `buildListForExcel` parameter (comma-separated build ids) through the
   gateway, alongside the existing `build_set[]` forwarding, so the comma-separated variant
   the modern screen builds in the export URL reaches the legacy controller too.

```php
'results_tc_flat' => [
    'file' => '/lib/results/resultsTCFlat.php',
    'params' => ['format' => FORMAT_XLS, 'do_action' => 'result',
                  'exportSpreadSheet_x' => '1'],
],
'results_tc_flat_mail' => [
    'file' => '/lib/results/resultsTCFlat.php',
    'params' => ['format' => FORMAT_MAIL_HTML, 'do_action' => 'result',
                  'sendSpreadSheetByMail_x' => '1'],
],
```

### Why this method

This is exactly what the launcher form already submits; the gateway simply transmits the
same contract to the legacy exporter. Passing nothing extra avoids changing the XLS shape —
the exporter still honours `build_set`/`buildListForExcel` for the subgrouping.

## Files changed

- `api/reportsexport/index.php` — `results_tc_flat` + `results_tc_flat_mail` entries gain
  `do_action=result`; gateway forwards `buildListForExcel`.
- `tmp/fixtures_1223.php` — new idempotent fixture (8-build plan + executions).

## Verification

Regression suite **1221.1–1221.9** (9 cases, 9/9 PASS) executed in the browser + curl
against `tmp/fixtures_1223.php` on a fresh install:

| Case | Result |
|------|--------|
| Launcher shown for >6 active builds (warning `(8 > 6)`, B1..B8, Apply Filter) | PASS |
| Apply B1+B2 → DataTable renders 4 rows (TC-ok×B1,B2 + TC-ko×B1,B2, Passed/Failed) | PASS |
| Export URL carries the build subset (`buildListForExcel=1,2&build_set[]=1&build_set[]=2`) | PASS |
| Gateway redirect adds `do_action=result` (`...?format=3&do_action=result&exportSpreadSheet_x=1...`) | PASS |
| Export returns real XLS: `Content-Type application/vnd.ms-excel; name=resultsTCFlat_RTCF8_Plan8.xls`, OLE `Composite Document File V2`, NOT HTML | PASS |
| 3-build subset (3,4,5) exports valid XLS | PASS |
| Mail variant (`results_tc_flat_mail`) redirect includes `do_action=result` | PASS |
| Event Viewer: zero new Error/Warning rows | PASS |
| Browser console clean (no JS Error/Warning) | PASS |

Screenshots: TODO — MCP screenshot timed out on the run (launcher + filtered-data views);
page state verified via a11y snapshot. `docs/screenshots/` placeholder.

Commits: `0746160f4` (fix), `f92a03931` (suite + fixture). Refs #1223, Fixes #1221.