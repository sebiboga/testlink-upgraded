# Modernize-Event-Viewer-Deep-Link-eventviewer (#1579)

The legacy **Event Viewer** controller `lib/events/eventviewer.php` was the
**LAST controller in all of `lib/**` still rendering a full standalone legacy
page** (a grep for `templates/dashio` across `lib/` returns only
`eventviewer.php`, `common.php` and `tlsmarty.inc.php`). Its modern twin —
`gui/templates/eventviewer/eventviewer.html` + the `api/eventviewer` BFF
(Refs #872) — already exists and fully supports per-object
`objectId`/`objectType` filtering, so no new screen was needed. The remaining
gap was **wiring**: two live pointers still sent users to the legacy renderer.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry already maps to a modern `gui/templates/**/*.html` screen + BFF (a fresh
`api/aside/index.php?action=init` walk returns 118 entries with zero
`lib/**.php` hrefs). Per the `modernize.yml` guidance, the smallest coherent
standalone legacy screen still in `lib/**` was picked. `eventviewer.php` was the
only controller left that still emitted a full standalone HTML page (inline
HTML + Chart.js, `testlinkInitPage` with the `mgt_view_events` guard), and two
live references to it remained:

1. `gui/javascript/testlink_library.js` `showEventHistoryFor()` (line ~1425)
   built a hidden `<form>` and POSTed `object_id`/`object_type` to
   `lib/events/eventviewer.php`.
2. `cfg/const.inc.php` — the legacy top-menu entry `guiTopMenu[7]`
   (`title_events`, shortcut `v`) targeted `lib/events/eventviewer.php`.

## Deliverables

- **Shim:** `lib/events/eventviewer.php` is now a session-guarded **302 redirect
  shim** following the established pattern (`bugAdd.php` #1560, `mainPage.php`
  #1555, `eventinfo.php` #1556): anonymous users are sent to the login screen
  (legacy `testlinkInitPage` contract — in practice the standard
  `login.php?note=expired&destination=...` redirect), authenticated users land
  on `gui/templates/eventviewer/eventviewer.html` with their
  `object_id` / `object_type` / `tproject_id` / `tplan_id` context forwarded.
  The rights gates (`mgt_view_events`, `events_mgt`) live in the
  `api/eventviewer` BFF exactly like the legacy `eventviewer.legacy.php` checks.
- **JS switch:** `showEventHistoryFor(objectID, objectType)` now opens the
  modern viewer directly via `window.open(..., "_blank")` with
  `object_id` / `object_type` query params — no hidden form, no legacy POST.
  (The modern screens — usersView, rolesView, userInfo, planMilestones,
  reqEdit — already had their own `showEventHistory()` wiring to
  `eventviewer.html`.)
- **Top menu:** `cfg/const.inc.php` `guiTopMenu[7]` url now targets the modern
  `eventviewer.html`.

No new i18n keys were required (the modern screen is fully localized — all
`ev.*` / `header.eventViewer*` / `footers.eventviewer` keys exist in all 10
bundles, validated with `python3 -m json.tool`).

## Verification (browser, live on the session fixture)

| Scenario | Result |
|---|---|
| Legacy deep link `?object_id=1&object_type=testcases` | 302 → `eventviewer.html?tproject_id=3&tplan_id=4&object_id=1&object_type=testcases`; object filter group visible, "Filtered by testcases #1", empty-state "No events found." |
| Same shim, `?object_id=3&object_type=testprojects` | real AUDIT row "Test Project 'Walk Probe' was created" rendered |
| Unfiltered modern view | 16 events listed, object filter hidden |
| Locale switch en → ro | header `Vizualizare Evenimente` |
| Anonymous (isolated context) | `login.php?note=expired&destination=%2Flib%2Fevents%2Feventviewer.php...` — legacy contract preserved |
| Event Viewer hygiene | 0 ERROR/WARNING (see below) |
| Syntax | `php -l` and `node --check` clean |

## Bug found + fixed this run (fixture hygiene)

The session's ASIDE-walk fixture `tmp/fixtures_aside_walk.php` created its test
project without setting `$item->color` / `$item->notes`, firing
`E_WARNING Undefined property: stdClass::$color / $notes` in
`testproject.class.php:131/133` (6 stale WARNING rows in the Event Viewer). Same
root cause all sibling fixtures avoid by setting both fields explicitly. Fixed
at source (`$item->color=''`; `$item->notes=''`, aligned with fixtures_1309 etc.)
and the 6 stale rows were scrubbed per the #1548 close-out precedent.

## Test suite

Suite **1579 — Event Viewer legacy deep link → modern screen** appended to
`tmp/TLU_Test_Cases.md`: **8/8 PASS** (BFF up; 302 with context; object filter
empty + populated; unfiltered view + RO locale; anonymous → login; JS wiring
switched; syntax checks; Event Viewer hygiene + fixture fix).

## Files

- `lib/events/eventviewer.php` — legacy controller → session-guarded 302 shim
- `gui/javascript/testlink_library.js` — `showEventHistoryFor()` → modern viewer
- `cfg/const.inc.php` — `guiTopMenu[7]` events entry → modern screen
- `docs/screenshots/issue-1579-shim-filter.png` / `issue-1579-shim-filtered.png`
- `CHANGELOG` + `docs/MODERNIZATION-STATUS.md` (DONE row, Summary 80 + 61
  extras) updated

Refs #1579. Commit `f522d80e3` (shim + JS switch + top-menu wiring) pushed to
`sebiboga`.