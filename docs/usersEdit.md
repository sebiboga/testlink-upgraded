# User Create/Edit standalone screen (`usersEdit`) — Refs #1880

Modernization of the legacy **Users → Create / Edit** form
(`lib/usermanagement/usersEdit.php` + `gui/templates/dashio/usermanagement/usersEdit.tpl`)
into a standalone deep-linkable Dashio screen with its own REST BFF.

## Entry points

- **Toolbar** on `gui/templates/usermanagement/usersView.html`: *Open editor* (create mode).
- **Row action** on `usersView.html`: external-link icon (edit mode of that row).
- **Deep link**: `gui/templates/usermanagement/usersEdit.html?mode=create|edit&user_id=N&tproject_id=P&tplan_id=L`
- **Legacy URL**: `lib/usermanagement/usersEdit.php` is now a non-mutating shim —
  GET (auth) → 302 to the modern screen (context preserved), POST → 405 `Allow: GET, HEAD`,
  anonymous → `login.php?note=expired`.

## Screen

`gui/templates/usermanagement/usersEdit.html` — Dashio shell, TLi18n locale switcher,
toolbar (Refresh / Back to User Management), context cards (Mode, User), form
(Login readonly on edit, First/Last name, Password + hint, Email, Role, Locale,
Authentication, Expiration date + Clear, Active), Save/Cancel, Event-history button
(gated `mgt_view_events`), Security card (Reset password / Generate API key behind
confirm modals, hidden for external password management, `api->enabled = FALSE` and
demo mode), localized state cards (missing id / denied / not-found / bad-request /
session expired) and flash + toast + busy overlay.

## BFF — `api/usersedit/index.php`

Session auth + `bffSameOriginGuard()` + `bffEnforceSession()`; `mgt_users` enforced on
every route (`403 NO_RIGHT`); demo mode blocks writes (`403 DEMO_MODE`).

| Verb / action | Purpose |
|---|---|
| `GET ?action=init&mode=create\|edit[&user_id]` (also HEAD) | form schema / user payload |
| `POST ?action=create` | create user |
| `POST ?action=update` | update user (self-update refreshes the session, legacy `setUserSession` parity) |
| `POST ?action=reset_password` | legacy `resetPassword()` |
| `POST ?action=gen_apikey` | `APIKey::addKeyForUser()` + e-mail |

Machine codes: `NOT_AUTHENTICATED` (401) · `NO_RIGHT` (403) · `DEMO_MODE` (403) ·
`INVALID_SMTP_HOSTNAME` (400, legacy parity) · `invalid_expiration_date` (400,
validated **before** the write, `is_string` + `checkdate`, `null`/`''` clears) ·
`UNKNOWN_ACTION` (400) · `METHOD_NOT_ALLOWED` (405, `Allow: GET, HEAD`) ·
`CREATE_FAILED` / `UPDATE_FAILED` (500, `r.message` shown).

Legacy parity kept: `/ \ : * ? < > |` stripped from First/Last name, `noExpDateUsers`
hides the expiration date (admin), external-auth users refuse local password reset,
`api->enabled = FALSE` refuses key generation, unconfigured `$g_smtp_host` refuses both
e-mail actions.

## i18n

65 new keys (`ued.*` = 64 + `user.openEditor`) + `footers.usersEdit` in **all 10**
bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`);
`bash ai/verify_i18n_coverage.sh` PASS. No hardcoded strings on screen or in the BFF.

## Verification

- Test suite: `Issue #1880` in `tmp/TLU_Test_Cases.md` (24 cases) — rights matrix with a
  real no-rights login (403 on read **and** write), CSRF, expiration traps, legacy shim
  branches, browser pass, i18n gate.
- Mandatory code review (rule 16): 0 blockers; minors #1/#2/#3/#5 fixed.
- Event Viewer: audit rows only (`log_level 16`), 0 new Error/Warning.
