# Free Test Cases Report — Modernized Screen

The **Free Test Cases** report (ASIDE → Reports → *Test Cases not Assigned to Any Test Plan*) lists all test cases in the active test project that are NOT linked to any test plan — one row per test case, grouped by test suite. It replaces the legacy 1.9.20 `lib/results/freeTestCases.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Test Cases not Assigned to Any Test Plan*
**URL:** `gui/templates/results/freeTestCases.html?tproject_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=free_testcases&tproject_id=N`
**Rights:** `testplan_metrics`
**Tracking issue:** [#751](https://github.com/sebiboga/testlink-upgraded/issues/751)

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Data Flow and Parity](#2-data-flow-and-parity)
3. [i18n](#3-i18n)
4. [Permission Path and Empty State](#4-permission-path-and-empty-state)
5. [BFF API Reference](#5-bff-api-reference)
6. [Files](#6-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Test Cases Not Assigned to Any Test Plan — for test project X" with locale switcher |
| **Toolbar** | Dark bar: test project context, match count badge |
| **Report** | DataTable with columns: Test Suite (path), Test Case (external ID + name), Importance (conditional on priority setting) |
| **Empty state** | Info icon with localized message when all test cases are assigned or no free test cases exist |
| **Footer** | Elapsed time |

## 2. Data Flow and Parity

The BFF calls exactly the same engine method as the legacy controller:

1. `testproject::getFreeTestCases($tprojectId)` — fetches all test case IDs in the project, fetches those linked to any test plan, then computes the difference (free = all − linked). If no test plans exist (`allfree=true`), all test cases are returned.
2. Row post-processing: `tree::get_full_path_verbose()` resolves the test suite path; the full external ID is built from `prefix + glue + external_id`.
3. Columns match legacy: Test Suite, Test Case, Importance (shown only when priority management is enabled for the test project).
4. Table is grouped by test suite and sorted by importance (descending), same as legacy `tlExtTable` behavior.

## 3. i18n

7 `ftc.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh):

| Key | English |
|-----|---------|
| `ftc.header` | Test Cases Not Assigned to Any Test Plan |
| `ftc.forProject` | for test project |
| `ftc.testProject` | Test Project |
| `ftc.testCases` | test cases |
| `ftc.matchCount` | Match count |
| `ftc.allAssigned` | All test cases are assigned to a test plan. |
| `ftc.elapsedSeconds` | Elapsed seconds |

## 4. Permission Path and Empty State

- No session → 401; missing `testplan_metrics` on global role AND project context → 403
- All test cases assigned → info icon with `ftc.allAssigned` message
- No test plan exists (allfree) → data table displayed with all test cases

## 5. BFF API Reference

### `GET ?action=free_testcases&tproject_id=N`

Returns project-scoped free test cases.

```json
{
  "status": "ok",
  "tproject_id": 1,
  "tproject_name": "FTC Test Project",
  "priority_enabled": true,
  "all_free": true,
  "has_data": true,
  "rows": [
    {
      "tcase_id": 6,
      "tc_external_id": 1,
      "external_id": "FTC-1",
      "name": "TC Free 1",
      "suite_path": "Suite A",
      "importance": "high"
    }
  ],
  "warning_msg": "",
  "elapsed_time": 0.01
}
```

## 6. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/freeTestCases.html` | Modernized HTML/JS screen |
| `api/reports/index.php` | BFF API (`free_testcases` action) |
| `lib/general/asideMenu.php` | Aside link switched to `freeTestCases.html` for `link_report_free_testcases_on_testproject` |
| `gui/templates/i18n/{en,ro,de,fr,es,it,ja,pt,ru,zh}.json` | `ftc.*` i18n keys |
| `lib/results/freeTestCases.php` | Legacy controller (reference) |
| `lib/functions/testproject.class.php:2554` | `getFreeTestCases()` method |
