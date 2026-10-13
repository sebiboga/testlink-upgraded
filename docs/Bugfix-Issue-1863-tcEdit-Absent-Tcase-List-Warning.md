# Bugfix Issue #1863 — `tcEdit.php`: absent `tcase_id` logged 4 `E_WARNING` from `buildDirectWebLink()` `list()` destructure

Class of defect: an unguarded PHP 8 `list()` destructure over the empty array that
`testcase::getExternalID()` contractually returns for a test case that does not exist.
The page itself was healthy (HTTP 200, 0 bytes); the only symptom was Event-Viewer pollution —
4 `E_WARNING` rows per request, forever, for any absent id.

## Symptom

Opening the test-case editor for an **absent** `tcase_id`
(`GET /lib/testcases/tcEdit.php?tcase_id=999999`) returned HTTP 200 with an empty body but wrote
4 rows to the `events` table, all at `log_level=2`:

```
E_WARNING  Undefined array key 0 - in .../lib/functions/testcase.class.php - Line 5700
E_WARNING  Undefined array key 1 - in .../lib/functions/testcase.class.php - Line 5700
E_WARNING  Undefined array key 2 - in .../lib/functions/testcase.class.php - Line 5700
E_WARNING  Undefined array key 3 - in .../lib/functions/testcase.class.php - Line 5700
```

## Root cause

1. `lib/testcases/tcEdit.php:647-652` — whenever `$argsObj->tcase_id > 0`, the editor builds a
   context object and calls `$tcaseMgr->buildDirectWebLink($webLinkContext)`.
2. `lib/functions/testcase.class.php:5692` `buildDirectWebLink()` → `:5700`
   `list($external_id,$prefix,$glue,$tc_number) = $this->getExternalID($id,$tproject_id);`
   — a 4-element `list()` destructure with **no guard** on the returned array.
3. `lib/functions/testcase.class.php:5717` `getExternalID()` → `:5734-5735`
   `if (is_null($info)) { return []; }` — a test case that does not exist has no
   `get_last_version_info()`, so the method deliberately returns an **empty array**.
4. PHP 8 raises one `E_WARNING "Undefined array key N"` per missing `list()` element
   (4 keys → 4 warnings); TestLink's error handler logs each at `log_level=2`.

This is latent and independent of the page logic: the empty-array contract of
`getExternalID()` is long-standing, but PHP 7 reported the missing keys only as a notice,
whereas PHP 8 reports each as an `E_WARNING`.

## Fix (minimal, 1 site)

`lib/functions/testcase.class.php:5700-5707` — capture the array and return `''` when it is not a
full 4-tuple before destructuring:

```php
$ext = $this->getExternalID($id,$tproject_id);
// getExternalID() returns an empty array for a test case that does not exist;
// destructuring it with list() would emit E_WARNING 'Undefined array key 0..3'
// on PHP 8 (issue #1863). There is no link to build in that case.
if (!isset($ext[3])) {
  return '';
}
list($external_id,$prefix,$glue,$tc_number) = $ext;
```

This mirrors the guard already used by `lib/functions/markdown_tc_export.class.php:322`
(`isset($arr[3]) && intval($arr[3]) > 0`), the repository convention for this contract.

**Alternative rejected:** making `getExternalID()` always return a 4-tuple would change the
empty-detection semantics its direct `[0]` consumers rely on
(`testcase.class.php:10439/10548`, `testcaseCommands.class.php:213`, `xmlrpc.class.php:3647/3950`,
`containerEdit.php:1343`) — a larger blast radius than the reported defect warrants.

## Verification (fresh DB, fixture `php tmp/fixtures_1859.php`)

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | pre-fix (guard reverted): `GET tcEdit.php?tcase_id=999999` then scan `events` | 4 × `E_WARNING` `Undefined array key 0..3` @ line 5700 | exactly 4 rows @ 5700 | PASS |
| 2 | post-fix: same request | HTTP 200, 0 bytes, 0 new events | http=200 bytes=0, 0 rows | PASS |
| 3 | `GET tcEdit.php?tcase_id=3` (no active version) | 200, 0 new events | 200 0, 0 rows | PASS |
| 4 | `GET tcEdit.php?edit_tc=1&tcase_id=6` (active) | 200, renders `TC1859-ACTIVE`, 0 new events | 200 18686, name present | PASS |
| 5 | `buildDirectWebLink(id=6,tp=1)` | valid link | `/linkto.php?tprojectPrefix=TC1859&item=testcase&id=TC1859-2` | PASS |
| 6 | `buildDirectWebLink(id=999999,tp=1)` | `''` | `''` | PASS |
| 7 | `php -l lib/functions/testcase.class.php` | no syntax errors | No syntax errors detected | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1863" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1863` in `tmp/TLU_Test_Cases.md` (9 cases).

## Files changed

- `lib/functions/testcase.class.php` — guard in `buildDirectWebLink()` (landed as `e89fd57c0`).
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + GitHub Wiki page + `CHANGELOG` line.
