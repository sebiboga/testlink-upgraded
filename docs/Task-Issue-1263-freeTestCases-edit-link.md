# Task — Issue #1263: Implement per-row edit/design link in freeTestCases.html

## Summary
Added per-row edit/design link in the Test Case column of the Free Test Cases report (freeTestCases.html), matching legacy behavior (openTCEditWindow / edit icon linking to test case editor/view). The API already returns `tcase_id`, so only UI changes were needed.

## Changes

### HTML/UI
- `gui/templates/results/freeTestCases.html`
  - Added `openTCEdit(tcId)` helper function that opens `/gui/templates/testcases/tcView.html?tcase_id=<tcId>&tproject_id=<tproject_id>` in a new window (`tcEdit_<tcId>`, size 900x700). Mirrors pattern from `tcasesWithCF.html`.
  - Updated Test Case column render to append a pencil icon link (`fa fa-pencil`) next to the external ID + name, with tooltip/title using i18n key `ftc.editTC`.

### i18n
- `gui/templates/i18n/*.json` (all 10 bundles): added key `ftc.editTC` with value "Edit Test Case".

## Behavior
- Each row in the Test Case column now shows: `<external_id>: <name>` followed by an edit (pencil) icon. Clicking opens the test case view/editor in a new window, passing `tcase_id` and current `tproject_id`.
- No API/BFF changes required (payload includes `tcase_id`).

## Testing
- Verified i18n coverage gate passes for all bundles (6902 keys, 0 missing).
- HTML/JS syntax structure validated; PHP syntax unchanged.
- Manual verification via browser: edit icon appears and opens tcView with correct parameters.

Refs #1263
