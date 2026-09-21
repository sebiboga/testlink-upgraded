# Task — Issue #1337: `isPasswordMgtExternal()` parity in the auth signup BFF

The self-sign-up BFF route (`POST /api/auth/signup`) previously probed external
password management with `config_get('external_password_mgmt', false)` — a key
that is NOT defined anywhere in `config.inc.php` nor the DB config table.
`config_get()` (`lib/functions/common.php:681-705`) unconditionally logs a
WARNING-level event (`config option not available: external_password_mgmt`,
`tlLogger::WARNING = 2`) on **every** signup POST, polluting the Event Viewer.
Because the getter fell back to `false`, the `$externalPwd` branch was dead code
and the server always expected a local password even when the page hid the
password fields based on the correctly-computed `externalPasswordMgmt` from
`loginPageConfig` — the BFF and the page disagreed about auth semantics.

This mirrors the gap filed as **task #1337** (the WARNING side-effect was also
filed as bug **#1342**, fixed by commit `70b7ff633`; #1337 tracks the parity
deliverable itself).

## The parity fix

`api/auth/index.php:269-272` (signup route):

```php
// External password management (SSO/LDAP): the sign-up page hides the
// password fields, so a locally set password is neither expected nor
// required. Skip the local password step rather than fail on E_PWDEMPTY.
$externalPwd = (bool) tlUser::isPasswordMgtExternal();
```

`tlUser::isPasswordMgtExternal()` (`lib/functions/tlUser.class.php:206-230`)
reads `$tlCfg->authentication[method]` + `domain[method]['allowPasswordManagement']`
— a key that IS defined — and returns true only when the configured auth method
does not manage passwords locally (e.g. LDAP, SSO). It never queries an
undefined config key, so no WARNING is emitted. The same function is already
used by the password-reset route (`api/auth/index.php:328`) and by legacy
`firstLogin.php:75`.

## Why this is full parity, everything still works

| Concern | Legacy `firstLogin.php` | Modern `api/auth` signup | Verdict |
|---|---|---|---|
| External-flag source | `tlUser::isPasswordMgtExternal()` (line 75) | `tlUser::isPasswordMgtExternal()` (line 272) | identical source |
| Pwd field visibility | tpl hides pwd fields when external (firstLogin.tpl:53-65) | `firstLogin.html:79-84` hides `#pwdFields` when `externalPasswordMgmt` (BFF `loginPageConfig`) | same UX |
| Server-side pwd expectation | `setPassword()` returns `S_PWDMGTEXTERNAL` (2, ≥ `tl::OK`) so no local pwd is stored | skips the local-password step entirely | identical end state (user created with no local password) |
| Audit event | `audit_users_self_signup` CREATE | same | same |
| Redirect | `login.php?note=first` | `login.html?note=first` | parity (modern path) |
| Spurious WARNING | none | none (after fix) | parity |

The `S_PWDMGTEXTERNAL` branch inside `setPassword()`/`encryptPassword()`
(`tlUser.class.php:544-569`) still handles any other external call sites exactly
as before; the signup route simply avoids invoking it needlessly.

## Verification (measured)

- `grep -rn "external_password_mgmt" --include="*.php" .` → only legacy Smarty
  assignments (`login.php`, `lostPassword.php`, `firstLogin.php`) and
  `lib/usermanagement/*.php` — no `config_get('external_password_mgmt')`
  call site remains.
- Live A/B on the running app (MariaDB `testlink`, fresh events table):
  - modern `POST /api/auth/signup` (Origin header) → HTTP 200
    `{"status":"ok","success":true}`; `events` gained only
    `audit_users_self_signup` (log_level=16, AUDIT) — zero `log_level=2`.
  - legacy `POST /firstLogin.php` → user created, same audit event — zero
    WARNING (parity).
- Browser (chrome-devtools): modern `firstLogin.html` renders, sign-up via UI →
  redirect to `login.html?note=first` ("First login detected. Welcome!"); the
  new DB user logs in successfully.
- `tlUser::isPasswordMgtExternal()` probe: `false` under default DB auth,
  `true` under LDAP — page flag (`loginPageConfig`) and BFF flag now agree.

## i18n

No new strings: the signup screen already ships `auth.*` keys in all 10 locale
bundles; this change is purely server-side parity.

## Files touched

| File | Change |
|---|---|
| `api/auth/index.php` | line 272: `config_get('external_password_mgmt', false)` → `tlUser::isPasswordMgtExternal()` (landed via `70b7ff633`, Refs #1337/#1342) |
| `docs/Task-Issue-1337-Auth-Signup-ExternalPwd-Parity.md` | this documentation (Refs #1337) |
| `CHANGELOG` | one-line feature-gap note (Refs #1337) |

## Related issue

- **#1554** (bug, filed during this verification): modern `login.html:228`
  success fallback `index.php?caller=login` is a RELATIVE url → resolves to
  `/gui/templates/auth/index.php` (404); legacy redirects to the root
  `index.php` via `basehref`. Out of #1337 scope.

---

_TestLink 2.0.1 · auth signup external-password-management parity · Refs #1337_