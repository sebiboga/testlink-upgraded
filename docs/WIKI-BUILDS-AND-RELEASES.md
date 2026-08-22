# Builds & Releases (buildView) — Modernized Screen 🏗️

Modernization of **Builds & Releases Management** (`lib/plan/buildView.php` +
`lib/plan/buildEdit.php`) — GitHub issue [#585](https://github.com/sebiboga/testlink-upgraded/issues/585).

The legacy Smarty screens are replaced by a standalone Dashio page
(`gui/templates/plans/buildsView.html`) backed by a plain-PHP REST BFF
(`api/builds/index.php`), following the same pattern as the other modernized
screens. The aside menu entry *Test Plan → Builds / Releases* now points to
the new HTML screen (switched in `lib/functions/common.php`).


## What the screen does (legacy parity, nothing dropped)

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| List builds of current test plan | `testplan_create_build` required (rightsAnd) | `GET /api/builds/?tplan_id=N` (403 otherwise) |
| Create build (name, rich notes, active, open, release date) | `do_action=do_create` + duplicate-name crossCheck + invalid-date check | `POST /api/builds/` (409 on duplicate, 400 on invalid date) |
| Edit build in modal | `do_action=edit` / `do_update` | `GET /api/builds/{id}` prefill + `PUT /api/builds/{id}` |
| Active/Inactive toggle per row | `setActive` / `setInactive` | `POST /api/builds/{id}/flags {"active":bool}` |
| Open/Closed lock toggle per row | `open` / `close`; closing stamps today as closure date | same flags route; stamping/clearing only on real transitions |
| Delete build with confirm dialog | `do_delete`; needs `exec_delete` when executions exist | `DELETE /api/builds/{id}` (409 with localized message otherwise) |
| Copy tester assignments from a source build | create-form selector newest-first with assignment counts + optional exec-status filter | same options served by BFF; source build validated to belong to the plan |
| Closure date semantics | preserved through hidden field on edit; stamped on close | historical date preserved across updates (`build::update()` wipes it — BFF restores it) |
| Audit trail | `audit_build_created/saved/deleted` events | identical audit events with project name resolved from context |

## Screenshots

Create Build modal (name required, notes, active/open checkboxes, release date;
copy-assignments section appears once the plan already has builds):


Edit Build modal (prefilled from API):


## Rights

* Everything: `testplan_create_build` (same as legacy `checkRights()`).
* Deleting a build that already has executions additionally requires
  `exec_delete`, exactly like legacy `doDelete()`.

## Notes & decisions

* Error messages are returned as stable codes (`warning_duplicate_build`,
  `invalid_release_date`, …) and localized client-side via the `bv.*` i18n
  keys present in all 10 locale bundles.
* Notes render as sanitized HTML (DOMPurify), matching the legacy rich-editor
  storage.
* Security review fixes applied: source-build must belong to the target plan,
  closure-date preservation, unknown exec-status codes ignored, JSON errors
  for all failure paths.
