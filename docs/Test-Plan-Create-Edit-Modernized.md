# Test Plan Create/Edit — Modernized (TestLink 2.0.1, Refs #1882)

Legacy controller `lib/plan/planEdit.php` (558 lines) and the Smarty templates
`gui/templates/dashio/plan/planEdit.tpl` + `plan/planEditJS.inc.tpl` +
`plan/inc_controls_planEdit.tpl`.

## Why this screen

It was the last full legacy Smarty renderer in the Test Plan area: every deep
link (`lib/plan/planEdit.php?do_action=create|edit`) still rendered the 1.9.20
screen, while `planView.html` had only an inline Create/Edit **modal**. That
modal can never reach feature parity with the standalone screen, and three open
gaps tracked exactly that:

- **#1117** — no copy-from-an-existing-plan, no API key display, no
  attachments in the modal.
- **#1118** — the full legacy copy option set (`items2copy`,
  `copy_assigned_to`, `tcversion_type`) had no UI at all.
- **#1119** — the plan's `api_key` (remote API / public links) was not
  visible anywhere in 2.0.1.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/plans/planEdit.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/planedit/index.php` (`GET ?action=init`, `POST ?action=save`) |
| Attachments | `api/attachments/index.php` (`table=testplans`, generic) |
| Legacy | `lib/plan/planEdit.php` → redirect-only shim (558 → 85 lines) |
| Wiring | `$actions->planEdit` in `lib/functions/common.php` (registered outside the `tplan_id` guard) |
| Entry points | `api/plans` `viewActions()` links + "Create Test Plan (full screen)" button and per-row editor links in `planView.html` |
| i18n | 67 `pe.*` / `pv.createFull` / `pv.editFull` / `footers.planEdit` keys in **all 10** bundles |

## Parity

- **Rights** — `mgt_testplan_create` on the **addressed** test project for
  every route (create, edit, save, attachments), exactly like legacy
  `init_args() → checkGUISecurityClearance(array('mgt_testplan_create'),'and')`.
- **Name rules** — trimmed, required, unique per project; on update the plan's
  own current name stays valid (`nameCanBeUsed` logic).
- **Copy from an existing plan** — the full legacy option set: test cases +
  version type (current/all), priorities, milestones, user roles, builds,
  platform links, attachments, `copy_assigned_to` (tester assignments, gated
  on "copy builds"), all mapping to `testplan::copy_as()` `items2copy`.
- **Legacy gating** — `copy_tcases` gates the version/priority radios and
  forces platform links checked+disabled; `copy_builds` gates the tester
  assignments row; source-plan change shows/hides the whole copy block.
- **Private plan** — creator gets his effective plan role when he has no
  explicit one (legacy `planEdit.php:93-101/151-158`).
- **API key** — read-only display with a Copy button (legacy `planEdit.tpl:123-130`).
- **Attachments** — upload / download / delete through `api/attachments`
  (`uploadedFile[]`, `X-Requested-With: XMLHttpRequest`), same owner gate.
- **Audit** — `audit_testplan_created` / `audit_testplan_saved` events,
  plus the "Show event history" deep link (gated on `mgt_view_events`).
- Refresh / Back to Test Plans / Close, locale switcher, footer.

## BFF contract

| Route | Meaning |
|---|---|
| `GET ?action=init&itemID=&tproject_id=` | project + plan payload (edit mode adds `api_key`, attachment rows, copy-source list) |
| `POST ?action=save` (form) | create (`copy_from_tplan_id` + copy options) or update (`itemID`) |

Error codes: `400` invalid/missing ids or `PROJECT_MISMATCH`, `401`
anonymous/expired (`UNAUTHENTICATED`), `403` no right / CSRF, `404`
`PLAN_NOT_FOUND`, `405` unknown action, `409` `DUPLICATE_NAME`.

## State handling

Every terminal condition is a machine-code state card, and the form is never
shown on an error:

| Code | Meaning |
|---|---|
| `NO_RIGHT` | no `mgt_testplan_create` on the project (verified with a tester-role user) |
| `PLAN_NOT_FOUND` | plan deleted, or `itemID` not addressable |
| `PROJECT_MISMATCH` | plan exists but belongs to another test project |
| session expiry | bounce to `login.php?note=expired` with a localized banner |

## Screenshots

| State | Image |
|---|---|
| Create mode with the copy-from block expanded | `1882-planedit-create.png` |
| Edit mode: API key, attachments table, event history | `1882-planedit-edit.png` |
| Insufficient rights (role without `mgt_testplan_create`) | `1882-planedit-denied.png` |
| `planView.html` entry points (toolbar button + per-row editor link) | `1882-planview-entries.png` |

## Defects found and fixed while testing (commit `6009ade75`)

- **Upload fatal** — the screen posted the file as `uploadedFile` (a plain
  string) while `api/attachments` counts `$_FILES['uploadedFile']['name']` as
  an array: PHP 8 `TypeError` → empty 500. Fixed to the canonical
  `uploadedFile[]` field name (as `attachmentUpload.html` uses).
- **Copy-gating parity** — `copy_platforms_links` started enabled; legacy
  `inc_controls_planEdit.tpl:114` starts it checked **and disabled** while
  "copy test cases" is on. The rule was only applied on the change handler.
- **Event history matched nothing** — the link passed `objectType=testplan`,
  but `events.object_type` stores `testplans` (plural, like `builds` for
  buildEdit), so the filter returned 0 of the 3 real audit rows.

## Deliberate behavior

`do_delete` stays where 2.0.1 already put it — the delete confirm modal on
`planView.html` + `DELETE api/plans` — so a legacy `do_action=do_delete`
bookmark lands on the plan's edit page instead of performing an unchecked
write through the removed renderer. The shim refuses non-GET/HEAD with 405 so
it can never smuggle a write past the BFF's checks.

## Test results

**41 / 41 PASS**, suite in `tmp/TLU_Test_Cases.md` (Issue #1882). Covered:
BFF contract (401/400/403/404/405/409/create/update/copy), anonymous bounce,
create + copy round trip, rename + duplicate-rename, all legacy copy gating,
attachments upload/download/delete with content verification, all three state
cards (403 with a real no-right user, 404, project mismatch), Romanian locale
switch, event history deep link + DB audit rows, both planView entry points,
the legacy shim (create/edit redirects, anonymous destination, 405 guard),
link-switch greps, the i18n coverage gate (7098 keys × 10 bundles) and a
clean Event Viewer (no new Error/Warning entries after the fixes).

## Commits

| Commit | Content |
|---|---|
| `6596b2d98` | BFF `api/planedit/index.php` |
| `bb6e0974a` | the screen `gui/templates/plans/planEdit.html` |
| `fc61d1a7a` | link switch (`common.php`, `api/plans`), planView entries, i18n ×10, legacy shim |
| `6009ade75` | three bugs found in browser testing |
| `80f099785` | test suite (Issue #1882, 41 cases) |
