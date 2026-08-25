# Test Cases Never Run — Modernized Screen

The **Test Cases Never Run** report (ASIDE → Reports → *Test Cases Never Run*) lists all test cases in the active test plan that have never been executed — one row per test case, optionally grouped by platform. It replaces the legacy 1.9.20 `lib/results/neverRunByPP.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF. The legacy "export as spreadsheet" and "send by email" actions are preserved as-is: their buttons link to the same legacy controller endpoints.

**Path:** ASIDE menu → Reports → *Test Cases Never Run*
**URL:** `gui/templates/results/neverRun.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=never_run_init` and `GET ?action=never_run_result`
**Rights:** `testplan_metrics`
**Tracking issue:** [#688](https://github.com/sebiboga/testlink-upgraded/issues/688)

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Data Flow and Parity](#2-data-flow-and-parity)
3. [Toolbar Actions (legacy parity)](#3-toolbar-actions-legacy-parity)
4. [i18n](#4-i18n)
5. [Permission Path and Empty State](#5-permission-path-and-empty-state)
6. [BFF API Reference](#6-bff-api-reference)
7. [Files](#7-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Test Cases Never Run — for test plan X" with locale switcher |
| **Toolbar** | Dark bar: test project context, *Export as spreadsheet* (legacy link), *Send spreadsheet by email* (legacy link), *Refresh* |
| **Launcher** | Platform selector (multi-select) shown only when the plan has platforms; *Generate Report* button |
| **Report** | DataTable with columns: Test Case Title (full external ID + name), Platform (if plan has platforms) |
| **Empty state** | Info icon with localized message when all test cases have been executed |
| **Footer** | Info description, generation timestamp, elapsed time |

## 2. Data Flow and Parity

The BFF calls exactly the same engine method as the legacy controller:

1. `tlTestPlanMetrics::getNeverRunByPlatform($tplanId, $platformSet)` — performs a LEFT JOIN from `testplan_tcversions × builds` to `executions`, filtering for NULL execution rows, then `HAVING COUNT(0) = count($buildSet)` ensures the TC was never executed on ANY build. Returns one row per never-run TC per platform.
2. Row post-processing: `testcase::getPathLayered()` resolves the test suite path; the full external ID is built from `prefix + glue + external_id`.
3. Platform filter: optional `platSet[]` parameter narrows results to selected platforms; when none selected, all platforms are included (matches legacy behavior).
4. When the plan has no platforms, the `JOIN platforms PLAT` in the legacy query naturally filters results — the screen shows an empty platform selector and the report is generated for all (nonexistent) platforms.

## 3. Toolbar Actions (legacy parity)

| Action | Behavior |
|--------|----------|
| **Export as spreadsheet** | Links to `lib/results/neverRunByPP.php?format=3&tplan_id=&tproject_id=&doAction=result` with optional `platSet[]` appended |
| **Send spreadsheet by email** | Links to `lib/results/neverRunByPP.php?format=6&tplan_id=&tproject_id=&doAction=result` with optional `platSet[]` appended |

Both URLs are updated dynamically after Generate when platform filters are selected.

## 4. i18n

11 `nr.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh):

| Key | English |
|-----|---------|
| `nr.header` | Test Cases Never Run |
| `nr.forPlan` | for test plan |
| `nr.testProject` | Test Project |
| `nr.btnExportXls` | Export as spreadsheet |
| `nr.btnSendEmail` | Send spreadsheet by email |
| `nr.selectPlatforms` | Select platforms to include |
| `nr.btnGenerate` | Generate Report |
| `nr.noNeverRun` | All test cases have been executed at least once. |

## 5. Permission Path and Empty State

- No session → 401; missing `testplan_metrics` on global role AND project context → 403
- Empty results → info icon with `nr.noNeverRun` message

## 6. BFF API Reference

### `GET ?action=never_run_init&tproject_id=N&tplan_id=M`

Returns context (project/plan names), platform list, export URLs.

```json
{
  "status": "ok",
  "hasContext": true,
  "tproject_id": 1, "tplan_id": 25,
  "tproject_name": "NeverRunTest", "tplan_name": "TP1",
  "show_platforms": true,
  "platforms": [{"id": 34, "name": "Platform1"}],
  "export_xls_url": "/lib/results/neverRunByPP.php?format=3&...",
  "send_mail_url": "/lib/results/neverRunByPP.php?format=6&..."
}
```

### `GET ?action=never_run_result&tproject_id=N&tplan_id=M&platSet[]=34`

Returns data rows.

```json
{
  "status": "ok",
  "hasData": true,
  "show_platforms": true,
  "info_msg": "This report shows all test cases not executed...",
  "rows": [
    {"suite_name": "Test Suite 1", "test_title": "NR-NR-2:TC2", "tcase_id": 5, "platform_name": "Platform1"},
    {"suite_name": "Test Suite 1", "test_title": "NR-NR-3:TC3", "tcase_id": 7, "platform_name": "Platform1"}
  ],
  "export_xls_url": "...", "send_mail_url": "...",
  "elapsed_time": 0.01
}
```

## 7. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/neverRun.html` | Modernized HTML/JS screen |
| `api/reports/index.php` | BFF API (`never_run_init`, `never_run_result` actions) |
| `lib/general/asideMenu.php` | Aside link switched to `neverRun.html` for `link_report_never_run` |
| `gui/templates/i18n/{en,ro,de,fr,es,it,ja,pt,ru,zh}.json` | `nr.*` i18n keys |
| `lib/results/neverRunByPP.php` | Legacy controller (reference; export/email endpoints) |
| `lib/functions/tlTestPlanMetrics.class.php:3052` | `getNeverRunByPlatform()` method |
