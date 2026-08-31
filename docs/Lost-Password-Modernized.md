# Lost Password / Password Reset — Modernized Screen

Modernization of the legacy lost-password screen (`lostPassword.php`) — GitHub
issue [#783](https://github.com/sebiboga/testlink-upgraded/issues/783).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/auth/lostPassword.html`) backed by a plain-PHP REST BFF action in
`api/auth/index.php` (`POST /api/auth/reset`). The login page "Lost password?"
link now points to the modern HTML screen.

**Path:** login page → *Lost password?*
**URL:** `gui/templates/auth/lostPassword.html`
**BFF API:** `POST /api/auth/reset`
**Rights:** public (pre-authentication entry screen).
**Tracking issue:** [#783](https://github.com/sebiboga/testlink-upgraded/issues/783)

---

## Screen layout

The modern lost-password screen is a single-page Dashio-styled form:

| Section | Description |
|---------|-------------|
| **Card** | Centered white card over a background image |
| **Heading** | TestLink logo + "Lost Password" |
| **Hint** | "Enter your user ID and we will email you a new password." |
| **Field** | User ID (required) |
| **Action** | "Send" button (loading state, disabled during submit) |
| **Footer** | "Back to login" link + version + GitHub repo link |

## Reset flow

1. The user enters their login and clicks **Send**.
2. `POST /api/auth/reset` is called with the login.
3. The BFF returns a **generic success** body for every path (see Security
   below), the page shows the generic "If this user exists, a new password was
   sent..." note, then redirects to `login.html?note=lost`.
4. For a real user whose auth method manages passwords internally, a new password
   is generated and emailed to the registered address (SMTP via MailDev locally)
   and a `PWD_RESET` audit event is written.

## Security: enumeration-safe reset

The reset endpoint must not let an anonymous caller discover whether a login
exists, its authentication method, or its email state. The BFF therefore returns
the identical `{"status":"ok","success":true}` body for:
- an unknown login
- an existing user on external password management (reset skipped, audited)
- an existing user without a registered email
- a successful reset

Internal distinctions are only logged server-side. This fixes the original
user-enumeration oracles (`bad_user`, `password_mgmt_is_external`,
`mail_empty_address`) — bug #784.

## Validation & error states

| Case | Result |
|------|--------|
| Empty login | `auth.requiredResetUser` (client-side) |
| Mail transport misconfigured | handled without a 500; generic body (no user leak) |
| External password mgmt | reset skipped + audit, still generic success |

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/reset` | request a password reset (generic, enumeration-safe) |

`POST` routes are protected by the shared same-origin CSRF guard (`_guard.php`).

## i18n

All labels, placeholders, links and messages use client-side `TLi18n` keys under
the `auth.*` namespace, present in every locale bundle
(`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`).

## Regression suite

Executed as **Suite 783 — 10/10 PASS** (2026-08-31, headless Chrome + MariaDB
testlink @127.0.0.1). Coverage: default render + i18n, empty-submit validation,
unknown-login enumeration-safe generic success, existing local user with mail
unconfigured (no 500 — `resetPassword()` persists the new password only after
`email_send` succeeds, so nothing is written and no user's password is silently
invalidated), redirect to `login.html?note=lost` with the "Password recovery
completed." note, login-page link wiring (`firstLogin.html` / `lostPassword.html`),
BFF `GET /api/auth/config`, CSRF guard on `POST /api/auth/reset` (403 for
non-same-origin requests), i18n completeness in all 10 bundles, and Event
Viewer cleanliness (zero new Error/Warning `events` rows).

Screenshots are on the GitHub Wiki page (`Lost-Password-Modernized.md`).

---

_TestLink 2.0.1 · Lost Password screen · Refs #783_
