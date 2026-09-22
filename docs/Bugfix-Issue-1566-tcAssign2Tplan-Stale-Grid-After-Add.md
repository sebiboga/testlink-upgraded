# Bug fix — Issue #1566: tcAssign2Tplan grid re-renders stale rows after Add (checkbox not disabled, no 'already linked' note until reload)

**Issue:** [#1566](https://github.com/sebiboga/testlink-upgraded/issues/1566)
**Branch:** `fix/issue-1566`
**Status:** FIXED & VERIFIED (2026-09-22)

## Symptom

In the modern Assign Test Case to Test Plan screen
(`gui/templates/testcases/tcAssign2Tplan.html`), after clicking **+ Add** the grid is
re-rendered from `loadInfo()` but the rows keep the PRE-ADD state: the just-linked
plan's checkbox is still enabled (not `checked disabled`) and the '(already linked)'
note is missing — until the user reloads the page. The `ctx.info` JS state is correct
(`can_do=false`, `already_linked=true`); only the rendered DOM is stale. This is
misleading after the core workflow action and risks a re-check + double-add on a plan
that is actually already linked.

## Repro steps (this run, fresh DB)

1. `php tmp/fixtures_1319.php` → A2PDemo (tproject=1), Plan A (tplan=2), Win10
   platform (1), A2P-1 tcase=4/tcversion=5 unlinked (can_do=true), A2P-2 v1 linked.
2. Open `http://localhost:8082/gui/templates/testcases/tcAssign2Tplan.html?tproject_id=1&tcase_id=4&tcversion_id=5` (admin/admin).
3. Tick the Plan A checkbox, click **+ Add**.
4. okBox 'Added to 1 test plan(s)' appears; the re-rendered row still shows an
   ENABLED checkbox and no '(already linked)' note.

**Actual (pre-fix), measured 1.2s after the AJAX round-trip:**
`<input type="checkbox" id="cb_2_1" data-tplan="2" data-platform="1">` (enabled,
unchecked), row text `1   Plan A   Win10`, while
`ctx.info.plans[0].platforms[0].already_linked` = `true`. A stateless page reload
rendered `<input ... checked="" disabled="">` + `Win10 (already linked)` — proving the
data layer was correct and only the DOM was stale.

## Root cause chain

1. `renderPlans()` (initial call, first load): `#planTable` is not yet a DataTable →
   fresh rows are written into `#planRows` and the table is initialized. DataTables
   snapshots that tbody as its "original" DOM.
2. Add completes → `doAdd()` → `loadInfo()` → `renderPlans()` again. On this second
   call the table IS an active DataTable.
3. The old code order was: `$('#planRows').html(rows)` (fresh rows) FIRST, then
   `$('#planTable').DataTable().destroy()` (tcAssign2Tplan.html:232-234, pre-fix).
   `destroy()` without the `remove` flag restores the table's ORIGINAL cached tbody —
   the pre-add rows from step 1 — replacing the just-written fresh rows.
4. Re-initialization reads the restored (stale) tbody, so the shown checkbox/link
   state is the PRE-ADD state even though `ctx.info` was rebuilt correctly from the
   BFF. The BFF (`api/tcassign2tplan/index.php`) and the add itself are sound; the
   DB link exists and a reload renders correctly.

**Blast radius:** `renderPlans()` is the single render path for this screen (init
load and the re-render after Add). No other screen shares it. No BFF change needed.

## Fix approach

Reorder the destroy-then-rebuild so the active DataTable is destroyed BEFORE the
tbody is mutated (the report's suggested fix):

```js
// Destroy any ACTIVE DataTable BEFORE mutating #planRows: destroy() restores
// the table's original cached tbody, so rewriting rows afterwards would leave
// the re-render stale. See #1566.
if ($.fn.DataTable.isDataTable('#planTable')) {
  $('#planTable').DataTable().destroy();
}
```

placed at the top of the plans-present branch, and the old destroy block (which ran
after `.html(rows)`) removed. Why this method: it leaves the pristine-page path intact
(the `isDataTable` guard is false on first init) and for re-renders guarantees the
tbody mutation lands on a plain HTML table that DataTables then re-reads. Alternative
rejected: `$('#planRows').empty()`/row-API removal through the live DataTable — more
invasive and DataTables row-API rewrites introduce ordering churn for no benefit.

## Verification

Browser regression matrix (admin, fresh DB, Plan B added for the multi-plan case) —
4/4 PASS (see `tmp/TLU_Test_Cases.md` suite 1577):

1. **Primary symptom:** after Add, the re-rendered row is `checked="" disabled=""`
   with `(already linked)` note, okBox 'Added to 1 test plan(s)', Add hidden —
   identical to a fresh page load.
2. **Init-render regression (can_do=false, A2P-2 v2):** checkbox `checked disabled`,
   note present, Add hidden, Cancel visible.
3. **Multi-plan re-render:** grid with Plan A + Plan B, both selected and Added →
   okBox 'Added to 2 test plan(s)', BOTH rows re-rendered `checked disabled` + note.
4. **Events/console:** `events` table shows only expected audit rows (log_level 16:
   login, `audit_tc_added_to_testplan`); zero Error/Warning; no new JS console errors.

Screenshot: `docs/screenshots/issue-1566-tcassign2tplan-grid-correct-after-add.png`.

## Files changed

- `gui/templates/testcases/tcAssign2Tplan.html` — `renderPlans()`: moved the
  DataTable `destroy()` before the `#planRows` rewrite (+5/-4 net).
- `tmp/TLU_Test_Cases.md` — Regression — Issue #1566 suite 1577 (4/4 PASS).
- `CHANGELOG` — KEY BUGFIX entry.
- This doc + wiki mirror + screenshot.

## Result

Issue #1566 closed as fixed: the stale re-render after Add is gone — the grid now
matches a fresh page load immediately after the link operation, error-free.