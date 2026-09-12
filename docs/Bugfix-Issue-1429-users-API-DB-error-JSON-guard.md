# Issue 1429 — api/users: PUT/POST with `locale` > varchar(10) leaks DB debug backtrace instead of JSON error (CWE-200)

**Issue:** [#1429](https://github.com/sebiboga/testlink-upgraded/issues/1429)
**Branch:** `fix/issue-1429`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

`PUT /api/users/index.php/{id}` (and the same `POST /api/users` create path) with a
`locale` longer than the DB column `users.locale varchar(10)` (e.g.
`<img src=x onerror=1>`) fails the write with MariaDB strict-mode error
`1406 - Data too long for column 'locale'`. Upstream of this fix the BFF returned a
broken non-JSON response instead of the documented JSON error contract:

- before #1423, `exec_query()` printed the raw HTML page
  `DB Access Error - debug_print_backtrace() OUTPUT START` with absolute server paths
  (CWE-200 path disclosure);
- after #1423 changed `lib/functions/database.class.php` to `throw
  Exception('Database error (query failed)')` for BFF XHR requests, the throw escaped
  `api/users/index.php` uncaught → `HTTP/1.0 500` with **empty body**.

Either way the client never gets `{status:'error', message:...}`; every logged-in
admin-token API caller can trigger it.

## Repro steps

1. Log in as admin (`POST /api/auth/login` `{login:admin,password:admin}`).
2. Create a fixture user with a non-empty email (the fresh-import admin has an empty
   email, which trips `checkEmailAddress()` = E_EMAILLENGTH before any SQL — unrelated
   pre-existing quirk): `POST /api/users` `{login:repro,email:repro@test.com,password:"Repro123!",locale:"en_GB",globalRoleID:5}`.
3. `PUT /api/users/index.php/2` with `{"locale":"<img src=x onerror=1>"}` and header
   `X-Requested-With: XMLHttpRequest`.
4. Observe the response (pre-fix): `HTTP/1.0 500`, `Content-Type: application/json`,
   empty body. `events` table gains `ERROR ON exec_query() ... 1406 - Data too long for column 'locale'`.

## Root cause

Chain (each hop source-verified and reproduced):
1. `api/users/index.php:359` (PUT) / `:295` (POST) assign `$u->locale = $body['locale']`
   verbatim — no length/allowed-value validation before the DB write.
2. `lib/functions/tlUser.class.php:401-466` `writeToDB()` builds the UPDATE with
   `locale = '<payload>'` (`:432`) and calls `$db->exec_query($sql)` (`:443`).
3. `users.locale` is `varchar(10) NOT NULL` under `STRICT_TRANS_TABLES` → MariaDB error
   `1406 Data too long`; `Execute()` returns falsy (`lib/functions/database.class.php:190`).
4. The #1423 guard (`database.class.php:204-207`) converts that into
   `throw new Exception('Database error (query failed)')` for `X-Requested-With:
   XMLHttpRequest` — this eliminated the HTML backtrace page but introduced an
   unhandled throw.
5. `api/users/index.php` had no exception handling on the two `writeToDB()` call sites
   (`:316` POST create, `:364` PUT update) → unhandled exception → naked `HTTP 500`,
   empty body. The BFF JSON contract is broken for **every** DB write failure, not just
   locale.

Blast radius (grep-verified): the same unguarded `writeToDB()` pattern exists in
`api/userinfo/index.php:166` (profile PUT, same locale field!) and
`api/roles/index.php:106,138` — they share `database.class.php:204` behavior and are
candidates for the same guard (tracked as a follow-up, not in scope here).

Method chosen: **minimal catch on the write paths**. Wrap the two `writeToDB()` calls in
`try/catch (Throwable)` and answer `HTTP 422` + `{status:'error', message:'Error
creating/updating user', code:'db_write_failed'}` — pure JSON, no exception text, no
paths, no HTML. Alternative rejected: validating the locale value up-front against
`config_get('locales')` — tighter, but changes acceptance semantics beyond the issue's
ask; the DB-layer constraint is the truth, and a generic catch also covers other future
constraint failures (e.g. `firstName`/`lastName` length) without re-visiting each field.

## Verification

Regression suite `Regression — Issue #1429` in `tmp/TLU_Test_Cases.md` (8 cases):

- 1429.1 pre-fix repro → `HTTP/1.0 500`/empty body reproduced;
- 1429.2 post-fix same PUT → `HTTP 422` `{"status":"error","message":"Error updating
  user","code":"db_write_failed"}` — JSON only;
- 1429.3 valid locale `en_US` PUT → `200` ok, row persisted (`locale='en_US'`);
- 1429.4 valid POST create → `200` ok;
- 1429.5 POST create with `locale` > 10 chars → `422` JSON, no row persisted;
- 1429.6 untouched routes (`active` toggle, DELETE, GET list/detail/meta) → all `200`,
  unchanged JSON shapes;
- 1429.7 UI smoke (`usersView.html` edit modal save) → success toast, DB updated, 0 JS
  console errors;
- 1429.8 Event Viewer → no new Error/Warning from valid operations (the only `1406` rows
  present are the deliberate overflow writes in 1429.1/1429.5).

Result: 8/8 PASS. Screenshot: `docs/screenshots/issue-1429-usersview-fixed.png`
(User Management after a UI edit-save).

## Files changed

- `api/users/index.php` (+19/-2) — try/catch (Throwable) on both `writeToDB()` call sites
  (POST create, PUT update) → `422` JSON `db_write_failed` on catch.
- `CHANGELOG` (+7) — one line under KEY BUGFIX referencing #1429.
- `tmp/TLU_Test_Cases.md` (+20) — regression suite 1429.1-1429.8.
- `docs/screenshots/issue-1429-usersview-fixed.png` — UI smoke-test evidence.

## Notes / related findings (not fixed here)

- #1423 (landed earlier) replaced the HTML backtrace with a throw — this fix completes
  the JSON contract on the `api/users` write paths.
- Follow-up candidates with the same unguarded `writeToDB()` pattern (filed/candidate):
  `api/userinfo/index.php:166` PUT profile `locale` — same overflow → same naked 500;
  `api/roles/index.php:106,138`.
- Fresh-import admin has an empty email; any `PUT` on id=1 returns
  `{"status":"error","message":"Error updating user","code":-2}`
  (`checkEmailAddress('')` = E_EMAILLENGTH, `tlUser.class.php:1079`) — pre-existing,
  unrelated to this issue (fixture users with valid emails work normally).