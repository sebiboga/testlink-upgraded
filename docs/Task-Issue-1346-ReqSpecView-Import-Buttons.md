# Task 1346 — Import Reqs / Import Req Spec buttons in reqSpecView (branch + items scope)

**Issue:** [#1346](https://github.com/sebiboga/testlink-upgraded/issues/1346)
**Status:** IMPLEMENTED — branch `task/issue-1346`, commit `3947c69f7`, `1e2ea8538`

## The gap

The modern Requirement Specification Viewer (`gui/templates/requirements/reqSpecView.html`)
offered no import entry points. The legacy 1.9.20 screen had two buttons in the
toolbar, both gated on the `req_mgmt` right:

- `gui/templates/dashio/requirements/include/reqSpecViewButtons.inc.tpl:54-57`
  (`btn_import_req_spec`) → `reqImport.php?scope=branch&req_spec_id=<id>`.
- `gui/templates/dashio/requirements/include/reqSpecViewButtons.inc.tpl:109-112`
  (`btn_import_reqs`) → `reqImport.php?req_spec_id=<id>`.

The URLs were built in `reqSpecView.tpl:28-29` / `:34-35`. Additionally
`lib/requirements/reqSpecView.php::initialize_gui` renamed the **branch** button to
`"Import via API (<reqmgr_name>)"` (`importViaAPI` label, `reqMgrSystemEnabled = 1`)
whenever `reqSpecCommands::getReqMgrSystem()` returned a non-null linked
requirement-management system — itself gated on `testprojects.reqmgr_integration_enabled`
(→ `tlReqMgrSystem::getLinkedTo($tproject_id)`).

Scope semantics in legacy `lib/requirements/reqImport.php`: `scope` defaults to
`items` (import requirements **into** the selected spec; file types
csv / csv_doors / XML / DocBook via `reqMgr->get_import_file_types()`); `scope=branch`
imports a whole spec-tree XML **as a child branch of** the selected spec (file types
XML only via `reqSpecMgr->get_import_file_types()`, `reqImport.php:204-218`).

## Modern implementation (port)

**BFF — `api/reqspec/index.php` (`spec_view` action):**
- New `req_mgr_system` field in the response `{id, name, type}` (or `null`).
  Computed exactly like legacy: if `testprojects.reqmgr_integration_enabled` for the
  spec's owner project, `tlReqMgrSystem::getLinkedTo($tproject_id)`; `type` prefers the
  verbose type when present. Drives the "Import via API (name)" label switch.

**HTML — `gui/templates/requirements/reqSpecView.html`:**
- `#importItemsLink` (Import Reqs) → `reqImport.html?req_spec_id=<spec>&tproject_id=<tp>`
  (items scope, spec preselected).
- `#importBranchLink` (Import Req Spec) → `reqImport.html?scope=branch&req_spec_id=<spec>&tproject_id=<tp>`
  (branch scope, spec preselected), label switches to `rsv.importViaApi` ("Import via
  API ({system})") when `r.req_mgr_system.name` is present, else `rsv.importReqSpec`.
- Both buttons share the `r.rights.manage` (`req_mgmt`) gate of the existing
  Create-Requirement / Create-from-Issues buttons (hidden by default, `.show()` inside
  the `manage` branch of `showHideActions()`).

**HTML — `gui/templates/requirements/reqImport.html` (import screen):**
- New `scope` query param honored: `scope=branch` shows the `#targetGroup` +
  `#branchNote` (new `reqimp.branchHint` message) and forces the spec-tree (XML)
  import types in `renderTypes()` independent of the selected target
  (`isTree = scope === 'branch' || specSelect === '0'`). The existing
  `data-preselect` mechanism preselects the target spec from `req_spec_id`.

**i18n:** new keys `rsv.importReqs`, `rsv.importReqSpec`, `rsv.importViaApi`,
`reqimp.branchHint` added to all 10 locale bundles, all validated via
`python3 -m json.tool`.

## REST API

`GET ?action=spec_view&id=N[&tproject_id=N]` returns `req_mgr_system`
(`{id,name,type}` or `null`). Import behaviour itself was already present in
`api/reqimport/index.php` (options/import) and required no changes — items scope with a
req_spec_id nests req-spec XML under the selected spec; the tree scope served the branch
case. Rights unchanged (`mgt_view_req` + `mgt_modify_req` on import).

## Testing

**Suite 1346 — Task Issue #1346** in `tmp/TLU_Test_Cases.md` (**8/8 PASS**):
both buttons + plain labels on Alpha (no reqmgr) and "Import via API (JIRA)" on Beta;
items-scope deep link (spec preselected, CSV/CSV (Doors)/XML/DocBook, no branch note);
branch-scope deep link (XML-only, branch note); end-to-end branch import
(`reqspec-starttrek-example1.xml` → 12 nodes created under SPEC-001, DB-verified parent
ids); end-to-end items import (`req-test1.xml` → child spec + 3 reqs under BSPEC-001);
non-manager gate (buttons hidden); Event Viewer clean (single INFO login event, no
ERROR/WARNING).

Screenshots: `docs/screenshots/issue-1346-alpha-viewer-buttons.png`,
`docs/screenshots/issue-1346-beta-viewer-jira-label.png`.

## Files

| File | Purpose |
|---|---|
| `api/reqspec/index.php` | `spec_view` now returns `req_mgr_system` (linked req-mgr → "Import via API" label) |
| `gui/templates/requirements/reqSpecView.html` | `#importItemsLink` + `#importBranchLink` buttons, hrefs + label switch + `req_mgmt` gate |
| `gui/templates/requirements/reqImport.html` | `scope=branch` support: branch note, target group, spec-tree XML types |
| `gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json` | `rsv.importReqs`, `rsv.importReqSpec`, `rsv.importViaApi`, `reqimp.branchHint` |
| `docs/screenshots/issue-1346-*.png` | viewer toolbar evidence (Alpha plain, Beta JIRA) |