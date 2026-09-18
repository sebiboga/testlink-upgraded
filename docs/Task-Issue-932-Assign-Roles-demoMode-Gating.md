# Task 932 — demoMode read-only gating in Assign Test Project Roles (gap vs legacy)

**Issue:** [#932](https://github.com/sebiboga/testlink-upgraded/issues/932)
**Status:** IMPLEMENTED & VERIFIED (2026-09-18) — branch `task/issue-932`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl` makes the whole
assignment form read-only when `$tlCfg->demoMode` is on:
- `:144-147` — the form's `onsubmit` becomes
  `alert('$TLS_warn_demo'); return false;` so submission is blocked;
- `:286-292` — the Save/submit button is REPLACED by the localized
  `$TLS_warn_demo` note **"We are sorry. This feature is disabled for Demo."**
  (`locale/en_GB/strings.txt:465`).

The modern screen `usersAssignProject.html` + `api/roles/index.php` ignored
demoMode on the client: the Save button stayed present and enabled after a role
change, and only the BFF (gated earlier for another screen, commit 593f9790a2,
#903) rejected the subsequent PUT — with the *generic* "Update Role DISABLED"
message, not the legacy warn_demo text.

## Legacy source of truth

- `config.inc.php:2060` — `$tlCfg->demoMode`;
- `gui/templates/dashio/usermanagement/usersAssign.tpl:144-147` — submit blocked
  with an alert of `warn_demo`;
- `gui/templates/dashio/usermanagement/usersAssign.tpl:286-292` — Save button
  replaced by `$labels.warn_demo`;
- `locale/*/strings.txt:465` — `$TLS_warn_demo` translations.

## Modern implementation

- **BFF** (`api/roles/index.php`):
  - `GET /roles/meta/tproject-roles` and `GET /roles/meta/tplan-roles` now both
    return `demoMode` (bool), mirror of the `demoMode` block fed by **GET /roles**
    (`:364`) — so the UI knows the demo state up front;
  - `PUT /roles/tproject-roles` and `PUT /roles/tplan-roles` gating calls now use
    `demoModeBlockedWrite('warn_demo', 'assign.demoDisabled')` — the 403 carries
    the legacy warn_demo message (`messageKey: assign.demoDisabled`).
- **Screen** (`gui/templates/usermanagement/usersAssignProject.html` +
  `gui/templates/usermanagement/usersAssignPlan.html` — the legacy usersAssign.tpl
  is shared by project and plan contexts, so both get the same gating):
  - `.demo-banner` / `.demo-note` CSS (Dashio usersView.html:56-57 pattern);
  - a `#demoBanner` div under the header and a `#demoNote` span beside the Save
    button, both rendering the `assign.demoDisabled` ("We are sorry...") text;
  - `demoMode` state captured from the meta responses; `applyDemoMode()`
    toggles the banner, REPLACES the Save button with the `#demoNote` note and
    locks the Save button;
  - `updateSaveBtn()` never re-enables Save in demoMode;
  - `saveAssignments()` early-returns with a warn_demo toast when demoMode is on
    (mirror of the legacy `onsubmit` alert + `return false`);
  - the demo_mode error fallback now resolves `assign.demoDisabled` instead of
    the generic role key; the plan screen gained the toast CSS + a `.fail`
    handler so a rejected save is surfaced, not silent.
- **i18n**: `assign.demoDisabled` added to **all 10** locale bundles
  (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), seeded from the
  legacy `$TLS_warn_demo` translations; Italian and Romanian translated for the
  first time (the legacy locale trees lacked them).

## Screenshots

- `docs/screenshots/issue-932-assign-project-roles-demoMode.png` — project
  screen with demoMode ON: banner + warn_demo note instead of the Save button.
- `docs/screenshots/issue-932-assign-plan-roles-demoMode.png` — plan screen with
  demoMode ON (same gating, shared legacy template).

## Verification evidence

- demoMode=ON, project screen: `{demoMode:true, saveVisible:false,
  noteVisible:true, bannerVisible:true}`; after a role change Save stays
  hidden+disabled; `saveAssignments()` → warn_demo toast, no PUT issued.
- Direct PUT `/roles/tproject-roles` and `/roles/tplan-roles` with demoMode=ON:
  HTTP 403 `{code:"demo_mode", messageKey:"assign.demoDisabled",
  message:"We are sorry. This feature is disabled for Demo."}`.
- demoMode=OFF regression: banner/note hidden, Save visible after a change,
  Save persists the role (DB `user_testproject_roles` row (2,1,7)) and shows the
  "User Roles updated" toast.
- Event Viewer: only AUDIT (log_level 16) rows from the run; 0 rows ≥ 32.
  Browser console clean (both screens).
- Full manual pass: `tmp/TLU_Test_Cases.md` — "Suite 932" — **12/12 PASS**.

Refs #932.