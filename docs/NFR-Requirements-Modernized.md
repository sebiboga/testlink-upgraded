# Non-Functional Requirements (NFR) — Modernized Screen

New feature: **Non-Functional Requirements** — GitHub issue
[#1052](https://github.com/sebiboga/testlink-upgraded/issues/1052)
(NFR requirements gap) implemented + recorded under
[#1462](https://github.com/sebiboga/testlink-upgraded/issues/1462).

TestLink 2.0.1 had no dedicated non-functional-requirements entity: requirement
trees covered functional specs only, and the Test Strategy document gave NFR a
single prose slot. This feature adds (a) **seven per-type NFR chapters** to the
Test Strategy document and (b) a **per-project NFR requirements registry** as a
standalone Dashio CRUD screen backed by its own REST BFF.

**Path:** ASIDE → Requirements Design → *NFR Requirements*
**URL:** `gui/templates/requirements/nfrRequirements.html?type=<slug>`
**BFF API:** `api/nfr/index.php`
**Rights:** `mgt_view_req` (read) / `mgt_modify_req` (write), enforced server-side
**Tracking issue:** [#1052](https://github.com/sebiboga/testlink-upgraded/issues/1052) (implementation) / [#1462](https://github.com/sebiboga/testlink-upgraded/issues/1462) (chapters + screen)

---

## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [Test Strategy chapters](#2-test-strategy-chapters)
3. [REST API Reference](#3-rest-api-reference)
4. [Data model](#4-data-model)
5. [i18n Keys](#5-i18n-keys)
6. [Security & auditing](#6-security--auditing)
7. [Testing](#7-testing)

## 1. What the screen does

| Feature | Behavior |
|---|---|
| Per-type grid | DataTable of the project's NFR requirements: Name, Type badge, Description, Acceptance Criteria, Active (Yes/No), Actions |
| Type filter | toolbar select (All + 7 types); selection reloads the list server-side and rewrites the URL to `?type=<slug>` (`history.replaceState`, no reload loop) |
| Project header | toolbar shows the active project name and id (resolved from the session `testprojectID`) |
| Create / Edit modal | Name (mandatory), Type select (7 slugs, localized labels), Description, Acceptance Criteria, Active checkbox |
| Delete | per-row button with native confirm dialog |
| Counter / footer | "NFR requirements: N" + generated-on timestamp + `footers.nfrRequirements` line |

## 2. Test Strategy chapters

`api/strategy/index.php` `?action=chapters` now serves **28** chapter cards
(previously 21). Chapters 22–28 are the NFR chapters, each also reachable
directly as an ASIDE Test Strategy sub-item:

| # | Chapter | Page | Slug |
|---|---|---|---|
| 22 | Non-Functional: Performance | `gui/templates/strategy/nfrPerformance.html` | performance |
| 23 | Non-Functional: Security | `gui/templates/strategy/nfrSecurity.html` | security |
| 24 | Non-Functional: Usability | `gui/templates/strategy/nfrUsability.html` | usability |
| 25 | Non-Functional: Accessibility | `gui/templates/strategy/nfrAccessibility.html` | accessibility |
| 26 | Non-Functional: Compatibility | `gui/templates/strategy/nfrCompatibility.html` | compatibility |
| 27 | Non-Functional: Reliability | `gui/templates/strategy/nfrReliability.html` | reliability |
| 28 | Non-Functional: Maintainability | `gui/templates/strategy/nfrMaintainability.html` | maintainability |

The slug is also the `nfr_type` value stored per requirement, so a chapter and
the matching grid filter share one identifier.

## 3. REST API Reference

All routes live in `api/nfr/index.php` (session auth, `bffSameOriginGuard()`).

- `GET /api/nfr/index.php[?type=<slug>]` → `{ status, tproject_id, tproject_name,
  type, types: {slug:{slug,labelKey}}, items: [...], rights: {canView, canModify} }`,
  right `mgt_view_req`. `items` ordered `is_active DESC, name ASC`; filtered by
  `type` and always by `testproject_id`.
  The segment form `GET /performance` works the same (segment = type).
- `POST /api/nfr/index.php` — create `{nfr_type, name, description,
  acceptance_criteria, is_active}`, right `mgt_modify_req`. 422 `invalid_nfr_type`
  / `empty_name`. Responds `{status:'ok', id:N}`.
- `PUT /api/nfr/index.php/{id}` — partial update of any of the same fields
  (present keys only). 404 unknown/foreign id, 422 `nothing_to_update` when empty.
- `DELETE /api/nfr/index.php/{id}` — 200 on success.

Error paths: 401 anonymous / user-not-found, 400 no project selected, 403
insufficient right, 405 non-whitelisted method — all guaranteed JSON.

## 4. Data model

One new table, created idempotently (`CREATE TABLE IF NOT EXISTS`) on first BFF
access and registered in `lib/functions/object.class.php` `getDBTables()`:

```
nfr_requirements(id, testproject_id, nfr_type, name,
                 description, acceptance_criteria, is_active,
                 author_id, creation_ts)
```

`testproject_id` scopes every query; the project display name for the toolbar is
joined from `nodes_hierarchy` (`testprojects` itself has no `name` column).

## 5. i18n Keys

Screen uses the client-side `TLi18n` module; type labels render client-side via
`labelKey` (`nfr.types.*`). All keys exist in **all 10** bundles (validated with
`python3 -m json.tool`):

- `nfr.*` (40): header/headerSub, forProject, colName/colType/colDesc/colAccept/
  colActive/colActions, createBtn, typesAll, empty, count, deleteConfirm,
  createTitle/editTitle, lbl*, msg*, err*, menuTitle, types (.performance …
  .maintainability × 7), save/cancel/delete.
- `ts.nfr{Type}*` (70 = 10 × 7): per-chapter Header/HeaderSub/C1Title/C1a–C1c/
  C2Title/C2a–C2c content keys.
- `ts.chapter{Type}`/`ts.chapter{Type}Desc` (14): the chapter 22–28 overview
  cards.
- `footers.nfrRequirements` + aside label keys `href_test_strategy_{performance
  …maintainability}` and `href_nfr_requirements` (locale `en_GB`/`en_US`/`ro_RO/strings.txt` +
  `gui/templates/dashio/labels/labels.aside.tpl`).

## 6. Security & auditing

- Server-side right checks on every route (`mgt_view_req` view / `mgt_modify_req`
  write). Anonymous → 401.
- CSRF guard reused from `api/_guard.php` (`X-Requested-With` header).
- Every create logs an audit event in the `events` table
  (`logAuditEvent(..., 'NFR_CREATE', id, 'nfr_requirement')`).
- All user-supplied values go through `prepare_string()`/`intval()` (no
  interpolation of raw input).

## 7. Testing

`tmp/TLU_Test_Cases.md` → **Test Suite 1462** (14/14 PASS). Browser-verified
admin session: ASIDE + chapter pages render; BFF GET (full + `?type=` + segment
form), POST create, PUT partial + type change + 404, DELETE; screen create/edit/
delete with type filter (`?type=` URL round-trip) and empty-filter state; all
labels localized; Event Viewer clean. In-run fixes: `nfrProjectName()` joins
`nodes_hierarchy` for the project title; `$strField` closure captured `$body`;
DataTable instance is destroyed before tbody re-population so re-renders never
duplicate rows.

## Screenshots

- `docs/screenshots/issue-1462-nfr-requirements-list.png` — the NFR grid with
  all three types (Performance / Usability / Security badges, Active Yes/No,
  Actions).
- `docs/screenshots/issue-1462-nfr-edit-modal.png` — Edit modal pre-filled.
- `docs/screenshots/issue-1462-nfr-chapter-performance.png` — Performance
  chapter page (Approach / NFR Criteria columns + Back to Test Strategy).