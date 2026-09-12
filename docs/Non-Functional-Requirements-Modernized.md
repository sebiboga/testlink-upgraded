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
---

## Part C — Per-type NFR management (Refs #1470)

Tracking issue:
[#1470](https://github.com/sebiboga/testlink-upgraded/issues/1470) (Performance) —
the same screen serves all seven NFR types from dedicated ASIDE sub-items
(#1471 security, #1472 usability, #1473 accessibility, #1474 compatibility,
#1475 reliability, #1476 maintainability).

### What it adds

The #1462 screen groups all NFR types on one page. The per-type screen gives
each NFR type its own standalone page so a team can focus on a single quality
dimension end to end:

| Feature | Behavior |
|---|---|
| URL date type | `?type=<nfr_type>` drives the whole page; type is validated against the NFR domain (400 on unknown) |
| Dedicated ASIDE sub-items | 7 sub-items under Requirements Design (gated `reqs_view`): *NFR: Performance / Security / Usability / Accessibility / Compatibility / Reliability / Maintainability* — wired via `$actions->nfr<Type>` in `common.php` and `href_nfr_<type>` labels in all 19 `locale/*/strings.txt` |
| Type chip + focus box | header shows the active NFR type and a type-specific focus blurb (localized, e.g. *Focus: load / stress / endurance / scalability targets and thresholds*) |
| Type-locked CRUD | create/edit modal shows the type read-only (`readonly` input); rows are always created under the active `?type=` and can never be moved to another type |
| Table | DataTable of that type only: title (+description), target, threshold, source, status badge, updated, actions — server-side filtered by `type` |
| Statuses | proposed / approved / in_scope / waived (localized badges) |
| Rights | `mgt_view_req` read / `mgt_modify_req` write on the owning project (401/400/403/404 JSON contract); `canEdit` gating hides Add/Edit/Delete for viewers |
| Audit | every write logs `NFR_CREATE` / `NFR_UPDATE` / `NFR_DELETE` (same events table as #1462) |

### BFF API

`api/nfrtype/index.php` (session auth, `bffSameOriginGuard()`, plain PHP):

- `GET ?action=projects&type=X` → projects the user can view (mgt_view_req filtered).
- `GET ?action=meta&type=X` → `{ type:{code,icon,focusKey}, statuses:[...] }`.
- `GET ?action=list&tproject_id=N&type=X` → `{ project, canEdit, count, items[] }` (type-filtered).
- `GET ?action=item&id=N` → single row for the edit modal.
- `POST ?action=create {tproject_id,type,title,description,target_value,threshold_value,source_ref,req_status}` → `{status:ok,id}`.
- `POST ?action=update {id,title,...}` → updates; preserves `nfr_type` from the row.
- `POST ?action=delete {id}` → removes the row.
- `POST ?action=validate` → title-required + type-domain validation (unused directly by the screen, which relies on BFF 400 errors).

`type` is validated against the NFR domain on every route (including create/update,
so a forged type is rejected with 400). Rights are checked against the row's
*owning* project on item/update/delete.

### Screen file

`gui/templates/requirements/nfrTypeView.html` — Dashio layout, DataTables,
Bootstrap modals, `TLi18n` client-side i18n, locale switcher, toast feedback.
Same visual language as `nfrRequirements.html` (teal/dark/red palette).

### i18n

`nfrt.*` keys (40) + `footers.nfrtype` added to all 10 client bundles
(`en.json ro.json de.json es.json fr.json it.json ja.json pt.json ru.json zh.json`),
plus the 7 aside labels in all 19 `locale/*/strings.txt`
(`$TLS_href_nfr_{performance,security,usability,accessibility,compatibility,reliability,maintainability}`,
native translations) and `labels.aside.tpl`.

### Data model

Reuses the single `nfr_requirements` table registered in
`lib/functions/object.class.php` `getDBTables()` (lazy-created), discriminated by
`nfr_type`. No schema change.

### Testing

`tmp/TLU_Test_Cases.md` → **Suite — Issue #1470** (full pass). Browser-verified:
all 7 ASIDE sub-items render with per-type labels and URLs; type-filtered list
(performance/security/reliability), create with type-locked readonly field,
edit pre-fill + status change, delete with confirm, mandatory-title validation,
DataTable search, Romanian localization (chip *Performanță*, statuses, labels),
audit events, Event Viewer clean, 401/403/400 API matrix.
