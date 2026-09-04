# Report XLS/Mail Export BFF Gateway — Modernized Feature

Modernization of the legacy report export endpoints used by the 10 modernized
report screens. GitHub issue
[#854](https://github.com/sebiboga/testlink-upgraded/issues/854),
[#756](https://github.com/sebiboga/testlink-upgraded/issues/756).

Previously, every modernized report screen's **Export as spreadsheet** and
**Send by e-mail** buttons pointed directly at legacy PHP controllers
(`lib/results/*.php`). Those buttons are now routed through a single
authenticated BFF gateway (`api/reportsexport/index.php`) that validates the
session and the `testplan_metrics` right, then 303-redirects to the legacy
controller which still generates the actual XLS / mail content.

**Path:** any modernized report screen's export toolbar.
**URL:** `/api/reportsexport/`
**BFF API:** `GET ?action=<type> &tplan_id=N &tproject_id=M`
**Rights:** `testplan_metrics` on the owning test project (parity with the
legacy report controllers).
**Tracking issue:** [#854](https://github.com/sebiboga/testlink-upgraded/issues/854)

---

## Why a proxy gateway

The 10 report screens were already modernized (standalone Dashio HTML + BFF in
`api/reports/index.php`), but their XLS/mail export buttons still embedded
legacy `/lib/results/*.php` URLs in the served JSON (`export_xls_url`,
`send_mail_url`). The report **data** was BFF-driven but the **export download**
jumped straight to the legacy controllers — a mixed state that tied the
front-end to legacy paths and bypassed BFF auth.

The fix does **not** re-implement XLS generation (that's a large chunk of
PhpSpreadsheet code shared across 9 report controllers). Instead it inserts a
BFF authentication+authorization layer in front: the front-end only ever knows
`/api/reportsexport/?action=...`, and the BFF enforces auth/rights before
forwarding to the legacy generator.

## Actions

| Action | Legacy target |
|---|---|
| `general_metrics` / `general_metrics_mail` | `lib/results/resultsGeneral.php` |
| `results_by_tsuite` / `results_by_tsuite_mail` | `lib/results/resultsByTSuite.php` |
| `baseline_l1l2` / `baseline_l1l2_mail` | `lib/results/baselinel1l2.php` |
| `results_matrix` / `results_matrix_mail` | `lib/results/resultsTC.php` |
| `results_tc_flat` / `results_tc_flat_mail` | `lib/results/resultsTCFlat.php` |
| `absolute_latest` / `absolute_latest_mail` | `lib/results/resultsTCAbsoluteLatest.php` |
| `results_by_status` / `results_by_status_mail` | `lib/results/resultsByStatus.php` |
| `never_run` | `lib/results/neverRunByPP.php` |
| `exec_timeline_stats` / `exec_timeline_stats_mail` | `lib/results/execTimelineStats.php` |
| `assigned_tc_overview` / `assigned_tc_overview_mail` | `lib/results/resultsTC.php` |

## Flow

1. A report screen's **Export as spreadsheet** button submits a GET request to
   `/api/reportsexport/index.php?action=general_metrics&tplan_id=200&tproject_id=100`.
2. The BFF checks the session user; resolves the test plan; verifies
   `testplan_metrics` on the owning project.
3. On success the BFF 303-redirects to the corresponding legacy controller
   with the proper `format`, `tplan_id`, `tproject_id` (and any passed-through
   build/platform params).
4. The legacy controller runs its `createSpreadsheet()` (PhpSpreadsheet) and
   streams the `.xls`/`.xlsx` download — same file as before, but now reached
   through the authenticated BFF.

## Error handling

- Unauthenticated → `401 {"status":"error","message":"Not authenticated"}`.
- Missing/invalid `tplan_id` → `400 "Missing tplan_id"`.
- Unknown `action` → `400 "Unknown action"`.
- Nonexistent test plan → `404 "Test plan not found"`.
- No `testplan_metrics` right → `403 {"status":"error","message":"No permission"}`.

## Report screens switched

All `export_xls_url` / `send_mail_url` values in `api/reports/index.php` now
point to the BFF gateway:

- `metrics_general` (Gen. Metrics)
- `metrics_by_tsuite` (Results by Test Suite)
- `metrics_baseline_l1l2` (Baselines L1 & L2)
- `metrics_results_matrix` (Results Matrix)
- `assigned_tc_overview` (Assigned TC Overview)
- `results_tc_flat` related actions (Results TC Flat)
- `absolute_latest` init + result actions (Absolute Latest)
- `by_status` (Results by Status)
- `never_run` init + result actions (Test Cases Never Run)
- `exec_timeline_stats` (Execution Timeline Statistics)

## Security

- **Auth + authorization:** session required; `testplan_metrics` enforced
  server-side on every action (parity with the legacy `checkRights()`).
- **404 vs 403:** unknown plan → 404; present-but-no-right → 403 (no info
  leak).
- **Redirect target whitelisted:** the legacy URL is built only from the
  hard-coded `$exportMap` — never from a user-supplied path.

## Test coverage

See **Suite 854** in `tmp/TLU_Test_Cases.md` (12/12 PASS).
