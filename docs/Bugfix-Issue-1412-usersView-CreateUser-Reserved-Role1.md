# Issue 1412 — usersView.html: Create User assigns reserved role 1 (no rights) instead of `default_roleid` on create

**Issue:** [#1412](https://github.com/sebiboga/testlink-upgraded/issues/1412)
**Branch:** `fix/issue-1412`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

Creating a user from the modernized User Management screen
(`gui/templates/usermanagement/usersView.html`) assigns the reserved role
`<reserved system role 1>` (id 1, **zero rights**) instead of the configured
`$tlCfg->default_roleid` (guest, id 5). The new user cannot see any project,
and re-saving such a user (or a user whose stored role is 0/unknown) can strip
rights on save.

## Repro steps

1. Login admin/admin → `http://localhost:8082/gui/templates/usermanagement/usersView.html?tproject_id=0&tplan_id=0`
2. Click **Create User**; leave the Global Role dropdown on its default selection
   (first, unnamed option = `<reserved system role 1>`); fill the rest; Save.
3. `mysql testlink -e "SELECT id, login, role_id FROM users"` → new user has `role_id = 1`.
4. Screenshots (before/after) live under `docs/screenshots/issue-1412-*.png`.

**Expected:** new users get the legacy default role
(`gui/templates/dashio/usermanagement/usersEdit.tpl:240-243` +
`usersEdit.php` use `$tlCfg->default_roleid`, `config.inc.php:1968` =
`TL_ROLES_GUEST`, `cfg/const.inc.php:521` → role 5) whenever the created
user's `globalRoleID` is 0.

## Root cause

`gui/templates/usermanagement/usersView.html:371` (`showCreateModal()`):

```js
$('#editRole').val($('#editRole option:first').val());
```

The dropdown is populated from `api/users/index.php:190`:
`tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM)`.
`lib/functions/tlRole.class.php:517` sorts `ORDER BY id ASC`, so the **first
option is DB row id 1 = `<reserved system role 1>`** (zero rights) — the least
privilege trap.

Chain:

1. `/api/users/meta/roles` returns roles id-ascending (role 1 first) and never
   exposed `default_roleid`.
2. `showCreateModal()` preselects `option:first` → role 1.
3. `POST /api/users` (`api/users/index.php:273`) stored
   `intval($body['globalRoleID'] ?? 0)` verbatim → `role_id = 1` persisted with
   no fallback for role 0/absent.
4. Edit path had the same invariant hole: `$('#editRole').val(u.globalRoleID)`
   silently fails when the stored role is not among the options (0 / stripped),
   leaving the first option (role 1) selected so a re-save rewrites the user
   onto role 1.

Blast radius:

- Only `usersView.html` consumes `/api/users/meta/roles`; the change is
  additive.
- Server `tlUser::writeToDB` (`lib/functions/tlUser.class.php:434,453`) has no
  role validation — 0/junk roles are stored as-is.

## Fix (minimal)

**BFF `api/users/index.php`**

- `/meta/roles`: expose `defaultRoleID` = `intval(config_get('default_roleid'))`
  (legacy `usersEdit.tpl:240-243` parity) **and** skip `id <= 0` entries
  (mirror of legacy `usersEdit.php:85` `unset($roles[TL_ROLES_UNDEFINED])`
  which drops the `<inherited>` pseudo-role from the dropdown).
- `POST /users`: `globalRoleID <= 0` (missing/zero) now maps to
  `intval(config_get('default_roleid'))` instead of persisting 0 — role-by-omission
  can no longer happen through the API.

**Frontend `gui/templates/usermanagement/usersView.html`**

New `presetRole(value)` helper used by **both** create and edit:

```js
function presetRole(value) {
  var v = parseInt(value, 10) || 0;
  if (v === 0) { v = defaultRoleID; }
  var sel = $('#editRole');
  if (v > 0 && sel.find('option[value="' + v + '"]').length) {
    sel.val(String(v));
  } else {
    sel.val(sel.find('option:first').val());
  }
}
```

- `showCreateModal()` → `presetRole(defaultRoleID)` (legacy: create form
  preselects `default_roleid` because `globalRoleID eq 0`).
- `editUser()` → `presetRole(u.globalRoleID)` — a stored valid role is preserved
  exactly; a 0/unknown stored role falls back to the default instead of silently
  landing on reserved role 1.

**Method / alternatives**

- Method: mirror the legacy invariant *"globalRoleID 0 ⇒ default role"* in the
  BFF (single source of truth) **plus** fix the modal preselection in the UI.
- Rejected: hardcoding role `5` in the HTML/JS — the default is
  config-driven (`$tlCfg->default_roleid`), a hardcode would diverge if an admin
  changes `default_roleid`.
- Rejected: silently guessing the "lowest real role" — that is exactly what
  produced role 1; the correct default is the configured one.
- No real role id 0 is ever a valid `users.role_id` (the `<inherited>` id-0
  entry is only a transient pseudo-object added by `tlRole::getAll`), so
  coercing 0→default is safe.

## Verification (regression matrix, all PASS on localhost:8082)

- **primary repro (UI create, default role):** pre-fix `reprouser` stored
  `role_id=1`; post-fix `fixuser` stored `role_id=5` (guest).
- **explicit role preserved:** UI create picking "test designer" → `role_id=4`.
- **API POST without `globalRoleID`** → `role_id=5`; **with `globalRoleID:0`** → `role_id=5`.
- **edit existing user with valid stored role** (reprouser, role 1): modal shows
  `<reserved system role 1>`, save preserves role 1.
- **edit user with legacy `role_id=0`**: modal preselects guest (5), save
  persists `role_id=5`.
- **dropdown hygiene:** `/meta/roles` returns ids 1..9, no `<inherited>` (id 0).
- **hygiene:** `php -l` clean; `node --check` clean; `events` table only
  log_level 16 (INFO) rows — no new Error/Warning; **no i18n bundle touched**
  (no user-facing string changed).

## Files changed

- `api/users/index.php` — `/meta/roles`: `defaultRoleID` + drop id≤0;
  `POST /users`: role 0/absent → default.
- `gui/templates/usermanagement/usersView.html` — `presetRole()` for create/edit.
- `CHANGELOG` — KEY BUGFIX entry (`#1412`).

## Evidence

Commit range `(fix)`, `(regression suite)`, `(docs + wiki)` on branch
`fix/issue-1412` (fix commit `c35db8c8d`).
Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #1412".
Screenshots: `docs/screenshots/issue-1412-create-modal-preselect-role1.png`
(pre-fix), `issue-1412-create-modal-preselect-guest-fixed.png` (post-fix),
`issue-1412-grid-after-create-fix.png` (post-fix grid).