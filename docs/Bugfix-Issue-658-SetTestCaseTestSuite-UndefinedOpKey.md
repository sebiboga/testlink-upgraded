# Issue 658 — setTestCaseTestSuite(): prefix built from undefined $ret['operation'] key — renders as "() - "

**Issue:** [#658](https://github.com/sebiboga/testlink-upgraded/issues/658)
**Branch:** `fix/issue-658-prefix-from-undefined` · **Commits:** `bfeca3430` (fix), `d313ea9e1` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-25)

## Symptom

Every error response from `tl.setTestCaseTestSuite` carried the malformed prefix `"() - "` instead of `"(setTestCaseTestSuite) - "`. For example:

```
() - Can not authenticate client: invalid developer key
```

## Root cause chain

`lib/api/xmlrpc/v1/xmlrpc.class.php:8833-8840`:

1. Line 8833: `$ret[] = array("operation" => __FUNCTION__, ...)` — list-append syntax makes `$ret = [0 => ["operation" => "setTestCaseTestSuite", ...]]`.
2. Line 8839: `$operation = $ret['operation']` — reads string key `'operation'` from `$ret`, but only key is `0` (integer) → `$operation = null` (+ PHP 8 warning).
3. Line 8840: `$msgPrefix = "({$operation}) - "` → renders as `"() - "`.

Why it breaks now: PHP 8.x emits an E_WARNING for undefined array key reads; older PHP silently returned `null` without warning, but the result was always `"() - "`.

**Blast radius:** Only `setTestCaseTestSuite` was affected — this was the only place in the file using `$ret['operation']`. Four other methods use the correct `$msgPrefix = "(" . __FUNCTION__ . ") - "` pattern.

## Fix

Replaced lines 8839-8840:
```php
$operation = $ret['operation'];
$msgPrefix = "({$operation}) - ";
```
With the pattern used by 4 other methods:
```php
$msgPrefix = "(" . __FUNCTION__ . ") - ";
```

1 line changed, 2 lines removed. No other files touched.

## Verification matrix

| # | Check | Result |
|---|---|---|
| R1 | curl POST with invalid devKey → `(setTestCaseTestSuite) - Can not authenticate...` | PASS |
| R2 | curl POST with invalid devKey + testcaseid → same correct prefix | PASS |
| R3 | `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` → no syntax errors | PASS |
| R4 | Event Viewer: no new Error/Warning entries | PASS |

Full suite: `tmp/TLU_Test_Cases.md` → "Suite 688" (PASS).

## Files changed

- `lib/api/xmlrpc/v1/xmlrpc.class.php` (+1 / -2)
- `tmp/TLU_Test_Cases.md` (Suite 688)
- this page + `docs/Bugfix-Issue-658-SetTestCaseTestSuite-UndefinedOpKey.md`
