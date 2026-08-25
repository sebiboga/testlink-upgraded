# Test Cases Never Run — Modernized Screen

The Test Cases Never Run report shows test cases that were never executed across
ALL ACTIVE BUILDS for a given test plan, optionally filtered by platform. It
replaces the legacy 1.9.20 `lib/results/neverRunByPP.php` HTML view with a
modern Dashio-styled page backed by a plain-PHP REST BFF. The legacy "export as
spreadsheet" action is preserved: the button links to the same legacy controller
endpoint (XLS generation stays in `lib/results/neverRunByPP.php`).

**Path:** ASIDE menu → Reports → Test Cases Never Run
**URL:** `gui/templates/results/neverRun.html?tproject_id=<id>&tplan_id=<id>` (loaded inside the main frame)
**BFF API:** `api/reports/index.php` — `GET ?action=never_run_init` and `GET ?action=never_run_result`
**Rights:** `testplan_metrics` (same right the legacy controller enforces via `checkRights()`; BFF returns 403 otherwise)
**Tracking issue:** #688

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Platform Launcher](#2-platform-launcher)
3. [Report Results](#3-report-results)
4. [Toolbar Actions](#4-toolbar-actions)
5. [i18n](#5-i18n)
6. [Permission Path and Empty State](#6-permission-path-and-empty-state)
7. [BFF API Reference](#7-bff-api-reference)
8. [Files](#8-files)

---

## 1. Screen Layout

The screen follows the same Dashio layout as all modernized report screens:

- **Header bar** (teal): screen title ("Test Cases Never Run"), "for test plan" subtitle
  with plan name, and locale switcher
- **Toolbar** (dark): project name context, export link, email link, refresh button
- **Launcher box** (shown initially): platform multi-select and "Generate Report" button
- **Report area**: DataTable with results (shown after generation)
- **Footer**: generation timestamp and elapsed time

## 2. Platform Launcher

When the test plan has linked platforms, the user sees a multi-select listbox
with all available platforms. The user can select any subset (or all) to filter
the never-run results.

If the test plan has no linked platforms (or only an "Any Platform" sentinel),
the platform section is hidden and the report runs without platform filtering.

## 3. Report Results

After clicking "Generate Report", the BFF calls
`tlTestPlanMetrics::getNeverRunByPlatform()` which identifies test cases that
have zero executions across all active builds for the given platform set.

The results DataTable shows:
- **Test Case**: external ID + name (e.g. "TNR-1:TC NeverRun 1")
- **Platform**: platform name (only shown when platforms are linked)

The table supports DataTables features: search, pagination (25 per page default),
column sorting.

## 4. Toolbar Actions

| Action | Description |
|--------|------------|
| Export as spreadsheet | Links to legacy `neverRunByPP.php?format=3` (XLS download via PhpSpreadsheet) |
| Send spreadsheet by email | Reserved for future use (not shown if no email configured) |
| Refresh | Returns to the platform launcher to change filters and regenerate |

## 5. i18n

All user-facing strings use the `TLi18n` client-side module with keys in the
`nr.*` namespace:

| Key | English value |
|-----|-------------|
| `nr.header` | Test Cases Never Run |
| `nr.forPlan` | for test plan |
| `nr.testProject` | Test Project |
| `nr.btnExportXls` | Export as spreadsheet |
| `nr.btnSendEmail` | Send spreadsheet by email |
| `nr.selectPlatforms` | Select platforms to include |
| `nr.btnGenerate` | Generate Report |
| `nr.noNeverRun` | All test cases have been executed at least once. |
| `nr.elapsedSeconds` | Elapsed |

Keys are present in ALL locale bundles (en.json, ro.json, de.json, fr.json, es.json,
pt.json, it.json, ja.json, zh.json, ru.json).

## 6. Permission Path and Empty State

**Rights gate:** The BFF checks `$user->hasRight($db, 'testplan_metrics', $tprojId, $tplanId)`.
Users without this right receive HTTP 403.

**Empty state:** When all test cases have been executed at least once, the BFF
returns `hasData=false` and the screen shows the `nr.noNeverRun` message.

## 7. BFF API Reference

### `GET ?action=never_run_init`

Returns test plan context, platform list, and export URLs.

```json
{
  "status": "ok",
  "tproject_id": 1,
  "tplan_id": 3,
  "tproject_name": "TestNR",
  "tplan_name": "TestPlan NeverRun",
  "show_platforms": true,
  "platforms": [
    {"id": 5, "name": "Platform A"},
    {"id": 6, "name": "Platform B"}
  ],
  "export_xls_url": "/lib/results/neverRunByPP.php?format=3&...",
  "send_mail_url": "",
  "elapsed_time": 0.01
}
```

### `GET ?action=never_run_result`

Returns the never-run test cases.

Parameters: `tproject_id`, `tplan_id`, `platSet[]` (optional array of platform IDs)

```json
{
  "status": "ok",
  "hasData": true,
  "tproject_id": 1,
  "tplan_id": 3,
  "tproject_name": "TestNR",
  "tplan_name": "TestPlan NeverRun",
  "show_platforms": true,
  "rows": [
    {
      "tcase_id": 11,
      "test_title": "TNR-1:TC NeverRun 1",
      "test_title_plain": "TNR-1:TC NeverRun 1",
      "platform_name": "Platform A",
      "platform_id": 5
    }
  ],
  "info_msg": "",
  "export_xls_url": "/lib/results/neverRunByPP.php?format=3&...",
  "send_mail_url": "",
  "elapsed_time": 0.01
}
```

## 8. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/neverRun.html` | Modernized HTML/JS/CSS screen |
| `api/reports/index.php` | BFF API — `never_run_init` and `never_run_result` actions |
| `lib/general/asideMenu.php` | Aside link switched to modernized screen |
| `gui/templates/i18n/*.json` | i18n keys (`nr.*` namespace) in all locale bundles |
| `lib/results/neverRunByPP.php` | Legacy controller (still used for XLS export) |
| `lib/functions/tlTestPlanMetrics.class.php` | `getNeverRunByPlatform()` — core query |
