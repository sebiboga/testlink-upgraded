# Test Plan with Custom Fields Report — Modernized Screen

Modernization of **Test Plan with Custom Fields** (`lib/results/testPlanWithCF.php`)
— GitHub issue [#739](https://github.com/sebiboga/testlink-upgraded/issues/739).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/results/tplanWithCF.html`) backed by a plain-PHP REST BFF
action inside `api/reports/index.php` (`action=tplan_with_cf`). The ASIDE
**Reports → Test Plan with Custom Fields** entry now points to the new HTML
screen when a test plan is selected.

**Path:** Reports → Test Plan with Custom Fields
**URL:** `gui/templates/results/tplanWithCF.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php?action=tplan_with_cf&tproject_id=<id>&tplan_id=<id>`
**Right:** `testplan_metrics` enforced server-side (same as legacy).

---

## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Page load | `testPlanWithCF.php` loads CF definitions via `cfield_mgr::get_linked_cfields_at_testplan_design()` | BFF action fetches same CF definitions, returns them as `cf_columns` array |
| Empty state | Warning message "There are no test cases on this test plan, with custom fields ENABLED ON Test Plan Design" | Same warning message shown in info panel, no DataTable rendered |
| Results table | ExtTable with dynamic columns for each CF, grouped by Test Suite | DataTables with sort, search, pagination; columns: Test Suite, Test Case + one column per CF |
| CF values | Rendered from CF design values per testplan, filtered rows with all-empty CFs | Same data shape; `cf_columns` drives column headers, rows with all-empty CF values filtered out |
| Edit link | `openTCEditWindow()` popup to test case editor | Same behavior: edit icon opens archiveData.php in new window |
| Locale | Server-side Smarty `lang` | Client-side `TLi18n` module; 18 locale switcher |

## 2. REST API Reference

All routes require an authenticated session; callers without `testplan_metrics` get `403`.

### GET `api/reports/index.php?action=tplan_with_cf`

Returns test cases with Test Plan Design custom field values.

**Query parameters:**
| Parameter | Required | Description |
|---|---|---|
| `tproject_id` | Yes | Test project ID |
| `tplan_id` | Yes | Test plan ID |

**Response:**
```json
{
  "status": "ok",
  "hasData": true,
  "tproject_id": 1,
  "tplan_id": 10,
  "tproject_name": "TP CF Test",
  "tplan_name": "Test Plan CF",
  "cf_columns": [
    { "id": "CF_TP_Design", "label": "CF TP Design", "name": "CF_TP_Design" }
  ],
  "rows": [
    {
      "tcase_id": 3,
      "tc_external_id": 1,
      "external_id": "TPCF--1",
      "tcase_name": "Test Case CF 1",
      "suite_path": "Test Suite CF",
      "cfields": { "CF_TP_Design": "Option A" }
    }
  ],
  "warning_msg": "",
  "elapsed_time": 0.01
}
```

**Error response (no data):**
```json
{
  "status": "ok",
  "hasData": false,
  "warning_msg": "There are no test cases on this test plan, with custom fields ENABLED ON Test Plan Design",
  "elapsed_time": 0
}
```

## 3. Legacy parity notes

- CF definitions fetched via same `cfield_mgr::get_linked_cfields_at_testplan_design()` path
- CF values joined via `testplan_tcversions.id` (link_id) — same as legacy
- `getPathLayered()` used for Test Suite path display
- Rows with all-empty CF values are filtered out (legacy parity)
- Edit link opens `archiveData.php?edit=testcase&id=<tc_id>&tproject_id=<tproject_id>` in new window

## 4. i18n Keys

All strings use `TLi18n` keys; no hardcoded text.

| Key | English value |
|---|---|
| `tpwcf.header` | Test Plan with Custom Fields |
| `tpwcf.forPlan` | for test plan |
| `tpwcf.testProject` | Test Project |
| `tpwcf.testCases` | test cases |
| `tpwcf.matchCount` | Match count |
| `tpwcf.editTC` | Edit Test Case |
| `tpwcf.elapsed` | Elapsed |
| `tpwcf.noData` | No test cases with custom field values found. |

Keys present in all 10 locale bundles (en, ro, de, fr, es, it, ja, pt, ru, zh).

## 5. Security

- Session-based authentication enforced at BFF entry point
- `testplan_metrics` right checked server-side before any DB query
- `tproject_id` and `tplan_id` validated against user's allowed projects
- All output escaped via `esc()` function in JavaScript rendering

## 6. Testing

Test suite #18 in `tmp/TLU_Test_Cases.md` — 9 test cases covering:
- Screen load without PHP warnings
- Data display with CF columns
- Edit link opens test case editor
- Locale switching translates labels
- Empty state — no CF values
- Error state — invalid plan
- Filter/search works
- Aside menu links to modernized screen
- i18n keys present in all locales
