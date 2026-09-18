# Task 928 — Remove the admin role option from the assignable role dropdown in Assign Test Project Roles (gap vs legacy)

**Issue:** [#928](https://github.com/sebiboga/testlink-upgraded/issues/928)
**Status:** IMPLEMENTED & VERIFIED (2026-09-18) — branch `task/issue-928`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:259-271` builds the
per-user role `<select>` and — for every row — SKIPS the `admin` role option
(`$removeRole`, TL_ROLES_ADMIN == 8) UNLESS that role is the user's current
effective **non-inherited** assignment, in which case it is rendered and
pre-selected (`$applySelected`). The dropdown therefore never offers "admin" as
a fresh assignable option; it can only preserve an already-assigned admin
(as the value-8 option that is pre-selected for that one row).

The modern `usersAssignProject.html:176-180` and `usersAssignPlan.html:153-157`
previously rendered **every** role from the BFF `roles` catalog (which correctly
keeps admin id 8) as an assignable option for **every** user row — so the modern
screens offered "admin" as a brand-new assignable role to any non-admin user,
the opposite of legacy.

## Legacy source of truth

- `gui/templates/dashio/usermanagement/usersAssign.tpl:259-271` — `$removeRole`
  (skip admin) unless `$applySelected` (effective_role_id == 8 && is_inherited == 0).
- `usersAssign.tpl:244-247` — `$applySelected` is computed from the effective
  (non-inherited) role id and the `is_inherited` flag.
- `usersAssign.tpl` is ALSO used by the Test PLAN screen (legacy shares one
  template for both contexts via `featureType` switch in `usersAssign.php:48-68`),
  so the same admin-skip parity applies to the plan role dropdown.
- `lib/functions/const.inc.php` — `TL_ROLES_ADMIN = 8` (and `TL_ROLES_INHERITED=0`,
  `TL_ROLES_NO_RIGHTS=3`).

## Modern implementation

Both dropdown loops now mirror legacy exactly:

- `usersAssignProject.html`: added `var ADMIN_ROLE_ID = 8;` (mirror of
  `TL_ROLES_ADMIN`). The options loop skips role 8 for a row unless
  `!inherited && u.effectiveRoleID == role.id` — i.e. the admin option is only
  rendered when it IS the user's current effective non-inherited assignment, and
  is then pre-selected (line ~191). Inherited admin keeps rendering as the
  value-0 `<inherited> admin` option above (legacy usersAssign.tpl:226-232).
- `usersAssignPlan.html`: same `ADMIN_ROLE_ID = 8` constant + skip rule, using
  the row's explicit plan assignment (`u.roleID == role.id`) as the keep
  condition (line ~166). These are the ONLY two screens in the app that read the
  `roles` assignable catalog for this dropdown; the BFF `roles` catalog keeps
  admin id 8 intact because role OPTIONS elsewhere (rolesView / rolesEdit /
  rights picker) legitimately show it.

## Verification evidence

Expanded the DB fixture so the keep-path is exercised live (not just the drop):

- `user_testproject_roles`: tester1 → explicit `admin` (8) on project 1
- `user_testplan_roles`: tester1 → explicit `admin` (8) on testplan 1
- Web GUI `usersAssignProject.html`: tester1's row renders the **admin option
  kept + selected**; tester2 / designer1 rows render **no admin option**.
- Web GUI `usersAssignPlan.html`: tester1's plan row renders **admin kept +
  selected**; other rows drop it.
- Full legacy parity matrix (both screens, admin-inherited vs explicit cases)
  passed in the live browser — see `tmp/TLU_Test_Cases.md`, "Suite — Issue #928".

Refs #928.
