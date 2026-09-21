# Task 943 — demoMode read-only gating in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#943](https://github.com/sebiboga/testlink-upgraded/issues/943)
**Status:** IMPLEMENTED & VERIFIED, issue CLOSED (2026-09-21) — branch `task/issue-943`

## The gap (as filed)

Auto-generated gap-analysis issue against the modernized **Assign Test Plan
Roles** screen. It reported that neither `usersAssignPlan.html` nor the
`api/roles` BFF `tplan-roles` routes honour `$tlCfg->demoMode`, whereas legacy
`usersAssign.tpl` turns the whole form read-only in demo mode (Save button
replaced by the localized `warn_demo` note, submit blocked).

## Investigation outcome

The gating was **already implemented on the default branch** when #943 was
picked: the shared `usersAssign.tpl` demo-path was ported for **both** project
and plan contexts in commit `661329532` (`feat(roles): demoMode read-only
gating in Assign Test Project/Plan Roles (Refs #932)`, 2026-09-18) — the
plan-context gap described by #943 (filed 2026-09-05) was a stale duplicate of
that fix. This run therefore **verified** the feature end-to-end, re-ran the
parity suite, documented the closure and closed the issue.

## Legacy source of truth

- `config.inc.php:2060` — `$tlCfg->demoMode`;
- `gui/templates/tl-classic/usermanagement/usersAssign.tpl:111-112` — form
  `onsubmit="alert('{$labels.warn_demo}'); return false;"` in demoMode;
- `gui/templates/tl-classic/usermanagement/usersAssign.tpl:248-249` — Save
  submit button replaced by `{$labels.warn_demo}` in demoMode.

## Modern implementation (on the default branch, committed under #932)

- **BFF** `api/roles/index.php`:
  - `demoModeBlockedWrite()` (:280-292) → HTTP 403 `code=demo_mode` +
    localized message when `config_get('demoMode')`;
  - `PUT /roles/tplan-roles` gated FIRST (:823-828) with
    `demoModeBlockedWrite('warn_demo','assign.demoDisabled')`;
  - `PUT /roles/tproject-roles` gated the same way (:682);
  - `GET /roles/meta/tplan-roles` exposes `demoMode` (:819), plus
    `GET /roles` (:414) and `GET /roles/meta/tproject-roles` (:670).
- **Screen** `gui/templates/usermanagement/usersAssignPlan.html`:
  - `demoMode` var (:142); `applyDemoMode()` (:180-187) toggles `#demoBanner`,
    hides `#saveBtn`, shows `#demoNote` (warn_demo text), disables Save;
  - demo state pulled from the meta responses on project load (:234) and plan
    load (:407);
  - save guard (:541-544), row-change guard (:458) and bulk-Do guard (:518)
    keep Save locked in demoMode; `saveAssignments()` renders the warn_demo
    toast, mirroring the legacy `onsubmit` alert.
- **i18n**: `assign.demoDisabled` in all 10 bundles.

## Verification evidence (this run, 2026-09-21)

- demoMode=ON (config.inc.php:2060 flipped to ON, restored after): banner +
  `#demoNote` render "We are sorry. This feature is disabled for Demo.",
  `#saveBtn` absent; grid/selects stay usable (legacy usersAssign.tpl:286-292).
- Browser fetch, demoMode ON: GET meta tplan-roles → 200 `demoMode:true`;
  PUT tplan-roles → **403**
  `{code:"demo_mode",messageKey:"assign.demoDisabled",message:"We are sorry.
  This feature is disabled for Demo."}`; access log pair `[403]` (demo ON) vs
  `[200]` (demo OFF) on the same PUT.
- Client guard: `saveAssignments()` with demoMode ON → demo toast, no PUT.
- demoMode=OFF regression: Save visible; a role change (u941designer → leader)
  enabled Save, PUT returned 200 + "User Roles updated" toast and wrote
  `user_testplan_roles (2,2,9)`.
- Event Viewer: only AUDIT (log_level 16) rows; 0 rows ≥ 32 (Error/Warning).
- Task suite: `tmp/TLU_Test_Cases.md` — "Task — Issue #943" — **6/6 PASS**.

## Screenshots

- `docs/screenshots/issue-943-demo-on.png` — plan screen demoMode ON: banner +
  warn_demo note in place of the Save button.
- `docs/screenshots/issue-943-demo-off.png` — plan screen demoMode OFF: normal
  Save button (regression).

Refs #943.