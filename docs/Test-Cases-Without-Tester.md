# Test Cases Without Tester — Modernized Screen

The **Test Cases Without Tester** report (ASIDE → Reports → *Test Cases Without Tester*) lists test cases linked to the current test plan that have NOT been executed and have NO tester assigned. It replaces the legacy 1.9.20 `lib/results/testCasesWithoutTester.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Test Cases Without Tester*
**URL:** `gui/templates/results/casesWithoutTester.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=cases_without_tester&tproject_id=&tplan_id=`
**Rights:** `testplan_metrics`
**Tracking issue:** [#689](https://github.com/sebiboga/testlink-upgraded/issues/689)

---

## Overview

The header carries the test plan context; the toolbar shows the test project name and a count badge of matching test cases. A DataTable displays the results with these columns:

| Column | Content |
|--------|---------|
| Test Suite | Full path from project root to the test case's parent suite (e.g. "Project / Suite A / Sub-Suite B") |
| Test Case | External ID (e.g. "CWT-1") + test case name |
| Platform | *(conditional)* Shown only when the test plan has linked platforms |
| Priority | *(conditional)* Shown only when the test project has `testPriorityEnabled`; rendered as color-coded badges (high=red, medium=yellow, low=green) |
| Summary | Plain-text summary of the test case |

## Empty states

* **No test cases linked** — "No test cases linked to this test plan."
* **All test cases have a tester** — "All test cases have a tester assigned." with a check icon.

## Features

* **DataTables** — sortable columns (click header), paginated (25 entries default), filter/search box
* **i18n** — all labels use `data-i18n` attributes backed by the `cwt.*` keys in all locale bundles
* **Locale switcher** — top-right dropdown to switch languages at runtime
* **Elapsed time** — footer shows server-side processing time

## BFF API

```
GET /api/reports/index.php?action=cases_without_tester&tproject_id=1&tplan_id=2
```

**Response:**
```json
{
  "status": "ok",
  "hasData": true,
  "has_linked_tcs": true,
  "tproject_id": 1,
  "tplan_id": 2,
  "tproject_name": "My Project",
  "tplan_name": "My Plan",
  "show_platforms": false,
  "priority_enabled": true,
  "rows": [
    {
      "suite_path": "Project / Suite A",
      "tcase_id": 4,
      "external_id": "CWT-1",
      "name": "Test Case Name",
      "summary": "Summary text",
      "priority_level": "medium"
    }
  ],
  "elapsed_time": 0.01
}
```

## How it works

The BFF reuses `tlTestPlanMetrics::getNotRunWoTesterAssigned()` — the exact same call the legacy controller makes — and the test suite path resolution uses `tree::get_full_path_verbose()`. The right gate (`testplan_metrics`) is enforced both globally and contextually per-project/per-plan.
