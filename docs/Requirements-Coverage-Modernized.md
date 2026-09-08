# Requirements Coverage Report — Modernized Screen

Modernization of **Requirements Coverage** (`lib/results/requirementsCoverage.php`)
— GitHub issue [#691](https://github.com/sebiboga/testlink-upgraded/issues/691).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/results/resultsRequirements.html`) backed by a plain-PHP REST BFF
(`api/reports/index.php`). The ASIDE **Reports → Requirements Coverage** entry
now points to the new HTML screen.

**Path:** Reports → Requirements Coverage
**URL:** `gui/templates/results/resultsRequirements.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php?action=metrics_results_reqs`
**Right:** `testplan_metrics` enforced server-side (same as legacy)

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
| Context toolbar | test project label, platform/build filter, Apply button | same: project label, platform select, build select, Apply button |
| Evaluation Summary | badge row with eval status counts (Passed, Failed, Blocked, Not Run, Not Run (nfc), etc.) | same badges with translated labels and counts |
| Requirements table | DataTable with 13 columns: Req Spec, Title, Ver, Coverage, Evaluation, Type, Status, Passed, Failed, Blocked, Not Run, Progress, Linked TCs | identical columns, same data |
| Linked TC expand | clicking "N TCs" expands a sub-table with linked test cases and their status | same expand/collapse behavior |
| Platform/Build filter | filter by specific platform/build or [Any] | same filter with [Any] default |
| Per-column list filters (Evaluation / Type / Status) | ExtTable `filter => ListSimpleMatch`/`list` dropdowns with eval/type/status label options (resultsReqs.php:157-162) | DataTable footer `<select>` filters under Evaluation (col 4), Type (col 5), Status (col 6), default option "All" + full translated label lists; combine freely with each other and with the global search (Refs #1234) |

## 2. REST API Reference

Single action endpoint: `GET /api/reports/index.php?action=metrics_results_reqs`

### Query Parameters

| Param | Required | Description |
|---|---|---|
| `tproject_id` | yes | Test project ID |
| `tplan_id` | yes | Test plan ID |
| `platform_id` | no | Platform ID (0 = all) |
| `build_id` | no | Build ID (0 = all) |

### Response (200 OK)

```json
{
  "status": "ok",
  "msg": "ok",
  "data": {
    "tproject_name": "...",
    "tplan_name": "...",
    "elapsed_seconds": 0.02,
    "platform_set": {"1": "Platform Name"},
    "build_set": {"1": "Build Name"},
    "eval_status_set": {
      "passed": {"label": "Passed", "count": 3, "long_label": "Passed Reqs"},
      "not_run": {"label": "Not Run", "count": 2, "long_label": "Not Run Reqs"},
      "not_run_nfc": {"label": "Not Run (nfc)", "count": 1, "long_label": "Not Run (nfc) Reqs"}
    },
    "expected_coverage_enabled": true,
    "eval_filter_options": ["Not Run", "Passed", "Failed", "Blocked", "Partially passed", "Not covered", "Partially Passed (nfc)", "Not Run (nfc)", "Passed (nfc)", "Failed (nfc)", "Blocked (nfc)"],
    "type_filter_options": ["Informational", "Feature", "Use Case", "User Interface", "Non functional", "Constraint", "System Function"],
    "status_filter_options": ["Draft", "Review", "Rework", "Finish", "Implemented", "Valid", "Not testable", "Obsolete"],
    "rows": [
      {
        "req_spec_path": "Req Spec 1",
        "req_doc_id": "REQ-001",
        "title": "Requirement Title",
        "version": 1,
        "type": "Functional",
        "status": "Valid",
        "eval": "n",
        "eval_label": "Not Run",
        "eval_css": "not_run",
        "expected_coverage": 2,
        "current_coverage": 1,
        "status_passed": 0,
        "status_failed": 0,
        "status_blocked": 0,
        "status_not_run": 1,
        "progress": 0,
        "linked_tccount": 2,
        "linked_tcversions": [
          {"tcversion_id": 101, "tc_external_id": 1, "name": "TC Login", "exec_status": "n"}
        ]
      }
    ]
  }
}
```

### Error Responses

| Status | Body | Condition |
|---|---|---|
| 401 | `{"status":"error","message":"Not authenticated"}` | No session |
| 403 | `{"status":"error","message":"No permission"}` | Missing `testplan_metrics` right |
| 400 | `{"status":"error","message":"..."}` | Missing params, invalid plan, etc. |

## 3. Legacy parity notes

- Per-column list filters on Evaluation / Type / Status port the legacy ExtTable
  `filter` definitions (resultsReqs.php:157-162) — `ListSimpleMatch` for Evaluation,
  `list` for Type and Status — with the same filter *options*: eval-status labels,
  req-type labels and req-status labels. Implemented as DataTable footer `<select>`
  elements wired to `column.search()` (anchored exact match on the translated cell
  text); filter state resets to "All" on each Apply/reload, mirroring the ExtTable
  re-render behavior (Refs #1234).
- Evaluation logic is a direct port of `evaluate_req()` from `lib/results/resultsReqs.php`
- `doNotRunAnalysis()` is ported as `doNotRunAnalysisBff()`
- `buildReqSpecMap()` logic is inlined in the BFF action
- `expected_coverage_enabled` respects the `$tlCfg->req_cfg->expected_coverage` config
- The legacy `approve_status_matrix()` for full coverage check is ported exactly

## 4. i18n Keys

24 keys under `reqcov.*` in all 10 locale bundles (`en.json`, `ro.json`, `de.json`,
`fr.json`, `es.json`, `pt.json`, `ja.json`, `zh.json`, `it.json`, `ru.json`).

Keys cover: page title, summary labels, eval status names, badge labels, column headers,
search placeholder, apply button, elapsed time label, and the per-column filter default
option `reqcov.all` ("All" / "Alle" / "Todos" / "Tous" / "Tutti" / "すべて" / "Toate" /
"Все" / "全部", Refs #1234).

## 5. Security

- Session-based auth via `doSessionLogin()`
- Authorization gate: `hasRight('testplan_metrics', array('tprojectID' => ..., 'testplanID' => ...))`
- No raw SQL; all queries via ORM (`requirement_mgr`, `requirement_spec_mgr`)
- XSS-safe output via `htmlspecialchars()` / DOM text nodes

## 6. Testing

See [Test Suite #46](../tmp/TLU_Test_Cases.md) — 13 test cases covering:
- Aside menu link, BFF API auth/authz, header labels
- Evaluation summary badges, DataTable columns and values
- Linked TCs expand, platform/build dropdowns, Apply filter
- Search, i18n, column alignment, Event Viewer

And the `Task — Issue #1234` suite (13 cases, all PASS): per-column
Evaluation/Type/Status footer filters — BFF option lists, single & combined filtering,
no-match empty state, All-reset, Apply-reset, global-search composition, locale
translation of the "All" option, console & Event Viewer cleanliness.
Screenshots: `docs/screenshots/issue-1234-before.png`,
`docs/screenshots/issue-1234-filter-passed-valid.png`.
