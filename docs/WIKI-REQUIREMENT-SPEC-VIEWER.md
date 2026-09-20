# Requirement Specification Viewer — Modernized Screen

**Type:** popup viewer (not an ASIDE entry) — reached from *Search Requirement
Specifications*, requirement-spec links in requirement views and the TestLink
search results. Refs
[#755](https://github.com/sebiboga/testlink-upgraded/issues/755).

**URL:** `gui/templates/requirements/reqSpecView.html?id=<req_spec_id>`
(`&tproject_id=<id>` optional — the API resolves the owning project itself)
**BFF API:** `api/reqspec/index.php`, JSON I/O, session-based auth.


**Replaces:** legacy popup `lib/requirements/reqSpecView.php` +
`gui/templates/dashio/requirements/reqSpecView.tpl`.

---

## What it does

Read-only detail view of one requirement specification — the Dashio rewrite of
the legacy spec viewer. Nothing from the legacy screen is dropped; the
declared/modify/freeze/revision actions that pointed at separate legacy screens
are inlined (freeze, new revision) or kept as navigation (spec management, req
viewer popup).

| Section | Content |
|---|---|
| Toolbar | Refresh, **Open in Spec Management** (jumps to the modern `reqSpecMgmt.html` of the owning project), **New Revision** and **Freeze all Requirements** (visible only with `mgt_modify_req` on the owning project) |
| Overview | identifier chip (`doc_id`) + spec title, current **revision** badge, **type** badge, declared total requirements, requirements in spec, revisions count, created by / on, last modified by / on |
| Scope | full scope note (from the current revision, `white-space: pre-wrap`) |
| Custom fields | design-time fields linked to **requirement_spec**, values of the **current revision** (date/datetime fields localized by the BFF) |
| Attachments | file list with title, file name, size, date and a **Download** link (`lib/attachments/attachmentdownload.php`); managers (`mgt_modify_req`) additionally get an **upload form** (file + optional title) and a per-attachment **Delete** action |
| Requirements | DataTable with identifier, title (opens the modern `reqView.html?id=` popup), version, type and status — type/status labels localized server-side |

The popup title shows the project name, the toolbar info line shows
`#<spec_id> · Revision r<N>`, and the footer is the standard TestLink footer.

## Actions (rights: `mgt_modify_req`)

* **Freeze all Requirements** — mirrors legacy
  `reqSpecCommands::doFreeze()`: collects the whole spec **subtree**
  (child specs included), takes the **latest version** of every requirement
  under it and sets `is_open = 0` (via `requirement_mgr::updateOpen`). One
  `audit_req_version_frozen` audit event is logged **per requirement version**
  (legacy parity). The confirm dialog asks first; the info banner reports how
  many versions were actually frozen.
* **New Revision** — mirrors legacy `doCreateRevision()` → `clone_revision()`:
  clones the latest spec revision with the supplied log message and a new
  author, then reloads the view (revision badge `r+1`, revisions count +1).
  Custom field values are copied to the new revision (`copy_cfields`, legacy
  behavior).
* **Attachment upload (manager)** — mirrors legacy
  `reqSpecEdit.php?doAction=fileUpload` / `attachments.inc.tpl`: a multipart form
  (file + attachment title) posts to `api/attachments/index.php?action=upload`
  with `table=req_specs` and the spec id. The title defaults to the file name
  when left empty. Errors (size/type limits, e.g.
  `TL_REPOSITORY_MAXFILESIZE` = 1 MB) surface as a red toast, legacy
  `file_upload_ko` alert parity.
* **Attachment delete (manager)** — mirrors legacy `jsCallDeleteFile` +
  `delAttachmentURL`: a `window.confirm` dialog, then
  `api/attachments/index.php?action=delete` (ownership-hardened: the file must
  belong to this spec), then the view reloads.

Both attachment controls stay hidden for view-only users (the BFF reports
`rights.manage=false`), exactly like legacy `$attach_downloadOnly=true`; the
download link stays available to everyone.

## Requirement Operations (Refs #1348)

Legacy `reqSpecViewButtons.inc.tpl:125-138` renders a `req_operations` fieldset
**only when** `grants->req_mgmt == yes`, and inside it three action links
**only when** the spec has requirements. The modern viewer keeps the exact gate:
a **Requirement Operations** group (`#reqOpsGroup`,
`gui/templates/requirements/reqSpecView.html:97-102`) appears in the toolbar
iff the BFF `spec_view` payload reports `rights.manage == true`
(`mgt_modify_req` on the owning project) **and**
`spec.requirements_count > 0` (`reqSpecView.html:492-496`). It hosts three
actions:

| Action | Modern screen | Legacy source |
|---|---|---|
| **Create Test Cases** | `reqCreateTestCases.html?spec_id=&tproject_id=` (already-modernized screen) | `reqSpecEdit.php?doAction=createTestCases` (`reqSpecView.tpl:41-43`) |
| **Copy Requirements** | `reqCopy.html?id=<spec>&tproject_id=` | `reqSpecEdit.php?doAction=copyRequirements` (`reqSpecView.tpl:59-61`) |
| **Bulk Monitoring** | `reqBulkMon.html?id=<spec>&tproject_id=` | `reqSpecEdit.php?doAction=bulkReqMon` (`reqSpecView.tpl:69-70`) |

### Copy Requirements screen (`reqCopy.html`)

Port of legacy `reqSpecCommands::copyRequirements()` → `reqCopy.tpl`. On load
the BFF `GET api/reqspec/index.php?action=copy_options&id=<spec>` returns the
spec's requirements (latest version each, `listSpecRequirements()`), the
project's req-spec **destination containers** (the tree subtree with
`get_subtree` + `createHierarchyMap('dotted', doc_id)` — testplans/testsuites/
testcases/requirements/revisions excluded, legacy parity) and project context.
The screen offers:
* a **choose target specification** select (dotted doc_id labels, e.g.
  `.SRS-RSV-002:Destination Specification RSV8`),
