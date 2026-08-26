# Bugfix: Issue #743 — Execution By Date/Time report fails with "Document generation error"

## Problem

Clicking **Reports → Execution By Date/Time** in the sidebar menu triggered a "Document generation error: the test plan is missing, unknown or does not belong to the selected test project."

## Root Cause

The sidebar menu's `else` clause in `lib/general/asideMenu.php` (lines 184-186) built legacy report URLs without the required `format`, `tproject_id`, and `tplan_id` query parameters. When the user clicked the report link, `execTimelineStats.php` received no parameters, `initArgsForReports()` parsed `tplan_id = NULL`, `get_by_id(NULL)` returned null, and the error was triggered at `displayMgr.php:76-78`.

Every modernized report entry (lines 68-183) explicitly appended these parameters. The `else` fallthrough for non-modernized reports did not.

## Fix

**File:** `lib/general/asideMenu.php:184-186`

```php
} else {
    $sep = (strpos($rptItem['url'], '?') !== false) ? '&' : '?';
    $hrefR = $baseHrefR . $rptItem['url'] .
             "{$sep}format=0&tproject_id={$tprojectID}&tplan_id={$tplanID}";
}
```

- Uses `strpos()` to choose `?` or `&` as separator (some legacy report URLs already contain `?type=...`).
- `format=0` is required because `displayMgr.php:87-90` exits if `format` is NULL.
- Integer values are safely `intval()`-cast at lines 28/42, so no XSS/injection risk.

## Affected Reports

The fix benefits all non-modernized reports in the `else` clause:
- `execTimelineStats.php` (Execution By Date/Time) — primary bug target
- `freeTestCases.php` (Free Test Cases) — params harmlessly ignored
- `resultsReqs.php` (Requirements Coverage) — reads from session
- `resultsByStatus.php` (Failed/Blocked/Not Run) — pre-existing title-mismatch routes to else clause
- `resultsBugs.php` (Bugs reports) — same pre-existing issue

## Commit

`b0401e3cc` — fix(asideMenu): append format/tproject_id/tplan_id to legacy report URLs
