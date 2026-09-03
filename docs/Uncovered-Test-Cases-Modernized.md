# Uncovered Test Cases Report — Modernized Screen

The **Uncovered Test Cases** report (ASIDE → Reports → *Test Cases without Requirements Assignment*) lists all test cases in the test project that have **no requirement assigned** (no row in `req_coverage`), one row per test case, grouped by test suite. It replaces the legacy 1.9.20 `lib/results/uncoveredTestCases.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF. This closes the last report screen that had no modernized equivalent. *(Screenshots for this screen live in the GitHub Wiki page — see `Uncovered-Test-Cases-Modernized`.)*

**Path:** ASIDE menu → Reports → *Test Cases without Requirements Assignment* (shown only when the project has requirements enabled and a test plan is selected)
**URL:** `gui/templates/results/uncoveredTestCases.html?tproject_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=uncovered_testcases&tproject_id=N`
**Rights:** `testplan_metrics`
**Tracking issue:** [#843](https://github.com/sebiboga/testlink-upgraded/issues/843)

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Data Flow and Parity](#2-data-flow-and-parity)
3. [i18n](#3-i18n)
4. [Permission Path and Empty States](#4-permission-path-and-empty-states)
5. [BFF API Reference](#5-bff-api-reference)
6. [Files](#6-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Test Cases without Requirements Assignment — for test project X" with locale switcher |
| **Toolbar** | Dark bar: test project context, test-case count badge |
| **Report** | DataTable with columns: Test Suite, Test Case (external ID + name), grouped by suite |
| **Empty state** | Info/check icon with a localized message (no req spec / no requirements / all covered) |
| **Footer** | Elapsed time |

## 2. Data Flow and Parity

The BFF mirrors the legacy `lib/results/uncoveredTestCases.php` SQL step by step:

1. `testproject::genComboReqSpec($tprojectId)` — are any requirement specs defined? No → `has_reqspec=false` → "There are no Requirement Spec. defined".
2. `requirement_spec_mgr::get_requirements_count()` per spec — does any spec hold requirements? No → `has_requirements=false` → "There are no Requirements defined".
3. `testproject::get_all_testcases_id($tprojectId)` — all test case ids in the project, then a LEFT OUTER JOIN to `req_coverage` WHERE `req_id IS NULL` → test cases with no assigned requirement.
4. External ids joined from `tcversions` (via the tcversion node), full external id built as `prefix + glue + tc_external_id` (`getTestCasePrefix`), matching legacy.
5. Grouped by test suite (suite name → its test cases), sorted by suite id (legacy `gen_spec_view` ordering). No rows → `has_data=false` → "All Test Cases are covered".

**Note (latent bug fixed):** `get_all_testcases_id($tprojectId, $tcasesID)` with the default `just_id` mode returns a **plain list of ids**, not a map — so the BFF builds the `IN (...)` clause with `implode(',', array_map('intval', (array)$tcasesID))`, not `array_keys()`.

## 3. i18n

9 `unc.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh):

| Key | English |
|-----|---------|
| `unc.header` | Test Cases without Requirements Assignment |
| `unc.forProject` | for test project |
| `unc.testProject` | Test Project |
| `unc.testCases` | test cases |
| `unc.matchCount` | Match count |
| `unc.noReqSpec` | There are no Requirement Spec. defined |
| `unc.noRequirements` | There are no Requirements defined |
| `unc.allCovered` | All Test Cases are covered |
| `unc.elapsedSeconds` | Elapsed seconds |

## 4. Permission Path and Empty States

- No session → 401; missing `testplan_metrics` (global role) → 403
- `has_reqspec=false` → info icon + `unc.noReqSpec`
- `has_requirements=false` → info icon + `unc.noRequirements`
- `has_data=false` → check icon + `unc.allCovered` (also the DataTable `emptyTable` message)
- Normal → DataTable grouped by suite

## 5. BFF API Reference

### `GET ?action=uncovered_testcases&tproject_id=N`

Returns project-scoped test cases with no requirement assignment.

```json
{
  "status": "ok",
  "tproject_id": 1,
  "tproject_name": "Uncovered Demo Project",
  "has_reqspec": true,
  "has_requirements": true,
  "has_data": true,
  "suites": [
    {
      "suite_id": 4,
      "suite_name": "Suite A",
      "tc_count": 1,
      "testcases": [
        { "tc_id": 8, "external_id": "UCD-2", "name": "Uncovered TC" }
      ]
    }
  ],
  "elapsed_time": 0.01
}
```

## 6. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/uncoveredTestCases.html` | Modernized HTML/JS screen (Dashio + DataTable + TLi18n) |
| `api/reports/index.php` | BFF API (`uncovered_testcases` action) |
| `cfg/reports.cfg.php` | Re-enabled `uncovered_testcases` entry (`enabled='req'`) |
| `lib/general/asideMenu.php` | Aside link (`link_report_uncovered_testcases`) → `uncoveredTestCases.html` |
| `gui/templates/i18n/{en,ro,de,fr,es,it,ja,pt,ru,zh}.json` | `unc.*` i18n keys |
| `lib/results/uncoveredTestCases.php` | Legacy controller (reference) |
| `lib/functions/testproject.class.php` | `get_all_testcases_id()` (plain list of ids) |
| `lib/functions/requirement_spec_mgr.class.php` | `get_requirements_count()` |
