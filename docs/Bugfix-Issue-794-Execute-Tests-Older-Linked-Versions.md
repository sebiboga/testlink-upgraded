# Bugfix: Issue #794 — Execute Tests cannot switch / execute older linked TC versions (regression)

## Summary

The modernized Execute Tests screen (`gui/templates/execute/execTest.html` +
`api/execute/index.php`) showed the version number of a test case but offered
**no way to select or execute an older linked version**. A test plan that has
two versions of the same test case linked (e.g. v1 and v2) only ever listed
the **latest** version: the BFF `tcList` deliberately collapsed the linked
version set (`$latestByTcase`), so the older version was unreachable from the
UI entirely — a regression vs. the legacy execution tree, where every linked
version appears as its own tree node and can be opened/executed.

## Root Cause

1. `api/execute/index.php` `?action=tcList` selected **all** linked versions
   in SQL (lines 476-487) but then discarded everything but the newest:
   lines 492-502 built `$latestByTcase` keeping only the row with max
   `TCV.version`, and every downstream block (execMap lines 504-535, pathMap
   lines 538-553, the item loop at line 562) operated on that single row.
   With `testplan_tcversions` holding both v1 (tcversion 13) and v2
   (tcversion 15), the API returned only `{tcversion_id: 15, version: 2}`.
2. `gui/templates/execute/execTest.html` `renderForm()` showed the version
   number in the form meta (`v` + `v.number`, line ~397) and serialized
   `current.version.id` / `current.version.number` on save, but there was no
   UI element to pick a different linked version and no `versions[]` payload
   to feed one.

The backend execution path was already version-agnostic and correct —
`?action=tcDetails` and `?action=save` both handle an arbitrary linked
`tcversion_id` (verified: saving against v1 writes the execution row
correctly). The entire regression lived in the `tcList` collapse plus the
missing selector.

## Fix

**Backend — `api/execute/index.php` `?action=tcList`:**
- Replace the latest-only collapse with a per-test-case grouping `$tcGroups`.
  The list/display row still uses the **latest** version (status badge, last
  execution column unchanged for existing callers), but the payload now also
  carries `versions[]` with **every** linked version
  (`{tcversion_id, version, active, last_execution}`), newest first (the SQL
  already orders by `TCV.version DESC`).
- `execMap` (last execution on the selected build/platform) is computed over
  **all** linked version ids instead of just the latest, so each version row
  gets its own `last_execution`.

**Frontend — `gui/templates/execute/execTest.html`:**
- `openCase()` copies the list item's `versions[]` into `current.versions`
  so the detail form knows which versions are linked.
- `renderForm()` renders a **Version** `<select>` (plus a "Switch version"
  button) whenever `current.versions.length > 1`. Options are `v<n>`
  (suffixed `(inactive)` for non-active versions). In read-only mode the
  selector is shown but the switch button is omitted.
- New `switchVersion()` re-fetches `tcDetails` for the chosen version (after
  a confirm dialog echoing that unsaved changes are lost) and re-renders;
  the existing save/partial-save paths already serialize
  `current.version.id`/`.number`, so an execution now targets the selected
  older version.

**i18n:** added `exe.version`, `exe.inactiveVersion`, `exe.switchVersion`,
`exe.confirmSwitchVersion` to all 10 locale bundles
(`de,en,es,fr,it,ja,pt,ro,ru,zh`).

## Why This Approach

- **Minimal**: `tcList` changed in place (reuses the existing SQL and the
  already-version-aware `tcDetails`/`save` routes); no DB/schema change, no
  new endpoint. `testplan_tcversions` has no unique key on
  `(testplan_id, tcversion_id)`, so multiple linked versions coexist exactly
  as legacy expects.
- **Non-breaking list**: keeping the latest version as the row keeps the
  search/filter/status-badge behavior of the screen identical for
  single-version test cases (regression check: `versions.length === 1`
  renders no selector at all).
- **Matches legacy UX**: legacy exposes versions through the tree; the
  in-form selector is the direct Dashio equivalent.
- **Alternatives rejected**: listing one row per version would duplicate rows
  in the left list (name/status repeated) and change the search/badge logic;
  auto-picking the newest linked version on open (legacy default) is already
  the behavior and would not have fixed the reported symptom.

## Files Changed

- `api/execute/index.php` — `tcList`: `$tcGroups` grouping + `versions[]`.
- `gui/templates/execute/execTest.html` — version switcher + `switchVersion()`.
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — 4 new `exe.*`
  keys per bundle.

## Commit

- `c164d2e14` — `fix(execute): keep all linked TC versions selectable/executable (Refs #794)`
- `c2d85c326` — `test(execute): attach issue #794 regression screenshots (Refs #794)`

## Regression Matrix

| # | Case | Result |
|---|------|--------|
| 1 | `tcList` on a multi-version plan shows `versions[]` with all linked versions (newest first); list row = latest | PASS |
| 2 | Per-version `last_execution` present in `versions[]` | PASS |
| 3 | Version selector rendered for a multi-version test case | PASS |
| 4 | Switch to the older version loads its summary/steps/prior execution | PASS |
| 5 | Saving an execution on the older version writes `executions` with its `tcversion_id` | PASS |
| 6 | Single-version test cases show NO selector (flow unchanged) | PASS |
| 7 | Read-only mode hides the switch button | PASS (guard review) |
| 8 | All 10 i18n bundles valid JSON | PASS |

Verified 2026-08-30 with fixture `tmp/fixture_794.php` (project VERS794,
test case TCVers794 with v1/v2 linked), headless Chrome at
http://localhost:8082, admin/admin. Event Viewer: no new Error/Warning rows
from the screen flow.