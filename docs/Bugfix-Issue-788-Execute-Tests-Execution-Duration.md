# Bugfix: Issue #788 — Execute Tests: missing Execution Duration input + no duration saved/displayed (regression)

## Summary

The modernized **Execute Tests** screen (`gui/templates/execute/execTest.html` +
`api/execute/index.php`) dropped the legacy **Execution Time / Duration**
(`execution_duration`) feature entirely. The tester could not record how long
executing a test case took, and the value was never stored or displayed. This
is a regression of a legacy TestLink feature that is **enabled** by default
(`config.inc.php:1189-1190` → `exec_cfg->features->exec_duration->enabled = true`).

## Legacy Behavior

- `gui/templates/tl-classic/execute/inc_exec_controls.tpl:44-51` renders the
  numeric **"Execution Time"** (`execution_duration`) input, gated on the
  `exec_duration->enabled` flag.
- `lib/functions/exec.inc.php:147-158` (`write_execution()`) INSERTs
  `execution_duration` (empty → NULL, else `floatval`).
- `lib/functions/print.inc.php:2110-2116` displays the recorded duration in
  the report.

## Root Cause

1. `gui/templates/execute/execTest.html` — **no** `execution_duration` input
   rendered (`grep` returned zero hits). `saveExecution()` (lines 939-957)
   never appended a duration to the FormData payload.
2. `api/execute/index.php` `?action=save` (lines 1064-1069) built `$execData`
   with only `statusSingle / tc_version / version_number / notes` — it never
   read an incoming `execution_duration` and never set
   `$execData['execution_duration']`.
3. `write_execution()` (exec.inc.php:153-158) only writes the duration when
   `$exec_data['execution_duration']` is set. Since the BFF never set it, the
   INSERT got `$dura = 'NULL'` → duration always stored NULL.
4. Display: the `/history` endpoint already returned `execution_duration`
   (api/execute/index.php:926, that block is the tcDetails inline history), but
   the separate `?action=history` output array (used by `execHistory.html`)
   **dropped** the column even though `getExecutionSet()` selects it
   (testcase.class.php:6524-6660). `execHistory.html` `renderTable()` /
   `renderDetail()` never rendered a duration anywhere.

Live proof (fresh fixtures, admin/admin): saving with `execution_duration=12.5`
yielded `SELECT execution_duration FROM executions` → **NULL** pre-fix.

## Fix

**Backend — `api/execute/index.php`:**
- `?action=save`: read optional `execution_duration` from the payload (gated on
  `exec_cfg->features->exec_duration->enabled`), pass it via
  `$execData['execution_duration']` to `write_execution()` so it persists
  (empty → NULL, numeric → stored) — mirrors exec.inc.php:153-158.
- `?action=init`: expose `exec_duration_enabled` flag so the frontend can gate
  the input (same pattern as the existing `new_exec_latest` /
  `platform_feature_enabled` flags).
- `?action=tcDetails`: include `execution_duration` in the `prior_execution`
  SELECT + object so re-opening the form pre-fills it.
- `?action=history`: emit `execution_duration` in each `$executions[]` row
  (`getExecutionSet()` already selected the column; serialization dropped it).

**Frontend — `gui/templates/execute/execTest.html`:**
- `execDurationEnabled()` helper gated on `initData.exec_duration_enabled`.
- `renderForm()` renders a numeric **"Execution duration (min)"** input (gated,
  read-only-mode aware, numeric-only via `onkeyup` filter) matching the legacy
  control.
- `resetFormInputs()` pre-fills it from `prior_execution.execution_duration`
  (restored on open, cleared on Reset).
- `saveExecution()` appends `execution_duration` to the FormData payload
  (empty when the feature is disabled).

**Frontend — `gui/templates/execute/execHistory.html`:**
- Added a **"Duration"** column (header + row cell), a detail-view block, and
  fixed the row/empty colspans (9→10 / 8→9 / no-result row 9→10).

**i18n:** added `exe.executionDuration` ("Execution duration (min)") and
`exechist.colDuration` ("Duration") to all 10 locale bundles
(`de,en,es,fr,it,ja,pt,ro,ru,zh`); Romanian translation in `ro.json`.

## Why This Approach

- **Minimal / legacy-parity**: reuses the existing `write_execution()` duration
  handling — the BFF just has to forward the value. No schema change, no new
  endpoint.
- **Gated on the real config**: the input and persistence honor
  `exec_cfg->features->exec_duration->enabled`, exactly like legacy; disabling
  the flag hides the input and stores NULL.
- **Both display paths covered**: the `save` write path AND the two history
  read paths (tcDetails inline history + `?action=history`).
- **Alternatives rejected**: replicating the legacy report table for the history
  screen was unnecessary scope; a new dedicated endpoint would duplicate the
  existing `history`/`tcDetails` routes.

## Files Changed

- `api/execute/index.php` — `save` (forward duration), `init`
  (`exec_duration_enabled`), `tcDetails` (prior duration), `history`
  (emit duration).
- `gui/templates/execute/execTest.html` — duration input + `execDurationEnabled()`
  + pre-fill/reset + payload append.
- `gui/templates/execute/execHistory.html` — Duration column + detail + colspans.
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — 2 new keys per bundle.
- `docs/screenshots/issue-788-exectest-duration-input.png`,
  `docs/screenshots/issue-788-exechistory-duration-column.png` — screenshots.

## Commit

- `993bb552f` — `fix(execute): restore Execution Duration input + persistence on Execute Tests (Fixes #788)`

## Regression Matrix

| # | Case | Result |
|---|------|--------|
| 1 | Execute form renders the "Execution duration (min)" input (feature flag ON) | PASS |
| 2 | UI end-to-end save: duration `8.5` + Passed → `executions.execution_duration=8.50` | PASS |
| 3 | Empty-duration save stores NULL | PASS |
| 4 | `tcDetails` `prior_execution.execution_duration` present and pre-fills the form | PASS |
| 5 | `?action=history` returns `execution_duration` per row | PASS |
| 6 | execHistory.html renders "Duration" column (`8.50`, `-`, `12.50`) | PASS |
| 7 | All 10 i18n bundles valid JSON and contain the 2 new keys | PASS |
| 8 | Event Viewer `events` table: no new Error/Warning from the fix | PASS |

Verified 2026-08-31 with fresh fixtures (project Demo Project id=1, plan Plan 1
id=2, case Login Test version node 4, build Build 1 id=1), headless Chrome at
http://localhost:8082, admin/admin. Event Viewer: only informational audit rows
(log_level 16: login + project created); no new Error/Warning.
