# Issue 458 — `setTestCaseExecutionType()`: missing-parameter error names `customfields` instead of `executiontype`

**Issue:** [#458](https://github.com/sebiboga/testlink-upgraded/issues/458)
**Branch:** `fix/issue-458` · **Commit:** `3478f3010` (fix, ±1 line)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Calling XMLRPC `tl.setTestCaseExecutionType` without the mandatory
`executiontype` parameter returned error code 200
(`MISSING_REQUIRED_PARAMETER`) with a message naming a parameter this method
never accepts:

```
Parameter customfields is required, but has not been provided
```

A client debugging against this message would go looking for a
`customfields` argument that does not exist on this method, while still not
sending the actually-missing `executiontype`. Reproduced live (not just by
code reading): fresh DB, fixtures created through the API itself — project
id=1 prefix `I458`, suite id=2, case id=3 = `I458-1` version 1; call with
`{devKey, testprojectid:1, testcaseexternalid:I458-1, version:1}` → response
above. Control run with `executiontype:2` succeeded normally.

## Root cause chain

1. `lib/api/xmlrpc/v1/xmlrpc.class.php:6629` — `setTestCaseExecutionType()`
   runs its standard checks (`authenticate`, `checkTestProjectID`,
   `checkTestCaseIdentity`, `checkTestCaseVersionNumber`).
2. `:6641` — presence check is **correct**: `_isParamPresent(
   self::$executionTypeParamName )`; `$executionTypeParamName =
   "executiontype"` declared at :160.
3. `:6643` — message token is **wrong**:
   `sprintf( MISSING_REQUIRED_PARAMETER_STR, self::$customFieldsParamName )`;
   `$customFieldsParamName = "customfields"` declared at :151.
4. Upstream copy-paste origin: the block was cloned from
   `updateTestCaseCustomFieldDesignValue()` (:6569-6573) where reporting
   `customfields` *is* legitimate — only the check token got renamed during
   cloning, not the message token. Present since the XMLRPC API was imported;
   not a modernization regression.

## Blast radius

Grep of every `_isParamPresent()` site that emits an error: 5 total.

| Line | Check token | Message token | Verdict |
|------|-------------|---------------|---------|
| 6569 | customfields | customfields | correct |
| 8263 | customfields | customfields | correct |
| 8358 | customfields | customfields | correct |
| 2749 | customfields (optional branch) | n/a | no error emitted |
| 6641/6643 | executiontype | customfields | **the only mismatch** |

No REST BFF or UI layer consumes this error path. Blast radius = one error
message.

## Approach — rename the message token (minimal)

One line at `xmlrpc.class.php:6643`:

```php
$msg = sprintf( MISSING_REQUIRED_PARAMETER_STR, self::$executionTypeParamName );
```

Rejected alternatives:
- renaming the static property declarations (pure churn, touches shared API);
- introducing a shared "require param" helper across all 5 sites
  (refactoring out of scope for a bug fix).

## Verification (Suite 458 — 6/6 PASS, see tmp/TLU_Test_Cases.md)

1. Pre-fix symptom documented: message named `customfields`.
2. Post-fix omitted-`executiontype` → `Parameter executiontype is required,
   but has not been provided` (code 200).
3. Happy path intact: `executiontype:1/2` succeed; SQL shows
   `UPDATE tcversions SET execution_type=… WHERE id = 4`.
4. Sibling `updateTestCaseCustomFieldDesignValue` without `customfields`
   still reports `Parameter customfields is required...`.
5. Event Viewer watermark unchanged (2 rows before/after whole suite run).
6. `php -l` clean; scripted grep confirms 6641/6643 now pair consistently.

## Side discovery

While building fixtures: XMLRPC `tl.createTestCase` silently drops step data
sent under the `expectedresults` key (only underscore spelling accepted) —
E_WARNING at `lib/functions/testcase.class.php:803`, empty `expected_results`
persisted despite input `"done"`. Filed as #661 (`bug`), out of scope here.
