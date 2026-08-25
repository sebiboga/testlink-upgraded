# Bugfix — Issue #516: E_WARNING Trying to access array offset on null in print.inc.php:1394

## Summary
Added null-safety guards in `renderTestCaseForPrinting()` to prevent PHP 8.x `E_WARNING` when `$tcInfo` keys (importance, estimated_exec_duration) or `$prio_info` results are null or missing.

## Root Cause
In `lib/functions/print.inc.php`, the `renderTestCaseForPrinting()` function accessed `$cfg["importance"][$tcInfo["importance"]]` without checking if `$tcInfo["importance"]` was null. PHP 8.x throws E_WARNING when accessing an array offset with a null key. Same pattern at lines 1407-1413 for priority access.

## Fix
- **Line 1387**: Added `$tcInfo['estimated_exec_duration'] ?? ''` null coalescing
- **Lines 1391-1395**: Extract `$impKey`, guard with `is_array()` + `!is_null()` + `?? ''`
- **Lines 1407-1408**: Guard `$prio_info` with `is_array()` + `isset()`
- **Line 1413**: Guard `$cfg['priority']` access with `is_array()` + `!is_null()`

## Files Changed
- `lib/functions/print.inc.php` (lines 1387, 1391-1395, 1407-1408, 1413)

## Testing
- `php -l lib/functions/print.inc.php` — no syntax errors
- HTTP 200 from tcPrint.php and printDocument.php with test data
- Zero E_WARNING entries in PHP server log after fix
- Regression test suite: 7/7 PASS (`tmp/TLU_Test_Cases.md`)
