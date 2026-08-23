# Test Project Management (projectView) — Modernized Screen

Modernization of **Test Project Management** (`lib/project/projectView.php` +
`lib/project/projectEdit.php`) — GitHub issue
[#640](https://github.com/sebiboga/testlink-upgraded/issues/640).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/projectsView.html`) backed by a plain-PHP REST BFF
(`api/projects/index.php`). The ASIDE **Dashboard → Test Project Management**
entry now points to the new HTML screen (switched in
`getActions()` in `lib/functions/common.php`).

**Path:** Dashboard → Test Project Management
**URL:** `gui/templates/projectsView.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/projects/index.php`
**Right:** `mgt_modify_product` enforced server-side on every BFF route
(same as legacy `checkRights()`); create/edit/delete controls are also hidden
client-side when the right is missing.


---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| List test projects ordered by name | `testproject::get_accessible_for_user()` with issue/code tracker info | `GET /api/projects/` — joined rows incl. tracker names + enabled flags + feature flags from the serialized options blob |
| Create project | `doCreate`: crossChecks (name syntax, dup name, dup prefix), feature checkboxes, tracker link | `POST /api/projects/` with same checks via `tprojectMgr::checkName/get_by_name/get_by_prefix`, `create(doChecks:false)` after explicit crossChecks |
| Edit project in modal | `edit/doUpdate`: same crossChecks excluding self, then `update()`+`activate()` | `PUT /api/projects/{id}` — partial updates supported (only sent fields change), manager-based update |
| Active/Inactive click-to-change | toggle icon per row → `setActive/setInactive` | row **Deactivate/Activate** button → `PUT {isActive}` partial update |
| Issue tracker integration | `tlIssueTracker::link/unlink` + `setIssueTrackerEnabled` | identical manager calls (raw SQL removed) |
| Code tracker integration | `tlCodeTracker::link/unlink` + `setCodeTrackerEnabled` | identical manager calls |
| Delete project | `doDelete` = full cascade via `tprojectMgr->delete()` + confirm | `DELETE /api/projects/{id}` real cascade; 409 JSON on refusal; confirm dialog explicitly warns all data is permanently deleted |
| Search by name | server-side LIKE filter (`doAction=search`) | client-side instant filter over the loaded list (same result for the accessible set) |
| Audit trail | `audit_testproject_created/saved/deleted` | identical events fired by the BFF |

## 2. REST API Reference

All routes require an authenticated session; non-admin callers without
`mgt_modify_product` get `403 {"status":"error","message":"No permission"}`.

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET /api/projects/` | list all projects (id, name, prefix, description, isActive, isPublic, opt* flags, trackers) | 401 unauthenticated, 403 no right |
| `GET /api/projects/{id}` | one project | 404 not found |
| `POST /api/projects/` | create | 400 empty name/prefix, name-syntax or duplicate name/prefix (legacy messages), create failure |
| `PUT /api/projects/{id}` | update (partial: only provided keys) | 404 not found, 400 validation/duplicates |
| `DELETE /api/projects/{id}` | full cascade delete | 404 not found, 409 cascade refused (localized legacy message) |

## 3. Legacy parity notes

* Delete is a **real cascade delete** (test plans, cases, executions,
  keywords, attachments...), exactly like `projectEdit.php doDelete()` — an
  earlier draft of this screen only deactivated the project, which silently
  diverged from legacy semantics.
* Duplicate-name and duplicate-prefix errors reuse the legacy language keys
  (`error_product_name_duplicate`, `error_tcase_prefix_exists`).
* Tracker linking goes through `tlIssueTracker`/`tlCodeTracker` managers, so
  the link tables never drift from what the legacy screens write.
* When a project becomes non-public, legacy adds the editing user's global
  role as project-specific role so they don't lock themselves out; this
  remains handled by the legacy code paths that consume the flag (the modern
  screen keeps the checkbox semantics unchanged).

## 4. i18n Keys

All strings are client-side localized through `TLi18n`. Keys live under the
`proj.*` prefix plus `header.projects*`, present in ALL 10 locale bundles:
en, de, es, fr, it, ja, pt, ro, ru, zh. New in #640:
`proj.toggleActive`, `proj.activate`, `proj.deactivate`; corrected
`proj.msg.confirmDelete` / `proj.msg.errorDelete` to describe a real,
irreversible deletion.

## 5. Security

* Server-side right check on every route (`mgt_modify_product`), session auth
  via the shared `api/_guard.php` bootstrap + same-origin CSRF guard.
* All user-controlled values (name, prefix, tracker names) are HTML-escaped in
  the row renderer (`esc()`) — verified by injecting `<script>` into a
  project name.
* Duplicate checks prevent case/prefix collisions server-side, not just in
  the UI.

## 6. Testing

Regression suite **Suite 640** in `tmp/TLU_Test_Cases.md`: 21/21 PASS
(render, modal validation, CRUD, toggles, tracker linking, search, XSS,
cascade delete + cancel, permission paths 401/403/404, i18n completeness,
aside switch, Event Viewer clean — AUDIT-only).

