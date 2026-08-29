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

- **New user? Create account** → `firstLogin.php?viewer=new` (sign-up form).
- **Lost password?** → `lostPassword.php?viewer=new` (reset form). The reset templates were
  added to the Dashio theme to fix a 500 (see #776).

These links only render when the server reports that self-signup and password management are
allowed by the configured authentication method.

---

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

_TestLink 2.0.1 · Login/Logout screen · Refs #775_
