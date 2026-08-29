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
| Attachments | file list with title, file name, size, date and a **Download** link (`lib/attachments/attachmentdownload.php`) |
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
