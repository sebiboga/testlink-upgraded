# Bugfix Issue #1536 — Legacy deep-link controllers 302-redirect to modern twins

**Issue**: #1536 (7x E_WARNING class on legacy deep-link views)
**Fix**: seen on branch `sebiboga`, commit `36783cb57` (stub) + `8e61c3866`/`968994a15` (docs).
**Status**: FIXED & verified — no new Error/Warning events recorded since (rule 13).

## Root cause
Legacy deep-link controllers `lib/requirements/reqSpecView.php` and
`lib/testcases/archiveData.php` still rendered legacy Dashio Smarty templates
(`reqSpecView.html`, `archiveData.html`) which read `$GUI->tproject_id`,
`$GUI->tplan_id`, `$GUI->tprojOpt->{"testPriorityEnabled","automationEnabled"}`
— fields the modern kernel no longer populates → 7x E_WARNING class per view.

## Fix
Both controllers are now clean **302 redirect stubs** to their modern twins,
preserving deep-link identity (`req_spec_id`, `tcase_id`, `tproject_id`,
`tplan_id`):

| Legacy controller | Modern twin |
|---|---|
| `lib/requirements/reqSpecView.php` | `gui/templates/requirements/reqSpecView.html` |
| `lib/testcases/archiveData.php` | `gui/templates/testcases/tcEdit.html` |

No legacy Dashio render path remains for these deep links → E_WARNING class gone
at the source.

## Verification
- Legacy deep links return HTTP 302 (verified via curl).
- `events` table: 0 new error/warning events since commit `968994a15` (rule 13).

