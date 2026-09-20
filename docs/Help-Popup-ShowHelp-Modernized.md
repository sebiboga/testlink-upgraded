# Help popup — showHelp (#1552)

> Mirror of the GitHub Wiki page `Modernize-Help-Popup-showHelp.md` (2026-09-20).

The last standalone Help popup — `lib/general/show_help.php` — is modernized
as a standalone Dashio screen backed by a REST BFF. The legacy controller was
**broken dead code** on the upgraded branch (it referenced the undefined
constant `TL_HELP_RPATH` and rendered `gui/help/<locale>/*.html` files that had
been deleted — their content was migrated into the locale `$TLS_htmltext /
$TLS_htmltext_title` arrays in `locale/<loc>/texts.php`). Modernizing the
screen **restored the functionality**.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry was already verified to map to a modern `gui/templates/**/*.html` screen
+ BFF. Per the `modernize.yml` guidance ("if the TODO section is empty, say so
in one line, then pick the smallest coherent one"), the Help popup was chosen
— the last standalone `lib/general/*` screen with no dedicated modern screen.
`api/staticpage` (#1501) serves the same `$TLS_htmltext` source for the 16
context-help keys from the Work Area; the modern Help popup exposes them all
from a standalone popup plus keeps the legacy `show_help.php` redirect shim.

## Deliverables

- **Screen:** `gui/templates/help/showHelp.html` — Dashio popup with teal
  header, 16-topic sidebar (populated from the BFF index on every load, so a
  direct-key open still fills the list), content card rendering the legacy
  help HTML body, active-topic highlight, TLi18n locale switcher (survives
  `?help=` on reload), Refresh/Close tool buttons, a `notFound` warn bar for
  unknown keys ("Help topic not available in the selected language") with the
  legacy "ask administrator to update localization file" body (HTTP 200,
  legacy parity), and a localized footer.
- **BFF:** `api/help/index.php` — session auth + `bffSameOriginGuard`:
  - `GET ?action=index` — 16 help topic items `{key, title, hasContent}` from
    the locale `$TLS_htmltext`/`$TLS_htmltext_title` texts.php bundles.
  - `GET ?action=show&help=<key>[&locale=xx]` — `{key, title, content_html,
    notFound, locale}`; unknown keys return the localized legacy missing-key
    message with `notFound:true`.
  - JSON contract 401 (anon) / 400 (invalid key or action params) / 405
    (unknown action) / 500 (no bundle at all).
  - **Security (code-review #1552):** the session/default locale is validated
    `^[a-z]{2}_[A-Z]{2}$` + `is_file(locale/<loc>/texts.php)` before `include`
    — closes the legacy @TODO directory-traversal hole; BOM-emitting
    `texts.php` bundles (`fr_FR`, `es_AR`) are `include`d under
    `ob_start()/ob_end_clean()` so the JSON response stays valid; the 2-letter
    `?locale=` hint is whitelist-mapped (en→en_GB, ro→ro_RO, …) and honoured
    only against a shipped texts.php. A locale without a `texts.php` (e.g.
    `ro_RO`) silently falls back to `en_GB` and reports the real bundle locale.
- **i18n:** `hlp.*` (7 keys: title/headerSub/topics/chooseTopic/loadError/
  notFound/noTopics) + `footers.showHelp` + `common.close` in all 10 bundles
  (en/ro/de/es/fr/it/ja/pt/ru/zh; `en_GB`'s sibling conventions kept).
- **Link switch:** `open_help_window()` in `gui/javascript/testlink_library.js`
  now opens `gui/templates/help/showHelp.html?help=<page>&locale=<locale>`
  (both values `encodeURIComponent`ed, popup widened 400px→780px).
- **Shim:** `lib/general/show_help.php` kept as a session-guarded 302 onto the
  modern screen (URL built on `$_SESSION['basehref']`, preserving the legacy
  link contract).

## Verification

- Browser (admin session): direct key open (`?help=editTc`) with sidebar
  populated + active highlight; topic click swap; locale switcher EN↔FR (French
  bundle starts with a UTF-8 BOM — response stays valid JSON); unknown key →
  warn bar + legacy body; legacy `show_help.php` 302→ modern screen. Console
  clean (0 error/warn).
- BFF curl contract: 401 anon, 405 unknown action, 400 invalid/traversal key,
  200+`notFound:true` unknown key, 16-item index, locale fallback reporting.
- Event Viewer clean after the run (only the 2 GUI-LOGIN AUDIT rows).

## Test cases

Suite **1552 (Screen — Help popup)** in `tmp/TLU_Test_Cases.md`.

Screenshots: `docs/screenshots/help-showHelp.png`,
`docs/screenshots/help-showHelp-fr.png`.