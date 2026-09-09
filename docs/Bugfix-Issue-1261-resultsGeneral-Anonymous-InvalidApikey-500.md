# Bugfix — Issue #1261: Legacy resultsGeneral — anonymous invalid-apikey request with NONE of tproject_id/tplan_id → uncaught Exception HTTP 500

## Problem

A crafted anonymous request to a legacy report URL with an invalid `apikey`
(no matching entity) **and** neither `tproject_id` nor `tplan_id` in the URL
dies with an uncaught PHP `Exception` → **HTTP 500** (blank page).

**Reproduce at HEAD (measured on the fresh-import CI box), no cookies:**

```
curl -s -o /dev/null -w "%{http_code}" \
  "http://localhost:8082/lib/results/resultsGeneral.php?apikey=INVALIDKEY0000000000000000000000000000000000000000000000000000"
```

→ `500`, server log tail:

```
Stack trace:
#0 .../lib/results/resultsGeneral.php(18): initArgsForReports()
#1 {main}
  thrown in .../lib/results/displayMgr.php on line 84
```

Discovering context: this URL shape was **not** covered by the #1257 repro
(which supplied fabricated ids); it is the missing-params case that the
#1257 null-guard fix (guard at `displayMgr.php:72-78`) never reached, because
that guard lives inside the authenticated (no-apikey) branch only. Pre-existing
(throw added in 2020, commit `b5de75784d`); not introduced by #1257.

## Root Cause

`initArgsForReports()` (`lib/results/displayMgr.php:16`) parses
`tproject_id`/`tplan_id` via `R_PARAMS` (`tlInputParameter::INT_N`,
displayMgr.php:27-30) — an absent param leaves the property **`null`**.

- Anonymous branch (displayMgr.php:61-66): `setUpEnvForAnonymousAccess()`
  (`lib/functions/common.php:1311`) resolves the apikey via
  `getEntityByAPIKey()`; an invalid key returns `null` ⇒ `$status_ok=false`,
  silent return. Neither `tproject_id` nor `tplan_id` is ever resolved onto
  `$args`.
- Execution reaches the shared guard `if ($args->tproject_id <= 0)`
  (displayMgr.php:82). PHP 8 relational semantics: **`null <= 0` → true** ⇒
  `throw new Exception(...)` (displayMgr.php:84).
- **No `try/catch` exists in any of the 7 callers** (`resultsGeneral:18`,
  `testAutomationSpec:21`, `baselinel1l2:20`, `neverRunByPP:33`,
  `resultsByTSuite:18`, `resultsTC:25`, `execTimelineStats:23`) ⇒ uncaught ⇒
  **HTTP 500**.

The authenticated branch (displayMgr.php:67-80) resolves `tproject_id` from a
fetched tplan (`$tplan['testproject_id']`) and pre-guards a missing tplan with
`displayInfo(...)` + `exit()` (displayMgr.php:72-78, added in #1257 /
`cf19af2d76`), so it can never reach the throw. The anonymous/remote branches
have no equivalent guard.

**Why it breaks now:** no recent change — the throw is old; the #1257 fix only
guarded the session branch. The anonymous invalid-apikey + no-params case was
simply never covered.

**Blast radius:** the guard sits in the shared `initArgsForReports()` used by
the 7 scripts above, but every one of them calls it as its **first** statement
and none catches exceptions — a graceful `exit()` inside the function is
therefore safe for all of them (nothing after the call can run).

## Fix

Committed on branch `fix/issue-1261`, commit `241ef81ad` (fix):

The `throw new Exception(...)` at displayMgr.php:82-86 is replaced by the same
graceful info page already used by the authenticated guard at displayMgr.php:72-78:

```php
if ($args->tproject_id <= 0) {
    // Missing/invalid tproject_id (incl. anonymous invalid-apikey requests where
    // neither tproject_id nor tplan_id is present): stop gracefully instead of an
    // uncaught Exception (PHP 8: null <= 0 is true) that dies with HTTP 500.
    require_once(__DIR__ . '/../functions/info.inc.php');
    displayInfo(lang_get('error_print_doc_title'),
                lang_get('error_print_doc_missing_testplan'));
}
```

