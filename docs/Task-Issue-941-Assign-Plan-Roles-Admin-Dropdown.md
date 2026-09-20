# Task 941 — Do not offer the admin role in the per-user role dropdown in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#941](https://github.com/sebiboga/testlink-upgraded/issues/941)
**Status:** IMPLEMENTED & VERIFIED (2026-09-20) — branch `task/issue-941`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:259-271` never offers the
admin role (`TL_ROLES_ADMIN`, id **8**) as an assignable option inside the per-user
"Plan Role Override" `<select>` — the option is hidden unless it is that row's current
**explicit, non-inherited** plan assignment (`$applySelected != ''` ⇒ `$removeRole = 0`).
The **bulk "Set roles to"** combobox, however, iterates the full `$gui->optRights`
list (`usersAssign.tpl:189-206`, `lib/usermanagement/usersAssign.php:587
optRights = tlRole::getAll(...)`) and therefore **keeps** the admin role — the
per-user combo and the bulk combo deliberately differ.

When the issue was created, the modern `usersAssignPlan.html` rendered the admin option
in every per-user row select AND dropped it from the bulk list — both diverged.

## Legacy source of truth

- `gui/templates/dashio/usermanagement/usersAssign.tpl:259-271` — per-user combo:
  `$removeRole` hides `TL_ROLES_ADMIN` unless it is the row's current explicit
  assignment (`$applySelected != ''`), so the option renders only when already selected.
- `usersAssign.tpl:189-206` — bulk "Set roles to" `<select id="allUsersRole">`
  iterates the **unfiltered** `$gui->optRights` (admin included).
- `lib/usermanagement/usersAssign.php:587` — `$gui->optRights = tlRole::getAll($db, ...)`
  (full role set incl. id 8 and the id-0 pseudo-role).

## Modern implementation

- `gui/templates/usermanagement/usersAssignPlan.html` `rolesOptions()` (:295-303) —
  the **per-user** select already excluded admin except on the row whose current
  explicit plan role is admin (`if (role.id == ADMIN_ROLE_ID && u.origRoleID !=
  role.id) return;`); this half of #941 had been shipped by the #928 fix.
- `gui/templates/usermanagement/usersAssignPlan.html` `buildBulkSelect()` (:469-480) —
  **this run:** removed the `role.id == ADMIN_ROLE_ID` skip so the bulk "Set roles to"
  combobox lists the admin role again, exactly like the legacy full `optRights`
  (value-0 "no override" stays as the revert-to-inherited equivalent of the legacy
  id-0 pseudo-role, which the BFF already filters from `roles`).
- `gui/templates/usermanagement/usersAssignPlan.html` `applyBulkRole()` (:497-514) —
  **this run:** added a legacy-`set_combo_group()` parity guard — the browser leaves a
  `<select>` unchanged when the assigned value matches no option in it, so selecting
  "admin" in the bulk box only affects rows whose current explicit plan role IS admin
  (their select hosts the 8 option); every other row stays untouched. Global-admin
  rows remain skipped as before.

## BFF

No new endpoint needed: `api/roles/index.php` GET `/roles/meta/tplan-roles` already
reports the full role list (incl. id 8) and each user's **explicit** plan `roleID`
(`$u->tplanRoles[$tplan_id]->dbID`), which is the exact per-row condition the legacy
`$removeRole` logic keys on.

## i18n

No new keys — the admin option label comes from the BFF role list (`tlRole::getAll`
display names), exactly as legacy rendered it.

## Verification matrix (live server, admin session; fixture `tmp/fixtures_941.php` this run)

Fixtures: project "PLANROLES941" (id 3), plan "PLAN941-R1" (id 4), users
`admin`(g8), `u941designer`(g4), `u941senior`(g6, plan override 6),
`u941tester`(g7, plan override 7), `u941leader`(g9),
`u941adminpl`(g7, **plan override 8 = admin**). All 5 non-admin users have project
roles so the Inherited Role column is populated.

| Check | Result |
|---|---|
| `GET /roles/meta/tplan-roles?tproject_id=3&tplan_id=4` | 200; roleOpts incl. `{id:8,name:"admin"}`; `adminpl roleID=8`, all other rows roleID 0/6/7 |
| Per-user combo — `admin` row (global admin) | options `[0,1,2,3,4,5,6,7,9]`, select disabled — no admin |
| Per-user combo — `u941adminpl` (current explicit plan role = admin) | options `[0,1,2,3,4,5,6,7,8,9]`, **admin present + selected** |
| Per-user combo — `designer/leader/senior/tester` | options `[0,1,2,3,4,5,6,7,9]` — no admin |
| Bulk "Set roles to" combobox | `[0,1,2,3,4,5,6,7,8,9]` — **admin included** (legacy parity) |
| Bulk "Set roles to: admin" + Do | model unchanged for every row (browser-no-option parity); Save stays disabled |
| Bulk "Set roles to: tester (7)" + Do | every non-admin row → 7 (bulk still works); Save enabled |
| UI row change (designer → leader 9) + Save | DB `user_testplan_roles` `(7,4,9)`; grid reloads |
| Console | no errors/warnings |
| Event Viewer (`events` table) | no Error/Warning rows (only INFO/audit) |

Screenshots: `docs/screenshots/issue-941-assign-plan-roles-admin-dropdown-disabled.png`,
`docs/screenshots/issue-941-bulk-set-roles-to-includes-admin.png`.

## Regression

`node --check` on the extracted inline script clean; per-user admin-option
exclusion, global-admin row lock, normal bulk "Set roles to", Save and reload all
re-verified — full walk-through in `tmp/TLU_Test_Cases.md` suite "Task — Issue #941"
(all PASS).