* a **Copy Test Case Assignments** checkbox (`copy_testcase_assignments`, on by
  default),
* a DataTable of the source requirements with per-row checkboxes (+ select-all),
* **Copy** → confirm dialog → `POST action=copy_reqs`
  (`req_spec_id`, `container_id`, `itemSet[]`, `copy_testcase_assignment`) →
  the BFF runs `requirement_mgr::copy_to()` per selected requirement with
  `copy_also.testcase_assignment` and logs an `audit_requirement_copy` **COPY**
  audit event per copy (`api/reqspec/index.php:1222-1271`). Success shows the
  localized "Copied requirements" summaries (id/errors lists mirror
  `doCopyRequirements()` message arrays); the table refreshes afterwards.
  Copied requirements get the ` (1)` doc-id suffix (legacy copy_to behavior).

### Bulk Monitoring screen (`reqBulkMon.html`)

Port of legacy `reqSpecCommands::bulkReqMon()` → `reqBulkMon.tpl`. On load the
BFF `GET action=bulk_mon_options&id=<spec>` returns the spec's requirements
with the current user's per-requirement monitor flag (`req_monitor` rows,
`getMonitoredByUser(user, tproject, ['reqSpecID'=>spec])` scoped to the spec)
plus `enable_start_btn` / `enable_stop_btn` parity flags. The screen renders a
DataTable with per-requirement **On/Off** monitor state and three submit
buttons — **Toggle Monitoring**, **Start Monitoring**, **Stop Monitoring** —
each posting `POST action=bulk_mon_toggle` (`op` = `toogleMon`/`startMon`/
`stopMon` + `itemSet[]`), mirroring `doBulkReqMon()`:
`toggle = getMonitoredByUser` flip (`monitorOff` when already On,
`monitorOn` otherwise), otherwise a straight `monitorOn`/`monitorOff` per
selected requirement (`api/reqspec/index.php:1278-1328`). The table re-renders
with the new flags and a localized "Monitoring toggled (N)" message.

Both copy/monitor BFF routes enforce the legacy rights: `mgt_view_req` to reach
the payload, `mgt_modify_req` (`needManageRight`) to write.

## Deleting / not-found & permissions

* Nonexistent spec (`get_by_id()` fatals on missing ids, Refs #569) is probed
  with a direct `SELECT testproject_id FROM req_specs WHERE id=N` first; the
  API answers HTTP 404 and the screen shows **"Failed to load requirement
  specification: Requirement specification not found"** while hiding the body.
* Without `mgt_view_req` the API answers HTTP 403 and the screen shows
  **"No permission"**; the Freeze / New Revision buttons are hidden in that
  case.
* Opening the screen with **no `tproject_id`** (e.g. from the search results
  deep link) works too: `spec_view` resolves the owning project itself and
  carries its own localized type/status labels.

## i18n

New keys under the `rsv.*` family were added to all ten locale bundles
(`gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json`); the requirement type/status and
spec type labels come from the existing server-side label maps.

## Deep links switched

* `gui/templates/requirements/searchReqSpec.html` — `openReqSpec()` now opens
  `reqSpecView.html?id=` (was `reqSpecView.php?req_spec_id=`).
* `gui/javascript/testlink_library.js` — `openLinkedReqSpecWindow()` (used by
  remaining legacy search screens) now targets the modern viewer URL.
* The revision links (`rev. N`) still point to the legacy
  `reqSpecViewRevision.php` — those are the next piece of #755.
