# Bugfix #1859 — `tcEdit.php:327` `key(get_last_active_version())` fatal (HTTP 500) for a test case with no active version

## Symptom

The legacy controller `lib/testcases/tcEdit.php` answered **HTTP 500 with an empty
body** — uncaught PHP 8 `TypeError` — for any deep link that reaches it without an
explicit `tcversion_id` when the target test case has **no active version** (all
`tcversions.active = 0`) or when the `tcase_id` does not exist. The fatal came from
`init_args()` at `tcEdit.php:327`, which runs on **every** request before any action
dispatch, and wrote no row into the Event Viewer (`events`) — only the server error
log got a line.

## Repro (measured, PHP 8.3.35)

Fixture (`tmp/fixtures_1859.php`): tproject `TC Edit 1859`, suite, `TC1859-NOACTIVE`
(only version deactivated via `UPDATE tcversions SET active=0`), `TC1859-ACTIVE`
(control). Admin session via cookie jar.

| URL | Result |
|---|---|
| `tcEdit.php?tcase_id=<no-active>` | **500**, 0 bytes, `key(): Argument #1 ($array) must be of type array, null given in lib/testcases/tcEdit.php:327` |
| `tcEdit.php?edit_tc=1&tcase_id=<no-active>` | **500**, 0 bytes, same |
| `tcEdit.php?tcase_id=999999` (absent) | **500**, 0 bytes, same |
| `tcEdit.php?edit_tc=1&tcase_id=<active>` (control) | 200, editor renders (unchanged) |

## Root cause

`lib/functions/testcase.class.php:6182` `get_last_active_version()` joins
`tcversions ... AND TCV.active = 1`; with no active row the result set is empty, and
`lib/functions/database.class.php:666` `fetchRowsIntoMap()` returns its initial
`$items = null` for a 0-row result → the method returns **null**. `key(null)` is a
PHP 8.3 `TypeError`. The line is dead code inherited from upstream TestLink
(`$nu` was assigned since 1.9.18 and never read — `git log -L 325,330`), so leaving
`tcversion_id = 0` (which downstream treats as `testcase::ALL_VERSIONS`) is the
correct, behaviour-preserving outcome.

## Why this fix

Minimal guard at `lib/testcases/tcEdit.php:325-331`, the same null-guard family already
applied for this exact defect elsewhere: `(array)` casts at
`requirement_spec_mgr.class.php:2639` / `:2763` (#1705, #1708), `is_null()` guards at
`specview.php:871` and `xmlrpc.class.php:6461`; `is_array()` matches the precedent at
`api/reqtcassign/index.php:438`. (Related open issue: #1858.)

```php
if( $args->tcversion_id == 0 && $args->tcase_id > 0 ) {
    // get latest active version
    // get_last_active_version() returns NULL when the test case has no active
    // version (or does not exist) -> key() would throw a PHP 8 TypeError (#1859)
    $lastActiveVersion = $tcaseMgr->get_last_active_version($args->tcase_id);
    $nu = is_array($lastActiveVersion) ? key($lastActiveVersion) : null;
}
```

Alternatives rejected:

- `key((array)$...)` cast only — equivalent, but the explicit `is_array()` documents
  the null case and matches the #1708/#1858 pattern.
- setting `$args->tcversion_id = $nu` — rejected: `$nu` has been dead since 1.9.18,
  so `tcversion_id` currently stays `0` everywhere; changing it would alter which
  version is edited.
- adding an error/redirect for "no active version" — rejected: the control URL
  (without `doAction`) has always answered `200`, parity is the correct expectation.

## Files changed

- `lib/testcases/tcEdit.php` (init_args, +4/-1)
- `CHANGELOG`, `docs/Bugfix-Issue-1859-tcEdit-NoActiveVersion-500.md` (this file)
- `tmp/TLU_Test_Cases.md` — suite "Regression — Issue #1859" (7/7 PASS)
- `tmp/fixtures_1859.php`, `tmp/verify_1859.sh` (repro + executable regression),
  `tmp/repro_1859_req.php` (evidence for sibling #1861)

## Verification (live, `bash tmp/verify_1859.sh` → PASS=9 FAIL=0)

| Case | Before | After |
|---|---|---|
| `?tcase_id=<no-active>` | 500, 0 bytes | **200** |
| `?edit_tc=1&tcase_id=<no-active>` | 500, 0 bytes | **200**, editor renders the test case |
| `?tcase_id=999999` (absent) | 500, 0 bytes | **200** |
| control `?edit_tc=1&tcase_id=<active>` | 200 | unchanged (200) |
| deactivate-last-version → edit | fatal | **200**, renders |
| `events` / server log | fatal lines only | no new Error; only the 4 documented #1863 warnings for the absent-id case |

`TLU_REQUIRE_SUITE="Issue #1859" bash ai/verify_test_suites.sh` → exit 0 (no suite
lost vs the merge base).

## Related bugs filed during this run (not fixed here)

- **#1860** — activate/deactivate/create-version flows render 500 from a Smarty
  Compiler error in `gui/templates/dashio/include/attachments.inc.tpl:106`.
- **#1861** — `requirement_mgr.class.php:1119` `current()` of a null
  `get_last_active_version()` (the BFF `assign-reqs` path).
- **#1862** — `testcase.class.php:5105` `count()` fatal when the **edit action** is
  used for an absent `tcase_id`.
- **#1863** — 4 `E_WARNING Undefined array key 0..3` per absent-id load from the
  `buildDirectWebLink()` `list()` destructure (`testcase.class.php:5700`).
- **#1864** — `testcase::addRelation()` (`testcase.class.php:8199-8200`, found in
  post-fix review): when the source test case has no active version,
  `get_last_active_version()` returns null, `intval(null)=0` is written into
  `testcase_relations.source_id` — verified: returns `status_ok=true` + bad row
  `(source_id=0, destination_id=53)` + 2 `E_WARNING` rows in `events`.

Commits on `fix/issue-1859`: `d1dfc415b` (fix), `de9876bf1` (regression suite),
`a6f1f9a82`+ (docs & CHANGELOG).