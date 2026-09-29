# Modernize: Reorder / Move Requirement Specifications — Issue #1027

*Task (feature gap) — legacy capability not ported during the reqSpecMgmt modernization.*

## The gap

Legacy 1.9.20 made the requirement-specification tree drag-and-drop
(`gui/templates/dashio/requirements/reqSpecListTree.tpl`, `treeCfg.enableDD`,
`treeCfg.dragDropBackEndUrl`, `treeCfg.useBeforeMoveNode`). Every gesture was posted to
`lib/ajax/dragdroprequirementnodes.php`:

| Legacy gesture | Legacy effect |
|---|---|
| `doAction=doReorder` | `tree::change_order_bulk(explode(',', $nodelist))` → `nodes_hierarchy.node_order` |
| `doAction=changeParent` | `nodes_hierarchy.parent_id` (plus `srs_id` for requirement nodes) |

`useBeforeMoveNode` was fed by `lib/ajax/getrequirementnodes.php:46-51`, which clears the
forbidden-parent guard only when `req_cfg->child_requirements_mgmt` is ENABLED.

The modern screen (`gui/templates/requirements/reqSpecMgmt.html`) had **no affordance at all**:
the toolbar offered Expand/Collapse/Create Spec/Import/Export, the row actions only
*New child spec / Edit / Delete*, and the BFF had no `move`/`reorder` action. The visible order
was frozen at creation (`update_spec` forces `node_order = null`), so with four top-level
specifications there was **no way at all** to put the fourth first, nor to nest it under another
spec — only delete + recreate, which loses the requirements, the revisions and the ids.

## What was implemented

### BFF — `api/reqspec/index.php`

| Route | Purpose |
|---|---|
| `POST ?action=reorder_specs` | `{tproject_id,parent_id,nodes_order:[spec_id,…]}` — rewrites `node_order` = array index for one parent's children. `parent_id` 0 = the test-project node. |
| `POST ?action=move_spec` | `{tproject_id,spec_id,new_parent_id,position:top\|bottom}` — re-parents one spec and honours the position with a real `node_order` write. |

Helpers: `childSpecIds()` (ordered children of a parent **node**, read order
`node_order ASC, id ASC` — the order the screen renders), `specNodePlacement()`,
`specSubtreeIds()` (inclusive subtree, the move cycle guard).

Validation (all of it absent in the legacy endpoint):

* `needManageRight()` — `mgt_modify_req` is required for **both** writes.
* **POST only** — the legacy shim mutated on a plain `GET`; `GET ?action=move_spec` now
  answers `404 Unknown action`.
* `needOwnedSpec()` proves the parent, the moved spec and the new parent belong to the
  addressed project.
* The submitted list must be **complete** (count equality), **duplicate-free** and every id
  must be a child of the addressed parent (membership test) — so a spec of another project
  cannot be reordered.
* No write when the order already matches (`{"status":"no_change"}`) — the comparison is an
  ordered list compare, not a set compare.
* A spec may not become its own parent nor a child of its own subtree.
* Nesting a spec under a spec is refused with `403` when
  `req_cfg->child_requirements_mgmt` is disabled (the legacy gate).
* Every write is audited through `tLog(..., 'INFO')`.

### Screen — `gui/templates/requirements/reqSpecMgmt.html`

* Toolbar **Reorder specifications** (`#btnReorderSpecs`).
* **Reorder modal** (`#reorderModal`): the spec tree flattened in tree order, parent
  indentation, drag handles, and per row *Up / Down / To top / To bottom / Move*.
  * An order gesture is only valid **inside one parent group**; a cross-parent drop is
    refused with `rso.errDifferentParent` — re-parenting is the Move gesture's business.
  * A specification and its **whole subtree** move as one contiguous block, so a child can
    never end up above its own parent.
  * *Discard changes* restores the server order; a dirty chip (`Unsaved order`) marks the
    pending state; *Apply order* first opens a confirmation modal
    (`#ordConfirmModal`, mirroring `reqTreeReorder.html`'s `confirmBox`) and then posts
    **one `reorder_specs` per changed parent group**, sequentially, so a failure stops the
    chain instead of leaving a half-applied tree.
* **Move modal** (`#moveModal`): parent `<select>` (dotted spec tree, **excluding the spec
  itself and its whole subtree** — the legacy forbidden-parent guard) + position top/bottom
  + error panel. The reorder modal is hidden while the move modal is up (two stacked
  Bootstrap 3 modals fight over the backdrop/scroll lock) and re-opened afterwards, rebuilt
  from the **reloaded** tree.
* Per-spec **Reorder / move requirements** link (`#btnReqReorder`) that hands the requirement
  half of the legacy gesture to the already-modernized `reqTreeReorder.html` (#1681), which
  was orphaned from the screen that replaced the tree.
* Rights: with `canManage = false` the toolbar/row buttons are hidden, the rows are not
  `draggable`, and the BFF answers `403`.

### i18n

30 new `rso.*` keys in **all** bundles (`de, en, es, fr, it, ja, pt, ro, ru, zh`).

## Legacy defects deliberately not reproduced

* `dragdroprequirementnodes.php` performed **no rights check, no ownership check** and read
  `$_REQUEST`, so a plain GET mutated the hierarchy. The modern routes are POST-only,
  session-authenticated, same-origin-checked and rights-checked.
* Its `init_args()` read a `top_or_bottom` argument that the switch never used, and
  `change_parent()` never touched `node_order`, so a moved node kept the order slot of its
  **old** parent. `position` is honoured here.
* `change_order_bulk()` renumbered exactly the ids it was given, leaving omitted siblings
  with stale orders. The list must be complete here.

## Verification

`tmp/TLU_Test_Cases.md` → suite `Task — Issue #1027` (17 cases, all PASS), executed against
`tmp/fixtures_1027.php` on a freshly imported database:
reorder modal + arrows + drag, cross-parent refusal, discard, apply + confirm + DB round-trip,
move under a parent, the requirement-reorder entry point, the read-only / no-rights user
paths, the BFF input matrix (duplicates, foreign ids, short lists, no-change, bad position,
unknown spec, GET-mutation hole) and a clean console + Event Viewer.

No new Error/Warning entry in `events` (`log_level` 16 = AUDIT only).
