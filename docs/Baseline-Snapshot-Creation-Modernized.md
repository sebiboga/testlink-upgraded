# Baseline Snapshot Creation (baselineSave) — Modernized

**Refs:** #1964 (tracking) · closes gap #1189 · parent reports area
**Screen:** `gui/templates/results/baselineSave.html`
**BFF:** `api/baselinesave/index.php`

## Why this screen exists

TestLink 1.9.20 could save a baseline only as a **side effect of a GET** to the
Results by Test Suite report (`lib/results/resultsByTSuite.php`,
`doAction=saveForBaseline`). There was no screen, no confirmation, no way to
review what was about to be stored, and repeating the request silently created
another snapshot. The modern Reports area exposed the read side
(`baselineL1L2.html`, `api/reports` → `metrics_baseline_l1l2`) but had no write
path at all, so snapshots could only be produced by the legacy controller.

This screen restores the missing capability with an explicit preview and an
auditable POST, and it is linked from both reports that need it.

## What the screen does

- Plan-scoped preview of the figures that will be frozen: per platform
  - execution window (first / latest execution),
  - number of level-2 suites and test cases,
  - status totals (passed / failed / blocked / not run),
  - whether the platform has executions and can be snapshotted.
- KPI tiles summarising the same figures for the whole plan.
- **Save baseline** writes one snapshot per executable platform.
- **Saved snapshots** table lists every existing snapshot (newest first) with
  its creation time, platform, execution window, detail-row count and total
  executions.
- Explicit empty/error states: no plans, no executions, 401, 403, 404 and a
  duplicate-save refusal. A no-execution plan keeps the preview visible but
  disables Save and shows the legacy warning.
- **Open Baselines report** jumps to `baselineL1L2.html` for the same plan.
- Client i18n via `TLi18n` (`bsv.*` keys) with the shared locale switcher.

Entry points:

- `gui/templates/results/baselineL1L2.html` — “Create baseline” button.
- `gui/templates/results/resultsByTSuite.html` — “Create baseline” button.
- `$actions->baselineSave` in `lib/functions/common.php`.

## BFF contract

File: `api/baselinesave/index.php`. Session auth, JSON in/out, same-origin
guard on writes.

| Method | Route | Purpose |
|---|---|---|
| `GET`/`HEAD` | `?action=init&tproject_id=N[&tplan_id=M]` | Project/plan context, plan list, per-platform preview, existing snapshots |
| `GET`/`HEAD` | `?action=snapshots&tproject_id=N[&tplan_id=M]` | Snapshot list only |
| `POST` | `?action=save&tproject_id=N&tplan_id=M` | Create one snapshot per executable platform |

Rights: `testplan_metrics` is required on the **addressed project**, and the
right is checked **before** the plan is resolved, so a foreign or unknown id
does not become an existence oracle. A write additionally requires
`bffSameOriginGuard` (`X-Requested-With` or Origin/Referer) and
`bffEnforceSession`.

Stable error codes: `unknown_action` (400), `invalid_parameter` (400),
`not_authenticated` / `session_expired` (401), `no_right` (403),
`tproject_not_found` / `tplan_not_found` (404), `wrong_method` (405),
`no_executions` (400), `duplicate_snapshot` (409).

### Data written

- `baseline_l1l2_context` — one row per `(testplan_id, platform_id, creation_ts)`
  with the execution window.
- `baseline_l1l2_details` — one row per `(top_tsuite_id, child_tsuite_id,
  status)` with `qty` and `total_tc`, using the legacy status letters
  `n` / `p` / `f` / `b`.

The figures come from
`tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender()` (grouped by
platform) and `getExecTimeSpan()`; platforms come from `testplan::getPlatforms()`.
A successful save writes an AUDIT event (`CREATE`, context id,
`baseline_l1l2_context`).

## Legacy behavior preserved

- The same level-1/level-2 suite granularity and status letters that the
  existing baseline reports read.
- One snapshot per platform, with a plan without platforms stored under
  `platform_id = 0`.
- Refusal to snapshot a plan with no execution records (legacy message kept in
  localized form).
- The existing read report is unchanged; saving adds a new snapshot rather than
  overwriting the previous one.

## Verification

- `php -l` clean on the BFF; `ai/verify_i18n_coverage.sh` 9/9 bundles pass.
- Suite `Issue #1964` (10 cases) in `tmp/TLU_Test_Cases.md`: render, save +
  DB rows, no-execution plan, duplicate guard, 401/403/404/405, entry points,
  i18n, audit/Event Viewer.
- Browser pass in EN and RO, including the no-execution plan.

Screenshots: `1964-baselinesave-main.png`, `1964-baselinesave-empty-plan.png`,
`1964-baselinesave-ro.png`.
