# Test Project Information — Modernized Screen

Modernization of the **Test Project Information** viewer (the legacy
`lib/testcases/archiveData.php?edit=testproject` project "home" pane) —
GitHub issue
[#923](https://github.com/sebiboga/testlink-upgraded/issues/923), with the
attachment **upload/delete** gap closed by
[#933](https://github.com/sebiboga/testlink-upgraded/issues/933).

The legacy project homepage is replaced by a standalone Dashio page
(`gui/templates/projects/projectInfoView.html`) backed by a plain-PHP REST
BFF (`api/projectinfo/index.php`, session-based auth, JSON I/O). Every in-app
entry point that used `archiveData.php?edit=testproject` now points to the new
screen: the right frame of the Specification workspace, the common
`$actions->projectInfo` link, the info button on each project row in
`projectsView.html`, and the testproject-level "Cancel" targets of
`containerEdit.php` and `tcImport.php`.

**Path:** any project row's Info button, or the project home pane while
working on a test project
**URL:** `gui/templates/projects/projectInfoView.html?tproject_id=<id>` (BFF
also accepts `?action=info&id=<id>`)
**BFF API:** `api/projectinfo/index.php?action=info`
**Rights:** `mgt_view_tc` context read; the UI additionally receives grants
`mgt_modify_product` / `mgt_view_tc` / `mgt_view_req` to drive what is
shown. Attachment **upload/delete** additionally require
`mgt_modify_product` on the owning test project (Refs #933).

Screenshots: `docs/screenshots/projectinfo-view.png`,
`docs/screenshots/projectinfo-projectsview-info-btn.png`,
`docs/screenshots/projectinfo-view-ro.png`,
`docs/screenshots/projectinfo-attachments-upload-delete.png`
(also mirrored on the wiki).

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

- **Toolbar:** locale switcher, Refresh (re-fetches the BFF), Manage project
  (`projectsView.html`) and Dashboard (`mainPage.html`).
- **Overview card:** name, prefix, status (Active/Inactive), visibility
  (Public/Private), test-case counter and the project feature options
  (Requirements, Priority, Automation, Inventory) as chips.
- **Description card:** the `notes` field of the project.
- **Integrations card:** Issue tracker, Code tracker and Requirements
  management system on/off flags.
- **Attachments card:** all project attachments (DataTable with search, sort,
  pagination and a server-built Download link to
  `attachmentdownload.php?id=<id>`). When the user holds
  `mgt_modify_product`, an **upload form** (title + file picker + Upload
  button) is rendered above the table and every row gains a **Delete**
  button (with a confirm dialog) — mirroring the legacy
  `containerEdit.php` `doAction=fileUpload` / `doAction=deleteFile` actions
  of the project-level container (Refs #933). The card is hidden when the
  project has no attachments **and** the user cannot upload; otherwise it
  stays visible so the first attachment (or an upload) can be added.

## 2. REST API Reference

`GET /api/projectinfo/index.php?action=info` — session auth required.

| Param | Meaning |
|---|---|
| `id` | explicit project id (highest priority) |
| `tproject_id` | legacy alias (used by the HTML screen and the aside links) |
| *(none)* | falls back to the session's `testprojectID` |

**Response** `200`: `project { id, name, prefix, notes, color, active,
is_public, tc_counter, options { requirementsEnabled, testPriorityEnabled,
automationEnabled, inventoryEnabled }, flags { issueTrackerEnabled,
codeTrackerEnabled, reqmgrIntegrationEnabled } }`, plus `attachments[]`
(title, file_name, file_size, file_type, date_added, download_url) and
`grants { mgt_modify_product, mgt_view_tc, mgt_view_req }`.

**Write routes** (both require `mgt_modify_product` on the owning project —
`403 No permission` otherwise):

- `POST ?action=upload&id=<project_id>` — multipart `uploadedFile` + optional
  `fileTitle` (defaults to the file name on the client side). Ports the legacy
  `fileUploadManagement($db, tprojectID, fileTitle, 'nodes_hierarchy')`
  path, including the repo's allowed-file-type / extension and size guards.
  `200` returns `{ status: ok, attachments: [...] }` (refreshed list);
  `422` with a descriptive message on rejections.
- `POST ?action=delete&id=<project_id>&file_id=<id>` — deletes the attachment
  via the legacy `deleteAttachment($db, file_id, false)`. Before deleting, the
  BFF verifies the attachment **exists and is bound to this project node**
  (`fk_id`/`fk_table = 'nodes_hierarchy'`); a forged file_id → `404`.

**Errors:** `401 Not authenticated` (no/invalid session), `403 No permission`
(upload/delete without `mgt_modify_product`), `404 Test project not found /
Attachment not found on this project`, `400 Missing project id` / `Unknown
action`.

## 3. Legacy parity notes

- Project id resolution order mirrors legacy `archiveData.php`:
  `id` > `tproject_id` > session `testprojectID`.
- Attachments use the same `nodes_hierarchy` fk_table convention as the
  legacy project pane and the modernized suite viewer.
- Attachment write actions mirror `containerEdit.php` `doAction=fileUpload`
  and `doAction=deleteFile` for the `testproject` container level. The legacy
  gate is `testcase_mgmt`; the BFF uses `mgt_modify_product` — the right that
  governs project-level editing (as suggested in
  [#933](https://github.com/sebiboga/testlink-upgraded/issues/933)).
- Legacy `deleteAttachment(..., $checkOnSession=true)` requires the
  `s_lastAttachmentInfos` session list which the BFF never populated; the BFF
  calls it with the session check disabled and instead enforces an explicit
  `fk_id`/`fk_table` ownership guard before deleting.
- `execNavigator.php` / `planAddTCNavigator.php` still pass
  `?edit=testproject` to `execSetResults.php` / `planAddTC.php` — that is
  their own item-level switch, not a call to the retired viewer; left
  untouched.

## 4. i18n Keys

Namespace `piv.*` added to all 10 bundles (`de, en, es, fr, it, ja, pt, ro,
ru, zh`) as **flat dotted top-level keys** (the `TLi18n.t()` lookup requires
flat keys — see testing note), e.g. `piv.title`, `piv.overview`,
`piv.attachments`, `piv.manage`, `piv.dashboard`, `piv.optReqs`,
`piv.infoShort`. The Refs #933 write feature adds `piv.upload`,
`piv.uploadTitle`, `piv.chooseFile`, `piv.noFileSelected`, `piv.delete`,
`piv.deleteConfirm`, `piv.uploadOk`, `piv.uploadFail`, `piv.deleteOk`,
`piv.deleteFail`, `piv.addAttachment` — also in all 10 bundles. No
user-facing string is hardcoded in the screen.

## 5. Security

- Session gate: unauthenticated requests get `401` JSON; the page shows the
  `piv.loadError` banner.
- All SQL uses intval'd ids (`getDBTables`-whitelisted table names); the
  query input is numeric only.
- DB-derived values are rendered with `.text()`; the download URL is built
  server-side from an intval'd id.
- Write routes (upload/delete) are gated by `mgt_modify_product` on the
  owning test project and by the shared BFF same-origin guard (CSRF). File
  upload passes the legacy repository validation (allowed filenames regexp,
  extension allow-list, size limits); the delete guard verifies the
  attachment belongs to the requested project before issuing the delete.

## 6. Testing

Regression suite `TC-923.*` in `tmp/TLU_Test_Cases.md` (20 cases, all PASS):
row-infobutton navigation, every card, attachments DataTable + download,
hidden-card-on-empty, BFF 200/404/400/401, `?locale=ro` rendering, Refresh,
flat-key bundle validation, cache-busted bundle loads, zero console errors,
containerEdit/tcImport cancel-link output, and an Event Viewer sweep that
produced no new Error/Warning entries.

Regression suite `TC-933.*` in `tmp/TLU_Test_Cases.md` documents the
attachment **upload/delete** gap: upload via UI + BFF, refreshed table after
upload, delete via UI + BFF (with confirm), the fixed DataTable re-render
(DOM rows replaced, no stale entries), row removal when the last attachment
is deleted, read-only rendering for a user without `mgt_modify_product`
(no upload form / no delete buttons), 403 on write routes for that user,
forged file_id → 404, empty upload → 422, disallowed file type → 422, unknown
project → 404, and Event Viewer cleanliness.