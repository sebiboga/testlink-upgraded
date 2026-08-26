# Bugfix Issue #756: Broken Legacy Redirects in Report/Execution Screens

## Problem

Multiple modernized HTML screens still contained hardcoded references to legacy PHP files instead of their modernized HTML equivalents. One reference was critically broken (404):

- **`tcasesWithCF.html`**: `openExecHistory()` linked to `/lib/requirements/reqMilestones.php` which does not exist → 404 on every click.

Additionally, 7 other references pointed to legacy PHP pages (`archiveData.php`, `execHistory.php`) that still function but are not the modernized versions.

## Root Cause

During the 1.9.20→2.0.1 modernization, PHP pages were replaced with HTML equivalents, but JavaScript references in consuming HTML files were not all updated. The pattern was established in `resultsMatrix.html` (lines 347-350) which correctly used `execHistory.html` and `tcView.html`.

## Fix

7 URL references updated across 5 HTML files:

| File | Old target | New target |
|---|---|---|
| `tcasesWithCF.html:102` | `reqMilestones.php` (404) | `execHistory.html` |
| `tcasesWithCF.html:108` | `archiveData.php` | `tcView.html` |
| `tplanWithCF.html:97` | `archiveData.php` | `tcView.html` |
| `assignedTcOverview.html:218` | `archiveData.php` | `tcView.html` |
| `assignedTcOverview.html:219` | `execHistory.php` | `execHistory.html` |
| `tcAssignments.html:362` | `execHistory.php` | `execHistory.html` |
| `tcAssignments.html:368` | `archiveData.php` | `tcView.html` |
| `showNewestTcVersions.html:211` | `archiveData.php` | `tcView.html` |

## Not Changed (out of scope)

- `execSetResults.php` links — no modernized execution screen exists yet
- `tcAssignedToUser.php` — server-driven URL from API
- `printDocument.php`, `execPrint.php`, `editExecution.php` — no modernized replacements exist

## Verification

- JS evaluation in browser confirmed `openExecHistory()` and `openTCEdit()` now return correct URLs
- Both target pages (`execHistory.html`, `tcView.html`) load correctly with the parameter contracts
- No Event Viewer errors introduced
- 6 regression test cases written and all PASS
- Code review: ALL PASS (correctness, consistency, missing changes, risk)

## Commit

`f74abfff3` — fix: replace broken/legacy PHP URLs with modernized HTML targets in 5 screens
