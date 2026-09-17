# Task 926 — Assign Test Project Roles: effective/inherited role display (gap vs legacy)

**Issue:** [#926](https://github.com/sebiboga/testlink-upgraded/issues/926)
**Status:** IMPLEMENTED (2026-09-17) — branch `task/issue-926-effective-role-display`

## The gap

The modern `usersAssignProject.html` screen showed the "*Assigned Role*" column
with a select built from all roles **plus** the id-0 `TL_ROLES_INHERITED`
pseudo-role injected by `tlRole::getAll()`. The BFF only returned `roleID`
(0 when nothing explicit was assigned), so:

1. **Two value-0 options** — one `-- no role --` (hardcoded) and one pseudo-role
   named `<inherited>` — corrupt duplicate; neither documented the user's real
   effective role.
2. **No effective role / inheritance info** — a user with an inherited role (no
   explicit project role) saw a blank/empty selection instead of the legacy
   `<inherited> <globalRoleName>`.
3. The `<inherited>` / reserved-system role labels rendered **blank** because
   unescaped `<` `>` were parsed as HTML tags by the browser.

## Legacy source of truth

- `gui/templates/dashio/usermanagement/usersAssign.tpl:226-269` — per-user select;
  `$ikx = effective_role_id` when `is_inherited`, else `uplayer_role_id`;
  the selected value-0 option text is `<inherited> <inherited_role_name>`; admin
  selects are `disabled`; `not_authorized_user` row when
  `effective_role_id == TL_ROLES_NO_RIGHTS`.
- `lib/functions/roles.inc.php:298-343` — `get_tproject_effective_role()`: 3-layer
  model, admin exception, private-project → `TL_ROLES_NO_RIGHTS`, explicit project
  assignment wins.

## Modern implementation (port)

### BFF — `api/roles/index.php`

- `getTprojectEffectiveRoleMap()` (`api/roles/index.php:142-183`) — legacy parity
  of `get_tproject_effective_role()`: resolves per user `effective_role_id`,
  `is_inherited`, `uplayer_role_id`, `inherited_role_id`, `inherited_role_name`
  (the legacy `$ikx` label source).
- `GET /roles/meta/tproject-roles` (`api/roles/index.php:505-560`): filters the
  id-0 `TL_ROLES_INHERITED` pseudo-role out of the `roles` option list (kills the
  duplicate value-0 option), fetches `is_public`, computes the effective map and
  returns `effectiveRoleID`, `isInherited`, `inheritedRoleID`,
  `inheritedRoleName`, `isAdmin`.

### Screen — `gui/templates/usermanagement/usersAssignProject.html`

- `esc()` HTML-escaping for every dynamic label (role names, logins, `<inherited>`
  literals) — fixes the blank reserved-system-role / `<inherited>` options.
- `loadUsers()` renders exactly **one** value-0 option per user: the translated
  `<inherited> {roleName}` (selected) when `isInherited==1`, otherwise
  `-- no role --`; real roles are selected by `effectiveRoleID` for explicit
  assignments.
- Admin selects disabled with an info-icon tooltip (`assign.adminRoleLocked`);
  inherited users get an `INHERITED ROLE` badge (`assign.inheritedRoleHint`);
  `not_authorized_user` row class for `effectiveRoleID == TL_ROLES_NO_RIGHTS`.

### i18n

- `assign.inheritedRoleOption` (`<inherited> {role}`), `assign.inheritedRoleHint`
  and `assign.adminRoleLocked` added to all 10 locale bundles (de/en/es/fr/it/
  ja/pt/ro/ru/zh). Mid-run, a parallel CI bundle rewrite clobbered the first
  insertion; the keys were re-added surgically and re-verified per-locale.

## Verification

- Public project (Alpha): alice→`<inherited> tester`, bob→`<inherited> leader`,
  carol→`<inherited> guest`, erin→`<inherited> senior tester`,
  frank→`<inherited> <no rights>` + `not_authorized_user`; admin select disabled;
  exactly one value-0 option per user; no raw `assign.*` keys in the DOM.
- Private project (Beta): all non-admin users without explicit assignment show a
  selected `<no rights>` option and `not_authorized_user` rows (legacy parity);
  admin select disabled.
- Assign flow: set alice→leader, save → row restored with "leader" selected and
  no inherited badge; DB `user_testproject_roles` row `(2,1,9)`. Unassign back to
  0 → row deleted → `<inherited> tester` + badge restored.
- Locale: `?locale=ro` renders `<mostenit> tester` with Romanian tooltips.
- `tplan-roles` sibling endpoint unaffected (200, empty plans).
- Event Viewer: no new Error/Warning (audit level 16 only). Full matrix:
  `tmp/TLU_Test_Cases.md` "Task — Issue #926" suite (12/12 PASS).

## Branch

- `task/issue-926-effective-role-display` — pushed; CI lands it on the default
  branch after the run.