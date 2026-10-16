# Task Issue #1139 — Delete legacy Execution History (cleanup)

Refs: [#1139](https://github.com/sebiboga/testlink-upgraded/issues/1139) (task),
[#1138](https://github.com/sebiboga/testlink-upgraded/issues/1138) (verification
that locked the parity), `docs/SCREEN-COMPARE-STATUS.md` row 35.

## What was deleted

| File | What it was | Lines |
|---|---|---|
| `lib/execute/execHistory.php` | session-guarded **302 redirect shim** (Refs #1806) onto the modern popup — no Smarty, no data | 36 |
| `gui/templates/dashio/execute/execHistory.tpl` | legacy Smarty/ExtJS Execution History popup — **dead**, its only renderer was the shim above | 207 |
| `lib/execute/getExecNotes.php` | legacy read-only notes **fragment shim** (Refs #1807): 401 no session / 405 write / 400 bad id / 302 browser-nav / 200 XHR fragment produced **in-process by `api/execnotesreadonly`** — it never rendered content itself | 166 |

Modern stack (unchanged): `gui/templates/execute/execHistory.html` +
`api/execute/index.php?action=history`, `gui/templates/execute/execNotesReadonly.html`
+ `api/execnotesreadonly/index.php`, child popups `editExecution.html` /
`execPrint.html`.

## Why it was safe (measured, see the INVESTIGATION comment on #1139)

- No live code pointed at `lib/execute/execHistory.php`: repo grep found only the
  file itself and provenance comments; every opener already targets
  `/gui/templates/execute/execHistory.html` (`testlink_library.js:1729`,
  `mainPage.html:550`, `tcView.html:1797`, ~20 modern screens).
- The 5 distinct `url2load()` call sites of `getExecNotes.php` all live in
  templates that are **never rendered**: their renderers are
  `lib/execute/execHistory.php` (deleted) and `lib/execute/execSetResults.php`
  (unconditional 302 shim), and the `tl-classic/` tree is never loaded at all
  (`TLSmarty` pins `gui/templates/dashio/`, cf. #957/#1125).
- The shim's bytes always came from the BFF, so nothing readable was lost —
  the modern details panel and `editExecution.html` read the same
  `api/execnotesreadonly` / `api/execnotes` routes.

## Provenance sweep (comments updated, parity annotations kept — #947/#976 policy)

- `api/execnotesreadonly/index.php` — docblock (call sites now dead, controller
  deleted), same-origin rationale paragraph, `legacy.controller` JSON key annotated.
- `api/execnotes/index.php`, `api/execute/index.php` (Mirrors header),
  `lib/functions/common.php` (`$actions->execNotesView` / `execNotesReadonly`).
- `docs/WIKI-EXECUTION-HISTORY.md`, `docs/SCREEN-COMPARE-STATUS.md` row 35,
  `docs/Execution-Notes-readonly-execNotesReadonly-Modernized.md`, this file,
  CHANGELOG, GitHub Wiki (`WIKI-EXECUTION-HISTORY.md` +
  `Execution-Notes-readonly-execNotesReadonly-Modernized.md`).

## Deliberately NOT deleted (documented follow-ups, conservative policy of #957/#966/#1125)

- `gui/templates/dashio/execute/getExecNotes.tpl` (18 L) — orphaned view template,
  not in the issue's delete list.
- `gui/templates/tl-classic/execute/{execHistory,getExecNotes,execSetResults}.tpl`
  + `include/execSetResults*.inc.tpl` — dead tree, never loaded.
- The `?action=fragment` route of `api/execnotesreadonly` — still same-origin
  gated, harmless, and its comments record the deleted contract.
- Out-of-tree/deep links to the two deleted controllers now answer **404**
  (they used to bounce to the modern screens); the modern URLs are canonical.

## Verification

- Browser (fixture `tmp/fixtures_1139.php`, project 1 `EHDemo` / plan 2 / TC 4
  executed 3× + TC 6 never run): rows + Build/Tester/Status/Date filters,
  active-plans checkbox (real server call `only_active_test_plans=1`), details
  with EXECUTION NOTES, edit popup (notes pre-filled), print popup, Refresh,
  never-executed warnbox, tcView → Execution History entry, read-only notes
  deep link, EN↔RO locale switch (0 raw i18n keys), 0 console messages.
- `curl`: deleted URLs 404; modern screen + all 3 BFF routes 200.
- `php -l` clean on the 4 touched PHP files; `events` table has **0** rows with
  `log_level <= 2`.
- Test suite `Task — Issue #1139` in `tmp/TLU_Test_Cases.md`: **20/20 PASS**,
  gate `TLU_REQUIRE_SUITE="Issue #1139"` → 7 PASS / 0 FAIL.
- No i18n change (no new user-facing strings), so no bundle edits.

Screenshots: `docs/screenshots/issue-1139-exechist-after-cleanup.png`,
`docs/screenshots/issue-1139-exechist-details-after-cleanup.png`
(pre-cleanup baseline: `docs/screenshots/issue-1139-exechist-before-cleanup.png`
— visually identical by design).
