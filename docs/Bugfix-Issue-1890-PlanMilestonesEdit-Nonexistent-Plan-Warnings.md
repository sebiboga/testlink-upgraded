# Issue #1890 — `planMilestonesEdit.php` with a non-existent `tplan_id` writes 5 E_WARNING rows (NULL dereferences)

**Issue:** [#1890](https://github.com/sebiboga/testlink-upgraded/issues/1890)
**Branch:** `fix/issue-1890-planMilestonesEdit-warnings`
**Status:** VERIFIED-FIXED — regression suite `Issue #1890` 5/5 PASS, suite gate 7/7
**Related:** [#1887](Bugfix-Issue-1887-PlanMilestonesEdit-CreateEdit-Warnings.md) (GET render warnings),
[#1888](Bugfix-Issue-1888-PlanMilestonesEdit-DoCreate-Milestone-Unset.md) (POST failure-path warnings),
[#1726](Bugfix-Issue-1726-PlanMilestonesEdit-Blank-200-Default-Render-Branch.md).

## Symptom

Opening the legacy Plan Milestones form with a `tplan_id` that does not exist (or a stale/deep-linked
id) returned HTTP 200 but wrote **5 `log_level=2` E_WARNING rows** into the Event Viewer, every request:
1 × `Trying to access array offset on null` at `lib/plan/planMilestonesEdit.php:96` and
4 × `Trying to access array offset on null` at `lib/functions/testplan.class.php:7322`.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo root), PHP 8.3.35,
DB `testlink` freshly imported (0 projects / 0 plans), `admin`/`admin`. Fixture
`tmp/fixtures_1890.php` recreated testproject `MS88` (id=1, priority enabled) and testplan
`MS88 Plan` (id=2) — the issue's repro was against the stale id `tplan_id=99999`, `tproject_id=5`.

| probe | result |
|---|---|
| `MAX(id) FROM events` before the repro | 2 |
| `GET ?doAction=create&tplan_id=99999&tproject_id=1` | HTTP 200 |
| `SELECT ... FROM events WHERE log_level=2 AND id>2` | **+5 rows** (ids 3-7) |

The 5 rows matched the issue exactly: id 3 at `planMilestonesEdit.php:96`, ids 4-7 at
`testplan.class.php:7322`.

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `lib/plan/planMilestonesEdit.php:87-96` | `$args->tplan_id = 99999`; `testplan::get_by_id(99999)` builds a `full` select. |
| 2 | `lib/functions/testplan.class.php:501` | `return ($rs ? $rs[0] : null);` → **null** for a non-existent id. |
| 3 | `lib/plan/planMilestonesEdit.php:96` | `$args->tplan_name = $info['name'];` reads on null → **1× warning** (event id 3). |
| 4 | `lib/plan/planMilestonesEdit.php:23` → `checkRights()` → `pageAccessCheck()` | the same 99999 flows into `tlUser::hasRight()` (4 invocations). |
| 5 | `lib/functions/tlUser.class.php:893` | `$accessPublic['tplan'] = $mgr->getPublicAttr($testPlanID);` (under `$getAccess`). |
| 6 | `lib/functions/testplan.class.php:7318-7322` | `SELECT is_public ... WHERE id=99999` → `database::get_recordset()` returns null for an empty result (`database.class.php:787,799`: `$output = null`). |
| 7 | `lib/functions/testplan.class.php:7322` | `return $ret[0]['is_public'];` → `$ret[0]` on null → **4× warning** (event ids 4-7). |

**Why it breaks now:** both reads are unguarded null-dereferences that are decade-old 1.9.20 code;
they were silent for years because PHP 7 tolerated null array access with a notice. PHP 8.3 promoted
the reads to `E_WARNING`, and TestLink persists every warning into `events`, so any deep link with a
stale/invalid `tplan_id` writes 5 rows per request.

## Blast radius

* `testplan::getPublicAttr()` callers: exactly **1** reachable site — `tlUser.class.php:893` inside
  `hasRight()`. The sibling `tlUser::getTprojectPublicAttr()` (`tlUser.class.php:821-844`) already
  documents the contract: unknown id ⇒ null ⇒ "no accessibility flag, caller keeps today's answer".
  The testplan accessor violated that contract by emitting warnings while ALSO returning null — so
  hardening it to null removes the noise without changing any caller's decision.
* `planMilestonesEdit.php:96` direct read: only that file's `init_args()`; `$args->tplan_name` feeds
  the template title only.

## The fix (minimal, 2 files, +7/-2)

1. `lib/plan/planMilestonesEdit.php:95-97` — guard the lookup:

```php
$info = $tplan_mgr->get_by_id($args->tplan_id);
// Refs #1890: get_by_id() returns null for a non-existent / stale tplan_id,
// so guard the read and fall back to an empty name instead of warning.
$args->tplan_name = is_array($info) && isset($info['name']) ? $info['name'] : '';
```

2. `lib/functions/testplan.class.php:7322` — return null on an empty recordset:

```php
return (is_array($ret) && isset($ret[0]['is_public'])) ? $ret[0]['is_public'] : null;
```

### Why this method (and what was rejected)

* **Guard the read (minimal) instead of changing `get_by_id()`.** `get_by_id()`'s contract of
  returning `null` for an unknown id is used defensively elsewhere; changing it to throw or return an
  empty array would ripple through its ~132 instantiations. Guarding the single deref in the caller is
  the exact blast radius of this bug.
* **Make `getPublicAttr()` return null.** It already returned null in practice — after raising the
  warning. Wrapping the shared accessor's `$ret[0]` read in a null-safe expression removes the noise
  at the source (4 rows per request) instead of special-casing `hasRight()`.
* **No template/Smarty changes.** `tplan_name` is only a title string; the form already falls back to
  the session/other context when it renders.

## After the fix

An invalid/stale `tplan_id` deep link renders the same HTTP-200 form with an empty `tplan_name` and
writes **zero** Event Viewer rows; a valid `tplan_id` behaves exactly as before.

## Verification

Regression suite `Issue #1890`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1890" bash ai/verify_test_suites.sh` → 7/7 PASS.

| case | before | after |
|---|---|---|
| `GET ?doAction=create&tplan_id=99999&tproject_id=1` | 200, **+5 warnings** | 200, **+0 warnings** |
| `GET ?doAction=create&tplan_id=2&tproject_id=1` (valid) | 200, +0 | 200, form renders, **+0** |
| `SELECT COUNT(*) FROM events WHERE id>7` (both requests) | — | 0 new events at ANY level |
| `GET /lib/plan/planMilestonesView.php?tproject_id=1&tplan_id=2` (sibling) | 200 | 200, **+0** |
| `php -l` both changed files | — | No syntax errors detected |

Screenshots: `docs/screenshots/issue-1890-invalid-tplan-form.png` (invalid tplan) and
`docs/screenshots/issue-1890-valid-tplan-form.png` (valid tplan).

## How to re-test in one command

```bash
php tmp/fixtures_1890.php          # tproject=1, testplan=2
# login admin/admin, then:
# GET http://localhost:8082/lib/plan/planMilestonesEdit.php?doAction=create&tplan_id=99999&tproject_id=1
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT COUNT(*) FROM events WHERE log_level=2 AND id>7;"  # expect 0
```