# Requirements Based Report (resultsReqs) — Modernized

**Refs:** #1966 (tracking) · Reports area · legacy controller retired
**Screen:** `gui/templates/results/resultsRequirements.html`
**BFF:** `api/reports/index.php` → `action=metrics_results_reqs`
**Legacy entry:** `lib/results/resultsReqs.php` (now a GET/HEAD-only 302 shim)

## Why this entry exists

The report itself was modernized earlier (#691 / #1233 / #1234): the Dashio
screen and the `metrics_results_reqs` BFF action already carried the full
legacy behaviour. What was still missing was the retirement of the legacy
Smarty controller — a stale bookmark to `lib/results/resultsReqs.php` still
rendered the 1.9.20 page (ExtJS tables, legacy layout) instead of the modern
screen. #1966 closes that gap and finishes the screen's verification cycle.

## What the screen does

- Plan-scoped Requirements Coverage report for the addressed test project.
- Optional **Platform** and **Build** filters; changing the build re-evaluates
  the latest execution of every linked test-case version.
- Evaluation Summary badges (Passed / Failed / Blocked / Not Run, plus the
  `_nfc` and partially-passed / uncovered verdicts) and a localized total.
- DataTable with Req Spec path, doc-id/title, version, coverage
  (`current/expected`), evaluation, type, status, per-status counters, progress
  bar and the linked-test-case badge.
- Footer list filters for **Evaluation / Type / Status** (fed by the BFF
  `*_filter_options` arrays, so only values present in the result set are
  offered) — they narrow the table only.
- Clicking the linked-TC badge expands an inline detail table (external id and
  name, platform, localized execution status).
- Empty states mirroring legacy `resultsReqs.php`: `no_srs_defined` when the
  project has no requirements specification in force and `no_matching_reqs`
  when requirements exist but none is covered by a TC in this plan.
- `TLi18n` client i18n (`reqcov.*` keys) with the shared locale switcher; the
  page title and the two count labels are localized too.

## BFF contract

File: `api/reports/index.php`, action `metrics_results_reqs`.

| Method | Route | Purpose |
|---|---|---|
| `GET`/`HEAD` | `?action=metrics_results_reqs&tproject_id=N&tplan_id=M[&platform=P][&build=B]` | Context, filter option sets, summary counts and per-requirement rows |

- Session auth; the global gate requires `testplan_metrics` (global or on the
  request's own project/plan context), then the action re-checks
  `testplan_metrics` on the owning project before returning data.
- The plan's project is resolved server-side from `tplan_id`; `tproject_id` is
  only a context hint, so a mismatched URL cannot read another project.
- Responses: `200` JSON payload; `400` missing/invalid plan; `401` no session;
  `403` no right; the screen renders these as a localized error toast.

## Legacy redirect shim

`lib/results/resultsReqs.php`:

- accepts `GET`/`HEAD` only (other verbs → `405 Allow: GET, HEAD`);
- anonymous → `login.php?note=expired&destination=<legacy path>` so a stale
  bookmark is not silently rendered;
- otherwise emits a JavaScript `window.location.replace()` to the modern
  screen, forwarding `tproject_id` / `tplan_id` (falling back to the session
  project/plan just like legacy `init_args()` did).

## This run's fixes

- Removed the DataTables search-input initializer that had been copy-pasted
  **10 times** in one `loadData()` callback; a single `ensureSearchInputId()`
  now runs once per table draw.
- Replaced hardcoded English count labels with `reqcov.countRequirements`
  ("{n} requirements") and `reqcov.tcCount` ("{n} TCs"), added to all 10 client
  bundles with real translations.
- `document.title` is now localized from `reqcov.header`.

## Verification

- Suite `Issue #1966` = 12/12 PASS in `tmp/TLU_Test_Cases.md` (render, build
  filter, column filters, expand, EN/RO, empty states, invalid plan, 401/403,
  legacy shim, wiring/i18n gate, Event Viewer).
- `bash ai/verify_i18n_coverage.sh`: 9/9 bundles, 0 missing (9991 keys).
- Event Viewer: no new Error/Warning rows (INFO/AUDIT only).
- Fixture: `tmp/fixtures_1065.php` (project RTM1065, plan R6 Plan, 4
  requirements, 6 test cases, 2 builds, executions, role-3 user).
