# Task 889 — Default role + reserved/undefined role handling in User Management create/edit (gap vs legacy)

**Issue:** [#889](https://github.com/sebiboga/testlink-upgraded/issues/889)
**Branch:** `task/issue-889-default-role`
**Status:** IMPLEMENTED / VERIFIED (2026-09-12)

## The gap

The legacy User Management create/edit flow (`lib/usermanagement/usersEdit.php` +
`gui/templates/dashio/usermanagement/usersEdit.tpl`) never shows the undefined /
`<inherited>` role (id 0) in the Global Role dropdown and defaults a new user to
`$tlCfg->default_roleid` (**guest**, role 5). The modern screen originally offered
`{"id":0,"name":"<inherited>"}` in `GET /api/users/meta/roles` and pre-selected the
**first** dropdown option on create — `<reserved system role 1>` (id 1, zero rights) —
so users created from the UI got a role with no rights, and editing a user with a
0/absent stored role could silently rewrite them onto the reserved role.

## Legacy source of truth

- `lib/usermanagement/usersEdit.php:77-78` — role dropdown list is built with
  `tlRole::getAll(..., TLOBJ_O_GET_DETAIL_MINIMUM)` and then
  `unset($roles[TL_ROLES_UNDEFINED])` → the id-0 pseudo-role is never selectable.
- `gui/templates/dashio/usermanagement/usersEdit.tpl:240-243` —
  `$selected_role = $gui->user->globalRoleID; if ($gui->user->globalRoleID eq 0) $selected_role = $tlCfg->default_roleid;`
  (`config.inc.php:1968` = `TL_ROLES_GUEST`, role 5).
- `doCreate()`/`doUpdate()` persist the selected `rights_id` verbatim; because the
  dropdown never contains 0, role-0-by-omission cannot happen through the UI.

## Modern implementation (port)

Landing commit: `80790f9d3` "fix(usersView): preselect default_roleid (guest) for
create/edit instead of reserved role 1 (`Fixes #1412`)" — already on the default
branch; this task verified and documented the full parity.

**BFF `api/users/index.php`**
- `GET /users/meta/roles` (lines 189-207): skips `intval($r->dbID) <= 0` (mirror of
  legacy `unset($roles[TL_ROLES_UNDEFINED])`) and exposes
  `defaultRoleID = intval(config_get('default_roleid'))`.
- `POST /users` (lines 293-294): `$roleID = intval($body['globalRoleID'] ?? 0);`
  `$u->globalRoleID = $roleID > 0 ? $roleID : intval(config_get('default_roleid'));` —
  a missing/zero role can never persist as a reserved/zero-rights role.

**Frontend `gui/templates/usermanagement/usersView.html`**
- New `presetRole(value)` (lines 384-393): 0/absent → `defaultRoleID`, otherwise
  selects the requested role when present, else first option.
- `showCreateModal()` line 408 → `presetRole(defaultRoleID)` (legacy create default).
- `editUser()` line 433 → `presetRole(u.globalRoleID)` (legacy edit preselection).

No i18n bundle was touched — every label/option content already exists (no new
user-facing string). `php -l` and `node --check` clean on the touched files.

## Measured verification (Chrome DevTools MCP + mysql, localhost:8082)

1. `GET /api/users/meta/roles` → `items` ids 1..9 only (no `<inherited>`),
   `defaultRoleID: 5`.
2. Create modal → Global Role preselected **guest**, 9 options, no id 0.
3. Create `rolletest` keeping the default → DB `role_id = 5` (guest).
4. Create `roleditpick` picking "tester" → DB `role_id = 7` (explicit preserved).
5. Edit `rolletest` guest→tester → DB `role_id = 7`; back to guest → `role_id = 5`.
6. Legacy edge: `UPDATE users SET role_id=0` on `roleditpick`, open Edit → modal
   preselects **guest** (5), Save persists `role_id = 5` — exact `usersEdit.tpl:240-243` behavior.
7. BFF POST without `globalRoleID` → HTTP 200 `globalRoleID: 5`; with `globalRoleID: 0` → HTTP 200 `globalRoleID: 5`.
8. Event Viewer / `events` table: `log_level <= 3` count = **0** (only `log_level=16` audit rows); browser console clean.

## Test suite

`tmp/TLU_Test_Cases.md` → "Task — Issue #889: Default role + reserved/undefined role
handling in User Management create/edit (gap vs legacy)" — **11/11 PASS**.

## Files

- `api/users/index.php` — role-0 filter + `defaultRoleID` + POST role fallback (landed in `80790f9d3`, Refs #1412).
- `gui/templates/usermanagement/usersView.html` — `presetRole()` for create/edit (landed in `80790f9d3`).
- `CHANGELOG` — NEW FEATURES entry (`#889`).
- `tmp/TLU_Test_Cases.md` — Task suite #889.
- Copyright headers/whitespace unchanged elsewhere.