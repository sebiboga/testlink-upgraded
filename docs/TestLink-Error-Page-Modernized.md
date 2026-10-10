# TestLink Error page — Modernized (TestLink 2.0.1, Refs #1895)

Legacy controller `error.php` (the root-level general purpose error page) + Smarty
template `gui/templates/dashio/feedback/error.tpl`.

## Why this screen

With the TODO ledger empty and every ASIDE entry already mapped to a modern
`.html` + BFF, the largest remaining legacy gap was the only top-level page that
still rendered legacy Smarty HTML: `error.php`. It is reached from
`lib/functions/csrf.php` — `csrfguard_start()` redirects to `error.php?code=1`
(`No CSRFName found…`) or `error.php?code=2` (`Invalid CSRF token`) — and from
any stale `?code=` deep link. It required **no session** (it never called
`testlinkInitPage()`), so an anonymous visitor must still be able to open it.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/feedback/error.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/error/index.php` (`GET ?code=<int>[&locale=xx]`, **session-optional**) |
| Legacy | `error.php` → GET/HEAD-only 302 shim (the renderer is gone) |
| Wiring | `$actions->errorPage` in `lib/functions/common.php:1920`; both `lib/functions/csrf.php` redirects switched to the modern screen |
| i18n | `err.*` (12 keys) + `footers.errorPage` in **all 10** bundles |

## Parity

- **Message mapping** — code `1` → `csrf_name_missing` / `err.csrfMissing`
  ("No CSRFName found, probable invalid request."); code `2` →
  `csrf_token_invalid` / `err.csrfInvalid` ("Invalid CSRF token"); any other or
  missing code → `generic` / `err.generic` ("Rocket Raccoon is watching You",
  the legacy default, TICKET 4977).
- **No rights gate** — like the legacy page, any visitor may open it; the BFF
  returns the session state (`auth.logged_in`) so the screen offers **Home** to a
  session holder and **Home + Go to Login** to an anonymous visitor.
- **Deep links** — legacy `error.php?code=N` still resolves (302 to
  `gui/templates/feedback/error.html?code=N`).
- State cards for 400/403/405/500 with the BFF machine `code` shown; loading
  spinner, Retry, locale switcher, footer.

## BFF hardening

- `?code[]=x` (array) and non-numeric `code` → `400 INVALID_CODE` at the API and
  `code=0` in the shim (the #1886 / #1893 defect class: never coerced to a sink).
- `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, JSON-only.
- Non-GET/HEAD → `405 METHOD_NOT_ALLOWED`; foreign-origin POST → `403` via
  `bffSameOriginGuard()`.
- Stable machine `error_code` returned alongside the localized `message_key`, so
  callers/tests assert the path without matching English text.
- The message is localized client-side (i18n) — the legacy page was hardcoded
  English regardless of the user's locale.
- **DB connect fix:** `common.php` leaves `$db = 0` at file scope and connects
  lazily, so the BFF connects explicitly (`$db = new database(DB_TYPE);
  doDBConnect($db);`) before `tlUser::getByID()` — otherwise the call fatals
  `Call to a member function fetchFirstRow() on int` (HTTP 500). Same contract as
  `api/mainpage/index.php`.

## Verified

- **API** (`tmp/vy_1895.sh`, 21/21 PASS): shim redirects (code 2 / none / array /
  non-numeric), 405 POST, HEAD, BFF mapping for codes 1/2/99/none, 400 on
  array/non-numeric, 403 foreign-origin POST, 405 same-origin POST, anon
  `auth.logged_in=false`, `$actions->errorPage` present, both `csrf.php`
  redirects switched, no Smarty render left in `error.php`, 13 keys × 10 bundles,
  `ai/verify_i18n_coverage.sh` PASS.
- **Browser** (headless Chrome): logged-in `?code=1` → 302 → "No CSRFName found,
  probable invalid request." + switcher; RO fully localized ("Nu s-a găsit
  CSRFName, cerere probabil invalidă."); `?code=99` → "Rocket Raccoon is watching
  You"; anonymous isolated context shows "Go to Login"; console clean.
- **Event Viewer:** 0 new ERROR/WARNING (only the admin LOGIN audit row).
- Suite `## Issue #1895` appended to `tmp/TLU_Test_Cases.md`; suite gate
  `TLU_REQUIRE_SUITE="Issue #1895" bash ai/verify_test_suites.sh` PASS.

## Commits

- `cb91d7224` — BFF + `$db` connect fix.
- `109987894` — screen + i18n (10 bundles) + `csrf.php` wiring + `error.php` shim +
  `$actions->errorPage`.
