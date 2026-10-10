# Issue #1889 — `split_localized_date()` throws PHP 8 `ValueError: explode(null)` on a date with no delimiter (HTTP 500 / 0 bytes)

**Issue:** [#1889](https://github.com/sebiboga/testlink-upgraded/issues/1889)
**Branch:** `fix/issue-1889-split_localized_date-explode-null`
**Status:** VERIFIED-FIXED — regression suite `Issue #1889` 6/6 PASS, suite gate 7/7
**Related:** [#1888](Bugfix-Issue-1888-PlanMilestonesEdit-DoCreate-Milestone-Unset.md) (the failed-`doCreate` re-render path where this was found),
[#1890](Bugfix-Issue-1890-PlanMilestonesEdit-Nonexistent-Plan-Warnings.md).

## Symptom

`POST lib/plan/planMilestonesEdit.php` (`doAction=doCreate` / `doUpdate`) with a **date value
containing no `.`, `-`, `/` or `%` delimiter** (e.g. `target_date=aaaaaa`) crashed the request:
**HTTP 500, 0 bytes**, blank page, no Event Viewer row. Same for `start_date=aaaaaa`.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo root), PHP 8.3.35,
DB `testlink` freshly imported (0 projects / 0 plans), `admin`/`admin` (curl session cookie via
`login.php`). The crash fires inside `init_args()` (line 17) **before** `checkRights()` (line 23),
so a project/plan is not even required — used `tproject_id=1&tplan_id=2` per the report.

| probe | result |
|---|---|
| `POST planMilestonesEdit.php doAction=doCreate` `target_date=aaaaaa` | **HTTP 500, 0 bytes** |
| `tmp/php_server.log` | `Uncaught ValueError: explode(): Argument #1 ($separator) cannot be empty` @ `lib/functions/common.php:1020`, trace `common.php:1020 ← planMilestonesEdit.php:56 ← :17` |
| `SELECT COUNT(*) FROM events` | 1 row total (`audit_login_succeeded`, `log_level=16`) — **no** Error/Warning from the request; the fatal precedes `tLog` |

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `cfg/const.inc.php:271` | `config_get("date_format") = "%d/%m/%Y"` → the parser only knows delimiters `.`, `-`, `/`, `%`. |
| 2 | `lib/functions/common.php:1007-1016` | delimiter scan: `$splitChar = null; foreach($needle ...){ if(strpos(...)!==false){ $splitChar=$target; break; } }` — for `"aaaaaa"` nothing matches, `$splitChar` stays `null`. |
| 3 | `lib/functions/common.php:1020` | `$pieces = explode($splitChar, $timestamp);` passes `null` as `$separator`. |
| 4 | PHP 8 | `ValueError: explode(): Argument #1 ($separator) cannot be empty` — uncaught → HTTP 500 / 0 bytes. |
| 5 | `lib/plan/planMilestonesEdit.php:56` (and `:65`) | `init_args()` calls `split_localized_date($_REQUEST['target_date'], $dateFormat)` on raw user input. |

**Why it breaks now:** a PHP runtime change, not a code change. On PHP 5/7 `explode(null, ...)`
coerced `null` to `""` (with a notice/warning); PHP 8 promotes it to a fatal `ValueError`.

## Blast radius

`grep -rn "split_localized_date(" lib/ api/` → **20+ call sites** of the shared helper:
Plan Milestones `init_args` (target **and** start date), `is_valid_date()` (`common.php:986`, used by
`planMilestonesCommands.class.php:143,235`), Event Viewer (`eventviewer.legacy.php`), results
(`resultsMoreBuilds.php`, `tcCreatedPerUserOnTestProject.php` ×2), search (`reqSearch.php`,
`searchCommands.class.php`, `tcSearch.php`), custom fields (`cfield_mgr.class.php` ×6),
`tlFilterControl.class.php` ×2, and the modernized `api/eventviewer`, `api/search`, `api/requirements`,
`api/builds`, `api/suiteedit`. Any user-controlled date reaching the helper with no delimiter crashes
the same way → **helper-level fix hardens every caller at once**.

## The fix (minimal, 1 file, +9/-0)

`lib/functions/common.php` — return `null` when no delimiter is found, immediately after the scan
(before the `explode`):

```php
// Refs #1889: no delimiter found -> the value is not a parseable localized date.
// Previously $splitChar stayed null and explode(null,...) below threw a PHP 8
// ValueError (fatal, HTTP 500 / 0 bytes). Return null: callers already treat a
// null result as "invalid date" (empty timestamp returns null just above).
if ($splitChar === null)
{
  return null;
}
```

### Why this method (and what was rejected)

* **Guard the shared helper (minimal).** Patching only `planMilestonesEdit.php` would leave 20+ other
  callers exposed to the identical fatal. A `null` return is already part of the function contract
  (empty timestamp returns `null` at line 1002-1005), and every caller checked — notably
  `planMilestonesEdit.php:57` `if ($date_array != null)` and `is_valid_date()` `common.php:989`
  `if ($date_array != null)` — already handles it. The downstream effect is exactly the legacy
  `warning_invalid_date` feedback path.
* **Rejected: `explode($splitChar ?? "", ...)`.** It silences the fatal but produces a 1-element
  `$pieces`; `count($pieces) != 3` then falls through to the empty-array branch, which still emits
  undefined-key warnings on `$date_array['month']` etc. Returning `null` is the honest "invalid date"
  signal and matches the callers' existing contract.
* **No normalized-format parsing added** (e.g. `date_create_from_format`): out of scope — the legacy
  parser is delimiter-driven (`$k2t` mapping), a format like `%Y%m%d` is not what these screens use.

## After the fix

A no-delimiter date in either field re-renders the milestone form with the localized
`warning_invalid_date` message ("This date is not a valid date."), writes **no** Event Viewer row, and
creates **no** milestone. Valid `dd/mm/yyyy` dates behave exactly as before (milestone created, ISO
date persisted).

## Verification

Regression suite `Issue #1889`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1889" bash ai/verify_test_suites.sh` → 7/7 PASS. Fixture
`tmp/fixtures_1889.php` → tproject 1 (`TL89`), tplan 2 (`TL1889 Demo Plan`).

| case | before | after |
|---|---|---|
| POST `doCreate` `target_date=aaaaaa` | **500, 0 bytes** | **200**, form re-render, 0 milestones created |
| POST `doCreate` `start_date=aaaaaa` (valid target) | **500, 0 bytes** | **200**, form re-render, 0 milestones created |
| POST `doCreate` `target_date=10/10/2026` (valid) | 302 + milestone | **302**, 1 row `NODELIM3 | 2026-10-10` |
| `events` table after all probes | — | only `log_level=16` audit rows, **0 Error/Warning** |
| `php -l lib/functions/common.php` | — | No syntax errors detected |
| `is_valid_date('aaaaaa', '%d/%m/%Y')` (doCreate:143 / doUpdate:235) | fatal | returns `false` |

Screenshot: `docs/screenshots/issue-1889-invalid-date-graceful-rerender.png` — the post-fix form with
the graceful "This date is not a valid date." feedback.

## How to re-test in one command

```bash
php tmp/fixtures_1889.php          # tproject=1, tplan=2
# login admin/admin (curl session cookie), then:
curl -s -b <cookie> -X POST http://localhost:8082/lib/plan/planMilestonesEdit.php \
  --data "doAction=doCreate&tplan_id=2&tproject_id=1&milestone_name=NODELIM&target_date=aaaaaa&low_priority_tcases=30&medium_priority_tcases=40&high_priority_tcases=30" \
  -w "HTTP=%{http_code} bytes=%{size_download}\n"   # expect HTTP=200, NOT 500
```