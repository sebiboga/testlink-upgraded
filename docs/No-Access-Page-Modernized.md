# No access to feature page — Modernized (TestLink 2.0.1, Refs #1902)

Legacy renderer `gui/templates/dashio/feedback/noaccesstofeature.tpl` — the last
standalone full-page feedback renderer in `feedback/` with no modern twin
(sibling of the #1895 Error page, the #1899 Info page and the #1901 Report-mail
confirmation).

## Why this screen

With the TODO ledger empty and every ASIDE entry already mapped to a modern
`.html` + BFF, the last remaining feedback renderer of the same class was
`noaccesstofeature.tpl`. In 1.9.20 the right-check failure path
`tlUser::checkGUISecurityClearance()` (`lib/functions/tlUser.class.php:1490`)
either rendered it or silently bounced the user home with no explanation. It
required **no session and no database** (the message came from the server
catalog string `$TLS_no_access_to_feature`), so an anonymous deep link (e.g. a
stale bookmark after logout) must still render.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/feedback/noAccess.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/noaccess/index.php` (`GET\|HEAD ?feature=<machine-id>[&script=<name>]`, **session-optional**, **DB-free**) |
| Wiring | `redirectToNoAccess()` + `$actions->noAccessPage` in `lib/functions/common.php`; `tlUser::checkGUISecurityClearance()` right-failure branch 302s here |
| i18n | `nacc.*` (11 keys) + `footers.noAccessPage` in **all 10** bundles |

## Parity

- **Message** — `nacc.message` carries the legacy `$TLS_no_access_to_feature`
  copy ("You do not have enough rights to access the feature"), translated in all
  10 bundles.
- **No rights gate** — like the legacy template, any visitor may open it; the
  BFF returns the session state (`auth.logged_in`) so the screen offers **Home**
  to a session holder and **Home + Go to Login** to an anonymous visitor.
- **Requested feature** — the optional `script` (the legacy controller name) is
  shown in a chip; hidden on a bare deep link.
- State cards for 400/403/405 with the BFF machine `code` shown; loading
  spinner, Retry, locale switcher, footer.

## BFF hardening

- `?feature[]=x` (array) and any value not matching `/^[a-z0-9_]{1,64}$/` →
  `400 INVALID_FEATURE` (the #1886 / #1893 defect class: never coerced to a sink).
- `script` is length-capped (128) and rendered client-side with `.text()`, so a
  hostile value can neither bloat the payload nor inject markup.
- JSON headers and method/feature validation are sent **before** any DB connect;
  the DB is connected lazily (guarded) only for the login fallback.
- `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, JSON-only.
- Non-GET/HEAD → `405 METHOD_NOT_ALLOWED`; foreign-origin POST → `403` via
  `bffSameOriginGuard()`.
- Stable machine `feature` returned alongside the localized `message_key`, so
  callers/tests assert the path without matching English text.

## Wiring

`tlUser::checkGUISecurityClearance()` — on a genuine missing right (not the
no-environment case, which keeps the legacy redirect home) — now calls
`redirectToNoAccess('no_right', $myContext['script'])`. The helper builds a
**path-only**, URL-encoded, same-origin `Location` (install root derived from
`TL_ABS_PATH` under `DOCUMENT_ROOT`, falling back to the session `basehref` path,
then the running script's directory), so the link is correct from any folder and
has no open-redirect surface. `$actions->noAccessPage` lets any caller build the
deep link without hardcoding the path (the page is non-menu, so there is no ASIDE
entry).

## Verified

- **API contract matrix** (7/7): anon `GET ?feature=no_right&script=tcEdit.php` →
  200 with the expected payload; bare GET → 200 `generic`; `?feature[]=x` → 400;
  `?feature=BadValue!!` → 400; same-origin POST → 405; foreign-origin POST → 403;
  headers correct.
- **Redirect helper** (2/2, served throwaway probe): 302
  `/gui/templates/feedback/noAccess.html?feature=no_right&script=tcEdit.php`;
  invalid feature falls back to `no_right` and the script is URL-encoded.
- **Browser** (5/5): anon card + Home + Go to Login; logged-in admin hides Go to
  Login; RO locale fully translated; bare deep link hides the feature chip; 0
  console errors.
- **i18n** (2/2): all 10 bundles valid JSON; `bash ai/verify_i18n_coverage.sh` →
  9 passed / 0 failed (7381 keys). `php -l` clean on the 3 touched PHP files.
- **Event Viewer**: no new Error/Warning.
- **Coverage suite**: `TLU_REQUIRE_SUITE="Issue #1902"` → 7 PASS / 0 FAIL,
  136 → 137 suites, none lost.

**Result: 18/18 PASS.**
