# Task 944 — effective/inherited role display incl. global-role fallback in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#944](https://github.com/sebiboga/testlink-upgraded/issues/944)
**Status:** IMPLEMENTED & VERIFIED, issue CLOSED (2026-09-21) — branch `task/issue-944` (commit `a0ba29973`)

## The gap

Legacy `lib/functions/roles.inc.php:373-437` `get_tplan_effective_role()` computes
every user's effective test-plan role through a 3-layer model:

1. explicit plan role (`user_testplan_roles`) → effective, **not** inherited;
2. private plan + non-admin global role → `<no rights>`, **not** inherited;
3. otherwise **inherited**, honouring `testplan_role_inheritance_mode`
   (`testproject`, config.inc.php:227, default → the project **effective** role
   — itself a 3-layer result: explicit project role → global role on a public
   project → `<no rights>` on a private project; `global` → the user's global role).

`gui/templates/dashio/usermanagement/usersAssign.tpl:225-232` labels the
`<inherited> X` select option with `$ikx = is_inherited ? effective_role_id :
uplayer_role_id` (the global role for explicit rows, e.g. a user with only a
global `guest` role on a public plan sees `<inherited> guest`).

**What modern did instead:** `api/roles/index.php` GET `/roles/meta/tplan-roles`
(:790-798) set `inheritedRoleID`/`inheritedRoleName` **only from the project
role**, defaulting `inheritedRoleName='No'` — a user with no project role but a
global role (e.g. global guest) showed `No` in the Inherited Role column and a
bare `-- no override --` 0-option, whereas legacy shows `<inherited> guest`.
No `effectiveRoleID`/`isInherited` was exposed at all (unlike the sibling
`getTprojectEffectiveRoleMap` + `usersAssignProject.html` which already ship the
full pattern).

## Implementation

- `api/roles/index.php` — new `getTplanEffectiveRoleMap()` (:235-309) porting
  `get_tplan_effective_role()` + `get_tproject_effective_role()` verbatim:
  explicit plan role → private-plan no-rights → inheritance per
  `testplan_role_inheritance_mode` from the project effective role (its own
  3-layer with global fallback) else the global role; derives the legacy `$ikx`
  (`is_inherited ? effective_role_id : global role`). The GET
  `/roles/meta/tplan-roles` handler now fetches **both** the project's and the
  plan's `is_public` and returns per user `effectiveRoleID`, `isInherited`,
  `effectiveRoleName`, plus the corrected `inheritedRoleID`/`inheritedRoleName`
  (was project-role-only with a hardcoded `'No'` fallback).
- `gui/templates/usermanagement/usersAssignPlan.html` —
  `loadUsers()` stores `isInherited` + `effectiveRoleID`; `rolesOptions()`
  labels the value-0 option `<inherited> <inheritedRoleName>` (i18n
  `assign.inheritedRoleOption`, "<inherited> {role}") when the effective role
  is inherited — the exact pattern `usersAssignProject.html:326-331` already
  uses — else `assign.noOverride`.
- **i18n:** no new keys — `assign.inheritedRoleOption`, `assign.noOverride` are
  present in all 10 bundles.

## Regression checklist (all PASS)

- Public plan global-only users: Inherited Role column now `guest` / `tester`
  (was `No`); the select's default (value-0, selected) option reads
  `<inherited> guest` / `<inherited> tester`.
- Explicit-override row (u944lead): select keeps `leader` selected, unchanged;
  0-option stays `-- no override --` (isInherited=0) — no spurious dirty row.
- Project-role inherited row (u944designer): `<inherited> test designer`.
- Private plan: BFF `effectiveRoleID=3 (<no rights>)`, `isInherited=0`; DOM
  keeps `-- no override --`, Save disabled (no dirty-state regression, issue
  body matrix case 5).
- Save round-trip: changing a global-fallback row to `tester` persists
  `user_testplan_roles (uid,plan,7)`, grid reloads with `tester` selected.
- Event Viewer: only AUDIT (log_level 16) rows, 0 Error/Warning.

Screenshot: `docs/screenshots/issue-944-inherited-role-global-fallback.png`
(public plan: column + `<inherited> <role>` value-0 options for admin, designer,
guest, tester; leader explicit).

Task suite: `tmp/TLU_Test_Cases.md` — **Suite 944, 6/6 PASS**.

Refs #944.