# Bugfix — Issue #1267: Legacy resultsGeneral — anonymous valid apikey + tproject_id WITHOUT tplan_id → SQL 1064 DB Access error

## Problem

An anonymous resolved-apikey request to `lib/results/resultsGeneral.php` with
`tproject_id` present but **no** `tplan_id` renders the raw **DB Access Error**
page (malformed SQL, `errorcode 1064`) instead of the graceful
"test plan is missing/unknown" info page. Discovered while regression-testing
the #1261 fix (the valid-anonymous-apikey branch with missing `tplan_id` was not
covered by the #1261 fixture). Pre-existing; NOT introduced by #1261 (its guard
only triggers on `tproject_id <= 0`; this path passes it with a valid id).

**Reproduce at HEAD (measured on the fresh-import CI box, no cookies):**

```
php tmp/fixtures_1257.php        # tproject id 1, tplan id 6, plan api_key rrrr...57
curl -s "http://localhost:8082/lib/results/resultsGeneral.php?apikey=rrrr...57&tproject_id=1"
```

→ HTTP 200 with a `DB Access Error - debug_print_backtrace() OUTPUT START`
body; events table gets `ERROR ON exec_query() ... 1064 ... syntax to use near
'AND enable_on_execution = 1 ORDER BY name'` for
`/* Class:tlPlatform - Method: getLinkedToTestplanAsMap */ SELECT ...`.

## Root Cause

`resultsGeneral.php:18` calls the shared `initArgsForReports()`
(`lib/results/displayMgr.php:16`), not the dead local `init_args()`
(resultsGeneral.php:158).

- Anonymous apikey (55 chars ≠ 32) → `setUpEnvForAnonymousAccess()`
  (`lib/functions/common.php:1311`) resolves the plan apikey fine, but never
  resolves `tplan_id` onto `$args` — with no `tplan_id` param, `R_PARAMS`
  leaves `$args->tplan_id = null`.
- The #1257/#1261 guard `if ($args->tproject_id <= 0)` (displayMgr.php:82)
  passes: `$args->tproject_id = 1`.
- `initializeGui()` (resultsGeneral.php:23) → `$tplanMgr->getPlatforms(null, ...)`
  (resultsGeneral.php:262) → `tlPlatform::getLinkedToTestplanAsMap(null)`
  (`testplan.class.php:3489`) builds `WHERE TP.testplan_id =  AND
  enable_on_execution = 1 ...` → **SQL 1064** → DB Access Error page + ERROR row.

The authenticated branch already stops a missing tplan gracefully
(`$tplan = get_by_id(null)` → null, guarded at displayMgr.php:72-78), but the
apikey (anonymous/remote) branch had **no** tplan guard — only the `tproject_id`
one. Same gap existed for the remote 32-char-key path and for every sibling
report script sharing `initArgsForReports()`.

**Why it breaks now:** no recent change — the anonymous branch simply never
validated `tplan_id`; #1257 only added the tproject guard.

**Blast radius:** `initArgsForReports()` is the shared arg parser for 8 report
controllers — `baselinel1l2`, `execTimelineStats`, `neverRunByPP`,
`resultsByTSuite`, `resultsGeneral`, `resultsTC`, `resultsTCFlat`,
`testAutomationSpec`. All dereference `tplan_id` further down; an anonymous
apikey request without `tplan_id` would hit the same SQL 1064 on each. A
single-point guard in the shared parser resolves them all.

## Fix

Committed on branch `fix/issue-1267`, commit `3b9aa61e0` (fix):

Added the same graceful guard the tproject check and the authenticated-branch
tplan check already use, placed right after the apikey if/else block and before
the `tproject_id <= 0` guard in `initArgsForReports()`:

```php
if (is_null($args->tplan_id) || $args->tplan_id <= 0) {
    // Missing/invalid tplan_id on an apikey (anonymous/remote) request: ...
    require_once(__DIR__ . '/../functions/info.inc.php');
    displayInfo(lang_get('error_print_doc_title'),
                lang_get('error_print_doc_missing_testplan'));
}
```

`displayInfo()` (`lib/functions/info.inc.php:29`) renders
`gui/templates/dashio/workAreaSimple.tpl` and `exit()`s → **HTTP 200** on every
branch (session / remote / anonymous). Placement is safe for all 8 callers: in
the authenticated `else` branch `tplan_id` is already guaranteed non-null by the
existing guard (displayMgr.php:72-78), so the new guard only fires on apikey
requests that previously leaked `null` into the SQL.

**Why this approach** (and alternatives rejected):
- *Guard in the shared parser* — the same single point where the #1257/#1261
  guards live; one place fixes all 8 report controllers consistently instead of
  a per-page copy (resultsGeneral.php's own local `init_args` is dead code and
  was not a viable patch site).
- *Rejected:* validating `tplan_id` inside `setUpEnvForAnonymousAccess()`
  (#1257 already rejected touching shared access semantics); throwing (the #1261
  run proved uncaught throws = HTTP 500, strictly worse); per-page patches in 8
  scripts (larger blast radius).

**Files changed:** only `lib/results/displayMgr.php` (+11/-0). No i18n bundle
changes (legacy strings `error_print_doc_title` / `error_print_doc_missing_testplan`
reused; no new user-facing strings).

## Verification

All checks on branch `fix/issue-1267`, PHP 8.3, MariaDB `testlink` fresh-import
schema, fixture `php tmp/fixtures_1257.php` (project RF1257 id 1, plan
"RF Plan 1257" id 6, 55-char plan key `rrrr...57`):

| Case | Request | Result |
|---|---|---|
| Primary symptom (A) | valid plan key + `tproject_id=1`, no tplan_id | pre-fix DB Access Error 1064 → **HTTP 200** graceful info page |
| (B) valid key + both ids | key + `tproject_id=1&tplan_id=6` | **HTTP 200** full "General Test Plan Metrics" / `RF Plan 1257` report |
| (C2) invalid key, no ids (Refs #1257) | 55-char unknown key, no params | **HTTP 200** graceful info page (unchanged) |
| (D) authenticated session, no tplan | admin cookie, no params | **HTTP 200** graceful info page (existing guard, unchanged) |
| (E) authenticated session + valid tplan | admin cookie, `tplan_id=6` | **HTTP 200** full report (browser-verified) |
| Siblings (1267.7) | valid key + `tproject_id=1` on `resultsTC`, `resultsByTSuite`, `execTimelineStats` | **HTTP 200** graceful info page each, no 1064 |

- `php -l lib/results/displayMgr.php` → clean.
- Browser (headless Chrome) → info page renders `<h1>Document generation
  error</h1>` + message; screenshot below.
- Event Viewer: no new `log_level=1` (ERROR) rows after the fix; only the
  benign pre-existing `'format' is not defined` WARNING (identical pre-fix) and
  login INFO rows.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1267", 7/7 PASS.

Screenshot: `docs/screenshots/issue-1267-resultsGeneral-missing-tplan-graceful.png`.