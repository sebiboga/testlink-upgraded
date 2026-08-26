# Issue 648 — xmlrpc createTestSuite update: E_WARNING "Undefined array key testsuitename" when name omitted

**Issue:** [#648](https://github.com/sebiboga/testlink-upgraded/issues/648)
**Branch:** `fix/issue-648`
**Status:** FIXED & VERIFIED (2026-08-26)

## Symptom

Calling `tl.createTestSuite` with `action=update` without passing `testsuitename` produces an `E_WARNING` in the Event Viewer despite the operation succeeding:

```
E_WARNING Undefined array key "testsuitename" - lib/api/xmlrpc/v1/xmlrpc.class.php:4461
```

A secondary warning was also triggered:

```
E_WARNING Undefined variable $result - lib/functions/testsuite.class.php:253
```

Both warnings are pure log noise — `testsuite::update()` at `testsuite.class.php:237` guards `!is_null($name)` so null name means "don't change".

## Root cause chain

1. `createTestSuite()` builds `$opt` defaults for the `update` case at `xmlrpc.class.php:4382`: `self::$testSuiteNameParamName => null`.
2. Lines 4419-4423 populate `$opt` from `$this->args` when present — correct normalization.
3. But the code at lines 4438, 4461, and 4485 reads `$args[self::$testSuiteNameParamName]` from the **raw request struct** (the function parameter `$args`), not from the normalized `$opt`. When `testsuitename` is absent, this is an undefined key → E_WARNING.
4. In `testsuite.class.php`, when both `name` and `details` are null, no SQL UPDATE executes and `$result` is never assigned. Line 253 `if (!$result)` reads the undefined variable → secondary E_WARNING.

## Fix — isset-default normalization

Two edits:

1. `xmlrpc.class.php:4458-4460`: Added `isset`-default guard for `testsuitename`, mirroring the existing `$details` guard at lines 4454-4456:
   ```php
   if( !isset( $args[self::$testSuiteNameParamName] ) ) {
       $args[self::$testSuiteNameParamName] = null;
   }
   ```

2. `testsuite.class.php:219`: Initialized `$result = null;` at method entry. Changed line 253 to `if (!is_null($result) && !$result)` to avoid false error when no SQL executed.

No behavior change — update with null name skips the name SQL as before; update with a name works as before.

## Files changed

| File | Change |
|---|---|
| `lib/api/xmlrpc/v1/xmlrpc.class.php` | +3 lines: `isset`-default for `testsuitename` (:4458-4460) |
| `lib/functions/testsuite.class.php` | +1 line: `$result = null` init (:219); 1 line changed: null-safe check (:253) |
| `tmp/TLU_Test_Cases.md` | Suite 648 regression entry (6 cases) |

## Verification

- `php -l` passes for both files.
- Repro re-run: update WITHOUT `testsuitename` → 0 events (was: 2 E_WARNING entries).
- Regression: update WITH `testsuitename` → name changes, 0 events.
- Regression: create test suite → still works, 0 events.
