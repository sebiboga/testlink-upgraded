# Issue 664 — xmlrpc createTestProject E_WARNING "Undefined array key testprojectname/testcaseprefix" (lines 2230-2231)

**Issue:** [#664](https://github.com/sebiboga/testlink-upgraded/issues/664)
**Branch:** `fix/issue-664` · **Commits:** `fcf0370cb` (fix), `b933f0b23` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Calling `tl.createTestProject` without `testprojectname` / `testcaseprefix`, or with
misnamed members (`name`/`prefix` — a real-world client mistake), returned the correct
application error codes but ALSO wrote two E_WARNING rows per call into the Event Viewer:

```
E_WARNING Undefined array key "testprojectname" - lib/api/xmlrpc/v1/xmlrpc.class.php:2230
E_WARNING Undefined array key "testcaseprefix"  - lib/api/xmlrpc/v1/xmlrpc.class.php:2231
```

Reproduced live against http://localhost:8082 (events table was empty; the single
repro call added exactly ids 1–2, both `log_level=2`, fired 10:35:44).

## Root cause chain

1. `createTestProject()` (xmlrpc.class.php:2136) delegates validation to
   `_checkCreateTestProjectRequest()` (:2228).
2. Lines 2230–2231 read the two required params **directly, with no presence guard**:

   ```php
   $name   = $this->args[self::$testProjectNameParamName];
   $prefix = $this->args[self::$testCasePrefixParamName];
   ```

   An absent key ⇒ PHP 8 `E_WARNING "Undefined array key"` ⇒ TestLink logs it to the
   `events` table ⇒ Event Viewer pollution on every malformed/misnamed request.
3. The API contract itself was never broken: with `$name = null`,
   `tprojectMgr::checkNameSintax(null)` (`lib/functions/testproject.class.php:886`)
   evaluates `null == ""` ⇒ true ⇒ `IXR_Error 7001`; and `!empty($prefix)` on null ⇒
   false ⇒ `IXR_Error 7004`. Only the log noise was wrong.

**Why it breaks now:** TestLink 1.9.x ran under PHP 5/7 where absent-key reads were
silent notices; this repo runs PHP 8 where they are logged E_WARNINGs.

## Blast radius

- Single method: `_checkCreateTestProjectRequest()` is called only from
  `createTestProject()` (grep: 1 call site, dynamic dispatch at :2142).
- Other required-param paths guard presence via check helpers
  (`_isParamPresent()`, `isset()`, or upstream validators such as
  `_isTestSuiteIDPresent()`); the two reads fixed here had none.

## Approach — minimal null-safe reads

Chosen fix (+2/−2 lines, zero contract change):

```php
$name   = $this->args[self::$testProjectNameParamName] ?? null;
$prefix = $this->args[self::$testCasePrefixParamName] ?? null;
```

`null` flows into the existing validation exactly like the previous undefined-key read,
minus the warning — responses stay byte-identical (7001 / 7004 / 7002 paths untouched).

Rejected alternative: `_isParamPresent(..., setError=true)` emitting a generic
`MISSING_REQUIRED_PARAMETER` IXR_Error — rejected because it would CHANGE the observable
error codes (7001/7004 today); the issue explicitly states responses are correct,
event-log hygiene only. Suppressing warnings at handler level was also rejected
(hides future bugs).

## Files changed

| File | Change |
|---|---|
| `lib/api/xmlrpc/v1/xmlrpc.class.php` | :2230-2231 null-coalescing reads (`?? null`) |
| `tmp/TLU_Test_Cases.md` | Suite 664 regression entry (6 cases) |

## Verification

- `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` PASS.
- Regression matrix re-run post-fix, events delta measured across ALL calls =
  only the level-16 audit `audit_testproject_created` (normal success event):

| # | Request | Response | New warnings |
|---|---|---|---|
| R1 | wrong names `name`/`prefix` | IXR_Error 7001 ✅ | 0 |
| R2 | required params omitted entirely | IXR_Error 7001 ✅ | 0 |
| R3 | valid name, empty prefix | IXR_Error 7004 ✅ | 0 |
| R4 | valid name+prefix | status=true, id=1, row in `testprojects` ✅ | 0 |
| R5 | duplicate existing name | IXR_Error 7002 TESTPROJECTNAME_EXISTS ✅ | 0 |

Regression suite: `Suite 664` in `tmp/TLU_Test_Cases.md` — 6/6 PASS.
