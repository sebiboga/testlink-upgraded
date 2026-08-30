# Self Sign-Up — Modernized Screen

Modernization of the legacy sign-up screen (`firstLogin.php`) — GitHub issue
[#782](https://github.com/sebiboga/testlink-upgraded/issues/782).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/auth/firstLogin.html`) backed by a plain-PHP REST BFF action in
`api/auth/index.php` (`POST /api/auth/signup`). The login page "New user? Create
account" link now points to the modern HTML screen.

**Path:** login page → *New user? Create account*
**URL:** `gui/templates/auth/firstLogin.html`
**BFF API:** `POST /api/auth/signup`
**Rights:** public (pre-authentication entry screen).
**Tracking issue:** [#782](https://github.com/sebiboga/testlink-upgraded/issues/782)

---

## Screen layout

The modern sign-up is a single-page Dashio-styled form:

| Section | Description |
|---------|-------------|
| **Card** | Centered white card over a background image |
| **Heading** | TestLink logo + "Sign Up" |
| **Fields** | User ID, First Name, Last Name, Email, Password, Repeat Password |
| **Action** | "Sign up" button (shows loading state, disabled during submit) |
| **Footer** | "Back to login" link + version + GitHub repo link |

When self-signup is disabled (`user_self_signup=false`) no form fields are shown —
a localized disabled banner is displayed instead and all inputs are disabled.

## Sign-up flow

1. The page loads `GET /api/auth/config` to decide whether self-signup is enabled
   and whether password management is external.
2. On submit the fields are validated client-side (required, password match) then
   posted to `POST /api/auth/signup`.
3. On success the BFF creates the user (shadow `tlUser::writeToDB`), audits
   `CREATE`, optionally notifies global admins, and the page redirects to
   `login.html?note=first` showing "First login detected. Welcome!".

## Validation & error states

| Case | Result |
|------|--------|
| Missing required fields | `auth.requiredFields` (localized) |
| Passwords do not match | `passwd_dont_match` (localized) |
| Weak password (if rules configured) | quality message returned by `checkPasswordQuality` |
| Duplicate login | "Login/User name is already in use" |
| Duplicate email | "This email address is already in use" |
| Self-signup disabled | `error_self_signup_disabled` (server) + disabled banner (client) |
| External password mgmt | password fields hidden; BFF skips local password set |

## Backend notes

- The BFF reuses `tlUser` helpers: `checkPasswordQuality`, `setPassword`,
  `writeToDB`, `checkDetails` — identical semantics to legacy `firstLogin.php`.
- External password management (SSO/LDAP) skips the local password step instead
  of failing with `E_PWDEMPTY` (bug #785).

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/auth/config` | self-signup enabled + external pwd mgmt flags |
| POST | `/api/auth/signup` | create a self-registered user |

`POST` routes are protected by the shared same-origin CSRF guard (`_guard.php`).

## i18n

All labels, placeholders, links and messages use client-side `TLi18n` keys under
the `auth.*` namespace, present in every locale bundle
(`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`).

---

_TestLink 2.0.1 · Self Sign-Up screen · Refs #782_
