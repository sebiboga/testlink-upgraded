# Bugfix — Issue #1685: `reqTreeReorder.html` — drag & drop did not show the 'Unsaved changes' chip

**Number:** #1685  
**Status:** Fixed  
**Component:** `gui/templates/requirements/reqTreeReorder.html`  
**Area:** Requirements / Specification Tree Reordering

## Symptom
Reordering by drag & drop changed rows but the `#dirtyChip` ("Unsaved changes") never appeared, while the same reorder done with Up/Down/To top/To bottom buttons did show the chip. The actual persistence (`applyOrder()`) would still save if invoked, but the UI feedback was missing.

## Root cause
The row drop handler called `render()` after mutating `ITEMS`, but it did not call `markDirty()`. The button path (`nudge()`) did call `markDirty()`, creating asymmetrical behavior. `markDirty()` is the only code that toggles `#dirtyChip` based on whether `ITEMS.map(x=>x.id).join(',') !== SAVED`.

## Fix
Ensure the drop handler also calls `markDirty()` after re-rendering (this is the minimal, correct fix and matches the button path). The fix is applied in the current codebase and verified via browser reproduction.

## Verification
- Navigated to `reqTreeReorder.html?tproject_id=1&req_spec_id=2` with fixture data (TREE1681 / TR1-SPEC-A with 3 requirements)
- Dragged row 3 onto row 1: order updated; `#dirtyChip` became visible (`display:inline-block`); chip disappeared after "Discard changes"
- Applied the new order via "Apply order": DB order persisted, chip cleared correctly
- Button-based reordering still shows chip as before (regression check)

## Files changed
- `gui/templates/requirements/reqTreeReorder.html` (drop handler calls `markDirty()` after `render()`)

## Commits
- `8199bc93e` (historical fix in this screen's commit chain)
- `5472ce255` (this run's verification/commit)

## Reference
See also: [Reorder Requirements Tree (reqTreeReorder) Modernized](Reorder-Requirements-Tree-Modernized.md)
