# Bugfix Issue #563: strftime Placeholders Rendered Literally in Document Header Date

**Issue:** [#563](https://github.com/sebiboga/testlink-upgraded/issues/563)  
**Date:** 2026-08-26  
**Branch:** `fix/issue-563-strftime-doc-header`

## Symptom

Every generated document (Requirements, Test Specs, Test Plan docs) showed a broken
print date in the header: `%26/%18/%2026` instead of `26/08/2026`.

## Root Cause

`tlStrftime()` in `lib/functions/php81_compat.php` used `IntlDateFormatter` on
PHP 8.1+ with intl extension. `IntlDateFormatter` expects ICU date patterns
(e.g. `dd/MM/yyyy`), NOT strftime patterns (e.g. `%d/%m/%Y`). Passing strftime
patterns to `IntlDateFormatter` silently produced wrong output.

The correct fallback path (converting strftime to `date()` format) was already
present but never reached because `IntlDateFormatter` did not throw an exception.

## Fix

Removed the broken `IntlDateFormatter` code path from `tlStrftime()`. The function
now uses only the strftime-to-`date()` conversion, which correctly handles all
strftime specifiers.

Additional fixes from code review:
- Added missing `%W` → `W` mapping (used by `reqOverview.php`)
- Moved `%%` literal-percent replacement to top of array to prevent corruption
- Updated docblock to reflect actual implementation

## Files Changed

- `lib/functions/php81_compat.php` — `tlStrftime()` function rewritten

## Blast Radius

15+ call sites across the codebase use `tlStrftime()` with strftime format strings.
ALL of them now produce correct output on PHP 8.1+ with intl:
- `lib/functions/print.inc.php:753` — document headers
- `lib/functions/date_api.php:138` — all date displays
- `lib/functions/lang_api.php:390,413` — localized date strings
- `lib/functions/testcase.class.php:601,8472` — test case timestamps
- `lib/results/` — various report date selectors
- `lib/requirements/reqOverview.php:212` — requirement overview dates
- `lib/codetrackerintegration/stashrestInterface.class.php:346` — integration dates

## Verification

- `php -l lib/functions/php81_compat.php` — no syntax errors
- CLI test: `tlStrftime('%d/%m/%Y', time())` returns correct `26/08/2026`
- All strftime specifiers tested: `%d`, `%m`, `%Y`, `%H`, `%M`, `%S`, `%W`, `%c`
- Event Viewer: no new Error/Warning entries from the fix
