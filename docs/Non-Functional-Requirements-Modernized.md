# Non-Functional Requirements (NFR) — Modernized Screen

Modernization / new feature: **Non-Functional Requirements** per-type management
— GitHub issue [#1462](https://github.com/sebiboga/testlink-upgraded/issues/1462)
(Non-Functional Requirements module, Part B).

TestLink 1.9.20/2.0.1 had no NFR concept: no tables, no API, no screen. This
adds a per-test-project quality-requirement registry — each non-functional
requirement is typed (performance / security / usability / accessibility /
compatibility / reliability / maintainability), carries a target value, a
threshold value, a source reference and a workflow status — as a standalone
Dashio page (`gui/templates/requirements/nfrRequirements.html`) backed by a
plain-PHP REST BFF (`api/nfr/index.php`).

**Path:** ASIDE → Requirements Design → *Non-Functional Requirements*
**URL:** `gui/templates/requirements/nfrRequirements.html?tproject_id=<id>`
**BFF API:** `api/nfr/index.php`
**Rights:** `mgt_view_req` (read) / `mgt_modify_req` (write), enforced server-side
**Tracking issue:** [#1462](https://github.com/sebiboga/testlink-upgraded/issues/1462)

---

## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Data model](#3-data-model)
4. [i18n Keys](#4-i18n-keys)
5. [Security & auditing](#5-security--auditing)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Behavior |
|---|---|
| Project selector | dropdown of all test projects; the chosen project is persisted in the URL (`tproject_id`) and becomes the owning context for every request |
| Type chips | one chip per NFR type with live count; clicking a chip filters the list server-side (`type` in URL); "All types" resets |
| Requirements table | DataTable: title (+ truncated description), type badge, target value, threshold value, source reference, status badge, updated timestamp, actions |
| Status badges | proposed (gray), approved (teal), in scope (blue), waived (orange) |
| Create / Edit modal | Title (mandatory), type, status, target value, threshold value, source reference, description; edit pre-fills via `?action=item`; type is pre-selected to the active chip filter |
| Delete | Bootstrap confirm dialog; removes the row and logs `NFR_DELETE` |
| Locale switching | full `TLi18n` client-side localization for all labels, chips, statuses, toasts, DataTable and footer (verified in Romanian) |
| Add / Edit / Delete gating | buttons hidden/disabled based on the `canEdit` flag (`mgt_modify_req`) returned by the BFF |

## 2. REST API Reference

All routes live in `api/nfr/index.php` (session auth, `bffSameOriginGuard()`).

- `GET ?action=projects` → `{ projects: [{id,name,prefix}] }` — project selector list.
- `GET ?action=types` → `{ types: [{code,icon}], statuses: [...] }` — NFR type + status domains.
- `GET ?action=list&tproject_id=N[&type=X]` → `{ status, project, canEdit, counts, items[] }` —
  project rows (optionally filtered by type) plus per-type counts for the chips. Right `mgt_view_req`.
- `GET ?action=item&id=N` → `{ status, item, canEdit }` — single row for the edit modal.
- `POST ?action=create {tproject_id,nfr_type,title,description,target_value,threshold_value,source_ref,req_status}` → `{status:ok,id}`.
  Right `mgt_modify_req`; logs `NFR_CREATE`.
- `POST ?action=update {id,...}` → same fields; logs `NFR_UPDATE`.
- `POST ?action=delete {id}` → logs `NFR_DELETE`.

Errors: 401 (unauthenticated), 403 (no right on the owning project), 400
(bad/unknown parameter: missing id, unknown action, unknown type),
404 (missing requirement or test project).

## 3. Data model

One new table (created idempotently by the BFF on demand, then registered in
`lib/functions/object.class.php` `getDBTables()` whitelist so lazy migration works):

```
nfr_requirements(id, testproject_id, nfr_type, title, description,
                 target_value, threshold_value, source_ref,
                 req_status, author_id, creation_ts, updated_ts)
```

NFR types: `performance, security, usability, accessibility, compatibility,
reliability, maintainability`. Statuses: `proposed, approved, in_scope, waived`.

## 4. i18n Keys

Screen uses the client-side `TLi18n` module. Keys added to all 10 bundles
(`de en es fr it ja pt ro ru zh`):

`nfr.title, nfr.headerSub, nfr.testProject, nfr.selectProject, nfr.noProject,
nfr.typeAll, nfr.add, nfr.colTitle, nfr.colType, nfr.colTarget, nfr.colThreshold,
nfr.colSource, nfr.colStatus, nfr.colUpdated, nfr.colActions, nfr.edit,
nfr.delete, nfr.deleteConfirm, nfr.emptyList, nfr.errLoad, nfr.errTitleRequired,
nfr.errSave, nfr.savedOk, nfr.deletedOk, nfr.newTitle, nfr.editTitle,
nfr.titleLabel, nfr.descLabel, nfr.targetLabel, nfr.thresholdLabel,
nfr.sourceLabel, nfr.typeLabel, nfr.statusLabel, nfr.footerCount,
nfr.typePerformance, nfr.typeSecurity, nfr.typeUsability, nfr.typeAccessibility,
nfr.typeCompatibility, nfr.typeReliability, nfr.typeMaintainability,
nfr.statusProposed, nfr.statusApproved, nfr.statusInScope, nfr.statusWaived,
footers.nfr`.

The aside label `href_nfr_requirements` was added to all 19 `locale/*/strings.txt`
and to `gui/templates/dashio/labels/labels.aside.tpl`; the sub-menu entry lives
under Requirements Design in `gui/templates/dashio/aside.tpl`, gated by
`$menuGrants->reqs_view == "yes"`, wired via `$actions->nfrRequirements`
in `lib/functions/common.php`.

## 5. Security & auditing

- Server-side right checks on every route (`mgt_view_req` for reads,
  `mgt_modify_req` for writes) against the **owning** test project.
- CSRF guard reused from `api/_guard.php` (`X-Requested-With` header / Origin check).
- Every write logs an audit event in the `events` table (`NFR_CREATE` /
  `NFR_UPDATE` / `NFR_DELETE`, log level info).
- No hardcoded string on the screen; all labels/messages localized.
- All user-provided values are rendered HTML-escaped (XSS-safe) and SQL-parameterized.

## 6. Testing

See `tmp/TLU_Test_Cases.md` → **Suite — Issue #1462** (16/16 PASS).
Browser-verified flows: list/empty state, create (mandatory title validation),
edit (pre-fill + status change), delete (confirm + count decrement), type chips
filter + URL, locale switching (Romanian), viewer-user permission gating,
BFF error/HTTP code matrix, Event Viewer / audit events.