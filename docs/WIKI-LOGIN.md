# TestLink Login / Logout — Wiki

The Login screen is a redeveloped, modern screen for authenticating users into TestLink.
It replaces the legacy login page with a clean HTML+JavaScript interface backed by its own
BFF (Backend-for-Frontend) API for authentication.

**Path:** entry point `login.php` (redirects/renders the modern page)
**URL:** `gui/templates/auth/login.html` (served by `login.php`)
**BFF:** `api/auth/index.php`
**Prerequisite:** none — this is the pre-authentication entry screen.

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Sign In](#2-sign-in)
3. [Authentication feedback](#3-authentication-feedback)
4. [Self-registration & lost password](#4-self-registration--lost-password)
5. [OAuth](#5-oauth)
6. [Logout](#6-logout)
7. [API Endpoints](#7-api-endpoints)
8. [i18n](#8-i18n)

---

## 1. Screen Layout

The modern login is a single-page Dashio-styled form:

| Section | Description |
|---------|-------------|
| **Card** | Centered white card over a background image |
| **Heading** | TestLink logo + "TestLink Sign In" |
| **Fields** | User ID + Password (localized placeholders) |
| **Action** | "SIGN IN" button |
| **OAuth row** | Optional provider buttons (shown only when configured) |
| **Demo banner** | Teal "this is a DEMO site" notice, only when `demoMode` is ON (#1047) |
| **Footer** | "New user?" / "Lost password?" links + "Secure login" note |

No iframes, no Smarty rendering for login — `login.php` serves the static page, which loads
its own public config and authenticates through the BFF.

---

## 2. Sign In

Enter your User ID and Password and click **SIGN IN**. The page submits to the BFF
`POST /api/auth/login` over AJAX. On success the BFF creates the server-side session
(reusing the legacy `doAuthorize()` engine) and redirects the browser to the TestLink main
page (`index.php?caller=login`) or to a requested destination. The password is never
embedded in the final URL.

---

## 3. Authentication feedback

The login page surfaces both pre-login notes (from the `note` query parameter) and
live authentication errors:

| State | Behavior |
|-------|----------|
| `note=logout` | Blue info banner "You have been logged out." |
| `note=expired` | Blue info banner "Session expired. Please log in again." |
| `note=first` | Blue info banner "First login detected. Welcome!" |
| `note=lost` | Blue info banner "Password recovery completed." |
| Wrong credentials | Red error "Invalid login or password."; password field cleared |
| Empty fields | "Please enter both user ID and password." |

---

## 4. Self-registration & lost password

- **New user? Create account** → `/gui/templates/auth/firstLogin.html` (modern sign-up form,
  `POST /api/auth/signup`).
- **Lost password?** → `/gui/templates/auth/lostPassword.html` (modern reset form,
  `POST /api/auth/reset`).

The links carry **absolute** hrefs (`/gui/templates/auth/firstLogin.html` and
`/gui/templates/auth/lostPassword.html`) because the login page is served two ways: directly at
`/gui/templates/auth/login.html` and `readfile()`-ed at `/login.php`. A relative href would
resolve against `/` in the latter context and 404 (`/firstLogin.html`). Fixed in #1338.

Each link is gated by its own flag, as in the legacy template
`login-model-marcobiedermann.tpl` (the one `config.inc.php` selects):

- **New user? Create account** renders only when `user_self_signup` is enabled
  (`$tlCfg->user_self_signup = TRUE;`); the flag arrives as `config.selfSignup` from
  `GET /api/auth/config`. With self-registration off the link is not advertised at all, instead of
  leading to the "self-registration is disabled on this site" refusal screen — see
  [Task #1050](Task-Issue-1050-Login-Signup-Link-SelfSignup-Gate.md).
- **Lost password?** is independent of self-signup and is hidden only when the authentication
  method manages passwords externally or demo mode is on (same gate as legacy
  `external_password_mgmt` / `demoMode`).
- The `|` separator only appears when both links are visible, and the whole footer row stays
  hidden when neither is.

---

## Demo mode notice

When the site runs in demo mode (`$tlCfg->demoMode = ON;` in `custom_config.inc.php`),
the login card shows a teal **"This is a DEMO site"** banner above the form, ported from
the legacy `login-model-marcobiedermann.tpl` block that rendered `{$labels.demo_usage}`:

```
{if $tlCfg->demoMode}
  <div class="grid__container">{$labels.demo_usage}</div>
{/if}
```

![Login screen with the demo-mode notice](issue-1047-demo-notice.png)

| Item | Value |
|------|-------|
| Trigger | `GET /api/auth/config` → `"demoMode": true` (`api/auth/index.php`, `config_get('demoMode')`) |
| Element | `#demoUsageBox` in `gui/templates/auth/login.html`, class `alert-box alert-demo` |
| Position | After the error/info notes, before the login fields (legacy order: note → demo banner → form) |
| Content | 4 lines — DEMO/RESPECT warning, re-install notice, data-deletion notice, "support our work" |
| Styling | Teal `#e2f7f5` with a 4 px `#4ECDC4` left border; the 4th line is bold |
| i18n key | `auth.demoUsage`, translated in **all 10** bundles, injected with `data-i18n-html` so the legacy `<br>` / `<b>` markup survives |

The banner is independent from the error/info boxes: a redirect note and the demo banner
are both visible at the same time, as in legacy.

![Demo notice together with the "Session expired" note](issue-1047-demo-notice-with-note.png)

Demo mode also hides the **Lost password?** link (same `demoMode` flag, legacy parity) —
so a demo instance shows the warning banner and no password-recovery path at all.
Implemented in
[#1047](https://github.com/sebiboga/testlink-upgraded/issues/1047).

## 5. OAuth

When OAuth servers are configured and enabled, the login card shows a row of provider
buttons (e.g. Google). Clicking a provider redirects to that provider's authorization flow.
The BFF `GET /api/auth/config` returns the enabled OAuth provider list.

---

## 6. Logout

The navigation bar "Logout" link calls `logout.php`, which audits and destroys the session
and redirects to `login.php?note=logout`, which renders the modern login with the
"You have been logged out." banner. The BFF also exposes `POST /api/auth/logout` for
programmatic logout.

---

## 7. API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/auth/config` | Public login-form config (self-signup, OAuth, pwd max len, sso flags) |
| GET | `/api/auth/check` | Session validity + current login |
| POST | `/api/auth/login` | Authenticate (form-encoded or JSON), creates session on success |
| POST | `/api/auth/logout` | Destroy the session |

All `POST` routes are protected by the shared same-origin CSRF guard (`_guard.php`).

---

## 8. i18n

All labels, placeholders, links and messages use client-side `TLi18n` keys under the
`auth.*` namespace, present in every locale bundle (`gui/templates/i18n/en.json`,
`ro.json`, `de.json`, `es.json`, `fr.json`, `it.json`, `pt.json`, `ru.json`, `ja.json`,
`zh.json`).

---

_TestLink 2.0.1 · Login/Logout screen · Refs #775, #1047 (demo-mode notice)_
