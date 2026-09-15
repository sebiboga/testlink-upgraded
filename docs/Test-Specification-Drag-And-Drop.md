# Test Specification — Drag & Drop Move / Copy (Task #910)

**Screen:** `gui/templates/testcases/testSpec.html` (modern Test Specification tree)
**BFF API:** `api/testcases/index.php` — two new POST actions:
`action=move` and `action=copy` (session-based auth, JSON I/O)
**Rights:** `mgt_modify_tc` for both actions (same as legacy
`lib/ajax/dragdroptprojectnodes.php` / `mgt_modify_tc` gate)
**Replaces:** legacy HTML5 DnD in
`lib/testcases/listTestCases.php` + `gui/javascript/treebyloader.js` +
`lib/ajax/dragdroptprojectnodes.php`.

---

## What it does

Test cases and test suites in the modern Test Specification tree can be
**moved** or **copied** (deep copy) to another test suite in the same test
project, two ways:

1. **Context menu** — right-click a suite/test-case offers **Cut / Copy /
   Paste**. Cut/Copy put the node on the clipboard; Paste (on a suite or on
   the tree root) opens a **target picker modal** (suites shown indented,
   current ancestor excluded, position Top/Bottom radio).
2. **HTML5 drag & drop** — drag a node onto a target suite (highlighted on
   hover); **Alt+drag copies**. Position = bottom of the target by default.

Copy options (checkbox group, only in copy mode): copy keywords,
copy requirements, copy only latest version, copy as ghost.

## BFF behavior

**`POST ?action=move`** body `{node_id, type: 'testsuite'|'testcase', new_parent_id, position: 'top'|'bottom'}`:
- validates node exists + type matches; rejects `new_parent_id === node_id` (`Cannot move a node into itself`);
- `$checkWrite($nodeId)` requires `mgt_modify_tc`;
- destination must resolve into the same test project via `owningProjectOf`;
- a testsuite cannot be moved into its own descendant (`Cannot move a suite into its own child/descendant`);
- `new tree($db)` → `tree::change_parent($nodeId, $newParent)`; then
  `tree::change_child_order($newParent, $nodeId, $position, $excludeNodeTypes)`
  (roots/testplans excluded from reordering) — mirrors legacy
  `dragdroptprojectnodes.php`.

**`POST ?action=copy`** body `{node_id, type, new_parent_id, position, options}`:
same guards, then `testcase::copy_to()` / `testsuite::copy_to()` (recursive
deep copy) — mirrors legacy `do_copy` in `tcEdit.php` / `containerEdit.php` —
followed by `change_child_order`.

Errors are returned as HTTP 400/500 with a localized toast on the client.

## i18n

New `tspec.*` keys (`cut`, `copy`, `paste`, `moveTo`, `copyTo`, `moveHere`,
`copyHere`, `position`, `positionTop`, `positionBottom`, `copyOptions`,
`copyKeywords`, `copyRequirements`, `copyOnlyLatestVersion`, `copyAsGhost`,
`clipboardEmpty`, `operationFailed`, `targetSuite`, `moving`, `copied`,
`moved`, `confirmMove`, `confirmCopy`, `errCannotMoveToDescendant`,
`errCannotMoveToSelf`, `errCannotDropOnSelf`) added to **all 10 locale
bundles** (`en, de, es, fr, it, ja, pt, ro, ru, zh`), all valid JSON.

## Bugs found & fixed

1. DnD suite move was rejected with `Cannot move a suite into its own child`
   even for valid moves: `$db->fetchFirstRow()` returns **`false`** (not `null`)
   on an empty result set, so `is_null()` never matched. Both descendant
   checks now use `!empty(...)`.

## Test cases

See `tmp/TLU_Test_Cases.md` → **Task — Issue #910** suite (15/15 PASS):
BFF move/copy + recursive suite copy, self/descendant/unknown-node rejects,
top/bottom positions, context-menu cut/copy/paste, DnD move, Alt+drag copy,
i18n, Event Viewer + console clean. Refs #910.