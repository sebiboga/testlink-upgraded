# Task 953 — Add-and-assign-to-current-project when creating a custom field in cfieldsView

**Issue:** [#953](https://github.com/sebiboga/testlink-upgraded/issues/953)
**Status:** IMPLEMENTED & VERIFIED (2026-09-22) — branch `task/issue-953`

## The gap

Legacy create form (Custom Fields) offers **two** submit buttons:

- `Add` (do_action `do_add`),
- `Add and assign (to current test project)` (do_action `do_add_and_assign`, the
  button at `gui/templates/dashio/cfields/cfieldsEdit.tpl:206-209`).

`lib/cfields/cfieldsEdit.php:44-45` routes both into `doCreate()`, and
`lib/cfields/cfieldsEdit.php:325-328` — right after a successful `create()` —
calls `$cfieldMgr->link_to_testproject($argsObj->tproject_id, array($ret['id']))`
for `do_add_and_assign`, so the new field is immediately linked to the active
test project (`tproject_id` from request, else `$_SESSION['testprojectID']`,
`cfieldsEdit.php:247-251`). That insert is the `cfield_testprojects` row.

The modern create modal only POSTed to `/api/cfields/` with no assignment
option — linking was possible only later, via the separate Assign screen
(`cfieldsAssignView.html`). Reproduced in browser: the modal showed
Label/Name/Type/Node Type/Possible Values/Enable On + a single **Save** button;
no project-assignment control, and the POST create route (`api/cfields/index.php:131-175`)
had no linking step.

## Server-side (Refs #953)

`api/cfields/index.php` POST `/` create route now accepts two extra body fields:

- `assign_to_project` (0/1) — requests the create-and-assign behaviour;
- `tproject_id` (optional) — the project id; when absent or 0 it falls back to
  the URL `tproject_id` query param then `$_SESSION['testprojectID']` via the
  existing `assignTprojectId()` helper (`api/cfields/index.php:280-286`).

Flow (mirrors legacy `cfieldsEdit.php:325-328`):

1. If `assign_to_project` is set and no project resolves (body → URL → session),
   the route returns **HTTP 400 `No test project selected` BEFORE creating** —
   this avoids creating a field that cannot be assigned, unlike the legacy path
   which would have passed `0`. A resolved-but-unknown project id returns
   **HTTP 400 `Test project not found`** (tree lookup, mirroring `GET /assignment`).
2. `$cfield_mgr->create($cf)` is unchanged.
3. On success, when `assign_to_project` was set:
   `$cfield_mgr->link_to_testproject($tprojectId, [$result['id']])` (the exact
   legacy call), then response fields `assigned:1` + `tproject_id`. The
   assignment is audited by `link_to_testproject()` itself (single ASSIGN event).

## Client-side

`gui/templates/cfields/cfieldsView.html`:

- A new `#assignProjectGroup` block (create-only) with checkbox `#editAssignProject`
  labelled `cf.assignToCurrentProject` — "Add and assign (to current test project)".
  `showCreateModal()` shows + resets it; `editCf()` hides it (legacy parity: the
  second button appears only on the create form).
- `saveCf()` on **create** sends `assign_to_project`
  (`$('#editAssignProject').is(':checked') ? 1 : 0`) plus `tproject_id` parsed
  from the page URL query string (the frame passes `tproject_id` to the screen);
  on **edit** no assignment payload is sent.
- Success toasts: create with `r.assigned===1` → `cf.msg.createdAndAssigned`;
  edit → `cf.msg.saved`. Added the `#toast` element + `.toast` CSS and a `toast()`
  helper following the `cfieldsAssignView.html` pattern.

## i18n

Three keys added to **all 10** locale bundles
(`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`):
- `cf.assignToCurrentProject` — checkbox label (wording matches the legacy
  button, `locale/en_GB/strings.txt:807`);
- `cf.msg.createdAndAssigned` — toast after a create-and-assign save;
- `cf.msg.saved` — toast after an edit save.

All bundles re-validated with `python3 -m json.tool`.

## Verification evidence (browser + DB, fixture: project "Demo Project" id 1)

- **Create w/o assign** (`plain_cf`): POST 200; `cfield_testprojects` empty for it.
- **Create w/ assign** (`assigned_cf`, checkbox checked): POST 200 with
  `assigned:1`, `tproject_id:1`; `cfield_testprojects` row (field_id=2,
  testproject_id=1, display_order=1); events "Custom field 'assigned_cf'
  created" + "Custom field 'assigned_cf' assigned to test project 1" (log_level 16).
- **Edit modal**: no assign checkbox (legacy parity).
- **No project** (`tproject_id=0`, assign checked): `#modalError` shows
  "No test project selected" / "Test project not found", nothing created (DB unchanged).
- **Toasts**: "Custom field created and assigned to current test project" after
  assigned create; "Custom field saved" after edit (label persisted to
  "Toast CF v2").
- **cfieldsAssignView regression**: Demo Project shows "Assigned(3)"
  [assigned_cf, shot_cf, toast_cf] and "Available(1)" [plain_cf].
- Event Viewer/`events`: only log_level 16 audit INFO — **0 Error/Warning**.
- Browser console clean; inline JS `node --check` clean; all 10 bundles valid.

Screenshots: `docs/screenshots/issue-953-cfield-create-assign-modal.png`,
`docs/screenshots/issue-953-cfield-create-assigned-toast.png` (mirrored to wiki).

Refs #953.