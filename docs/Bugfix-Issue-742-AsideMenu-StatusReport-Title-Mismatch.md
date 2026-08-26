# Bugfix Issue #742 — Aside Menu Status Report Title Mismatch

**Issue:** [#742](https://github.com/sebiboga/testlink-upgraded/issues/742)
**Branch:** `fix/issue-742-results-by-status-warning`
**Files changed:** `lib/general/asideMenu.php` (3 lines)

## Symptom

Clicking Reports → Not Runned Test Cases in the sidebar triggered an E_WARNING:

```
resultsByStatus.php(397): testproject->get_by_id(NULL)
```

The report page returned empty content (200 OK, 0 bytes). The same issue affected the Failed and Blocked status reports.

## Root Cause

The `asideMenu.php` sidebar compared `$rptItem['title']` against the config **array keys** (`list_tc_failed`, `list_tc_blocked`, `list_tc_not_run`) instead of the actual `title` field values from `cfg/reports.cfg.php` (`link_report_failed`, `link_report_blocked_tcs`, `link_report_not_run`).

Since none of these comparisons matched, all three reports fell through to the generic `else` clause (line 196), which constructed a URL to the **legacy** controller `lib/results/resultsByStatus.php`. The legacy controller then failed because `tproject_id` was NULL in certain access patterns.

## Fix

Updated three title comparisons in `lib/general/asideMenu.php:140-148`:

| Line | Before (wrong) | After (fixed) |
|------|----------------|---------------|
| 140 | `'list_tc_failed'` | `'link_report_failed'` |
| 143 | `'list_tc_blocked'` | `'link_report_blocked_tcs'` |
| 146 | `'list_tc_not_run'` | `'link_report_not_run'` |

This routes the sidebar links to the already-modernized screens (`gui/templates/results/resultsByStatus.html`) instead of the legacy controller, eliminating the E_WARNING.

## Verification

- All three status reports (Not Run, Failed, Blocked) load the modernized screen without warnings
- BFF API (`api/reports/index.php?action=by_status`) returns `status: ok` for all three
- PHP syntax check: no errors
- Code review: APPROVED
