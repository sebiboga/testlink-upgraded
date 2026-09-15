# Help & Instructions (staticPage) — Modernized Screen

Modernization of **Help / Instructions viewer** (`lib/general/staticPage.php`)
— GitHub issue [#1501](https://github.com/sebiboga/testlink-upgraded/issues/1501).

This was the last standalone `lib/general/*` screen not yet ported: it renders
the contextual `$TLS_htmltext` help topics from `locale/*/texts.php`, keyed by
the `show_instructions()` / frmWorkArea `staticPage` entry points (e.g.
`?key=planAddTC`). The legacy Smarty screen is replaced by a standalone Dashio
page (`gui/templates/documentation/staticPage.html`) backed by a plain-PHP REST
BFF (`api/staticpage/index.php`, session-based auth).

**Path:** any help entry point (`show_instructions()` in
`lib/functions/common.php` + the `frmWorkArea.php` staticPage fallback both
redirect to the modern screen)
**URL:** `gui/templates/documentation/staticPage.html?key=<help_key>[&refreshTree=0|1][&locale=xx]`
**BFF API:** `api/staticpage/index.php`
**Right:** any authenticated session (legacy required none beyond login)

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Help topic lookup | `$_REQUEST['key']` read directly, included from `locale/<locale>/texts.php` | BFF `GET ?action=show&key=…` resolves the key against the current locale bundle |
| Title / body | `$TLS_htmltext_title[$key]` + `$TLS_htmltext[$key]` rendered raw | same arrays, rendered in the Dashio content card |
| Unknown key | greys out the pane + prints a "missing key / ask administrator" block with the raw key | warnbar "Help topic not available…" + same legacy body text (title shows the raw key) |
| Locale resolution | server session locale only; no UI switching (legacy page could not be localized on the fly) | session locale + optional `?locale=xx` client hint (from the TLi18n switcher) → localized `texts.php` body when the locale ships one |
| `refreshTree` param | forwarded through to the calling context (tree refresh after help) | echoed back in the BFF response for the same downstream contract |
| Help / Back / refresh | legacy pane reloading | **Refresh** button re-fetches; **Back** button = `history.back()` |
| Footer | legacy pane chrome | `footers.staticPage` i18n line with title + key + generated time |

## 2. REST API Reference

All routes require an authenticated session (401 when anonymous).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=show&key=<key>[&refreshTree=0\|1][&locale=xx]` | help title + body for a key in the resolved locale | 400 invalid/missing key, 401 anonymous, 405 other actions |
| `GET ?action=show&key=…` with unknown key | `{status:'ok', notFound:true, title:'', content_html:<legacy "ask administrator" block>}` | — |

The `16` shipped keys (en_GB `texts.php`): `error, assignReqs, editTc,
searchTc, searchReq, searchReqSpec, printTestSpec, reqSpecMgmt, printReqSpec,
keywordsAssign, executeTest, showMetrics, planAddTC, tc_exec_assignment,
planUpdateTC, test_urgency`.

## 3. Legacy parity notes

- Legacy resolved the locale as `$_SESSION['locale']`; the BFF keeps that, then
  falls back to `config.inc.php` `default_language`, then `en_GB`.
- `ro_RO` ships NO `texts.php` — both legacy and BFF serve the `en_GB` English
  body for Romanian sessions (help UI chrome is still fully localized).
- The client-locale hint (`?locale=de`) maps via the same region preference as
  the i18n.js switcher (`es_ES`, `pt_PT`, `de_DE`, …) and is only honoured when
  that locale actually ships a `texts.php` file.
- Unknown keys reproduce the legacy block verbatim ("Please, ask administrator
  to update localization file …") instead of hard-failing, and the page title
  falls back to the raw key (legacy left the title empty there).

## 4. i18n Keys

All labels/messages use the client-side `TLi18n` module; keys `stp.title`,
`stp.headerSub`, `stp.helpKey`, `stp.loadError`, `stp.missingKey`,
`stp.notFound`, `common.back`, `footers.staticPage` are defined in ALL locale
bundles (`gui/templates/i18n/*.json`: de, en, es, fr, it, ja, pt, ro, ru, zh).

## 5. Security

- Session-based auth on the only route (401 when anonymous) — enforced before
  any locale/file access.
- `key` is validated against `^[a-zA-Z0-9_\-]+$`; anything else gets 400 — no
  path traversal into `locale/` is possible.
- Locale hint restricted to `^[a-z]{2}$` then mapped through a fixed allow-list
  to a real TL locale dir by `texts.php` existence check.
- No HTML built from unescaped server strings client-side (jQuery text() + esc
  for the key badge).

## 6. Testing

Suite `#1501` in `tmp/TLU_Test_Cases.md`: 13/13 PASS — covers valid key,
unknown key, invalid-key 400, anonymous 401, German + Romanian locale
resolution, Refresh / Back buttons, `refreshTree=1`, unimplemented action 405,
console hygiene and Event Viewer check (0 ERROR/WARNING rows).