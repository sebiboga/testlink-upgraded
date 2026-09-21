# Bugfix — Issue #1557: PHP 8 E_WARNING in isIssueTrackerEnabled() when test project id does not exist

## Problem

Every ASIDE init for a non-existent / stale test project id wrote an
`E_WARNING` to the `events` table:

```
E_WARNING
Trying to access array offset on null - in .../lib/functions/testproject.class.php - Line 3487
source: GUI - Test Project ID : 1
```

The HTTP response stays 200 — the symptom only shows up in the Event Viewer /
`events` table (log_level 2, activity=PHP).

**Reproduce at HEAD** (measured on the fresh-import CI box, zero
`testprojects` rows): with an authenticated session call

```
curl -b <cookies> "http://localhost:8082/api/aside/index.php?action=init&tproject_id=1&tplan_id=2"
```

→ HTTP 200 and one new E_WARNING row per call in `events`
(`.../lib/functions/testproject.class.php - Line 3487`).

## Root Cause

- `lib/functions/database.class.php:785 get_recordset()` initializes
  `$output = null` and only populates the array while rows are fetched — a
  SELECT that returns 0 rows leaves the return value `null`.
- `lib/functions/testproject.class.php:3479 isIssueTrackerEnabled($id)` builds
  `SELECT issue_tracker_enabled FROM testprojects WHERE id=<int>` and executes
  `$ret = $this->db->get_recordset($sql); return $ret[0]['issue_tracker_enabled'];`
  (line 3487) — indexing offset `[0]` on `null` throws the PHP 8 warning.
- Triggered whenever the referenced `tproject_id` has no row (stale id, freshly
  imported DB, crafted request).

**Blast radius:** 3 call sites — `api/aside/index.php:125`,
`lib/general/asideMenu.php:71`, `lib/results/resultsNavigator.php:126`. In all
three the value is consumed exclusively inside a boolean AND
(`($rptItem['enabled'] == 'bts') && $btsEnabled`, resp. `$gui->btsEnabled`
passed into `get_list_reports()` which filters reports with the same boolean
AND shape at `lib/functions/reports.class.php:86`). No caller does a strict
type check; `null` and `0` are equally falsy, so the fail-closed default is
indistinguishable from the previous behaviour at every consumer.

## Fix

Committed on branch `fix/issue-1557`, commit `98d4d1f0b`:

```php
$ret = $this->db->get_recordset($sql);
if( !is_array($ret) || !isset($ret[0]['issue_tracker_enabled']) )
{
  return 0;
}
return $ret[0]['issue_tracker_enabled'];
```

Guard semantics: `get_recordset()` returns `null` for a failed/empty query and
an array of ≥1 assoc rows otherwise (rows are appended one per fetched record,
the SQL explicitly selects `issue_tracker_enabled`). `!is_array($ret)` covers
the null case, `!isset(...)` covers an empty array / missing key defensively.
The fallback `0` (= issue tracker disabled) is correct because the schema
default is `0` and consumers only enable BTS report buttons with a truthy
value — hiding UI affordances is the right behaviour for a project that does
not exist. Valid project ids behave exactly as before (happy path untouched).

**Rejected alternatives:** (a) early-return with a `displayInfo()` "unknown
project" page — a user-visible behaviour change beyond a log-noise bug; (b)
modifying `get_recordset()` to return `[]` instead of `null` — a database-layer
contract change with far wider blast radius than this bug; (c) unifying
`sibling isCodeTrackerEnabled()` (which uses `(array) $ret` cast) — a drive-by
refactor outside this issue's scope. This fix is minimal and matches the
issue's suggested fix.

## Files Changed

- `lib/functions/testproject.class.php` — `isIssueTrackerEnabled()`, lines
  3486-3491: added `is_array`/`isset` guard returning `0`.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1557",
  3/3 PASS.

## Verification

All checks on branch `fix/issue-1557`, PHP 8, MySQL `testlink` fresh-import
schema:

- R1 — non-existent id `1`, `DELETE FROM events` first, 3× ASIDE init calls →
  HTTP 200 each, `SELECT COUNT(*) FROM events` = **0** (pre-fix: one E_WARNING
  per call).
- R2 — valid id `100` (`issue_tracker_enabled=1` seeded) → HTTP 200, full
  ASIDE JSON menu, `events` stays 0.
- R3 — `php -l` clean on `lib/functions/testproject.class.php`,
  `lib/general/asideMenu.php`, `lib/results/resultsNavigator.php`,
  `api/aside/index.php`.
- R4 — Event hygiene: zero Error/Warning rows after the whole matrix; no
  `Line 3487` warning anywhere; browser console clean.

No new user-facing strings → no locale-bundle additions (i18n not applicable).