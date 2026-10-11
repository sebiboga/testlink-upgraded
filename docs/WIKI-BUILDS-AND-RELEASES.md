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

## Parity-check pass (SCREEN-COMPARE row 32, Refs #1121)

Full feature-parity audit of `lib/plan/buildView.php` + `lib/plan/buildEdit.php`
(+ dashio `buildView.tpl`/`buildEdit.tpl`) against the modern screen + BFF
(`gui/templates/plans/buildsView.html` + `api/builds/index.php`), executed in
the 2026-09-06 parity run.

### Two BFF bugs found and fixed (commit 69f0e01e0)

1. `build::get_by_id()` returns `bool(false)` — not `null` — when a build does
   not exist, so the `is_null($b)` guard in the four build-fetch routes
   (GET `/api/builds/{id}`, POST `/api/builds/{id}/flags`, PUT `/api/builds/{id}`,
   DELETE `/api/builds/{id}`) fell through into `resolveBuild()` and answered a
   misleading **"Invalid Test Project ID" 404**. Guarded with `if (!$b)` → proper
   **"Build not found"** 404 (browser/API verified on `id=9999`).

2. POST create validated the copy-assignments **source** build *after*
   `createFromObject()`, so an invalid `source_build_id` returned 400 **while an
   orphan build row stayed persisted** (and the PHP8 `is_null($b)` on a `false`
   value emitted an E_WARNING into the Event Viewer). Source build is now
   validated **up front**, before creation — no orphan row, no PHP warning.

### Build design-time custom fields (Refs #1122)

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| List CF **columns** | `buildView.php:56-110` builds `cfieldsColumns`/`cfieldsType` from `build_mgr->get_linked_cfields_at_design()` and per-build `getCustomFieldsValues()` | BFF `GET /api/builds/` now returns `cfields_columns` (`{id,name,label,type}`) + a `cf` map per build; `buildsView.html` inserts a dynamic `<th>` per column and one value cell per row |
| `TL_BUILDVIEW_HIDECOL` / `_<prefix>` | A config CF whose `possible_values` is a `|`-list of CF **names** to hide; the config CF itself is hidden too | honoured in the list BFF (searched `_<prefix>` first, then `''`); the config CF is excluded from both columns and the modal inputs |
| CF **inputs** in create/edit | `buildEdit.php` `html_custom_field_inputs()`; `doCreate()/doUpdate()` persist via `design_values_to_db(...,'build')` | `buildsView.html` modal loads `GET /api/builds/cfields?tplan_id=&build_id=` and renders all design CF controls; `POST`/`PUT` persist through the existing `saveBuildCfields()` -> `cfield_build_design_values` |
| Date/datetime values | stored as `mktime()` epochs | converted epoch -> ISO server-side (`epochToIsoDate()`), converted back to the session locale format on save (`isoToLocaleDate()`) |
| Full-replacement safety | legacy HTML form always submitted every field | the modal sends `cfields` only when it rendered inputs, so a plain rename never wipes values |

Verified in the browser: list shows `BVC Environment` / `BVC Release Date` (with `bvc_component` hidden by `TL_BUILDVIEW_HIDECOL_BVC`), create persists `qa` / `backend` / `2026-07-20`, edit prefills `staging` / `2026-06-15`, no JS console errors. i18n key `bv.cfSection` added to all 10 bundles. Not caused by this work: a pre-existing POST-create audit E_WARNING (`$ctx['tproject_name']`) is tracked in **#1900**.

### Gaps (open issues)

| Issue | Type | Gap |
|---|---|---|
| #1123 | task | Edit-screen **event-history icon** dropped — legacy `buildEdit.tpl:52-57` `showEventHistoryFor('{id}','builds')` gated by `mgt_view_events` (`buildEdit.php:106`) |
| #1124 | task | **closed_on_date** value never displayed — legacy `buildEdit.tpl:96` shows the stored closure date; BFF returns it, modern modal only shows the generic `bv.closureHint` |

Cleanup: #1125 delete legacy `lib/plan/buildView.php` + `buildEdit.php` +
dashio tpls (Refs #1123 #1124).

### NOT gaps (deliberate parity)

* Rich-text notes (`web_editor`) vs modern plain textarea — matches
  `projectsView.html`/`planView.html` precedent.
* Legacy custom pagination `[20,40,60,100]`/default 10 vs modern
  `10/[10,25,50,100]` — deliberate cross-screen DataTables convention.
* `copy_to_all_tplans` + `commit_id/tag/branch/release_candidate` are
  API/JSONB-only in *both* legacy (`buildEdit.php` never rendered inputs;
  `:129-145` just reads REQUEST) and modern (BFF honors them on POST/PUT) — parity,
  no UI gap.
* Copy-to-all-plans is a no-op-by-design today: builds are project-scoped (#503;
  `builds` has `testproject_id`, no `testplan_id`), so a name created on one plan
  already exists project-wide and `check_build_name_existence()` skips every
  sibling.

### Screenshots

Admin run, seeded BV1121 project (plans + builds in varied states):

![Builds &amp; Releases — parity run, admin list](images/bv1121-browser-list.png)

Guest user (role 3, no `testplan_create_build`) gets a graceful rights toast and
an empty control-less table — matches legacy `rightsAnd = testplan_create_build`:

![Builds &amp; Releases — parity run, no-rights](images/bv1121-browser-norights.png)

### Verified this run

20/20 test cases PASS (append-only `tmp/TLU_Test_Cases.md`): ASIDE link switched
(`common.php:1932`), project-scoped build visibility, create/edit/delete modals,
active toggle, open→closed closure-date stamping, duplicate/empty/invalid-date
errors, 404 "Build not found", invalid-source no-orphan, no-rights 403, copy
assignments, copy-to-all-plans no-op, i18n 46 `bv.*` keys × 10 bundles, clean
events table + JS console.
