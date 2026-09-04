# Test Project Information — Modernized Screen

Modernization of the **Test Project Information** viewer (the legacy
`lib/testcases/archiveData.php?edit=testproject` project "home" pane) —
GitHub issue
[#923](https://github.com/sebiboga/testlink-upgraded/issues/923).

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
shown.

Screenshots: `docs/screenshots/projectinfo-view.png`,
`docs/screenshots/projectinfo-projectsview-info-btn.png`,
`docs/screenshots/projectinfo-view-ro.png` (also mirrored on the wiki).

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
  `attachmentdownload.php?id=<id>`); the card is hidden entirely when the
  project has no attachments.

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

**Errors:** `401 Not authenticated` (no/invalid session), `404 Test project
not found`, `400 Missing project id` / `Unknown action`.

## 3. Legacy parity notes

- Project id resolution order mirrors legacy `archiveData.php`:
  `id` > `tproject_id` > session `testprojectID`.
- Attachments use the same `nodes_hierarchy` fk_table convention as the
  legacy project pane and the modernized suite viewer.
- The viewer is **read-only** (list + download). The legacy pane additionally
  exposed attachment **upload/delete**; those are intentionally out of scope
  for this screen (matching the pattern of `suiteView.html`) and are tracked
  as issue [#933](https://github.com/sebiboga/testlink-upgraded/issues/933).
- `execNavigator.php` / `planAddTCNavigator.php` still pass
  `?edit=testproject` to `execSetResults.php` / `planAddTC.php` — that is
  their own item-level switch, not a call to the retired viewer; left
  untouched.

## 4. i18n Keys

Namespace `piv.*` added to all 10 bundles (`de, en, es, fr, it, ja, pt, ro,
ru, zh`) as **flat dotted top-level keys** (the `TLi18n.t()` lookup requires
flat keys — see testing note), e.g. `piv.title`, `piv.overview`,
`piv.attachments`, `piv.manage`, `piv.dashboard`, `piv.optReqs`,
`piv.infoShort`. No user-facing string is hardcoded in the screen.

## 5. Security

- Session gate: unauthenticated requests get `401` JSON; the page shows the
  `piv.loadError` banner.
- All SQL uses intval'd ids (`getDBTables`-whitelisted table names); the
  query input is numeric only.
- DB-derived values are rendered with `.text()`; the download URL is built
  server-side from an intval'd id.
- No write endpoints — the BFF is GET-only.

## 6. Testing

Regression suite `TC-923.*` in `tmp/TLU_Test_Cases.md` (20 cases, all PASS):
row-infobutton navigation, every card, attachments DataTable + download,
hidden-card-on-empty, BFF 200/404/400/401, `?locale=ro` rendering, Refresh,
flat-key bundle validation, cache-busted bundle loads, zero console errors,
containerEdit/tcImport cancel-link output, and an Event Viewer sweep that
produced no new Error/Warning entries.