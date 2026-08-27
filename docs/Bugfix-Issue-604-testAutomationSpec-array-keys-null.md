# Issue 604 — testAutomationSpec.php fatals on every request: array_keys(null) when get_last_active_version() returns null

**Issue:** [#604](https://github.com/sebiboga/testlink-upgraded/issues/604)
**Branch:** `fix/issue-604-array-keys-null` · **Commits:** `16ba44c5e` (fix, already merged via CI leftover)
**Status:** FIXED & VERIFIED (2026-08-27)

## Symptom

Any request to `lib/results/testAutomationSpec.php` (HTML or spreadsheet format) ended in HTTP 500:

```
PHP Fatal error: Uncaught TypeError: array_keys(): Argument #1 ($array) must be of type array, null given
in lib/functions/specview.php:860
```

This made the Test Automation Spec report completely unusable.

## Root cause chain

1. `genSpecViewFlat()` calls `getTestSpecFromNode()` in `lib/functions/specview.php:559`
2. The function reaches the `default` switch branch at line 867
3. **Pre-fix code:** `$tcvidSet = array_keys($tcversionSet);` — `$tcversionSet` came from `get_last_active_version()` which returns `null` when no test case in the project has an ACTIVE version
4. PHP 8+ made `array_keys(null)` a **fatal TypeError** (was a warning in PHP 7)

**Why it breaks now:** Legacy TestLink 1.9.20 ran on PHP 5/7 where `array_keys(null)` was a non-fatal warning; this repo runs PHP 8.3 with strict type checking.

### Blast radius

The `default` case is the catch-all for any filter combo that doesn't match the specific `case` branches above it. Any report screen calling `genSpecViewFlat()` with a project that has TCs but no active versions would have hit this fatal. Primary caller: `testAutomationSpec.php` (line 34-36).

## Approach — null/empty guard before array_keys

Chosen fix (commit `16ba44c5e`): Added explicit null/empty check before `array_keys()`, treating null/empty `$tcversionSet` as "nothing to filter" (empty result set), consistent with how sibling branches handle empty sets.

Changes at `lib/functions/specview.php`:
- **Line 872:** `$emptySet = is_null($tcversionSet) || count($tcversionSet) == 0;`
- **Line 873:** `$tcvidSet = $emptySet ? array() : array_keys($tcversionSet);`
- **Line 880:** `if (!$emptySet && $useFilter['execution_type'])` — skip filter when empty

**Rejected alternative:** Wrapping with try/catch — would hide real errors instead of treating the legitimate empty-set case properly.

## Verification matrix

| # | Check | Result |
|---|---|---|
| R1 | HTML view: `testAutomationSpec.php?tplan_id=<empty>&tproject_id=<id>` | PASS — HTTP 200, no fatal |
| R2 | XLS export: `testAutomationSpec.php?tplan_id=<empty>&tproject_id=<id>&do_report=1&exportToXLS=1` | PASS — HTTP 200, no fatal |
| R3 | `php -l lib/functions/specview.php` | PASS — clean syntax |
| R4 | Events table: no new Error/Warning entries after accessing report | PASS |
| R5 | grep for "Fatal error" / "array_keys" in response bodies | PASS — 0 hits |

Full suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #604" (4/4 PASS).

## Files changed

- `lib/functions/specview.php` (null guard added, already in codebase via commit `16ba44c5e`)
- `tmp/TLU_Test_Cases.md` (Suite 604)
- this page + `docs/Bugfix-Issue-604-testAutomationSpec-array-keys-null.md`
