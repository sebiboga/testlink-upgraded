# Issue 1406 — usersView.html: user grid text cells rendered as innerHTML — stored-XSS sink

**Issue:** [#1406](https://github.com/sebiboga/testlink-upgraded/issues/1406)
**Branch:** `fix/issue-1406-usersview-xss`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

The modernized User Management grid (`gui/templates/usermanagement/usersView.html`)
pushed every text cell (login, first/last name, email, globalRoleName, locale) into
DataTables as a raw string. DataTables 1.13.7 injects cell values via `td.innerHTML`
**without escaping** (escaping only became the default in DataTables 2.x), so a stored
value such as `<img src=x onerror=…>` executes as soon as the grid renders. Browser
measurement on this fixture: creating a user whose `firstName` is
`</td><img src=x onerror=document.title="XSSFIRED">` and reloading the grid flipped the
page title to `XSSFIRED` and left the raw `<img>` markup inside the cell — stored XSS
confirmed.

## Repro steps

1. Log in admin/admin at http://localhost:8082.
2. `PUT /api/users/index.php/{id}` (or `POST`) with a payload in a name field, e.g.
   `{"firstName":"</td><img src=x onerror=document.title=\"XSSFIRED\">"}` — the BFF
   stores it verbatim (no HTML validation on write).
3. Open `gui/templates/usermanagement/usersView.html?tproject_id=0&tplan_id=0`.
4. The payload renders as live markup. Before the fix the handler executed
   (`document.title === 'XSSFIRED'`).

Note: the original report's `locale` vector is **not** storable on this fixture —
`users.locale` is `varchar(10)` and MariaDB strict mode rejects longer values (the BFF
then leaks a raw DB debug backtrace; tracked separately as #1429). The real stored
vectors are the wider name/email columns (`first`/`last` varchar(50), `email` varchar(100)).

## Root cause

- The BFF's PUT handler (`api/users/index.php:282-288`) accepts `firstName` /
  `lastName` / `email` / `locale` and persists them through `tlUser::writeToDB()`
  (`lib/functions/tlUser.class.php:435-449`). That path is SQL-safe
  (`prepare_string()`) but intentionally stores HTML as-is — escaping is a
  render-time responsibility.
- `renderTable()` (`gui/templates/usermanagement/usersView.html:292-312`) built
  DataTables row arrays from the raw strings; DataTables 1.13.7 sets `td.innerHTML`
  without escaping.
- Bonus sink in the same block: the delete icon interpolated `u.login` raw into an
  `onclick="deleteUser(id, '<login>')"` attribute — attribute-injection vector of the
  same chain.
- Why it breaks NOW: this screen was modernized before the `esc()` convention existed;
  its sibling `rolesView.html` already shipped an identical helper for exactly this.

## Fix (minimal, mirrors rolesView.html)

1. Added the existing `esc()` helper — `document.createElement('div')` +
   `createTextNode(s)` + `innerHTML` read-back — so DataTables' `innerHTML` assignment
   receives HTML-entity-escaped text and renders it literally.
2. Wrapped all seven text cells (`login`, `firstName`, `lastName`, `email`,
   `globalRoleName`, `locale`, `expirationDateFormatted`) in `esc()`. Status badge and
   action cells stay explicit HTML by design (they only carry hardcoded markup, numeric
   `u.id` and i18n titles).
3. Delete action no longer embeds `u.login` in the `onclick`: it now passes only
   `u.id` and `deleteUser()` resolves the login from the `allItems` array by id — the
   exact pattern `resetPassword()` already used in the same file. `confirm()` renders it
   as a JS string, not HTML, so it is safe by construction.

Method chosen on purpose: reuse the proven in-repo convention instead of inventing a
new one (alternative rejected: wrapping values in a DataTables `render`/`columns`
callback would have changed ordering/search semantics and duplicated the helper).
No backend change needed — output encoding belongs at the render boundary.

## Verification

Regression suite `Regression — Issue #1406` in `tmp/TLU_Test_Cases.md` (7 cases):
- 1406.1 pre-fix control → payload executed (`document.title === 'XSSFIRED'`), raw
  `<img>` markup found in `td.innerHTML`;
- 1406.2 post-fix → title stays `User Management`, cell `innerHTML` is `&lt;…&gt;`
  entities, `textContent` is the literal payload;
- 1406.3 normal grid renders (admin row, Active/Inactive badges, action icons);
- 1406.4 edit modal opens on the payload user (login readonly, values pre-filled via
  `.val()` — no execution);
- 1406.5 delete on a non-admin user works (confirm shows login, row removed);
- 1406.6 DataTables search unaffected;
- 1406.7 hygiene — no new Error/Warning events from the fixed render path.
Result: 7/7 PASS.

## Files changed

- `gui/templates/usermanagement/usersView.html` (+16/-9)
- `docs/screenshots/issue-1406-usersview-xss-before.png` / `...-after.png`
- `tmp/TLU_Test_Cases.md` (suite commit 2)

Pre-existing bugs discovered while testing (out of scope, filed):
- BFF PUT with a `locale` longer than `varchar(10)` returns the raw "DB Access Error -
  debug_print_backtrace()" HTML page with HTTP 200, leaking absolute server paths
  (CWE-200) → **#1429**.
- Edit-modal `<option>` labels (`role.name`, `loc.name`, auth `label`/`description`)
  are appended unescaped in usersView (`loadMeta()`, lines 247/255/270-272) — a
  narrower, admin-gated variant of the same family → **#1430**.