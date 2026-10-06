# Bugfix #1708 — `requirement_spec_mgr::getReqsOnSpecForLatestTCV()` fatals (HTTP 500) for a test case with no active version

## Symptom

`GET /api/requirements/assign-reqs?req_spec_id=N&tcase_id=X` — the read behind the
modern **Assign Requirements** screen (`assignReqs.html`) and the Assign Requirements
modals of `tcEdit.html` / `testSpec.html` / `tcView.html` — returned **HTTP 500 with an
empty body** whenever the target test case had **no active version** (all
`tcversions.active = 0`). No Event Viewer row was written for the fatal.

## Root cause (measured, PHP 8.3.35)

`lib/functions/requirement_spec_mgr.class.php:2632` did
`current($tcMgr->get_last_active_version($tcase_id))`. `get_last_active_version()`
returns **null** when the test case has no active version
(`lib/functions/testcase.class.php:6184` init, `:6304` return), and `current(null)` throws an uncaught
`TypeError` (`Argument #1 ($array) must be of type array, null given`) → fatal → 500.
On PHP 7 the same line produced the filed E_WARNING ("Trying to access array offset on
value of type bool") and `$ltcv = 0`, an empty "Assigned" grid.

The gate that lets the call through, `arLatestTCVersion()`
(`api/requirements/index.php:2338-2346`), selects the latest version with **no
`TCV.active = 1` filter**, so a deactivated test case still reaches the method. The
sibling screen `reqTcAssign.html` (BFF `api/reqtcassign`) was already safe: its
`resolveCtx()` answers a clean 404 ("Test case has no active version") first.

## Why this fix

`getReqsOnSpecNotLinkedToLatestTCV()` — the twin method — already carries the correct
guard from issue **#1705** (`current((array) …)` + `is_array()`), and this sibling was
deliberately left untouched on that run. The fix applies the identical pattern:

```php
$tcInfo = current((array)$tcMgr->get_last_active_version($tcase_id));
$ltcv  = is_array($tcInfo) ? intval($tcInfo['tcversion_id']) : 0;
```

`$ltcv = 0` is the honest value: no `req_coverage` row can point at tcversion 0, so the
Assigned grid comes back **empty with HTTP 200** instead of fataling — the issue's
"Expected" branch (a) and exactly the semantics #1705 chose for the twin.

**Alternatives rejected:** returning an explicit error from the BFF would diverge from
the twin method and require touching 4 screens; fixing `arLatestTCVersion()` alone would
leave every other caller of the method exposed; a per-caller `is_null()` guard at each of
the 4 screens would repeat the fix instead of closing it once.

## Files changed

* `lib/functions/requirement_spec_mgr.class.php` (1 call site + comment; 9+/2-)
* `CHANGELOG`, `docs/Bugfix-Issue-1708-ReqSpecNoActiveVersion-500.md` (this file)
* `tmp/TLU_Test_Cases.md` — suite "Regression — Issue #1708"

## Verification (live, http://localhost:8082, fixture `tmp/fixtures_1708.php`)

| Case | Before | After |
|---|---|---|
| GET assign-reqs, v1 `active=0` | 500, 0 bytes, `Uncaught TypeError` in server log | **200, `assigned: []`**, no fatal |
| GET assign-reqs, v1 active (control) | 200 + `FX70-1 link_id 1` | unchanged |
| GET assign-reqs spec-only (no `tcase_id`) | 200 | unchanged |
| `api/reqtcassign` init (same TC) | 404 clean | unchanged |
| #1705 twin method | guarded | untouched, guard intact |
| `events` / server-log fatals | 3 / 1 (pre-fix repro) | 3 / 1 — no new Error/Warning |

Commits: `875b44976` (fix), `cacde11b5` (regression suite) on `fix/issue-1708`.
