# Task 927 — Assign Test Project Roles: lock (disable) the role select for global-admin users (gap vs legacy)

**Issue:** [#927](https://github.com/sebiboga/testlink-upgraded/issues/927)
**Status:** IMPLEMENTED & VERIFIED (2026-09-17) — branch `task/issue-927`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:244-248` renders the
per-user role `<select>` with `disabled="disabled"` when the user's GLOBAL role is
admin (`$user->globalRole->dbID == TL_ROLES_ADMIN`, 8) and shows a heads-up hint
(`usersAssign.tpl:275-277`) whose title comes from
`system_design_blocks_global_admin_change` = "Global Admin can not be changed"
(`locale/en_GB/strings.txt:3038`). A global admin can therefore never be given a
project-level role from this screen — and because the disabled control is not
submitted, the legacy server never receives an assignment for admins at all.

The modern `usersAssignProject.html` used to render an editable `<select>` for every
user with no admin distinction, so a global admin's project role could be changed
(or erased) — the opposite of legacy.

## Legacy source of truth

- `gui/templates/dashio/usermanagement/usersAssign.tpl:244-248` — `disabled="disabled"`
  gated on `$user->globalRole->dbID == TL_ROLES_ADMIN`.
- `usersAssign.tpl:275-277` — hint image next to the disabled select for admins.
- `usersAssign.tpl:226-232` — the displayed value for such rows is the effective role
  ("<inherited> admin"), never a project role.
- `lib/usermanagement/usersAssign.php` serves BOTH Test Project AND Test Plan
  assignment from the SAME template (featureType switch at `:48-68`), so the admin
  lock applies to the plan screen too.

## Modern implementation

The UI lock itself landed on the default branch earlier via commit `7eec567aa`
(a previous task-implementation run that never closed the ticket):

- `api/roles/index.php:553` — `GET /roles/meta/tproject-roles` items carry
  `'isAdmin' => intval($u->globalRoleID) == TL_ROLES_ADMIN`.
- `gui/templates/usermanagement/usersAssignProject.html:182-188` — admin rows render
  a `disabled` select plus a localized info hint (`assign.adminRoleLocked`).

This run completed the port — the remaining fidelity gap was that **the save payload
still contained the locked admin rows** (`{"1":0,...}`), which legacy never submits:

- `usersAssignProject.html saveAssignments()`. skip `:disabled` selects (mirror of
  "disabled form controls are not submitted").
- `api/roles/index.php` PUT `/roles/tproject-roles` — after the empty-map guard,
  active global-admin ids are stripped from the assignments map before
  `deleteUserRoles()` / `addUserRole()` (defense in depth; a crafted direct API call
  can no longer change or erase an admin's project role).
- Same legacy feature ported to the Test Plan screen (it shares the legacy template):
  `api/roles/index.php` GET `/roles/meta/tplan-roles` now emits `isAdmin`;
  PUT `/roles/tplan-roles` strips admin ids; `usersAssignPlan.html` disables the
  "Plan Role Override" select for admin rows with the same localized hint and skips
  disabled selects in `saveAssignments()`. `.assign-table select:disabled` CSS added
  for visual parity.

## i18n

- `assign.adminRoleLocked` — "Administrator role is locked and cannot be changed."
  present in ALL 10 bundles (en 29, ro, de, es, fr, it, pt, ru, ja, zh) — added by
  the earlier run, re-verified valid JSON here.
- `assign.inheritedRole` / `assign.inheritedRoleHint` — the non-admin inherited hint
  (already present).

## Verification matrix (live server, admin session; fixtures `tmp/fixtures_927.php`)

| Check | Result |
|---|---|
| `GET /roles/meta/tproject-roles?tproject_id=3` | user 1 `isAdmin=true`, user 2 `isAdmin=false` |
| Project screen, admin row | select `disabled`, value "<inherited> admin", info hint shown |
| Project screen, tester927 row | select enabled, "<inherited> leader" |
| Change tester927 → tester, Save | PUT body `{"tproject_id":2,"assignments":{"2":7}}` — **no `"1"` key**; row `(2,2,7)` written |
| Crafted PUT `{"assignments":{"1":9,"2":4}}` | 200, admin assignment **stripped** server-side; only `(2,2,4)` written |
| Plan screen (CATALOG-R1) | admin row "Plan Role Override" select disabled + hint; tester927 editable |
| Plan screen change + Save | PUT `{"tplan_id":4,"assignments":{"2":7}}` — no admin key |
| Crafted PUT tplan `{"assignments":{"1":9,"2":6}}` | 200; admin stripped; `(4,2,6)` written only |
| Console | no JS errors |
| Event Viewer (`events` table) | only AUDIT/16 + INFO rows; **no new Error/Warning** |

Screenshots: `docs/screenshots/issue-927-assign-project-roles-admin-locked.png`,
`docs/screenshots/issue-927-assign-plan-roles-admin-locked.png`.

## Regression

`php -l api/roles/index.php` clean; `node --check` clean on both screen scripts; the
authorized assign paths (regular users on project & plan screens, both BFF routes)
are unaffected — full walk-through in `tmp/TLU_Test_Cases.md` suite
"Task — Issue #927" (all PASS).