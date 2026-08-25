# Absolute Latest Execution Results — Modernized Screen

The **Absolute Latest Execution Results** report shows the most recent execution
status for each test case on a chosen platform, ignoring builds. It replaces the
legacy 1.9.20 `lib/results/resultsTCAbsoluteLatest.php` HTML view with a modern
Dashio-styled page backed by a plain-PHP REST BFF. The legacy XLS export button
is preserved as-is: it links to the same legacy controller endpoint for
spreadsheet generation.

**Path:** ASIDE menu → Reports → *Absolute Latest Execution Results*
**URL:** `gui/templates/results/absoluteLatest.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=absolute_latest_init` and
`GET ?action=absolute_latest_result`
**Rights:** `testplan_metrics` (same right the legacy controller enforces;
BFF returns 403 otherwise)
**Tracking issue:** [#686](https://github.com/sebiboga/testlink-upgraded/issues/686)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Screen Layout](#2-screen-layout)
3. [Platform Launcher](#3-platform-launcher)
4. [Data Flow and Parity](#4-data-flow-and-parity)
5. [Toolbar Actions](#5-toolbar-actions)
6. [i18n](#6-i18n)
7. [Permission Path and Empty State](#7-permission-path-and-empty-state)
8. [BFF API Reference](#8-bff-api-reference)
9. [Files](#9-files)

---

## 1. Overview

The report renders each test case as a single row, showing the absolute latest
execution status on the chosen platform. Builds are ignored — only the most
recent result per test case matters. Test cases with no executions display
"Not Run". A DataTable with search, pagination and column sorting is provided.

---

## 2. Screen Layout

| Element | Description |
|---------|-------------|
| **Header** | Teal banner with "Absolute Latest Execution Results" title, plan name, locale switcher |
| **Toolbar** | Test project name, XLS Export button, Refresh button |
| **Launcher** | Platform dropdown with Generate Report button (shown when no platform selected) |
| **Results** | DataTable with columns: Test Suite, Test Case, Priority (color-coded), Latest Execution (status badge), Latest Exec Notes |
| **Footer** | Generation timestamp and processing time |

Status badges: Passed (green), Failed (red), Blocked (yellow), Not Run (grey).
Priority badges: Highest (red), High (orange), Medium (amber), Low (grey).

---

## 3. Platform Launcher

On initial load, the BFF `absolute_latest_init` action returns the list of
platforms available for the test plan. The user selects a platform from the
dropdown and clicks "Generate Report" to load results.

- If no platforms exist, a warning message is displayed
- Validation prevents submitting without selecting a platform
- Refresh button always returns to the launcher state

---

## 4. Data Flow and Parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| `resultsTCAbsoluteLatest.php` loads via Smarty | `absoluteLatest.html` renders standalone HTML+JS |
| PHP template builds HTML table with inline styles | DataTables jQuery plugin renders the table |
| XLS export via `format=3` query param | Same XLS export URL (legacy controller), new tab |
| Platform selected via legacy form POST | Platform selected via dropdown + AJAX GET |
| Data from `tlTestPlanMetrics::getLatestExecOnSinglePlatformMatrix()` | Same backend call via BFF |
| `getNeverRunOnSinglePlatform()` fills Not Run rows | Same backend call via BFF (PHP 8.x `count(null)` guard) |

---

## 5. Toolbar Actions

| Button | Action |
|--------|--------|
| **Export as spreadsheet** | Opens legacy XLS export URL in new tab (hidden when no data) |
| **Refresh** | Reloads the platform launcher (clears results) |

---

## 6. i18n

All labels use the `alx.*` i18n key namespace. The screen uses the shared
`TLi18n` module for locale loading and switching. Keys are defined in all 10
locale bundles (`en.json`, `ro.json`, etc.).

**Note:** The DataTable search label reuses the existing `common.search` key
rather than a new `common.filter` key.

---

## 7. Permission Path and Empty State

**Rights check:** BFF validates `testplan_metrics` right before any data query.
Returns HTTP 403 with `{"status":"error","message":"Insufficient rights"}` if
the user lacks the right.

**Empty states:**
- No platforms configured → warning message in the launcher area
- No execution data on selected platform → empty box with "No execution data found"
- Legacy PHP 8.x `count(null)` crash in `getNeverRunOnSinglePlatform()` is
  guarded by an empty-array fallback in the BFF

---

## 8. BFF API Reference

### GET `?action=absolute_latest_init`

Returns platforms and context for the launcher.

**Parameters:** `tproject_id` (int), `tplan_id` (int)

**Response (200):**
```json
{
  "status": "ok",
  "tproject_id": 1,
  "tproject_name": "TestProject",
  "tplan_id": 2,
  "tplan_name": "TestPlan1",
  "platforms": [{"id": 1, "name": "Linux"}, {"id": 2, "name": "Windows"}]
}
```

### GET `?action=absolute_latest_result`

Returns the matrix of latest execution status per test case.

**Parameters:** `tproject_id` (int), `tplan_id` (int), `platform_id` (int)

**Response (200):**
```json
{
  "status": "ok",
  "tproject_id": 1,
  "tproject_name": "TestProject",
  "tplan_id": 2,
  "tplan_name": "TestPlan1",
  "platform_name": "Linux",
  "hasData": true,
  "priority_enabled": true,
  "rows": [
    {
      "tc_external_id": 1,
      "external_id": "TP-1",
      "tc_name": "TC - Valid Login",
      "version": "v1",
      "version_tag": " v1",
      "tsuite_name": "Login Suite",
      "priority_level": 3,
      "priority_label": "High",
      "status_code": "p",
      "status_label": "Passed",
      "exec_notes": ""
    }
  ],
  "export_xls_url": "lib/results/resultsTCAbsoluteLatest.php?format=3&doAction=result&tplan_id=2&tproject_id=1&platform_id=1",
  "elapsed_time": "0.01"
}
```

---

## 9. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/absoluteLatest.html` | Standalone HTML+JS+CSS screen |
| `api/reports/index.php` | BFF API — `absolute_latest_init` and `absolute_latest_result` actions |
| `lib/general/asideMenu.php` | ASIDE link switch to route to the modernized screen |
| `gui/templates/i18n/*.json` | i18n locale bundles (22 `alx.*` keys per bundle) |
| `lib/results/resultsTCAbsoluteLatest.php` | Legacy controller (XLS export still used) |
