# Results by Issues — Modernized Screen

The **Results by Issues** reports show test cases with linked bugs from the issue tracker. Two variants share one screen: "Latest Generation" (only latest executions) and "All Executions" (every execution with bugs). It replaces the legacy 1.9.20 `lib/results/resultsBugs.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Results / Issues by Test Plan* / *Results / Issues by Test Plan - All Builds*
**URL:** `gui/templates/results/resultsBugs.html?tproject_id=<id>&tplan_id=<id>&type=0|1`
**BFF API:** `api/reports/index.php` — `GET ?action=results_bugs&type=0|1`
**Rights:** `testplan_metrics` (same right the legacy controller enforces)
**Tracking issue:** [#763](https://github.com/sebiboga/testlink-upgraded/issues/763)

---

## 1. Overview

Two report variants share one screen:

| Variant | `type` param | Title | Behavior |
|---------|-------------|-------|----------|
| Latest Generation | `0` | Results / Issues by Test Plan | Shows only latest generation executions with bugs |
| All Executions | `1` | Results / Issues by Test Plan - All Builds | Shows all executions with bugs (may include duplicates) |

The "All Executions" variant shows a warning hint that some test cases may appear multiple times.

---

## 2. Screen Layout

| Element | Description |
|---------|-------------|
| **Header** | Teal banner with "Results by Issues" title, "for test plan" label, plan name, locale switcher |
| **Toolbar** | Test project name, report type dropdown (Latest Generation / All Executions) |
| **Summary cards** | Four cards: Open Bugs, Resolved Bugs, Total Bugs, TCs with Bugs |
| **Report body** | DataTable with Test Suite, Test Case, and Bugs columns; grouped by test suite |
| **Footer** | Generation timestamp, elapsed time |

---

## 3. Data Flow and Parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| `resultsBugs.php?type=0|1` loads via Smarty | `resultsBugs.html?type=0|1` renders standalone HTML+JS |
| PHP template builds HTML table | DataTables jQuery plugin renders the table |
| Data from `getLTCVNewGeneration()` (type=0) or `getAllExecutionsWithBugs()` (type=1) | Same backend calls via BFF |
| Bug links via `get_bugs_for_exec()` | Same backend call via BFF |
| ExtTable with group-by | DataTable with sorting, pagination, search |

---

## 4. Report Type Switcher

| Type | Description |
|------|-------------|
| **Latest Generation** (default) | Only shows test cases from their most recent execution — no duplicates |
| **All Executions** | Shows every execution that has linked bugs — test cases may appear multiple times across builds |

---

## 5. i18n

All labels use the `rb.*` i18n key namespace (14 keys per bundle). The screen uses the shared `TLi18n` module for locale loading and switching. Keys are defined in all 10 locale bundles (`en.json`, `ro.json`, etc.).

Column headers reuse existing i18n keys: `title_test_suite_name`, `title_test_case_title`, `title_test_case_bugs`.

---

## 6. BFF API Reference

### GET `?action=results_bugs`

Returns test cases with linked bugs for a test plan.

**Parameters:**
- `tproject_id` (int, required)
- `tplan_id` (int, required)
- `type` (int, optional): `0` for latest generation (default), `1` for all executions

**Response (200):**
```json
{
  "status": "ok",
  "tproject_id": 1,
  "tplan_id": 2,
  "tproject_name": "Test Project",
  "tplan_name": "Test Plan 1",
  "type": 0,
  "verbose_type": "latest",
  "title_key": "link_report_total_bugs",
  "bug_interface_on": true,
  "total_open_bugs": 5,
  "total_resolved_bugs": 3,
  "total_bugs": 8,
  "total_cases_with_bugs": 6,
  "rows": [
    {
      "tc_id": 10,
      "tc_name": "Login Test",
      "full_external_id": "TP-1",
      "tsuite_name": "Authentication",
      "external_id": 1,
      "bugs": [
        {
          "bug_id": "BUG-123",
          "link": "https://bts.example.com/BUG-123",
          "is_resolved": false,
          "build_name": "Build 1"
        }
      ]
    }
  ],
  "has_data": true,
  "elapsed_time": 0.01
}
```

---

## 7. Permission Path and Empty State

**Rights check:** BFF validates `testplan_metrics` right (global + contextual per-project/per-plan) before any data query.

**Empty states:**
- No test plan configured → HTTP 400 error
- No issue tracker enabled → "No linked bugs found for this report."
- Issue tracker enabled but no bugs found → Same empty state message

---

## 8. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/resultsBugs.html` | Standalone HTML+JS+CSS screen (~230 lines) |
| `api/reports/index.php` | BFF API — `results_bugs` action (lines ~3820–3971) |
| `lib/general/asideMenu.php` | ASIDE link switch for `link_report_total_bugs` / `link_report_total_bugs_all_exec` |
| `gui/templates/i18n/*.json` | i18n locale bundles (14 `rb.*` keys per bundle) |
| `lib/results/resultsBugs.php` | Legacy controller (still exists but no longer linked from ASIDE) |
