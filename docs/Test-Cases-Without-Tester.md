# Test Cases Without Tester — Modernized Screen

The Test Cases Without Tester report shows test cases linked to a test plan that
have NOT been executed AND have NO tester assigned, across all active builds. It
replaces the legacy 1.9.20 `lib/results/testCasesWithoutTester.php` HTML view
with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → Test Cases without Tester Assignment
**URL:** `gui/templates/results/casesWithoutTester.html?tproject_id=<id>&tplan_id=<id>` (loaded inside the main frame)
**BFF API:** `api/reports/index.php` — `GET ?action=cases_without_tester`
**Rights:** `testplan_metrics` (same right the legacy controller enforces via `checkRights()`; BFF returns 403 otherwise)
**Tracking issue:** #689

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Report Results](#2-report-results)
3. [i18n](#3-i18n)
4. [Permission Path and Empty States](#4-permission-path-and-empty-states)
5. [BFF API Reference](#5-bff-api-reference)
6. [Files](#6-files)

---

## 1. Screen Layout

The screen follows the same Dashio layout as all modernized report screens:

- **Header bar** (teal): screen title ("Test Cases Without Tester"), "for test plan"
  subtitle with plan name, and locale switcher
- **Toolbar** (dark): project name context, count badge
- **Report area**: DataTable with results (shown after data loads)
- **Footer**: elapsed time

## 2. Report Results

The BFF calls `tlTestPlanMetrics::getNotRunWOTesterAssigned()` which identifies
test cases that have zero executions across all active builds AND have no tester
assignment for the given plan.

The results DataTable shows:
- **Test Suite**: full path (e.g. "TestProject / Suite A")
- **Test Case**: external ID + name (e.g. "1: TC Without Tester 1")
- **Priority**: colored badge (high/medium/low) — only shown when the project has
  the "Testing Priority" option enabled
- **Platform**: platform name — only shown when the test plan has linked platforms
- **Summary**: test case summary text

The table supports DataTables features: search, pagination (25 per page default),
column sorting.

## 3. i18n

All user-facing strings use the `TLi18n` client-side module with keys in the
`cwt.*` namespace:

| Key | English value |
|-----|-------------|
| `cwt.header` | Test Cases Without Tester |
| `cwt.forPlan` | for test plan |
| `cwt.testProject` | Test Project |
| `cwt.noLinkedTcversions` | No test cases linked to this test plan. |
| `cwt.elapsedSeconds` | Elapsed seconds |
| `cwt.allHaveTester` | All test cases have a tester assigned. |
| `cwt.matchCount` | Match count |
| `cwt.testCases` | test cases |

Keys are present in ALL locale bundles (en.json, ro.json, de.json, fr.json, es.json,
pt.json, it.json, ja.json, zh.json, ru.json).

## 4. Permission Path and Empty States

**Rights gate:** The BFF checks `$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)`.
Users without this right receive HTTP 403.

**Empty states:**
- When the test plan has zero linked test cases: screen shows "No test cases linked to this test plan."
- When all test cases have a tester assigned: screen shows "All test cases have a tester assigned."

## 5. BFF API Reference

### `GET ?action=cases_without_tester`

Parameters: `tproject_id`, `tplan_id`

```json
{
  "status": "ok",
  "hasContext": true,
  "tproject_id": 1,
  "tplan_id": 100,
  "tproject_name": "TestProject",
  "tplan_name": "TestPlan1",
  "has_linked_tcs": true,
  "hasData": true,
  "show_platforms": false,
  "priority_enabled": true,
  "rows": [
    {
      "suite_path": "TestProject / Suite A",
      "tcase_id": 300,
      "external_id": 1,
      "name": "TC Without Tester 1",
      "summary": "Summary for TC1 - no tester",
      "priority_level": "high"
    }
  ],
  "elapsed_time": 0.01
}
```

Empty result (all TCs have testers):
```json
{
  "status": "ok",
  "has_linked_tcs": true,
  "hasData": false,
  "show_platforms": false,
  "priority_enabled": false,
  "rows": [],
  "elapsed_time": 0.003
}
```

## 6. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/casesWithoutTester.html` | Modernized HTML/JS/CSS screen |
| `api/reports/index.php` | BFF API — `cases_without_tester` action |
| `lib/general/asideMenu.php` | Aside link switched to modernized screen |
| `gui/templates/i18n/*.json` | i18n keys (`cwt.*` namespace) in all locale bundles |
| `lib/results/testCasesWithoutTester.php` | Legacy controller (kept for reference) |
| `lib/functions/tlTestPlanMetrics.class.php` | `getNotRunWOTesterAssigned()` — core query |
