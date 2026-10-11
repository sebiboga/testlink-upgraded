# Generic Info / Notice page — Modernized (TestLink 2.0.1, Refs #1899)

Legacy renderer `gui/templates/dashio/workAreaSimple.tpl`, produced by three
standalone callers that had no modern twin (sibling of the #1895 Error page).

## Why this screen

With the TODO ledger empty and every ASIDE entry already mapped to a modern
`.html` + BFF, the last standalone full-page feedback renderer left was
`workAreaSimple.tpl`. It is emitted by:

- **Fatal database connection failure** — `doDBConnect($db, true)`
  (`lib/functions/common.php`), title `fatal_page_title` ("TestLink ::: Fatal
  Error") + the DB host/name/error text. Reached by *any* page load while the DB
  is unreachable (its `tLog` redirects there when `$onErrorExit` is set).
- **Report document error** — `displayInfo()`
  (`lib/functions/info.inc.php:29`), called from `lib/results/displayMgr.php`
  (three guards: unknown test plan, missing `tplan_id` on an API-key request,
  missing `tproject_id`), title `error_print_doc_title` + message
  `error_print_doc_missing_testplan`.
- **Self-registration disabled** — `firstLogin.php:23` (when
  `user_self_signup` is off), title `fatal_page_title` + message
  `error_self_signup_disabled` + a back-to-login link; reached anonymously
  *before* any DB connection.

All three require **no session gate** (the DB-fail page runs before a session env
exists), so an anonymous visitor must be able to open it.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/feedback/info.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/info/index.php` (`GET ?code=<machine-id>[&locale=xx]`, **session-optional**, **DB-free**) |
| Wiring | `redirectToInfoPage()` + `$actions->infoPage` in `lib/functions/common.php`; `displayInfo()` (`lib/functions/info.inc.php`), the `doDBConnect()` failure path and `firstLogin.php` redirect here |
| i18n | `info.*` (17 keys) + `footers.infoPage` in **all 10** bundles |

## Parity

- **Message mapping** — `db_connect_fail` → `info.dbFailTitle` /
  `info.dbFailMsg`; `missing_testplan` → `info.printDocErrorTitle` /
  `info.printDocMissingTestplan`; `signup_disabled` → `info.signupDisabledTitle`
  / `info.signupDisabledMsg`; anything else (or none) → `generic` /
  `info.genericTitle` / `info.genericMsg`.
- **Legacy strings preserved** — the titles and the `printDoc*` / `signup*`
  message values are copied verbatim from `locale/en_US/strings.txt`
  (`fatal_page_title`, `error_print_doc_title`,
  `error_print_doc_missing_testplan`, `error_self_signup_disabled`); the other
  bundles carry the server-catalog translations. The one deliberate exception is
  `info.dbFailMsg`, a new fixed string that replaces the legacy screen's raw
  DBMS error text (host/name/driver), which was an information-disclosure
  surface and is intentionally not reproduced.
- **No rights gate** — like the legacy page, any visitor may open it; the BFF
  returns `auth.logged_in` so the screen offers **Home** to a session holder and
  **Home + Go to Login** to an anonymous visitor.
- **Deep links** — `displayInfo()`, the DB-fail path and `firstLogin.php` all 302
  to `gui/templates/feedback/info.html?code=…`. The `Location` is **path-only** —
  the install root is derived from `TL_ABS_PATH` under `DOCUMENT_ROOT` (falling
  back to the session `basehref` path, then the running script's directory), so
  the link is correct from any folder and, being host-free, is same-origin by
  construction (no open-redirect surface).
- Loading spinner, Retry, locale switcher, footer; a fatal DB failure gets the
  amber warning glyph, everything else the teal info glyph.

## BFF hardening

- `?code[]=x` (array) and non-`[a-z0-9_]` `code` → `400 INVALID_CODE` (the
  #1886 / #1893 defect class: never coerced to a sink).- **No database access** — the page must answer during an outage; it never calls
  `doDBConnect()`/`new database()`.
- `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, JSON-only.
- Non-GET/HEAD → `405 METHOD_NOT_ALLOWED`; foreign-origin POST → `403` via
  `bffSameOriginGuard()`.
- Stable machine `info_code` returned alongside the localized keys, so
  callers/tests assert the path without matching English text.
- The legacy template rendered the caller `content` **un-escaped** and the DB
  failure embedded the raw DBMS error text; the modern page renders fixed
  localized messages, removing that injection surface.

## Verified

- **API** (25/25 PASS, see `tmp/TLU_Test_Cases.md` "Issue #1899"): mapping for
  `missing_testplan`/`db_connect_fail`/`signup_disabled`/generic, 400 on
  array/non-token code, 405 same-origin POST, 403 foreign/no-proof POST, HEAD,
  stale-tplan report 302, DB-down `login.php` 302, `firstLogin.php` 302 when
  self-signup is disabled, 0 Smarty renders of `workAreaSimple.tpl` left,
  `$actions->infoPage` present, 18 keys × 10 bundles, `ai/verify_i18n_coverage.sh`
  PASS.
- **Browser** (headless Chrome): `?code=missing_testplan` → "Document generation
  error" + legacy message; `?code=db_connect_fail` → amber glyph + "TestLink :::
  Fatal Error"; `?code=signup_disabled` → "TestLink ::: Fatal Error" + the
  self-registration-disabled message; `?locale=ro` fully localized ("TestLink -
  Notificare"); console clean (only the shared `api/userinfo` 401 anon locale
  probe, same as the Error page).
- **Event Viewer:** 0 new ERROR/WARNING.
- Suite `## Task — Issue #1899` appended to `tmp/TLU_Test_Cases.md`; suite gate
  `TLU_REQUIRE_SUITE="Issue #1899" bash ai/verify_test_suites.sh` PASS.

## Screenshots

- `docs/1899-info-missing-testplan.png` — report document error notice.
- `docs/1899-info-db-fail.png` — fatal DB connection failure notice.
- `docs/1899-info-signup-disabled.png` — self-registration-disabled notice.
- `docs/1899-info-ro.png` — Romanian locale.
