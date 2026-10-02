# Login screen (modernized 2.0.1) — SSO auto-login + `ssodisable` bypass

## Behaviour
- Auto-SSO: when `$tlCfg->authentication['SSO_enabled']` is `true`, the legacy `login.php?action=loginform` with an empty `note` automatically runs the configured SSO handshake (`WEBSERVER_VAR` via `$_SERVER[$SSO_uid_field]`, or `CLIENT_CERTIFICATE` when TLS is present) and redirects on success or falls back to the form with a note on failure.
- Bypass: `login.php?ssodisable=1` (or any presence of `ssodisable`) disables SSO for that login and shows the interactive form.
- Modern parity (`gui/templates/auth/login.html` + `api/auth/index.php`): the page reads `ssoEnabled`, `ssoMethod`, `ssoOnly` from `/api/auth/config`; if SSO is enabled, no `ssodisable` and no `note` are present, it POSTs to `/api/auth/sso` and, on soft failure/skipped, falls back to the form — matching legacy behavior. The `ssodisable` flag is propagated to the post-login redirect so the bypass is not lost on session expiry.

## BFF API
- `GET /api/auth/config` → includes `ssoEnabled`, `ssoMethod` (`CLIENT_CERTIFICATE`/`WEBSERVER_VAR`/`""`), `ssoOnly`.
- `POST /api/auth/sso` → runs the same handshake as legacy; returns `{status,success,skipped,destination|reason}`. Identity source: `$_SERVER[…]` (no body/header input).
- `POST /api/auth/login` → accepts `ssodisable` and includes `&ssodisable=1` in the returned `destination` when set.

## i18n
New keys added to all locale bundles: `auth.ssoInProgress`, `auth.ssoFailed`, `auth.ssoUnavailable`, `auth.ssoDisabled`, `auth.ssoSkipped`.

Refs: #1048
