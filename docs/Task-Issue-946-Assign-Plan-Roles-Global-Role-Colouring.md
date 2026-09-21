# Task 946 — usersAssignGlobalRoleColoring row colour coding in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#946](https://github.com/sebiboga/testlink-upgraded/issues/946)
**Status:** IMPLEMENTED & VERIFIED, issue CLOSED (2026-09-21) — branch `task/issue-946`

## The gap

Legacy paints each user's cell with the background colour configured for that
user's GLOBAL role when the config flag is on:

- `lib/usermanagement/usersAssign.php:593-598` `initializeGui()` — when
  `config_get('gui')->usersAssignGlobalRoleColoring == ENABLED` sets
  `$gui->role_colour = tlRole::getRoleColourCfg($dbHandler)`.
- `lib/functions/tlRole.class.php:539-552` `getRoleColourCfg()` — returns
  `config_get('role_colour')` (= `$GLOBALS['g_role_colour']`,
  `cfg/const.inc.php:536`: 'admin'=>'white','tester'=>'wheat','leader'=>'acqua',
  'senior tester'=>'#FFA','guest'=>'pink','test designer'=>'cyan',
  '<no rights>'=>'grey','<inherited>'=>'seashell`) merged with every
  `roles.description` row found in DB (missing → '').
- `gui/templates/dashio/usermanagement/usersAssign.tpl:239-241` — the first
  `<td>` of each user row (`$user->login (first last)`) gets
  `style="background-color: {$gui->role_colour[$globalRoleName]};"` when that
  colour is non-empty; `$globalRoleName = $user->globalRole->name` which is the
  role **description**.

**What modern did instead:** `gui/templates/usermanagement/usersAssignPlan.html`
rendered plain monochrome rows; `api/roles/index.php` `GET /roles/meta/tplan-roles`
returned no global-role/colour data at all.

## Implementation

**BFF** (`api/roles/index.php`):
- `globalRoleColourContext(&$db)` — legacy parity of `usersAssign.php:593-598`:
  when `config_get('gui')->usersAssignGlobalRoleColoring == ENABLED` builds the
  colour map via `tlRole::getRoleColourCfg()`; otherwise returns
  `['enabled'=>false,'map'=>[]]`.
- `userGlobalRoleColour(&$db,&$u,$colourCtx)` — resolves the user's GLOBAL role
  RAW description (`$u->globalRole->name` == `roles.description`, the key legacy
  looks up) and its configured colour ('' when off / not configured).
  IMPORTANT: the lookup uses the raw `->name`, NOT `getDisplayName()` — the
  latter rewrites `<no rights>` into `<no_rights>` and would miss the `grey`
  map entry (parity bug caught in code review).
- Both `GET /roles/meta/tplan-roles` and `GET /roles/meta/tproject-roles` now
  emit per-item `globalRoleName` + `roleColour` and a top-level `roleColouring`
  bool (computed once via `globalRoleColourContext($db)` per route). The
  tproject route is covered too because legacy `usersAssign.tpl` is the SHARED
  template for project and plan contexts.

**Front-end** (`gui/templates/usermanagement/usersAssignPlan.html` +
`usersAssignProject.html`):
- Plan screen: `loadUsers()` model entries carry `globalRoleName`/`roleColour`;
  `renderUsersTable()` paints Login (col 1) AND Name (col 2) cells with
  `background-color` when `roleColour` is non-empty (legacy fused login+name in
  one cell; the modern grid splits them, so both share the tint);
  `createdRow` re-applies the background so DataTables paging/search/sort
  re-draws keep the colour — same mechanism as the existing changed-badge.
- Project screen: `buildBodyHtml()` applies the same inline tint from the BFF
  items (data flows straight from `currentItems`).
- No i18n keys added — no new user-facing strings.

## Verification (browser, admin/admin, fixtures_946: tproject=1, tplan=2, flag ENABLED)

- Plan grid computed backgrounds match `$g_role_colour`: admin `white`,
  u946designer `cyan`, u946guest `pink`, u946senior `#FFA`, u946tester `wheat`,
  u946norights `grey` (RAW description key — proves `->name` not
  `getDisplayName()`); u946leader stays plain — its `acqua` value is invalid CSS
  injected verbatim (legacy-identical).
- Colours persist through DataTables search + sort re-draws (createdRow).
- Changed row keeps its tint + gains `.changed` class/badge.
- Config gating: flag DISABLED → all plain, `roleColouring:false`;
  flag ENABLED → tints. Repo default stays `DISABLED`.
- Event Viewer: no new Error/Warning; console clean.

Screenshots: `docs/screenshots/issue-946-assignplan-role-colors.png`,
`issue-946-assignproject-role-colors.png`, `issue-946-assignplan-role-colors-modified.png`.
Fixture: `tmp/fixtures_946.php`.

Task suite: `tmp/TLU_Test_Cases.md` — **Suite 946, 7/7 PASS**.

Refs #946.