`displayInfo()` (`lib/functions/info.inc.php:29`) renders
`gui/templates/dashio/workAreaSimple.tpl` (plain HTML, only uses `$basehref`
inside an unset `link_to_op` branch) and `exit()`s → **HTTP 200** on every
branch (session / remote / anonymous), including the anonymous invalid-key case
where the session/basehref block in `setUpEnvForAnonymousAccess()` is skipped
(`TLSmarty` falls back to `TL_BASE_HREF`/`TL_DEFAULT_LOCALE`).

**Why this approach** (and alternatives rejected):
- *Graceful info page instead of throw* — matches the exact pattern already
  shipped in the authenticated guard (#1257, `displayMgr.php:72-78`), reuses
  the existing legacy `strings.txt` keys
  `error_print_doc_title` / `error_print_doc_missing_testplan`
  (`locale/en_US/strings.txt:642-643`), and is strictly better behaviour:
  any request that previously produced a 500 now renders a clean 200 page.
  No valid request can reach the guard with `tproject_id <= 0` (session branch
  resolves it from a fetched tplan; remote/anonymous valid keys pass id > 0),
  so no healthy-path regression is possible.
- *Rejected:* dropping the guard entirely (loses the error signal for genuinely
  corrupted state); throwing a *caught* error in a new try/catch per caller
  (7 files, larger blast radius); auto-resolving the anonymous request to the
  first test plan (silently changes public-link semantics). Validating ids
  inside `setUpEnvForAnonymousAccess()` would change shared access semantics
  used by many screens (#1257 already rejected it).

**Files changed:** only `lib/results/displayMgr.php` (+7/-2). No i18n bundle
changes (legacy strings reused; no new user-facing strings).

## Verification

All checks on branch `fix/issue-1261`, PHP 8.3.33, MairaDB `testlink`
fresh-import schema, fixture `php tmp/fixtures_1257.php` (project RF1257 id 1,
plan "RF Plan 1257" id 6, 64-char plan key, 32-char admin script_key):

| Case | Request | Result |
|---|---|---|
| Primary symptom | invalid apikey, no ids | pre-fix 500 → **HTTP 200** info page ("Document generation error … the test plan is missing") |
| Anonymous valid key, no ids | 64-char plan key, no params | **HTTP 200** info page (previously 500) |
| Anonymous valid key + both ids | plan key + `tproject_id=1&tplan_id=6` | **HTTP 200** full "General Test Plan Metrics" with `RF1257` / `RF Plan 1257` |
| Remote 32-char user key + both ids | admin script_key + ids | **HTTP 200** report, unchanged |
| Authenticated session, missing tplan | admin cookie, no params | **HTTP 200** info page (existing #1257 guard, unchanged) |
| Authenticated session + valid tplan | admin cookie, `tplan_id=6` | **HTTP 200** full report |

- `php -l lib/results/displayMgr.php` → clean.
- Browser (headless Chrome, anonymous incognito) → the info page renders with
  `<h1>Document generation error</h1>` + the message; screenshot below.
- Event Viewer: the fixed paths emit **no** new Error/Warning `events` rows
  (invalid-key paths exit inside `displayInfo()` before any DB work; healthy
  paths only produce the pre-existing benign `'format' is not defined`
  warning identical to HEAD).

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1261", 7/7 PASS.

Screenshot: `docs/screenshots/issue-1261-resultsGeneral-anon-invalid-apikey-info-page.png`.

## Related finding (filed separately)

While regression-testing, a **pre-existing** adjacent bug surfaced: anonymous
**valid** plan-key + `tproject_id` present but **no** `tplan_id` → malformed
SQL (`WHERE TP.testplan_id = <null>`) → SQL 1064 DB Access error page
(`tlPlatform::getLinkedToTestplanAsMap(NULL)`, `testplan.class.php:3489` via
`getPlatforms(NULL)` at resultsGeneral.php:262). Out of scope for this issue
(the guard only triggers on `tproject_id <= 0`; this path passes it) —
filed as **#1267**.