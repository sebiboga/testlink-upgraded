# Test Project Create / Edit — Modernized Screen

Modernization of the **Test Project Create/Edit** screen (the legacy
`lib/project/projectEdit.php`, actions `create`/`edit`/`doCreate`/`doUpdate`/
`doDelete`/`setActive`/`setInactive`/`enableRequirements`/
`disableRequirements`) — GitHub issue
[#967](https://github.com/sebiboga/testlink-upgraded/issues/967).

The legacy admin-only form is replaced by a standalone Dashio page
(`gui/templates/projects/projectEdit.html`) backed by a plain-PHP REST BFF
(`api/projectedit/index.php`, session-based auth, JSON I/O). Every in-app
entry point that reached `lib/project/projectEdit.php` now points at the new
screen: the zero-test-projects admin bootstrap redirect in
`lib/functions/common.php` and the "Create/Edit" actions of the modernized
`projectsView.html` (rows' Edit button taskbar + toolbar).

**Path:** `gui/templates/projects/projectEdit.html` (create) or
`gui/templates/projects/projectEdit.html?tproject_id=<id>` (edit)
**BFF API:** `api/projectedit/index.php` (`?action=init|create|update|delete|toggle_active|toggle_requirements`)
**Rights:** `mgt_modify_product` (the legacy `checkRights()`);
the BFF also reports `mgt_view_events` so the screen can expose the event history.

Screenshots: `docs/screenshots/Test_Project_CreateEdit-create.png`,
`Test_Project_CreateEdit-edit.png`,
`Test_Project_CreateEdit-error.png`,
`Test_Project_CreateEdit-eventviewer.png`.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

- **Create mode** (no `tproject_id`): a **Source data** card appears only when
  at least one test project already exists, exposing **Create from existing
  Test Project?** — the legacy `copy_from_tproject_id` / `copy_as` clone.
- **Basic Information card:** project name (required), test-case ID prefix
  (required, with the legacy hint icon "Up to 16 characters allowed, but we
  recommend using at most five") and description (`notes`).
- **Features card:** Enable Requirements / Priority / Automation / Inventory
  checkboxes — the legacy `optReq`/`optPriority`/`optAutomation`/`optInventory`
  (priority and automation default on, matching `config.inc.php`
  `testcase_priority`/`testcase_automation` defaults).
- **Issue Tracker / Code Tracker / Requirement Management System cards:**
  integration dropdowns with an **Enabled** checkbox. The checkbox toggles the
  dropdown's disabled state (legacy `manageTracker('/` behavior); with no
  tracker interfaces registered the dropdowns list only "-- none --" and the
  checkboxes stay disabled.
- **Availability card:** **Active** and **Public** checkboxes (legacy
  `active`/`is_public`).
- **API Key card (edit mode only):** read-only display of the project's
  `api_key` (legacy parity — the key exists on `testprojects` and is shown,
  never edited).
- **Event history button (edit mode only):** opens the modern Event Viewer
  pre-filtered to the project via
  `eventviewer.html?objectId=<id>&objectType=testprojects`.
- **Save:** client validation (empty name / empty prefix dialogs reusing the
  legacy warning wording), server cross-checks (`crossChecksBFF` —
  duplicate name or duplicate prefix, with the legacy messages) then a
  success toast and auto-redirect to the modern `projectsView.html`.
  In edit mode the same flow saves through `update` and records the legacy
  `audit_testproject_saved` event.
- **Cancel:** returns to `projectsView.html`.

## 2. REST API Reference

Base: `api/projectedit/index.php`. Session cookie required; `mgt_modify_product`
(right `checkRights()`) gates every mutating route (the `init` route returns a
`canManage` grant and a `notAllowed` flag instead of a hard 403 so the screen
can render a friendly error).

### `GET ?action=init&tproject_id=<id>`
- `tproject_id` omitted / `0` → **create-mode** model: `mode: "create"`,
  `project: {id:0, name:'', prefix:'', description:'', api_key:'', active:1,
  isPublic:1, optReq:0, optPriority:1, optAutomation:1, optInventory:0}`,
  `copyFromProjects` (list of existing projects for the clone dropdown),
  `issueTrackers` / `codeTrackers` / `reqMgrSystems` option lists
  (from the DB tracker interfaces), grants
  `{canManage, mgt_view_events}`. 401 unauthenticated; 403 attempt against a
  project the user cannot manage is still reported through `canManage:false`.
- `tproject_id=<id>` → **edit-mode** model with the current row preloaded
  (options decoded to the feature booleans) and `mode: "edit"`. Returns 404
  for unknown project ids.

### `POST ?action=create`
Body (JSON, `application/json`): `copy_from_tproject_id`, `name`, `prefix`,
`description`, `active`, `isPublic`, `optReq/optPriority/optAutomation/optInventory`,
`issue_tracker_id`+`issue_tracker_enabled`,
`code_tracker_id`+`code_tracker_enabled`,
`reqmgrsystem_id`+`reqmgr_integration_enabled`.
- Cross-checks duplicates/empties (`Project name and prefix are required`,
  `Test Case ID prefix … already exists`, `Test Project name … already
  exists`), then legacy `testproject::create($item, ['doChecks'=>true,
  'setSessionProject'=>true])`, tracker apply
  (`applyTrackers`), `protectFromLockout`, and optional `copy_as` clone of the
  picked source project. Logs `audit_testproject_created` (and the role-add
  event) just like the legacy controller.
- 200 `{success,id,message}`; 400 on validation; 500 wrapped server errors.

### `POST ?action=update&tproject_id=<id>`
Same body semantics with partial-update safety (only fields present in the
body are touched). 404 unknown project; 400 empty name/prefix; cross-checks
exclude the edited project itself so a no-op save is legal. Logs
`audit_testproject_saved`.

### `POST ?action=delete&tproject_id=<id>`
Full legacy cascade `testproject::delete()`; 404 unknown; 409 when the
operation reports failure (message prefixed by `info_product_not_deleted_check_log`);
200 + `test_project_deleted` localized message.

### `POST ?action=toggle_active&tproject_id=<id>&enable=0|1` / `?action=toggle_requirements&tproject_id=<id>&enable=0|1`
Legacy one-liners `setActive`/`setInactive`/`enableRequirements`/
`disableRequirements` — active and requirement feature toggles.

## 3. Legacy parity notes

- Action map `create/edit/doCreate/doUpdate/doDelete/setActive/setInactive/
  enableRequirements/disableRequirements` → `init`(+mode)/`create`/`update`/
  `delete`/`toggle_active`/`toggle_requirements`.
- `testlinkInitPage($db,true,false,'checkRights')` → BFF `mgt_modify_product`
  gate on every mutating route + `canManage` grant on `init`.
- `doCreate` call shape preserved: `create($item,['doChecks'=>true,
  'setSessionProject'=>true])` then `enable_issue_tracker`/`enable_code_tracker`/
  `enable_reqmgr` equivalents (`applyTrackers`) and
  `protectFromLockout`.
- Legacy labels re-used verbatim where the keys exist (`locale/en_GB/strings.txt`
  lines 1636-1666): create-from prompt, prefix hint, the empty-name /
  empty-prefix warnings and the `Edit %s` / `Create a new project` captions.
- `web_editor` legacy field dropped by design (editor toolbar is outside the
  scope of the modernized project form).
- The zero-test-projects bootstrap path in `lib/functions/common.php` now
  redirects to `gui/templates/projects/projectEdit.html` instead of
  `lib/project/projectEdit.php?doAction=create` (this is how a fresh TestLink
  install with no projects is sent to the create form immediately after login).

## 4. i18n Keys

`pedit.*` flat namespace in all 10 locale bundles
(`gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json`) plus one footer
key (`footers.projectEdit`). Keys added:

`pedit.createProjectTitle`, `pedit.editProjectTitle` (interpolates `{name}` via
`TLi18n.t`), `pedit.subtitle`, `pedit.createFrom`, `pedit.createFromSection`,
`pedit.prefixHint`, `pedit.warningEmptyName`, `pedit.warningEmptyPrefix`,
`pedit.apiKey`, `pedit.eventHistory`, `pedit.createSuccess`,
`pedit.updateSuccess`, `pedit.saveError`, `pedit.loadError`, `pedit.notFound`,
`pedit.no`, `footers.projectEdit`.

## 5. Security

- **AuthN:** session cookie required; unauthenticated calls get 401
  (`$_SESSION['userID']` check shared with every other BFF).
- **AuthZ:** `mgt_modify_product` checked on every mutating route (403);
  `crossChecksBFF` guards duplicate names/prefixes with project scoping
  (`excludeId` for self on update).
- **Origin guard:** the shared `api/_guard.php` `bffSameOriginGuard()`
  (safe GET/HEAD pass; unsafe verbs need `X-Requested-With` or a matching
  `Origin`/`Referer`).
- **Output encoding:** all server-supplied option labels / project names are
  HTML-escaped (`esc()`) before `innerHTML` use; ids are `parseInt`'d.
- **No secrets:** API keys are displayed read-only, never logged or echoed
  into hidden inputs.

## 6. Testing

Full browser walkthrough (admin session): create with defaults and with
features toggled; empty-name and empty-prefix dialogs; duplicate name/prefix
server errors rendered inline; create-from-copy clone; edit-mode prefill +
update persistence; event-history popup pre-filtered; active/requirements
toggles; delete cascade. Verified in DB (`testprojects`,
`nodes_hierarchy`, `events`) that every write lands and each audit event
(`audit_testproject_created` / `audit_testproject_saved` /
`audit_testproject_deleted`) is recorded with no ERROR/WARNING rows. Suite in
`tmp/TLU_Test_Cases.md` (Refs #967); a legacy `copy_as` E_WARNING on empty
sources is tracked separately in issue #968.