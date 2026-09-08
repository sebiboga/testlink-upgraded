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
`docs/screenshots/projectinfo-attachments-upload-delete.png`,
`docs/screenshots/issue-996-export-all-testsuites.png`
(also mirrored on the wiki).
**Export all test suites** (Refs #996): `docs/screenshots/issue-996-export-all-testsuites.png`.
**Generate Test Spec (HTML + Word)** (Refs #997):
`docs/screenshots/issue-997-testspec-links-admin.png`,
`docs/screenshots/issue-997-testspec-html-doc.png`.
**Import test cases / test suites** (Refs #995):
`docs/screenshots/issue-995-import-button.png`,
`docs/screenshots/issue-995-import-target.png`.
**Test Suite Operations — New test suite + Reorder A-Z** (Refs #994):
`docs/screenshots/issue-994-testsuite-operations.png` (toolbar + suites card),
`docs/screenshots/issue-994-new-suite-modal.png` (New Test Suite modal).

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
  (`projectsView.html`), **Export all test suites** (shown only when the user
  holds `mgt_modify_tc` **and** the project has ≥1 direct test-suite child —
  gated by `canDoExport` and `mgt_modify_tc` from the BFF response; opens the
  modernized export dialog `tcExport.html?tproject_id=<id>&containerID=<id>&useRecursion=1`
  which streams the `.testproject-deep.xml` file, Refs #996),
  **Generate Test Spec (HTML)** and **Generate Test Spec (Word)** (both shown
  only when the user holds `mgt_modify_tc`; they open `printDocument.php?type=testspec&level=testproject&allOptionsOn=1&id=<id>&format=0|4`
  in a new tab, generating the full Test Specification document for the whole
  project — HTML for `format=0`, Word/RTF download for `format=4`, Refs
  #997), **Import test cases / test suites** (shown only when the user holds
  `mgt_modify_tc`; it opens the modernized import screen
  `tcImport.html?containerID=<id>&tproject_id=<id>&intoProject=1&useRecursion=1`
  pre-targeted to import into THIS project, mirroring the legacy
  `importToTProjectAction`, Refs #995), **New Test Suite** and **Reorder test
  suites (A-Z)** (both shown only when the user holds `mgt_modify_tc`; they
  call the BFF write routes below — Refs #994), and Dashboard (`mainPage.html`).
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
(title, file_name, file_size, file_type, date_added, download_url),
top-level `canDoExport` (boolean — the project has ≥1 direct test-suite
child, mirroring legacy `testproject.class.php:778`) and
`grants { mgt_modify_product, mgt_view_tc, mgt_view_req, mgt_modify_tc }`.

**Write routes** (both require the `POST` method — non-POST → `405 Method
not allowed` — and `mgt_modify_product` on the owning project — `403 No
permission` otherwise):

- `POST ?action=upload&id=<project_id>` — multipart `uploadedFile` + optional
  `fileTitle` (defaults to the file name on the client side). Ports the legacy
  `fileUploadManagement($db, tprojectID, fileTitle, 'nodes_hierarchy')`
  path, including the repo's allowed-file-type / extension and size guards.
  `200` returns `{ status: ok, attachments: [...] }` (refreshed list);
  `422` with a descriptive message **and a machine `code`** on rejections
  (`allowed_files`, `allowed_filenames_regexp`, `empty_extension`,
  `fNameORfTypeOrfSize`, ...). The screen maps those codes to localized
  `piv.err*` bundle keys client-side (unknown codes fall back to the generic
  `piv.errUploadGeneric`).
- `POST ?action=delete&id=<project_id>&file_id=<id>` — deletes the attachment
  via the legacy `deleteAttachment($db, file_id, false)`. Before deleting, the
  BFF verifies the attachment **exists and is bound to this project node**
  (`fk_id`/`fk_table = 'nodes_hierarchy'`); a forged file_id → `404`.

**Test Suite Operations write routes** (Refs #994 — both require `POST` →
non-POST returns `405`, and `mgt_modify_tc` on the owning project → `403 No
permission: modify test cases required`):

- `POST ?action=new_suite&id=<project_id>` — JSON body `{ name, details }`.
  Ports the legacy `lib/testcases/containerEdit.php` `doAction=new_testsuite`
  path: empty name → `400 code=empty_name`; name failing the global
  `check_string($name, $g_ereg_forbidden)` (config.inc.php:2257 `/[\|]/i`) →
  `400 code=bad_chars`; then `testsuite::create($projectID, $name, $details,
  null, config_get('check_names_for_duplicates'), 'block')` → duplicate →
  `400 code=duplicate`; other failures → `400 code=create_failed`. On success
  fires `event_signal('EVENT_TEST_SUITE_CREATE')` and returns
  `{ status: ok, id, name, message: 'testsuite_created', suites: [...] }`
  (refreshed list).
- `POST ?action=reorder_suites_alpha&id=<project_id>` — ports the legacy
  `doAction=reorder_testproject_testsuites_alpha`
  (`reorderTestSuitesDictionary()`): `tree->get_children` excluding
  testplan/requirement/testcase/requirement_spec, map `id => strtolower(name)`,
  `natsort()`, `tree->change_order_bulk(array_keys)`; returns
  `{ status: ok, message: 'suites_reordered', suites: [...] }`.

**Errors:** `401 Not authenticated` (no/invalid session), `403 No permission`
(upload/delete without `mgt_modify_product`; new_suite / reorder without
`mgt_modify_tc` — messages distinguish the two), `404 Test project not found /
Attachment not found on this project`, `405 Method not allowed` (write
actions via GET), `400 Missing project id` / `Unknown action`.

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
- **Export all test suites** mirrors the legacy `containerView.tpl` control
  panel icon `btn_export_all_testsuites` (`containerView.tpl:150`), gated by
  `canDoExport` — set in `lib/functions/testproject.class.php:777-778` —
  and by the legacy `modify_tc_rights` block (`containerView.tpl:119`, i.e.
  `mgt_modify_tc`). The modern BFF computes `canDoExport` by counting the
  project's direct children that are not testplan/requirement-spec/testcase
  nodes — exactly the legacy `get_children($safeID, ['testcase','me','testplan'=>'me','requirement_spec'=>'me'])`
  semantics (only direct test-suite children make the project exportable).
  Clicking the button opens the pre-existing modern export dialog
  (`gui/templates/testcases/tcExport.html` + `api/testcasesexport/`) set to
  project-deep mode (`containerID = tproject_id` + `useRecursion=1`), which
  streams `<project>.testproject-deep.xml` — the same file the legacy
  `lib/testcases/tcExport.php` produced (Refs #996).
- **Generate Test Spec (HTML + Word)** mirrors the legacy `containerView.tpl`
  report icons `btn_gen_test_spec_new_window` (`containerView.tpl:154-155`,
  HTML `format=0`) and `btn_gen_test_spec_word` (`containerView.tpl:157-158`,
  Word `format=4`) at `level=testproject`, both under the legacy
  `modify_tc_rights` block (`containerView.tpl:119`, i.e. `mgt_modify_tc`);
  the modern toolbar links open `lib/results/printDocument.php` with the same
  `type=testspec&level=testproject&allOptionsOn=1&id=<project>&format=0|4`
  parameters. The backend `printDocument.php` authenticates via the session
  (`testlinkInitPage`) and gates on its own `checkRights()` → `testplan_metrics`;
  its `init_args()` does **not** parse `form_token`, so the legacy HTML link's
  `form_token` param is inert here and the modern links do not need it. No BFF
  change was required — `grants.mgt_modify_tc` was already returned by
  `api/projectinfo/index.php?action=info` (Refs #997). Caveat (pre-existing
  legacy behavior): `printDocument.php:341` derives the title/rights context
  from the session's active project, while `id` selects the subtree root — a
  deep link `?id=X` with a different session project Y keeps the legacy
  behavior   unchanged.
- **Import test cases / test suites** mirrors the legacy `containerView.tpl`
  project-level control-panel import icon
  `$importToTProjectAction` (`containerView.tpl:42-43,148`), gated by the same
  `modify_tc_rights == 'yes'` block (`containerView.tpl:119`, i.e.
  `mgt_modify_tc`). The modern toolbar link opens
  `gui/templates/testcases/tcImport.html?containerID=<project>&tproject_id=<project>&intoProject=1&useRecursion=1`
  — exactly the parameters the legacy `$importToTProjectAction` produced,
  landing the user on the modern import screen pre-targeted to import into the
  current project (`intoProject=1&useRecursion=1`). The modern `tcImport.html`
  already reads `containerID`/`tproject_id`/`intoProject`/`useRecursion`, so no
  change to the import screen was needed. The BFF already returned
  `grants.mgt_modify_tc` (api/projectinfo/index.php:209), so no BFF change was
  required either — only the toolbar link + i18n key (Refs #995).
- **Test Suite Operations — New test suite + Reorder A-Z** mirrors the legacy
  `containerView.tpl` management panel items `btn_new_testsuite` and
  `btn_reorder_testsuites_alpha` (`containerView.tpl:116-163`), both under the
  legacy `modify_tc_rights == 'yes'` gate (`containerView.tpl:119`, i.e.
  `mgt_modify_tc`). Creation is the exact `containerEdit.php`
  `doAction=new_testsuite` flow (`containerEdit.php:248-255` →
  `addTestSuite()` → `testsuite::create(..., config_get('check_names_for_duplicates'),
  'block')`, `containerEdit.php:729-765`), including the global forbidden-char
  regex `$g_ereg_forbidden` applied via `check_string()` (legacy
  `containerEdit.php:87-89`) and the duplicate guard; on success the legacy
  fires `EVENT_TEST_SUITE_CREATE` which the BFF preserves. Ordering is the
  legacy `doAction=reorder_testproject_testsuites_alpha` algorithm
  (`containerEdit.php:337-342,1381-1395`): direct children excluding
  testplan/requirement/testcase/requirement_spec, `natsort` on lowercased
  names, `change_order_bulk`. The BFF also exposes the direct-suite list as a
  new top-level `suites` array in `action=info` (id, name, node_order,
  details), which the screen renders as a read-only Test Suites card for ALL
  viewers (matches `mgt_view_tc` read context); only the two operations are
  gated on `mgt_modify_tc` (Refs #994).

## 4. i18n Keys

Namespace `piv.*` added to all 10 bundles (`de, en, es, fr, it, ja, pt, ro,
ru, zh`) as **flat dotted top-level keys** (the `TLi18n.t()` lookup requires
flat keys — see testing note), e.g. `piv.title`, `piv.overview`,
`piv.attachments`, `piv.manage`, `piv.dashboard`, `piv.optReqs`,
`piv.infoShort`. The Refs #933 write feature adds `piv.upload`,
`piv.uploadTitle`, `piv.chooseFile`, `piv.noFileSelected`, `piv.delete`,
`piv.deleteConfirm`, `piv.uploadOk`, `piv.uploadFail`, `piv.deleteOk`,
`piv.deleteFail`, `piv.addAttachment` — also in all 10 bundles. No
user-facing string is hardcoded in the screen. Refs #996 adds `piv.exportAll`
("Export all test suites") to all 10 bundles. Refs #997 adds
`piv.genSpecHtml` ("Generate Test Spec (HTML)") and `piv.genSpecWord`
("Generate Test Spec (Word)") to all 10 bundles. Refs #995 adds `piv.import`
("Import") to all 10 bundles. Refs #994 adds the Test Suite Operations keys
`piv.newSuite` ("New Test Suite"), `piv.reorderAlpha` ("Reorder test suites
(A-Z)"), `piv.reorderConfirm`, `piv.reordered`, `piv.errReorder`,
`piv.suiteName`, `piv.suiteDetails`, `piv.newSuiteTitle`, `piv.newSuiteIn`
("Create in project:"), `piv.createSuite`, `piv.suiteNameRequired`,
`piv.suiteCreated` ("Test Suite created"), `piv.errSuiteDuplicate`,
`piv.errSuiteEmptyName`, `piv.errSuiteBadChars`, `piv.errSuiteCreate`,
plus the read-only card `piv.testSuites` ("Test Suites"), `piv.suitesHint`,
`piv.noSuites`, `piv.order`, and `common.working` (spinner during ops) — all
to the same 10 bundles.

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

The `Task — Issue #997` suite in `tmp/TLU_Test_Cases.md` (8 cases, all PASS)
documents the **Generate Test Spec (HTML + Word)** gap: links visible for a
user with `mgt_modify_tc` (correct `format=0`/`format=4` hrefs), HTML doc
opens in a new tab with the full project spec (TOC + all suites/cases/steps),
Word doc returns the `.doc` attachment (200, `application/vnd.ms-word`,
`Content-Disposition: attachment`), both links hidden for a user without
`mgt_modify_tc`, `piv.genSpecHtml`/`piv.genSpecWord` present in all 10
bundles, and Event Viewer cleanliness (no Error/Warning).

The `Task — Issue #995` suite in `tmp/TLU_Test_Cases.md` documents the
**Import test cases / test suites** gap: link visible with the correct
`tcImport.html?...&intoProject=1&useRecursion=1` href for a user with
`mgt_modify_tc`, link hidden for a user without `mgt_modify_tc`, click lands
on the import screen pre-targeted at the current project, `piv.import` present
in all 10 bundles, and Event Viewer cleanliness (no Error/Warning).

The `Task — Issue #994` suite in `tmp/TLU_Test_Cases.md` (10 cases, all PASS)
documents the **Test Suite Operations** feature: `info` returns direct suites
in display order; admin sees the two operations + the read-only card; create
via modal (append + DB row); empty-name / duplicate / forbidden-char
rejections (UI message + BFF `400` codes); reorder A-Z rewrites node_order
(natsort, case-insensitive); a guest without `mgt_modify_tc` sees no
operations and gets `403` on both write routes; all `piv.*` keys + 
`common.working` in all 10 bundles; Event Viewer cleanliness.
