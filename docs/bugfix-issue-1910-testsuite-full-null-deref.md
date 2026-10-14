# Bugfix Issue #1910 — `testsuite` `'full'` detail branches dereferenced a `null` `get_last_version_info()` for a versionless test-case node

Class of defect: the `details='full'` walkers of the test-suite manager assumed every test-case
tree node always has a `tcversions` row. A node with no version row makes
`testcase::get_last_version_info()` return `null`, and the next statement dereferenced it. This is
the direct follow-up of #1908, which fixed the sibling `getExternalID()` `list()` destructure in the
`simple` branch of the same method; the issue was filed there as out-of-scope.

## Symptom

`testsuite::get_testcases_deep()` (`lib/functions/testsuite.class.php:902`) and
`testsuite::get_children_testcases()` (`:964`) enumerate a suite's test-case nodes and, in the
`details='full'` branch, do:

```php
$item=$tcase_mgr->get_last_version_info($value['id'],array('output' => 'full', 'get_steps' => true));
$item['tcversion_id']=$item['id'];
```

When a test-case tree node has **no `tcversions` row**, `get_last_version_info()` returns `null`
(`lib/functions/testcase.class.php:2559-2568`), so PHP 8 emits one
`E_WARNING "Trying to access array offset on null"` per call at `log_level=2` (Event Viewer
pollution). The same statement also auto-vivifies `$item` to `['tcversion_id' => null]`, so the
orphan node leaks into the `full` result set as a degenerate row with no real data.

Reachable via:
- XML-RPC `getTestCasesForTestSuite` with `details='full'` — both the deep and the children walker
  (`lib/api/xmlrpc/v1/xmlrpc.class.php:2388-2403`).
- the dev harness `lib/functions/code_testing/testsuite.class.test.php:80`.

The default UI paths (`api/suiteedit/index.php:778`, `lib/testcases/containerEdit.php:792/1334/1457`)
pass `simple`/`only_id`, so they were unaffected.

## Root cause

1. `lib/functions/testcase.class.php:2548-2557` — `get_last_version_info()` computes
   `MAX(version)` over the node's `tcversions` rows; with none, the result is falsy.
2. `testcase.class.php:2559-2568` — `$tcInfo` stays `null` and is returned (`:2576`).
3. `testsuite.class.php:902` — `$item['tcversion_id']=$item['id'];` on `null` → PHP 8
   `E_WARNING "Trying to access array offset on null"`; the node is then emitted.
4. `testsuite.class.php:964` — the identical statement in `get_children_testcases()`.

PHP 8 turned "array offset on null" into a warning; TestLink's `watchPHPErrors` handler logs it at
`log_level=2` into the `events` table. The guard was missing because `testcase::create()` always
writes a `tcversions` row, so the condition was assumed impossible.

## Fix (minimal, 2 sites)

In both `full` loops, skip a null result — a node with no version has no complete info to return:

```php
$item=$tcase_mgr->get_last_version_info($value['id'],array('output' => 'full', 'get_steps' => true));
// get_last_version_info() returns null for a tree node with no
// tcversions row; the 'full' contract cannot be fulfilled for it, so
// skip it instead of dereferencing null (issue #1910).
if( is_null($item) ) {
  continue;
}
$item['tcversion_id']=$item['id'];
```

This mirrors the #1908 guard convention: guard at the dereference site, leave the shared
`get_last_version_info()` contract alone.

**Alternative rejected:** synthesizing an empty result in `get_last_version_info()` would change its
contract for every caller (XML-RPC, test-case edit, export) — a far larger blast radius than the
reported two-site defect warrants.

## Verification (fresh DB, fixture `php tmp/fixtures_1910.php`)

`tproject=1 suite=2`, `TC1910-OK` node 3 (tcversions 4), `TC1910-NOVER` node 6 (tcversions row 7
deleted → no version). Driver `php tmp/repro_1910.php 2`; Event-Viewer check `php tmp/events_1910.php`.

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | pre-fix `get_testcases_deep(2,'full')` | 1 `E_WARNING` @ 902 | 1 row @ 902 | PASS |
| 2 | pre-fix `get_children_testcases(2,'full')` | 1 `E_WARNING` @ 964 | 1 row @ 964 | PASS |
| 3 | pre-fix events table (`watchPHPErrors`) | >0 Error/Warning | `new_events=2` | PASS |
| 4 | post-fix `get_testcases_deep(2,'full')` | 0 warnings, rows=1 | 0 warnings, rows=1 | PASS |
| 5 | post-fix `get_children_testcases(2,'full')` | 0 warnings, rows=1 | 0 warnings, rows=1 | PASS |
| 6 | post-fix events table | 0 new | `new_events=0` | PASS |
| 7 | `get_testcases_deep(2,'simple')` | 0 warnings, rows=2 (unchanged) | 0 warnings, rows=2 | PASS |
| 8 | positive control `TC1910-OK` | `tcversion_id=4`, `version=1` | exactly | PASS |
| 9 | `get_testcases_deep(2,'only_id')` | `3,6` | `3,6` | PASS |
| 10 | `php -l lib/functions/testsuite.class.php` | no syntax errors | No syntax errors detected | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1910" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1910` in `tmp/TLU_Test_Cases.md` (10 cases).

## Files changed

- `lib/functions/testsuite.class.php` — null guard in both `full` branches (`get_testcases_deep()`
  and `get_children_testcases()`).
- `CHANGELOG` — 2.0.1 key-bugfix line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + GitHub Wiki page.
