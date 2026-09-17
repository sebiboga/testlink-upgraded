# Task 924 — Assign Test Project Roles: legacy `checkRights()` + effective-role project filtering (gap vs legacy)

**Issue:** [#924](https://github.com/sebiboga/testlink-upgraded/issues/924)
**Status:** IMPLEMENTED (2026-09-17) — branch `task/issue-924-assign-tproject-rights`

## The gap

`api/roles/index.php` gated **every** `/api/roles/*` route with a single
`role_management` check (`api/roles/index.php:42-50`, added by `c7439e57c` for
#897). The legacy Assign Test Project Roles screen used a **union** check
(`lib/usermanagement/usersAssign.php:201-240` `checkRights()`): a user is allowed
in when they hold **any** of

- `role_management`,
- `testplan_user_role_assignment` (test project / test plan context),
- `user_role_assignment` (global),
- `testproject_user_role_assignment` on the target test project.

Consequences of the modern behaviour:

1. **False denial** — a user whose only assign right is `user_role_assignment`
   (e.g. role `leader`) received `403 no_permissions_for_action` from the BFF
   although the legacy page loaded for them.
2. **No project filtering** — the Test Project combo was built from
   `get_accessible_for_user(...,'map_of_map')` (name/active, no `effective_role`),
   so it listed projects whose effective role cannot assign roles. Legacy
   `getTestProjectEffectiveRoles()` (`:273-336`) lists only projects whose
   **effective role** holds `user_role_assignment` OR
   `testproject_user_role_assignment`; when none remain it shows
   `testproject_roles_assign_disabled`.

## Legacy source of truth

- `lib/usermanagement/usersAssign.php:23` — `testlinkInitPage($db,false,false,"checkRights")`
- `lib/usermanagement/usersAssign.php:201-240` — `checkRights()` union
- `lib/usermanagement/usersAssign.php:246-266` — `checkRightsForUpdate()`
- `lib/usermanagement/usersAssign.php:273-336` — `getTestProjectEffectiveRoles()`
- `lib/functions/common.php:1010-1048` — `checkUserRightsFor()` (audit + redirect)
- `lib/functions/roles.inc.php:191-205` — `g_rights_users_global` (propagable rights)
- `lib/functions/testproject.class.php:490-683` — `get_accessible_for_user()` (`map_of_map_full` carries `effective_role`)

## Modern implementation (port)

### BFF — `api/roles/index.php`

- Removed the global `role_management` gate; enforcement is now **route-aware**:
  - role-catalog routes keep `role_management` (`denyRoleManagement()`);
  - `GET /meta/tproject-roles`, `GET /meta/tplan-roles`, `PUT /tproject-roles`,
    `PUT /tplan-roles` use the new helpers:
    - `userCanAssignRoles()` — legacy `checkRights()` union (reads the caller's
      project/plan roles before evaluating `hasRight()`);
    - `userCanUpdateAssignments()` — legacy `checkRightsForUpdate()`;
    - `denyAssignRights()` — `403` + `logAuditEvent(audit_security_user_right_missing)`.
- `getAssignableProjects()` — ports `getTestProjectEffectiveRoles()`: uses
  `get_accessible_for_user($userId, ['output'=>'map_of_map_full'])` and keeps only
  projects whose `effective_role` (`tlRole::hasRight()`) has
  `user_role_assignment` or `testproject_user_role_assignment`.
- `GET /meta/tproject-roles` now returns that filtered combo.

### Screen — `gui/templates/usermanagement/usersAssignProject.html`

- New `#disabledMsg` block renders the legacy `testproject_roles_assign_disabled`
  feedback when the combo is empty or the API answers `403`; the table is hidden
  and the project select disabled.
- Safe selection: a URL `tproject_id` not present in the filtered combo falls back
  to the first assignable project (legacy `:307-316`) instead of loading a
  non-assignable project.
- i18n key `assign.rolesDisabled` added to all 10 locale bundles.

## Verification

- `leader` (global role 9, project role `tester` on Alpha): BFF returns
  `projects=[Beta]`; the legacy page renders the identical combo `["2:Beta Project"]`.
- `frank` (role 3 `<no rights>`): BFF returns `403` and logs
  `audit_security_user_right_missing`; screen shows the disabled message.
- admin: both projects listed, assignments table loads.
- `php -l` clean; all 10 i18n bundles valid JSON. Full matrix: `tmp/TLU_Test_Cases.md`
  suite 1523 (15/15 PASS).

## Note (separate, pre-existing bug)

`PUT` with an empty `assignments` map triggers `deleteUserRoles(id, [])` →
`user_id IN()` SQL syntax error (`lib/functions/testproject.class.php:1830-1843`).
Not part of this task; filed as bug [#1523](https://github.com/sebiboga/testlink-upgraded/issues/1523).
