# Bugfix Issue #744: Edit project fails with "Error saving project"

## Summary

In the modernized Test Project Management screen (`projectsView.html`), clicking **Edit** on any project, changing the name, and clicking **Save** produced a generic "Error saving project" alert despite the project actually being updated in the database.

## Root Cause

`api/projects/index.php:412` called `logAuditProject()` — a function that **does not exist** anywhere in the TestLink codebase. This caused a PHP Fatal Error (Call to undefined function) after the DB update had already succeeded, resulting in a 500 response with an empty body. The jQuery AJAX error handler at `projectsView.html:484-486` caught the 500 status and displayed the generic error alert.

The correct function used by every other BFF endpoint is `logAuditEvent()` (defined in `lib/functions/logging.inc.php:37`). Furthermore, lines 403-410 of the same file already properly log the same audit event via `logEvent()`, making the `logAuditProject()` call a redundant broken duplicate.

## Fix

Removed the broken `logAuditProject()` call from `api/projects/index.php:412`. The audit logging is already properly handled by the `logEvent()` block at lines 403-410.

## Files Changed

- `api/projects/index.php` — removed 1 line (`logAuditProject()` call)

## Verification

- Edit project name: succeeds without error, modal closes, list refreshes
- Toggle active state: succeeds
- Edit inactive project: succeeds
- Event Viewer: no new Error/Warning entries
- Browser console: no errors
