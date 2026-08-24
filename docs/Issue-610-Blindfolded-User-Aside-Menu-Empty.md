# Issue #610: ASIDE menu renders completely empty for non-admin roles

## Root Cause

In `lib/functions/common.php:initUserEnv()`, when a non-admin user has no
accessible test projects (the "blindfolded" case), `$args->tproject_id` is set
to 0. This causes `$doInitUX` (line 1691) to be false, and since
`$gui->zeroTestProjects` is also false (projects exist, the user just can't
access them), neither branch executes — leaving `$gui->showMenu` as null.

`aside.tpl:15` checks `{if $gui->showMenu != null}` and skips the entire
menu when it's null, resulting in zero sub-menu sections for the user.

## Fix

Added `elseif( $args->userIsBlindFolded )` branch at line 1714, before the
existing `zeroTestProjects` branch. When projects exist but the user cannot
access any, this branch populates:

- `showMenu` with System + Projects enabled
- `grants` with minimal admin-level rights (so aside.tpl sub-item checks
  don't fatal on null)
- `access` via `getAccess($gui)`

This follows the exact same pattern as the `zeroTestProjects` branch.

## Files Changed

- `lib/functions/common.php` (+28/-1)

## Verification

- Non-admin with no project role + private projects → sees System + Projects
- Admin → sees all 10 sections (unchanged)
- User with project access → sees all permitted menus (unchanged)
- Zero test projects → System + Projects shown (unchanged)
