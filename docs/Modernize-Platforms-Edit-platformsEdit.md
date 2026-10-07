# Modernize-Platforms-Edit-platformsEdit (#1871)

The legacy **Platform Create/Edit** form (`lib/platforms/platformsEdit.php`
rendering a Smarty template) was replaced by a standalone Dashio
HTML/JavaScript screen backed by a session-authenticated PHP BFF.

## Deliverables

- `gui/templates/platforms/platformsEdit.html` provides project context,
  Create/Edit mode cards, name with uniqueness hint, notes, the three legacy
  flags (enable on design, enable on execution, open for execution),
  Save/Cancel/Back/Refresh actions, delete-confirm modal, locale switcher,
  localized success toasts, and explicit invalid-project, not-found, and
  permission-denied state cards.
- `api/platformedit/index.php` exposes `init`, `save`, `flag`, and `delete`
  actions, validates the TestLink session and Test Project, re-checks the
  legacy rights (`platform_view` for init, `platform_management` for writes)
  on the OWNING test project, and returns a 400/401/403/404/405/422/500 JSON
  contract the screen localizes client-side.
- Delete reproduces the legacy test-plan guard: a platform linked to any test
  plan answers `422 DELETE_BLOCKED`.
- Duplicate names are pre-checked on BOTH create and update (ownership is
  proven before the name probe so a 422 can never precede the 404), answered
  as `422 E_NAMEALREADYEXISTS`.
- `lib/platforms/platformsEdit.php` is now a session-guarded shim: `GET/HEAD`
  redirect 302 to the modern screen with `tproject_id` / `platform_id` /
  `tplan_id` forwarded, anything else `405`, anonymous callers get the
  standard framework login bounce.
- `lib/functions/common.php` registers the `platformEdit` action;
  `gui/templates/platforms/platformsView.html` launches the screen.
- All `pedit.*` labels are present in all client-side i18n bundles (shared
  `pedit.*` namespace with the Test Project editor — no key collisions).

## Verification

| Scenario | Result |
|---|---|
| init create | 200, mode `create`, flags default 1/1/1 |
| init edit | 200, mode `edit`, platform values loaded |
| save create | 200, `mode:created`, new id; MODE card flips to Edit |
| duplicate name (create) | 422 `E_NAMEALREADYEXISTS`, localized toast |
| duplicate name (update) | 422 `E_NAMEALREADYEXISTS`, no rename |
| empty name | client-side warning + 422 `E_NAMELENGTH` |
| save update | 200, notes/flags persisted |
| flag action | 200, DB column updated |
| delete unlinked | 200, row removed, redirect to list `?notice=deleted` |
| delete linked to a plan | 422 `DELETE_BLOCKED`, localized toast |
| anonymous session | BFF 401; shim bounces to login |
| no-rights user (`platform_view` missing) | BFF 403 `NO_RIGHT`; localized permission state card |
| foreign project/platform ownership | rights checked BEFORE resolution: a restricted caller gets a uniform `403 NO_RIGHT` for unknown and foreign test projects (no existence oracle, the #1697 lesson); a foreign platform inside an addressed project answers `404 NOT_FOUND` before any name probe |
| name over 100 characters | server-side 422 `E_NAMELENGTH` (`platforms.name` is varchar(100), legacy `PLATFORM_MAXLEN`) |
| wrong verb (POST init, PUT, unknown action) | 400/405/400 |
| POST without same-origin proof | 403 CSRF |
| legacy shim GET (authed) | 302 to modern screen with params |
| legacy shim POST (authed) | 405 + `Allow: GET, HEAD` |
| Romanian locale | full relabel incl. form, flags, modal, footer |
| missing/invalid `tproject_id` | localized invalid-project state card |
| `platform_id` not found | localized not-found state card |

Browser verification used disposable Test Project `PLED1871` (fixture
`tmp/fixtures_1871.php`, foreign project `PLFOR`, user `plednorights`).
No unexpected JavaScript exceptions and no new product Error/Warning rows in
the Event Viewer (the single WARNING row came from the throwaway fixture
itself, in git-ignored `tmp/`).

Four defects were found while testing and fixed in the same run (one commit
each): raw `-4` duplicate code on create, shim accepting POST with a 302,
stale MODE card after create, and the missing Dashio Bootstrap JS include
that had left the delete-confirm modal dead (`$(...).modal is not a
function`).

The mandatory code review then applied three more findings: the session gate
now runs BEFORE the DB connect (the #1677/#1780/#1814 lesson — a DB failure
can no longer answer an anonymous caller with a raw `dbms_msg`), `init`
checks rights BEFORE resolving the test project (uniform `403` for unknown
and foreign projects — measured with the role-3 user: byte-identical `403`
for `tproject_id=99999` and `tproject_id=13`), and the name gets a
server-side 100-character cap. The review's other claims (CSRF, SQL
injection, XSS in toasts, `out()` status reset, verb contract) were
disproven by the executed verification matrix.

## Test suite

Suite **TC-1871 — Modernize: Platform Create/Edit (platformsEdit) (#1871)**
in `tmp/TLU_Test_Cases.md` was re-executed in full against the fresh
fixture: API matrix (16 checks), browser pass (19 checks), shim and wiring —
all PASS; suite gate `TLU_REQUIRE_SUITE=1871` 7/7.

## Files

- `api/platformedit/index.php` — init/save/flag/delete BFF
- `gui/templates/platforms/platformsEdit.html` — standalone Dashio screen
- `lib/platforms/platformsEdit.php` — session-guarded redirect shim
- `lib/functions/common.php` — `platformEdit` action registration
- `gui/templates/platforms/platformsView.html` — live launcher wiring
- `gui/templates/i18n/*.json` — localized `pedit.*` keys
- `docs/screenshots/issue-1871-platformsedit-*.png` — browser evidence

Refs #1871.
