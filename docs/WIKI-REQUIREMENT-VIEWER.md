# Requirement Viewer (reqView)

Modernized screen: **Requirement Viewer** (opened from Requirement Overview,
Requirement Monitoring Overview and Search Requirements). Refs
[#755](https://github.com/sebiboga/testlink-upgraded/issues/755).

## What it does

Read-only detail view of a single requirement — the Dashio rewrite of the
legacy popup `lib/requirements/reqView.php`, backed by a new JSON BFF action
`GET /api/requirements/view`. Nothing from the legacy screen was dropped:

| Section | Content |
|---|---|
| Toolbar | version selector (`vN rM`, closed versions tagged `*`), refresh, **New Revision**, monitoring toggle, **Print**, **Direct link** (toggle), **Help** |
| Print | opens the new print screen `printReq.html` in a resizable popup |
| Direct link | shows the requirement permalink (`linkto.php?tprojectPrefix=<T>&item=req&id=<docID>`) plus a version-specific link and a **Copy** button (clipboard API with `execCommand` fallback; teal toast on success) |
| Help | links to the GitHub wiki Requirement-Viewer page |
| Overview | identifier (`docID`), requirement title, requirement spec path, type, status, frozen, author, created / modified, coverage `pct% (covered/expected)` |
| Scope | approval-scope note text (legacy *"approval scope"* field) |
| Custom fields | values of requirement-level custom fields (dates localized by the BFF) |
| Linked Test Cases | DataTables grid: TC external id, test case name (opens the modern `tcView.html`), TC version |
| Relations | DataTables grid: relation, target requirement (`docID : title`, clickable), project, status |
| Monitors | DataTables grid: user login of every user monitoring the requirement (gated on `monitor_requirement` right; shows empty-state message when no monitors) |

Deleted requirements show a "This requirement no longer exists" banner and
an empty version selector; the permission-denied path shows
"Failed to load requirement: No permission".

## Version selector & monitor

- The selector lists every version of the requirement (newest first); a
  trailing `*` marks closed (frozen) versions. Switching versions reloads the
  whole view for that version.
- **Start/Stop monitoring** hits the existing `POST /api/requirements/monitor`
  action (`{reqId, action:'on'|'off'}`). Monitoring is per **requirement**
  (legacy parity), so the state survives version switches.
- The **Print** toolbar button opens
  `gui/templates/requirements/printReq.html?req_id&req_version_id&req_revision&tproject_id`
  in a popup. The print screen mirrors `tcPrint.html`: it calls
  `GET /api/requirements/index.php?action=req_print` and embeds the legacy
  generator output (`lib/requirements/reqPrint.php`) in a sandboxed iframe
  (Print / Back / Refresh toolbar, inline-anchor navigation, localized error
  banner on failure). The BFF resolves the requested revision (falling back to
  the version's stored revision) — without it the legacy generator crashed with
  a DB error.
- The **Direct link** toggle shows the requirement permalink
  (`basehref` + `linkto.php?tprojectPrefix=<code>&item=req&id=<docID>`,
  legacy `reqView.php` formula) and re-renders the version-specific link when
  the version selector changes.

## New Revision (Refs #1303)

Port of the legacy `reqViewVersionsViewer.tpl` "New Revision" button
(`doAction=doCreateRevision`) into the modern toolbar:

- **Button visibility** mirrors the legacy gates exactly
  (`reqViewVersionsViewer.tpl:49,62,106-111`): shown only when the caller has
  the `mgt_modify_req` grant (`req_mgmt`) on the requirement's own test project
  AND the displayed version is **open** (`is_open = 1`, i.e. not frozen).
- **Log prompt**: clicking opens `window.prompt` with
  `reqv.newRevisionPrompt` ("Revision log message:") — the modern equivalent of
  the legacy `ask4log()` callback. Cancel is a clean no-op; the prompt may be
  submitted empty (legacy parity).
- **Server-side write** `POST /api/requirements/index.php/versions/{id}/revision`
  with `{log_message}`: re-checks `mgt_modify_req` on the owning project
  (HTTP 403), rejects frozen versions (HTTP 409), 404s on unknown version ids.
  It mirrors `reqCommands::doCreateRevision` → `requirement_mgr::create_new_revision`:
  snapshots the version row into `req_revisions` (scope/status/type + custom
  fields via `copy_cfields`), keeps the OLD `log_message` in the snapshot
  (legacy `copy_version_as_revision` SELECT), then bumps `req_versions.revision`
  (+1), stamps `creation_ts`/`author_id` and stores the NEW log message.
- **Feedback**: teal toast `reqv.revisionCreated` and the whole view reloads —
  the version selector now reads `vN rM+1` and the history/compare features
  show the new snapshot.
- No audit event is emitted on purpose: legacy `doCreateRevision` emits none
  and no locale key exists (a `TLS()` call would raise a Not-localized Event
  Viewer warning); the revision journal lives in `req_revisions`.

## Access & permission

* Deep links switched from `lib/requirements/reqView.php` to
  `gui/templates/requirements/reqView.html` in the three modern screens:
  `reqOverview.html` (`openReq`), `reqMonitorOverview.html` (`openReq`),
  `searchReq.html` (`openReq`/`openReqVersion`; the legacy revision popup is
  mapped to the version viewer).
* **Set Results popup** (`execSetResults.html` `openReqWindow()`, Refs #1477):
  the last legacy screen reference left in the modern UI. The linked
  requirements list ("LINKED REQUIREMENTS" section) now opens
  `gui/templates/requirements/reqView.html?id=..&tproject_id=..` instead of
  `/lib/requirements/reqView.php?showReqSpecTitle=1&requirement_id=..`. The
  legacy `showReqSpecTitle=1` flag is obsolete — the modern viewer always
  renders the requirement spec path (`r.spec_path`) in the Overview card.
* Required right: `mgt_view_req`. The BFF resolves the requirement's own
  test project through a direct JOIN (`requirements` → `req_specs`) instead of
  `requirement_mgr::getTestProjectID()` (that helper throws a DB error on
  missing ids). Without the right the API answers HTTP 403 and the screen shows
  "No permission".

## i18n

29 new keys (`reqv.*`) plus 18 more (`reqv.print`, `reqv.directLink`,
`reqv.help`, `reqv.helpTooltip`, `reqv.copyLink`, `reqv.specificLink`,
`reqv.directLinkCopied` and the `reqprint.*` block) added to **all** locale
bundles: en, de, es, fr, it, ja, pt, ro, ru, zh. The New Revision port adds
3 more keys to every bundle: `reqv.newRevision`, `reqv.newRevisionPrompt`,
`reqv.revisionCreated`.

## BFF

`GET /api/requirements/index.php/view?id=N[&version_id=M]`

Returns the requirement (current or requested version), spec path, type/status
labels, coverage data, custom-field values, monitor state for the caller,
relations, the full version list for the selector, the caller's grants
(`req_mgmt`, `monitor_req`, `req_tcase_link_management`), and the **`direct_link`**
permalink. Unknown ids return `req_deleted:true`.

`GET /api/requirements/index.php?action=req_print&req_id&req_version_id&req_revision&tproject_id`

Renders the single requirement via the legacy `reqPrint.php` into
`{status, req_id, req_version_id, req_revision, tproject_id, tproject_name,
reqname, title, body_html}`. Checks `mgt_view_req` (HTTP 403 on missing right).

`POST /api/requirements/index.php/versions/{id}/revision` with body `{"log_message":"..."}`

Creates a new revision of the requirement version `{id}` (Refs #1303). Checks
`mgt_modify_req` on the owning project (HTTP 403), rejects frozen versions
(HTTP 409), 404s on missing versions. Returns
`{status:'ok', req_id, version_id, revision, log_message}`.

## Bugs found while testing

* #765 — legacy `requirement_mgr::getTestProjectID()` +
  `requirement_spec_mgr::get_last_child_info()` emit DB-SQL-error /
  null-array warnings when creating requirements on a first-level spec
  (surfaced while seeding fixtures; the modern viewer does not use this path).

## Test evidence

Suite 764 in `tmp/TLU_Test_Cases.md` — 17/17 PASS (BFF routes, version switch,
monitor on/off + DB rows, deleted banner, 403 permission path, relations grid,
deep-link regression). Suite 1305 — Print / Direct link / Help (see below).
Suite 1303 — New Revision button/frozen/403/404/Event-Viewer — 7/7 PASS.

![reqView toolbar with Direct link box](screenshots/issue-1305-reqview-directlink-toolbar.png)
![Print screen](screenshots/issue-1305-reqprint-screen.png)
![Requirement Viewer opened from the Set Results popup](screenshots/issue-1477-reqview-popup-from-setresults.png)
![Requirement Viewer after creating a revision (v1r2)](screenshots/issue-1303-reqview-new-revision-v1r2.png)