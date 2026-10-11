# Role Create/Edit standalone screen (`rolesEdit`) — Refs #1898

Modernization of the legacy **User Management → Create / Edit Role** form
(`lib/usermanagement/rolesEdit.php` + `gui/templates/dashio/usermanagement/rolesEdit.tpl`)
into a standalone deep-linkable Dashio screen with its own REST BFF.

## Entry points

- **Row action** on `gui/templates/usermanagement/rolesView.html`: external-link
  icon *Open editor* (edit mode of that row). Kept visible in demo mode — the
  editor itself renders read-only there, matching the legacy screen.
- **Deep link**: `gui/templates/usermanagement/rolesEdit.html?mode=create|edit|duplicate&roleid=N&tproject_id=P&tplan_id=L`
- **Legacy URLs**: `lib/usermanagement/rolesEdit.php?doAction=create|edit|duplicate&roleid=N`
  is now a non-mutating shim — GET (auth) → 302 to the modern screen (context
  preserved), POST → 405 `Allow: GET, HEAD`, anonymous → login.

## Screen — `gui/templates/usermanagement/rolesEdit.html`

Dashio shell, TLi18n locale switcher, toolbar (Refresh / Back to Role
Management), Role name (+ event-history icon gated by `mgt_view_events`),
Description, the **grouped rights matrix** (the 12 `rights_*` config groups
rendered as 12 fieldsets — the parity feature the inline `rolesView` modal only
offered as a flat list), Select All / Clear All, Save / Duplicate / Delete /
Cancel, the **possible affected users** card, the save-confirm warning when users
carry the role, and localized deny / locked / demo state cards.

## BFF — `api/rolesedit/index.php`

Session auth + `bffSameOriginGuard()` + `bffEnforceSession()`; the legacy
`checkRights()` (`$user->hasRight($db, "role_management")`) is enforced on every
route; demo mode blocks writes (403 `demo_mode`, `messageKey
role.demoUpdateDisabled`). Every scalar input is `is_scalar`-guarded (issue
#1738 class: a JSON body `{"roleid":[1]}` is rejected 400 instead of silently
coercing to role 1).

| Verb / action | Purpose |
|---|---|
| `GET ?action=init&mode=create\|edit\|duplicate[&roleid=N]` | form schema + role payload + grouped rights + affected users |
| `POST ?action=save` | create (`roleid=0`) or update (`roleid=N`) |
| `POST ?action=duplicate` | `generateUniqueName()` copy of an existing role |
| `POST ?action=delete` | delete a non-system role |

Machine codes: `session_expired` (401) · `wrong_method` (405) · `demo_mode`
(403) · `missing_roleid` / `invalid_roleid` / `invalid_input` / `empty_role_name`
/ `no_rights` / `invalid_rights` (400) · `role_not_found` (404).

Legacy parity kept:

- **Protected roles** — `TL_ROLES_ADMIN` (8) and `TL_ROLES_NO_RIGHTS` (3) are
  read-only; a forged update is refused (400 + audit trace).
- **System roles** — role ids `<= TL_LAST_SYSTEM_ROLE` (9) cannot be deleted
  (`role.systemDeleteDenied`).
- Right names in a request are allow-listed against the config maps
  (`$GLOBALS['g_rights_*']`, built by `init_global_rights_maps()`); unknown names
  are dropped and an empty set is refused.
- Audit rows `audit_role_created` / `audit_role_saved` on `roles`, matching
  `rolesEdit.php:126-131`.
- On delete of a role still assigned to users, those users are reset to
  `<no rights>` (legacy `deleteFromDB`), surfaced in the confirm modal.

## i18n

18 new keys (`roe.group.*` × 12, `roe.affectedUsers`, `roe.confirmSaveTitle`,
`roe.confirmSaveMsg`, `roe.backToRoles`, `roe.clearAll`) + `footers.rolesEdit` in
**all 10** bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`);
`bash ai/verify_i18n_coverage.sh` PASS. No hardcoded strings on screen or in the
BFF.

## Verification

- Test suite: `Issue #1898` in `tmp/TLU_Test_Cases.md` — rights matrix with a real
  no-rights login (403 on read **and** write), protected/system-role guards,
  demo-mode write block, array-input traps, legacy shim branches, i18n gate,
  browser pass with create / edit / duplicate / delete / affected-users confirm.
- Mandatory code review (rule 16): 0 blockers; medium (array-input guards, i18n
  hardcoded strings) + low items applied.
- Event Viewer: audit rows only, 0 new Error/Warning.

## Screenshots

- `docs/1898-rolesedit-create.png` — create mode, grouped rights matrix.
- `docs/1898-rolesedit-edit.png` — edit mode + affected-users card.
- `docs/1898-rolesedit-confirm-save.png` — save-confirm warning (assigned users).
- `docs/1898-rolesedit-admin-locked.png` — admin role read-only.
- `docs/1898-rolesedit-edit-ro.png` — Romanian locale.
