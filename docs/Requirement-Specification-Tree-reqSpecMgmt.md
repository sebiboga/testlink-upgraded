# Requirement Specification Management — hierarchical specification tree (Refs #1026)

Port of the legacy **requirement-spec tree** semantics (TestLink 1.9.20
`lib/requirements/reqSpecListTree.php` + `lib/ajax/getrequirementnodes.php?mode=reqspec`
+ `generate_reqspec_tree()` / `render_reqspec_treenode()` / `prepare_reqspec_treenode()`
in `lib/functions/treeMenu.inc.php`) into the modernized
`reqSpecMgmt` screen (`gui/templates/requirements/reqSpecMgmt.html` +
`api/reqspec/index.php`).

## The gap

Before this change the modern screen was a flat DataTable:

* `action=specs` selected `nh.node_order` but never `nh.parent_id`, and the
  response map emitted neither — a child spec (RS-200 under RS-100) came back
  as a top-level sibling;
* the `Reqs` badge was `COUNT(*) FROM requirements GROUP BY srs_id`, i.e. the
  **direct** count, where legacy showed the **subtree** count
  (`child_req_count` in `treeMenu.inc.php:1935-1960` adds the counters of every
  nested child spec);
* the project-wide total (`count(get_all_requirement_ids())`,
  `tlRequirementFilterControl.class.php:275-280`) was not exposed at all;
* no expand/collapse, no indent, no nesting indicator;
* this screen could not create a nested spec: `create_spec` already accepted
  `parent_id` (Refs #1345) but the modal had no parent field.

## What is implemented now

### BFF — `api/reqspec/index.php`, `action=specs`

| field | meaning | legacy counterpart |
|---|---|---|
| `parent_id` | hierarchy parent, `0` = top level (test project node / orphan / self-parent / cycle) | `nodes_hierarchy.parent_id` |
| `node_order` | sibling order | `position` (`node_order`) |
| `child_specs` | number of directly nested specs | — (implicit in the tree) |
| `subtree_reqs` | **own + all nested child specs** requirements | `child_req_count`, `treeMenu.inc.php:2149-2150` |
| `req_count` | direct requirements only (unchanged, now the tooltip) | — |
| envelope `total_reqs` | project subtree total | root node `"{$name} ({$req_qty})"` |
| envelope `child_requirements_mgmt` | `req_cfg->child_requirements_mgmt` (`config.inc.php:1689`) | `getrequirementnodes.php:46-51` `forbidden_parent` guard |

The subtree counters come from a single post-order pass over the
already-loaded rows (`$resolve` closure, `api/reqspec/index.php:658-680`), so
the whole screen costs **one** extra query instead of the legacy
`tree::getAllItemsID()` N+1 (one recursive query per visible node).

### Screen — `gui/templates/requirements/reqSpecMgmt.html`

* `orderedSpecTree()` does a depth-first walk over `parent_id`, siblings sorted
  by `node_order` then `id`; a cycle guard prevents an infinite walk.
* The DataTable is created with `order: []` + `paging: false` so the tree order
  is not destroyed (DataTables otherwise re-sorts by column 0).
* Each spec with children gets a `+`/`-` toggle; the toolbar gets
  **Expand all** / **Collapse all** (legacy `inc_tree_control.tpl:15-27`
  `tree.expandAll()` / `tree.collapseAll()`).
* Collapsed state is persisted in `localStorage` per test project
  (`TL_req_spec_mgmt_collapsed_<tproject_id>`), mirroring the legacy
  `TreePanelState` / `LocalStorageProvider` cookie prefix scheme.
* The section header shows the legacy root-node count:
  `total requirements in <project>: 4`.
* The create modal gained a **Parent specification** select (dotted-path
  options, a spec can never be its own parent); the picker is only offered when
  `child_requirements_mgmt` is ENABLED and the user may manage specs. A per-row
  *New child specification* action pre-fills it.

## Bug found and fixed while porting

`needOwnedSpec()` ended with `return @$reqSpecMgr->get_by_id(...)`. **No caller
uses the return value** — it is purely an existence/ownership guard. But
`get_by_id()` resolves the latest revision through the `latest_rspec_revision`
view and builds `... AND RSPEC_REV.id = ` with a NULL child id when that row is
absent. That is an uncaught database Exception, not a suppressible warning, so
`@` did nothing and the request died with **HTTP 500 / empty body**.

Reproduced: `POST action=create_spec` with `parent_id: 0` → `200 {"status":"ok"}`,
with `parent_id: 10` → `500` (empty body,
`Uncaught Exception: Database error (query failed) … requirement_spec_mgr.class.php(218)
… api/reqspec/index.php(138) needOwnedSpec()`). This made the **entire**
nested-spec creation path — including the `reqSpecView.html` "New Child Spec"
button shipped with #1345 — unusable. Fix: drop the dead `get_by_id()` call and
return `true` after the ownership probe.

## i18n

13 new keys in **all 10** locale bundles (`de, en, es, fr, it, ja, pt, ro, ru, zh`):
`rs.expandAll`, `rs.collapseAll`, `rs.expandNode`, `rs.collapseNode`,
`rs.treeHint`, `rs.totalReqsOf`, `rs.reqsInSubtree`, `rs.childSpecs`,
`rs.childSpecsShort`, `rs.newChildSpec`, `rs.parentSpecLabel`,
`rs.parentSpecHint`, `rs.parentTopLevel`.

## Files

| file | purpose |
|---|---|
| `api/reqspec/index.php` | `specs` tree model + `needOwnedSpec()` fix |
| `gui/templates/requirements/reqSpecMgmt.html` | tree DataTable, toggles, total line, parent picker |
| `gui/templates/i18n/*.json` | 13 new keys × 10 locales |
| `CHANGELOG` | `[FEATURE GAP] - #1026` entry |
| `docs/screenshots/issue-1026-spec-tree.png` | the rendered tree |
