# ASIDE Navigation Frame (asideMenu) — Modernized Screen

Modernization of the **left navigation frame** (`lib/general/asideMenu.php` +
`gui/templates/dashio/asideFrame.tpl` + `aside.tpl`) — GitHub issue
[#1548](https://github.com/sebiboga/testlink-upgraded/issues/1548).

With the titlebar (#1547) and every ASIDE menu item already modernized, the
aside frame was the **final legacy `lib/**.php` frame controller left in the
running frameset** (the `iframe#asidebar` every TestLink page renders between
the title bar and the main work area). It is replaced by a standalone Dashio
page (`gui/templates/aside/aside.html`) backed by a plain-PHP REST BFF
(`api/aside/index.php`). The legacy controller remains as a session-guarded
readfile shim so deep links to the old URL still render the modern menu (an
anonymous request keeps the legacy redirect to the login screen).

**Path:** rendered automatically as `iframe#asidebar` by `index.php`
**URL:** `gui/templates/aside/aside.html?locale=..&tproject_id=..&tplan_id=..`
**BFF API:** `api/aside/index.php` (action `init`, GET)
**Auth:** session (401 anonymous) + same-origin CSRF guard, mirroring every other `api/*` BFF.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Construction & parity notes](#3-construction--parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

The aside menu is TestLink's app-wide navigation rail. It lives in its own
frame so it survives navigation in the main frame, and every link targets the
`mainframe`. Exactly like the legacy `aside.tpl`, the modern screen renders (in
order): **Dashboard** (single entry), **Search**, **System**, **Projects**,
**Test Strategy** (unconditional), **Requirements Design**, **Test Case
Design**, **Test Plan**, **Test Case Execution**, **Reports**, **Plugins**,
**Documentation** (single entry).

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Section visibility | `getMenuVisibility($gui)` gates per project/plan context + grants | rebuilt server-side in the BFF through the same `initUserEnv()` call chain (a `showMenu` assoc array) |
| Per-item rights | `$menuGrants->xxx == "yes"` / `$gui->access->xxx == 'yes'` | identical gates (`getGrantSetWithExit` + `getAccess` via `initUserEnv`) |
| Item hrefs | `$gui->uri->*` placeholders pre-contexted by `initActions` | same `$gui->uri` object serialized into the JSON tree |
| Reports sub-menu | built in `asideMenu.php` from `cfg/reports.cfg.php` (`enabled=all|req|bts` + `format_html`) with modern href map | same logic ported verbatim into the BFF (incl. the `testPlanReport type=` map, editors keep the modern `.html` targets) |
| Plugins | `EVENT_LEFTMENU_TOP/BOTTOM` + `EVENT_RIGHTMENU_TOP/BOTTOM` merged into one Plugins section with `plugin_management` | `event_signal()` loop ported; merged into the same "Plugins" section |
| hasKeywords | keyword assignment item only when the project has keywords | `testproject::hasKeywords` server-side |
| Active link highlight | parent `main.tpl` runs `syncAsideActiveLink()` on every main-frame load marking `li.active` | keep the exact selector contract (`ul.sidebar-menu ul.sub li a[target="mainframe"]`) so the script keeps working unchanged |
| Last-clicked entry | `tl-sub-selected` + `localStorage('tlAsideSelected')` (issue #617) | replicated in the modern screen |
| Rail (icon rail) | `tlSetRail()/tlIsRailed()` globals on the aside frame; cookie `menuRailCookieName()`; frameset narrows the iframe | `window.tlSetRail/tlIsRailed` stay callable cross-frame (titlebar/legacy `common-scripts.js`), cookie read server-side by `menuRailIsOn()` for the frameset iframe class; client applies `body.rail` from the cookie before the BFF round-trip (no flash) |
| Accordion | `jquery.dcjqaccordion.2.7.js` on `#nav-accordion`, autoClose + saveState | same plugin/options |
| Long labels | ellipsis + `--menu-label-shift` hover slide | `.menu-label` span + native `title` attribute (same dashio CSS) |
| Reports list scroll | `#sidebar` scrolls when the list overflows (issue #612) | thin scrollbar CSS kept, scroll-to-top on open (issue #617) |

## 2. REST API Reference

`POST`/unknown-authorization paths respond 405/401; `action != init` responds 400.

### GET /api/aside/?action=init[&tproject_id=..][&tplan_id=..]

Commits the requested project/plan to the session (`initProject`, same contract
as `api/navbar`), then runs `initUserEnv()` and serializes the full menu tree.
All labels are localized server-side with `lang_get()` — the same
`strings.txt` sources the legacy Smarty `$labels` object used — so every one of
the app's server locales is honored out of the box.

```json
{
  "status": "ok",
  "tproject_id": 32,
  "tplan_id": 33,
  "railed": false,
  "rail_cookie": "TESTLINK1920_menuRail",
  "sections": [
    {
      "key": "system",
      "label": "System",
      "icon": "fa fa-desktop",
      "items": [
        { "id": "events",
          "label": "Event viewer",
          "href": "/gui/templates/eventviewer/eventviewer.html?tproject_id=32&tplan_id=33" },
        { "label": "User Profile", "href": "...userInfo.html?...", "icon": "fa fa-user-circle" }
      ]
    }
  ]
}
```

Item fields: `id` (legacy anchor id, kept for parity), `label` (localized),
`href` (modern screen, pre-contexted), `icon` (FontAwesome class, shown as the
legacy inline `<i>`). Single-entry sections (`dashboard`, `documentation`) are
flagged with `"single": true` and the client renders them as top-level `<li>`
(with the `mt` class for Dashboard).

## 3. Construction & parity notes

- **BFF session user**: `initUserEnv()` derives grants/sections from the
  `$_SESSION['currentUser']` tlUser object, not from a fresh lookup — the BFF
  seeds it after auth exactly like `api/reports`, `api/userinfo`,
  `api/reportsprint`.
- **showMenu is an array**: legacy Splickdown/Smarty consumed `$gui` in the
  template; the BFF normalizes array/object shapes via a small `$get` helper
  (the initial object-only implementation silently rendered only the two
  unconditional sections — fixed during dev).
- **`initUserEnv` self-corrects `tproject_id=0`** to the user's first
  accessible project, so a system-wide (no project) menu never persists —
  same behavior as the legacy frame (verified: a no-param call returns the
  same 12-section tree).
- **Frameset contracts preserved** (do not rename these without updating
  `main.tpl`): `#sidebar`, `ul.sidebar-menu#nav-accordion`, `li.sub-menu`,
  `ul.sub`, `a[target="mainframe"]`, `li.active`,
  `window.tlSetRail/tlIsRailed`, `li a.tl-sub-selected`.
- **Base href**: the legacy `asideFrame.tpl` set `<base href="{$basehref}">`,
  so relative hrefs in `aside.tpl` (`gui/templates/projectsView.html`,
  `projects/severityConfig.html`, `documentation/documentation.html`) resolved
  against the app root. The modern page keeps an equivalent `<base href="/">` —
  without it those links resolved against `/gui/templates/aside/`.
- **Accordion arrow asset**: `dashio/img/nav-expand.png` (used by the dcjq
  accordion) is not shipped with the repo, so the legacy frame 404'd on it. The
  modern screen draws the chevron with FontAwesome (`#sidebar .dcjq-icon`
  background override) — no 404, same arrow indicator.
- **Shim**: `lib/general/asideMenu.php` calls `doSessionStart()`; an
  authenticated request streams the modern HTML (`readfile`), an anonymous one
  falls through to the legacy controller which redirects to the login screen.
  The current `index.php` `asideframe` param points straight at the modern page
  with the session `locale` + committed project/plan forwarded.

## 4. i18n Keys

Menu labels come server-localized (19 server locales) — no client bundle work
needed for the items. The two client-chrome strings are in **all 10 bundles**:

| Key | en |
|---|---|
| `aside.loading` | "Loading navigation menu…" |
| `aside.retry` | "Retry" |

## 5. Security

- **Auth**: 401 JSON when the PHP session has no valid `userID`; anonymous
  `aside.html` renders a localized error + Retry instead of the menu.
- **CSRF**: `bffSameOriginGuard()` (GET short-circuits; state is read-only).
- **XSS**: every server-derived label is inserted with `textContent`; only
  plugin-supplied item HTML (`item.html`) is written as markup — the same
  trust level the legacy template afforded plugin strings.
- **Session muddle**: `initProject($_GET+$_POST, 'aside')` commits the
  requested context before the menu build, so a deep link into the frame alone
  resolves the right project/plan.

## 6. Testing

Suite 1548 in `tmp/TLU_Test_Cases.md`. Verified live in the browser
(admin, project NWALK id=32 / plan 33):

- 12 sections / 109 mainframe links rendered by the frameset; accordion
  autoClose + dcjq arrows; open section scrolls into view.
- `Event viewer` leaf → main-frame navigation, `li.active` (from
  `syncAsideActiveLink`) + `tl-sub-selected` + `localStorage.tlAsideSelected`.
- Rail toggle: `tlSetRail(true)`/`(false)` — body.rail + iframe class +
  `TESTLINK1920_menuRail` cookie; rail survives a full reload; clicking any
  section header while railed un-rails (parity).
- Shim: authed `lib/general/asideMenu.php` serves the modern screen; anonymous
  hits redirect to `login.php`; anonymous `aside.html` shows the 401 error
  state with Retry.
- Event Viewer clean after the final pass (three `E_WARNING` rows from
  dev-cycle BFF closure bugs have since been fixed). Console clean.