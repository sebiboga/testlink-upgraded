# Results by Status — Modernized Screen

The **Results by Status** reports show test cases filtered by their latest execution status: Failed, Blocked, or Not Run. A single modernized screen covers all three variants, selected via the `status` query parameter. It replaces the legacy 1.9.20 `lib/results/resultsByStatus.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF. The legacy XLS export and email buttons are preserved as-is.

**Path:** ASIDE menu → Reports → *Failed Test Cases* / *Blocked Test Cases* / *Not Run Test Cases*
**URL:** `gui/templates/results/resultsByStatus.html?tproject_id=<id>&tplan_id=<id>&status=failed|blocked|not_run`
**BFF API:** `api/reports/index.php` — `GET ?action=by_status&status=failed|blocked|not_run`
**Rights:** `testplan_metrics` (same right the legacy controller enforces)
**Tracking issue:** [#687](https://github.com/sebiboga/testlink-upgraded/issues/687)

---

## 1. Overview

Three report variants share one screen:

| Variant | `status` param | Title | Columns |
|---------|---------------|-------|---------|
| Failed | `failed` | Failed Test Cases | Test Suite, Test Case Title, Version, Platform, Build, Run By, Date, Execution Notes, (Bugs) |
| Blocked | `blocked` | Blocked Test Cases | Same as Failed |
| Not Run | `not_run` | Not Run Test Cases | Test Suite, Test Case Title, Version, Platform, Build, Assigned To, Summary |

The "Not Run" variant uses different columns (Assigned To + Summary instead of Run By + Date + Notes) matching legacy behavior. The screen dynamically adjusts its column set and row rendering based on the `status` parameter.

---

## 2. Screen Layout

| Element | Description |
|---------|-------------|
| **Header** | Teal banner with "Results by Status" title, "for test plan" label, plan name, locale switcher |
| **Toolbar** | Test project name, XLS Export button, Send Email button, Refresh button |
| **Report body** | DataTable with variant-specific columns; section head shows title + record count badge |
| **Bugs counter** | Shown above table when bug tracking is enabled and there are TCs without bugs (not shown for not_run) |
| **Footer** | Report context info, scope description, generation timestamp |

---

## 3. Data Flow and Parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| `resultsByStatus.php?type=f|b|n` loads via Smarty | `resultsByStatus.html?status=failed|blocked|not_run` renders standalone HTML+JS |
| PHP template builds HTML table | DataTables jQuery plugin renders the table |
| XLS export via `format=3` query param | Same XLS export URL (legacy controller), new tab |
| Email via `format=6` query param | Same email URL (legacy controller), new tab |
| Data from `tlTestPlanMetrics::getExecutionsByStatus()` | Same backend call via BFF |
| Not Run data from `getNotRunWithTesterAssigned()` | Same backend call via BFF |

---

## 4. Toolbar Actions

| Button | Action |
|--------|--------|
| **Export as spreadsheet** | Opens legacy XLS export URL in new tab (hidden when no data) |
| **Send spreadsheet by email** | Opens legacy email export URL in new tab (hidden when no data) |
| **Refresh** | Re-fetches the BFF data for the current status variant |

---

## 5. i18n

All labels use the `rbsf.*` i18n key namespace (20 keys per bundle). The screen uses the shared `TLi18n` module for locale loading and switching. Keys are defined in all 10 locale bundles (`en.json`, `ro.json`, etc.).

Column headers reuse existing i18n keys where available: `title_test_suite_name`, `title_test_case_title`, `version`, `platform`, `th_build`, `th_run_by`, `th_date`, `title_execution_notes`, `assigned_to`, `summary`, `th_bugs_id_summary`.

---

## 6. BFF API Reference

### GET `?action=by_status`

Returns test cases filtered by latest execution status.

**Parameters:**
- `tproject_id` (int, required)
- `tplan_id` (int, required)
- `status` (string, required): `failed`, `blocked`, or `not_run`

**Response (200):**
```json
{
  "status": "ok",
  "tproject_id": 2,
  "tproject_name": "TestProj",
  "tplan_id": 5,
  "tplan_name": "TestPlan1",
  "status_type": "failed",
  "title": "Failed Test Cases",
  "warning_msg": "",
  "info_msg": "This report shows all test cases for each build...",
  "report_context": "Attention: This report considers ONLY test cases with tester assignment",
  "hasData": true,
  "show_platforms": true,
  "bug_interface_on": false,
  "without_bugs_counter": 0,
  "rows": [
    {
      "suite_name": "TC-FAILED",
      "test_title": "RBS-1:TC-FAILED",
      "testcase_id": 22,
      "tcversion_id": 23,
      "executions_id": 1,
      "build_id": 1,
      "platform_id": 0,
      "tcase_id": 22,
      "version": "1",
      "platform": "",
      "build": "Build1",
      "tester": "Testlink Administrator",
      "execution_ts": "2026-08-25 10:16:56",
      "notes": "Failed execution"
    }
  ],
  "export_xls_url": "/lib/results/resultsByStatus.php?type=f&format=3&tplan_id=5&tproject_id=2",
  "send_mail_url": "/lib/results/resultsByStatus.php?type=f&format=6&tplan_id=5&tproject_id=2",
  "elapsed_time": 0.01
}
```

For `not_run`, rows include `tester` (assigned user) and `notes` (summary text) instead of `execution_ts`.

---

## 7. Permission Path and Empty State

**Rights check:** BFF validates `testplan_metrics` right before any data query.

**Empty states:**
- No data matching the status filter → info box with the legacy info message
- `hasData: false` → "No data available" message

---

## 8. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/resultsByStatus.html` | Standalone HTML+JS+CSS screen (302 lines) |
| `api/reports/index.php` | BFF API — `by_status` action (lines ~2078–2353) |
| `lib/general/asideMenu.php` | ASIDE link switch for failed/blocked/not_run variants |
| `gui/templates/i18n/*.json` | i18n locale bundles (20 `rbsf.*` keys per bundle) |
| `lib/results/resultsByStatus.php` | Legacy controller (XLS/email export still used) |
