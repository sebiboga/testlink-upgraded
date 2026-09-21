# Modernize-Event-Info-Popup-eventinfo (#1556)

The standalone **Event Info popup** — `lib/events/eventinfo.php` (+
`gui/templates/dashio/events/eventinfo.tpl`) — is modernized as a standalone
Dashio screen backed by a REST BFF. It renders a single log record and is opened
from the Event Viewer row detail (the new **Open in window** link) or from the
legacy `?id=` deep link.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry was already verified to map to a modern `gui/templates/**/*.html` screen +
BFF (a fresh `api/aside/index.php?action=init` walk returns zero `lib/**.php`
hrefs). Per the `modernize.yml` guidance ("if the TODO section is empty, say so
in one line, then pick the smallest coherent one"), the Event Info popup was
chosen — the last standalone `lib/events/*` controller with no dedicated modern
screen. The modern Event Viewer (#872) inlines a detail row but exposes no
standalone, deep-linkable popup, and `lib/events/eventviewer.php` is an orphan
early PHP+HTML duplicate.

## Deliverables

- **Screen:** `gui/templates/eventviewer/eventinfo.html` — Dashio popup with a
  teal header ("Event Info"), a Close button (`window.close()`), an **Open Event
  Viewer** link, a level badge + card title `Event #<id>`, the full record rows
  (Level / Timestamp / Source / Description), a **Session information** section
  shown only when the event belongs to a transaction (User display name /
  Session ID / Transaction #), an **Activity** section shown only when
  `objectID` is set (Activity code / Object ID / Object Type), a localized
  footer and a TLi18n locale switcher. Access-denied, not-found and load-error
  states; a 401 redirects to `/login.php`.
- **BFF:** `api/eventinfo/index.php` — `GET ?action=show&id=N`, session auth +
  `bffSameOriginGuard`. Ports `tlEvent::readFromDB(TLOBJ_O_GET_DETAIL_TRANSACTION)`
  plus `tlUser` display-name resolution; the timestamp is localized server-side
  via `tlStrftime(config_get('timestamp_format'))`, matching the legacy
  `localize_timestamp` rendering. JSON contract: 401 (anon) / 403 (no
  `mgt_view_events`) / 400 (missing or invalid id, unknown action) / 404
  (unknown event) / 405 (non-GET).
- **Link switch:** `$actions->eventInfo` added in `lib/functions/common.php`;
  the modern Event Viewer expanded row detail gains an **Open in window** link
  (`evinfo.openWindow`) that opens the popup with the current object/project
  context.
- **Shim:** `lib/events/eventinfo.php` is kept as a session-guarded 302 redirect
  onto the modern screen (anon → login, forwards `?id=` + project/plan context)
  so legacy deep links and the old ExtJS autoLoad panel still resolve.
- **i18n:** `evinfo.*` (8 keys) + `footers.eventInfo` + `evinfo.openWindow` in
  all 10 bundles (en/ro/de/es/fr/it/ja/pt/ru/zh), validated with
  `python3 -m json.tool`.

## Verification

- Browser (admin session): `eventinfo.html?id=6` renders both Session and
  Activity sections; `?id=5` (no object) omits Activity; `?id=999999` → not
  found; no id → "Missing or invalid event id."; locale switch to `ro`
  re-renders with Romanian labels (0 raw keys); Event Viewer row link opens the
  popup in a new tab.
- BFF curl contract: 401 anon, 403 guest without `mgt_view_events`, 405 POST,
  400 bad action/missing id, 404 unknown event.
- Legacy shim `lib/events/eventinfo.php?id=6` → 302 → modern popup.
- Event Viewer clean after the run (only pre-existing WARNING rows from the
  separately-filed bug **#1557**); console clean; `php -l` clean.

## Test cases

Suite **1556 (Screen — Event Info popup)** in `tmp/TLU_Test_Cases.md` — 9/9 PASS.

Screenshots: `docs/screenshots/issue-1556-evinfo-id6.png`,
`docs/screenshots/issue-1556-evinfo-403.png`.

## Bug found

- **#1557** — PHP 8 E_WARNING `Trying to access array offset on null` in
  `isIssueTrackerEnabled()` (`lib/functions/testproject.class.php:3487`) when a
  test project id does not exist.
