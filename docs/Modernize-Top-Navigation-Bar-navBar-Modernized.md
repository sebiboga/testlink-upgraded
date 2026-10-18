# Top Navigation Bar (titlebar) navBar — Modernized Screen

Modernization of the **top navigation bar / titlebar** frame
(`lib/general/navBar.php` + `gui/templates/dashio/navBar.tpl`) — GitHub issue
[#1547](https://github.com/sebiboga/testlink-upgraded/issues/1547).

With every ASIDE menu item already modernized, the titlebar was the **last
legacy `lib/**.php` screen still served in the running frameset** (the
`iframe#titlebar` every page renders above the aside menu and main area). It is
replaced by a standalone Dashio page
(`gui/templates/navbar/navBar.html`) backed by a plain-PHP REST BFF
(`api/navbar/index.php`). The legacy controller remains as a session-guarded
readfile shim so deep links to the old URL still render the modern bar (an
anonymous request keeps the legacy redirect to the login screen).

**Path:** rendered automatically as `iframe#titlebar` by `index.php`, and by any
deep link to `lib/general/navBar.php` while authenticated
**URL:** `gui/templates/navbar/navBar.html?locale=..&tproject_id=..&tplan_id=..`
**BFF API:** `api/navbar/index.php` (action `init`, GET)
**Auth:** session (401 anonymous) + same-origin CSRF guard, mirroring every other `api/*` BFF.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy defects fixed](#3-legacy-defects-fixed)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)
7. [Bugfix — unassociated labels (Issue #1938)](#7-bugfix--unassociated-labels-issue-1938)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Logo / reload main view | `index.php` reload | Logo → `/index.php?tproject_id=..&tplan_id=..` (absolute, frameset reload); `navb.reloadMain` title |
| Test Project selector | combo posting `index.php?action=projectChange` (target `_top`) | same contract (`switchTestProject()` reads the main-frame `feature=` and forwards it in `returnFeature` so the frameset lands back in the same work area); project options = `map_name_with_inactive_mark` + `gui.tprojects_combo_format/order_by` |
| Test Plan selector | combo posting `index.php?action=planChange` | same; options from `getAccessibleTestPlans`, session-selection parity via `setSessionTestPlan` (null-stored guard: nothing auto-selected) |
| Whoami | name + role, opens user info | name + effective role (project role else global role), opens `gui/templates/usermanagement/userInfo.html` in the mainframe; title `name - role` |
| Logout | `logout.php?viewer=` | absolute `/logout.php?viewer=` (+ `ssodisable`), target `_top` |
| Locale switcher | none (server locale) | client-side `TLi18n` switcher (all 10 bundles); reloads the titlebar with `?locale=` |
| Project resolution | param → session → cookie (`testProjectMemory.userID`) → first accessible | identical precedence; graceful degrade (combos hidden) instead of the legacy exception when the user has no accessible project (`$_SESSION['testprojectTopMenu']=''` parity) |
| Plugins | `EVENT_TITLE_BAR` signal computed | same signal, wrapped into the payload (array coalesced to string) |
| Test case prefix | `prefix + glue` when a project is in context | same (`tcase_prefix`) — kept for parity |

## 2. REST API Reference

`POST`/unknown-authorization paths respond 405/401; `action != init` responds 400.

### GET /api/navbar/?action=init

Resolves and returns the full titlebar state:

```json
{
  "status": "ok",
  "new_installation": false,
  "tproject_id": 1,
  "tproject_name": "MWK:Modern Walk",
  "projects": { "1": "MWK:Modern Walk", "3": "SWK:Second Walk" },
  "testplans": [ { "id": 2, "name": "Walk Plan", "selected": 1 } ],
  "tplan_id": 2,
  "whoami": { "name": "Testlink Administrator", "role": "admin" },
  "grants": { "view_testcase_spec": true },
  "ssodisable": false,
  "logout_url": "/logout.php?viewer=",
  "user_info_url": "/gui/templates/usermanagement/userInfo.html?tproject_id=1&tplan_id=2",
  "plugins": {},
  "tcase_prefix": "MWK:",
  "update_main_page": 0
}
```

Client gets the frame url `?tproject_id=`/`?tplan_id=`/`?locale=` and forwards the
first two into the BFF query so deep-linked frames stay consistent with
`index.php`'s committed project/plan.

## 3. Legacy defects fixed

- **Relative form targets** — legacy navBar forms posted `index.php?action=…`
  relative to the frame URL (e.g. `/gui/templates/navbar/`), which resolves
  nowhere; the modern forms (and the whoami/logout/logo links) use absolute
  `/index.php?...`, `/logout.php`, `/gui/templates/...` URLs.
- **`EVENT_TITLE_BAR` array** — the plugin signal returns an array; coalesced to
  a string for the JSON payload.
- **Broken plan selection on null stored plan** — legacy only selects/adjusts a
  test plan when the session still holds a plan id; the BFF mirrors that guard
  instead of auto-committing the first plan.

## 4. i18n Keys

`navb.testProject`, `navb.testPlan`, `navb.logout`, `navb.reloadMain`,
`navb.toggleNav`, `footers.navBar` — added to all 10 locale bundles
(`gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json`, validated with
`python3 -m json.tool`).

## 5. Security

- GET-only BFF, session auth (401 anonymous), `bffSameOriginGuard()` (403
  without same-origin proof).
- All server-provided strings are rendered through JS `text()`/`attr()` (plugin
  HTML is trusted server output, same trust model as the legacy Smarty render).
- The `switchTestProject()` feature round-trip is validated against a fixed
  allowlist server-side (`getReturnWorkArea()`), never echoed raw.

## 6. Testing

Regression suite **NAVBAR (1547)** in `tmp/TLU_Test_Cases.md` (9 steps, all
PASS): titleframe wiring, BFF contract (200/401/405/400), legacy shim, project
switch 1→3→1 with `feature=reqSpecMgmt` round-trip and combo repopulation, plan
change frameset rebuild, whoami→userInfo, logout→login→re-login, locale
de/en, Event Viewer clean (AUDIT-only).

## 7. Bugfix — unassociated labels (Issue #1938)

Chrome DevTools raised **`No label associated with a form field (count: 2)`**
on every screen of the shell (Refs #1938, bug reported while testing the
REST API Reference screen). The Issues rule (`genericFormLabelHasNeitherForNorNestedInput`)
fires once per `<label>` that has neither a `for` attribute nor a wrapped form
field — and the titlebar renders on every page.

**Root cause.** The Dashio port of the titlebar (commit `22c574cd2`, Refs #1547)
kept the legacy *visual* labels but never wired them:
`navBar.html:47` `<label id="lblProject">` had no `for`, its control
`<select id="projectSelect">` sits at line 48 in the same `projectForm`;
`navBar.html:58` `<label id="lblPlan">` vs `<select id="planSelect">` at
line 60 in `planForm`. Without the `for`↔`id` pairing (and without wrapping),
`HTMLLabelElement.control` is `null` and `select.labels` is empty, so both
context pickers had **no accessible name**. Runtime localization only rewrites
the label *text* (`render()` → `$('#lblProject').text(TLi18n.t('navb.testProject'))`),
so the defect survived every locale. The legacy `navBar.tpl` contained no
`<label>` elements at all (plain `{$labels.testproject}` text), so this was a
regression introduced by the modernization, not carried over from 1.9.20.

**Fix (minimal — 2 attributes).** `for="projectSelect"` on `lblProject` and
`for="planSelect"` on `lblPlan`; both target ids that already exist and are
unique in the frame document. The labels already carry the localized text, so
**no i18n keys were added**. Alternatives rejected: `aria-label` would duplicate
the translatable text into a second attribute that can drift from the `navb.*`
bundles; wrapping the select inside the label would restructure both forms and
risk the POST contract; `aria-labelledby` adds indirection with no gain over the
native association. The form contract (`name="testproject"` / `name="tplan_id"`,
`action=projectChange` / `planChange`, `switchTestProject()`) is untouched.

**Verified** (headless Chrome, chrome-devtools MCP, fixtures project 1 + plans
2/4 and project 3): Issues log on `index.php` empty (pre-fix entry
`msgid=3 … (count: 2)` gone), 0 console issue/error/warn messages;
`label.control === select` and `select.labels.length = 1` for both pickers;
plan switch (→ `tplan_id=4` committed), project switch (→ `tproject_id=3`,
plan combo hidden for the plan-less project, legacy parity) and locale switch
en→ro ("Proiect de testare" / "Plan de testare", association intact) → en all
PASS; Event Viewer clean (audit-only). Regression suite **Regression — Issue
#1938** 8/8 PASS. Code review: APPROVE, no findings.

**Files:** `gui/templates/navbar/navBar.html` (2 attributes), CHANGELOG entry,
`docs/Modernize-Top-Navigation-Bar-navBar-Modernized.md` mirror.
Follow-up (same class, other screen): **#1939** — `planView.html` raises the
same issue (count: 4) plus a field-without-id/name — filed separately, not
fixed here.