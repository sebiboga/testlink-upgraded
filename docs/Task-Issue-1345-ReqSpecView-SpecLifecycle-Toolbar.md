# Task 1345 — ReqSpecView spec lifecycle toolbar (New Req Spec / Edit / Delete)

**Issue:** [#1345](https://github.com/sebiboga/testlink-upgraded/issues/1345)
**Status:** IMPLEMENTED — branch `task/issue-1345`

## The gap

The modern Requirement Specification Viewer (`gui/templates/requirements/reqSpecView.html`)
was a read-only viewer that dropped the legacy **Requirement Specification
Operations** toolbar. Legacy 1.9.20 renders a `req_operations` fieldset
(`gui/templates/dashio/requirements/include/reqSpecViewButtons.inc.tpl:38-52`):

- **New Req Spec** (`btn_new_req_spec`, gated on `req_cfg->child_requirements_mgmt == ENABLED`,
  config.inc.php:1661) → `reqSpecEdit.php?doAction=createChild&parentID=<spec_id>`.
- **Edit** (`btn_edit`) → `reqSpecEdit.php?doAction=edit`.
- **Delete** (`btn_delete`) → `reqSpecEdit.php?doAction=doDelete&req_spec_id=`.
- All three gated on `grants->req_mgmt == yes` (`mgt_modify_req`).

The modern port dropped all three, leaving managers no in-viewer way to create a
child spec, edit or delete a spec (the modern mgmt screen `reqSpecMgmt.html`
covers the spec list, but the viewer was a dead end).

## Modern implementation (port)

Pattern mirrors the sibling `reqSpecMgmt.html` modal UX (specModal/delModal).

**BFF — `api/reqspec/index.php`:**
- `create_spec` accepts an optional **`parent_id`** (validated with
  `needOwnedSpec` — the parent must exist and be owned by the target project).
  It passes `parentId` into `requirement_spec_mgr::create($tproject_id,$parentId,
  $doc_id,$title,$scope,$countReq,$user_id,$type,$node_order,$options)` → child
  semantics (node created under the parent spec's tree node, exactly like the
  legacy `createChild` URL `parentID=` param). Without `parent_id` the behaviour
  is unchanged (root-level spec under the test project).
- `options` now exposes **`childRequirementsManagement`** (`boolishConfig($cfg,
  'child_requirements_mgmt', true)` over `$tlCfg->req_cfg`), so the client can
  apply the legacy New Req Spec gate.
- `spec_view` returns **`spec.child_requirements_mgmt`** (same flag, for
  deep-linked viewers that never hit `options`) and, when `?full=1&id=` is used
  to fetch a single spec, a top-level **`specTypes`** map (spec-type id → label)
  so the modal's type `<select>` renders labeled options even on deep links.

**HTML — `gui/templates/requirements/reqSpecView.html`:**
- Toolbar group `#specOpsGroup` (label + `#newSpecBtn`/`#editSpecBtn`/
  `#deleteSpecBtn` ghost buttons) after the `#createReqBtn`; shown by
  `showHideActions()` when the BFF `spec_view` payload reports `r.rights.manage`
  (`mgt_modify_req`), New Req Spec additionally gated on
  `r.spec.child_requirements_mgmt` (hide path included — the group shrinks to
  Edit + Delete when child management is disabled).
- `openSpecChildCreate()` opens `#specModal` pre-bound to the current spec as
  parent (doc id input, title, type `<select>` via `fillSpecTypeSelect()`
  defaults to the current spec type, declared total requirements, scope textarea).
- `openSpecEdit()` opens the same modal prefilled from `SPEC` (doc id, title,
  type, `spec.total_req`, latest-revision scope).
- `saveSpecModal()` branches: child-create `POST action=create_spec` with
  `parent_id=SPEC_ID` vs edit `POST action=update_spec` (same payload shape the
  mgmt screen uses). On success → toast `rsv.specSaved` + reload.
- `confirmDeleteSpec()` → `#delModal` (spec title + `rsv.deleteSpecConfirm`),
  `doDeleteSpec()` → `POST action=delete_spec`; success → toast `rsv.specDeleted`
  + reload (the spec is gone, the viewer falls to its localized 404 state).
- META merge for `specTypes` added in `loadView()` for deep-link runs.

## i18n

New keys under `rsv.*` added to all 10 locale bundles
(`en ro de es fr it ja pt ru zh`): `specOperations` ("Requirement Specification
Operations"), `newReqSpec`, `editSpec`, `deleteSpec`, `newChildSpec`,
`editSpecHeading`, `specSaved`, `specDeleted`, `deleteSpecConfirm`.
All bundles validated with `python3 -m json.tool`.

## REST API

- `POST ?action=create_spec` — additional optional `parent_id`: child-spec
  creation under the given parent. Rights unchanged: `mgt_view_req` +
  `mgt_modify_req` (`needManageRight`), plus `needOwnedSpec` on `parent_id`.
- `GET ?action=options` — new `childRequirementsManagement` boolean.
- `GET ?action=spec_view&id=N[&tproject_id=N]` — new `spec.child_requirements_mgmt`
  boolean; `/spec_view?...&full=1&id=N` additionally carries `specTypes` map.

## Testing

**Suite 1545 — Task Issue #1345** in `tmp/TLU_Test_Cases.md` (**9/9 PASS**):
toolbar buttons visible for manager on `reqSpecView.html?id=2`; child spec
created via the modal appears under its parent (DB node parent check); edit
persists title on node + doc-id + latest-revision scope; deep link without
`tproject_id` resolves; delete removes subtree incl. revision rows + 404 state;
`child_requirements_mgmt = DISABLED` hides only New Req Spec (config.inc.php
flip, reverted); Event Viewer shows no new Error/Warning rows; console clean.

Screenshots: `docs/screenshots/issue-1345-toolbar.png`,
`docs/screenshots/issue-1345-delete-modal.png`. Fixture: `tmp/fixtures_1345.php`.

## Parity note (total_req)

The "declared total requirements" number is **not** persisted by the legacy
engine itself: `requirement_spec_mgr::create_revision()` omits `total_req` from
its INSERT (column defaults 0) and plain `update_revision()` (no new revision)
only touches `scope`/modifier. The modern `create_spec`/`update_spec` reuse the
same engine calls, so the behaviour is bit-for-bit legacy parity (and matches
the sibling `reqSpecMgmt.html` screen). Not treated as a regression.

## Files

| File | Purpose |
|---|---|
| `api/reqspec/index.php` | `create_spec` `parent_id` child semantics; `childRequirementsManagement` in options; `spec.child_requirements_mgmt` + `specTypes` in spec_view |
| `gui/templates/requirements/reqSpecView.html` | `#specOpsGroup` toolbar, `#specModal`/`#delModal`, JS CRUD handlers, META specTypes |
| `gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json` | 9 new `rsv.*` keys |
| `tmp/fixtures_1345.php` | re-runnable fixture: project RSV1345 + root spec SRS-PARENT-001 |