# Bugfix Issue #1908 — `testsuite::get_testcases_deep()`: unguarded `getExternalID()` `list()` destructure logged 4 `E_WARNING` for a versionless test-case node

Class of defect: the same unguarded PHP 8 `list()` destructure over the empty array that
`testcase::getExternalID()` contractually returns for a test case **with no version**, fixed for
`buildDirectWebLink()` in #1863 but left latent in the test-suite deep walker. The suite view/export
stayed healthy (HTTP 200); the only symptom was Event-Viewer pollution — 4 `E_WARNING` rows per call.

## Symptom

`testsuite::get_testcases_deep()` (`lib/functions/testsuite.class.php:843`) enumerates a suite's
subtree and, in the non-`only_id` path (`:872`), destructures the 4-element tuple returned by
`testcase::getExternalID()` with a bare `list()`. When a test-case tree node has **no `tcversions`
row**, `getExternalID()` returns `[]` (`lib/functions/testcase.class.php:5733-5736`), so PHP 8
emits one `E_WARNING` per missing key — 4 per call, all at `log_level=2`:

```
E_WARNING  Undefined array key 0 - in .../lib/functions/testsuite.class.php - Line 872
E_WARNING  Undefined array key 1 - in .../lib/functions/testsuite.class.php - Line 872
E_WARNING  Undefined array key 2 - in .../lib/functions/testsuite.class.php - Line 872
E_WARNING  Undefined array key 3 - in .../lib/functions/testsuite.class.php - Line 872
```

Every flow that enumerates a suite's test cases hits it: the legacy Test-Spec container
(`lib/testcases/containerEdit.php:792`), the modern BFF `api/suiteedit/index.php:778`, and the
XML-RPC `getTestCasesForTestSuite`.

## Root cause

1. `lib/functions/testsuite.class.php:872` — `list($identity,$prefix,$glueChar,$external) =
   $tcase_mgr->getExternalID($elem['id'],null,$prefix);` — no guard on the returned array.
2. `lib/functions/testcase.class.php:5717` `getExternalID()` → `:5733` calls
   `get_last_version_info($id, ['output'=>'minimun'])`; for a node with zero `tcversions` the
   result is `null` and `getExternalID()` deliberately returns `[]` (`:5733-5736`).
3. PHP 8 raises one `E_WARNING "Undefined array key N"` per missing `list()` element (4 keys →
   4 warnings); TestLink's error handler logs each at `log_level=2`.

Note: merely **deactivating** the last version does **not** trigger this — `get_last_version_info()`
has no `active` filter (`testcase.class.php:2553`) and still returns the row. The trigger is a node
with **no version row at all** (orphan/consistency break, failed version insert).

## Fix (minimal, 1 site)

`lib/functions/testsuite.class.php:872` — capture the array, guard on `isset($ext[3])` before
assigning, and default the external id to `''`; the `$prefix` carry-over that skips `getPrefix()`
on later nodes is preserved:

```php
$ext = $tcase_mgr->getExternalID($elem['id'],null,$prefix);
if (isset($ext[3])) {
  $identity = $ext[0];
  $prefix = $ext[1];
  $glueChar = $ext[2];
  $external = $ext[3];
} else {
  $identity = '';
}
$elem['external_id'] = $identity;
```

This mirrors the #1863 guard (`buildDirectWebLink()`) and the pre-existing
`markdown_tc_export.class.php:322` guard — the repository convention for this contract.

**Alternative rejected:** changing the shared `getExternalID()` contract (e.g. always returning a
4-tuple) would touch every consumer at once (`testcase.class.php:10438/10547`,
`lib/testcases/testcaseCommands.class.php:211`, `xmlrpc.class.php:3949`,
`lib/testcases/containerEdit.php:1343`, plus the #1863 guard) — a larger blast radius than the
reported single-site defect warrants; the repository convention is a guard at the destructuring
site.

**Out of scope, filed separately:** the `details='full'` branch of the same method
(`testsuite.class.php:902`) and `get_children_testcases()` (`:964`) still dereference a `null`
`get_last_version_info()` for the same input — filed as **#1910**.

## Verification (fresh DB, fixture `php tmp/fixtures_1908.php`)

`tproject=9 suite=10`, `TC1908-OK` node 11 (ext `TC1908-1`), `TC1908-NOVER` node 14 (tcversions row
15 deleted → no version).

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | pre-fix: `GET api/suiteedit/index.php?action=init&mode=delete&suite_id=10&tproject_id=9` then scan `events` | 4 × `E_WARNING` `Undefined array key 0..3` @ line 872 | exactly 4 rows @ 872 | PASS |
| 2 | post-fix: same request | HTTP 200, 0 new events | HTTP 200, 0 rows | PASS |
| 3 | `get_testcases_deep(10,'simple')` | node 14 `external_id=''`, node 11 `TC1908-1` | exactly | PASS |
| 4 | `get_testcases_deep(10,'only_id')` | `[11,14]` | `11,14` | PASS |
| 5 | `php -l lib/functions/testsuite.class.php` | no syntax errors | No syntax errors detected | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1908" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1908` in `tmp/TLU_Test_Cases.md` (9 cases).

## Files changed

- `lib/functions/testsuite.class.php` — guard in `get_testcases_deep()` (landed as `d49dd373d`).
- `CHANGELOG` — 2.0.1 key-bugfix line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only, `fb7edd15a`).
- `docs/` mirror + GitHub Wiki page.
