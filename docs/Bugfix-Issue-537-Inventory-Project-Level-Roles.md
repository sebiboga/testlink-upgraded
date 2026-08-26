# Bugfix — Issue #537: Inventory rights not checked with project context in getGrantSetWithExit()

## Symptom

When a test project has inventory enabled, a user whose inventory rights come ONLY from a project-level role never sees the Inventory management link in the aside menu, even though they are entitled.

## Root Cause

`getGrantSetWithExit()` in `lib/functions/common.php` computes inventory grants by calling `hasRight()` with only two arguments (no project context), so only the user's global/system role is consulted.

However, the deeper issue is that even passing project context to `hasRight()` does NOT fix the problem. The rights `project_inventory_view` and `project_inventory_management` are defined in `$g_rights_product` (roles.inc.php:169-170) which feeds `$g_propRights_global` (roles.inc.php:205). Inside `hasRight()` (tlUser.class.php:858), the line:

```php
$testProjectRights = array_diff($testProjectRights, array_keys($g_propRights_global));
```

strips these rights from project-role results, and `propagateRights()` only re-adds them if the user has them in their global role. So project-level inventory rights are silently dropped regardless of context arguments.

## Fix

In the inventory branch of `getGrantSetWithExit()`, after trying `hasRight()` (for global-role users), add a direct fallback that inspects `$user->tprojectRoles[$tproject_id]->rights` to find the right in the project role's rights array. This bypasses the propagation model for these project-scoped rights.

**File changed:** `lib/functions/common.php` (lines 2149-2169)

## Method

The fix uses a two-phase approach:
1. **Global role check:** `hasRight($dbHandler, $r)` — works for users with inventory rights in their global role (admin, leader).
2. **Project role fallback:** Directly inspects `$user->tprojectRoles[$tproject_id]->rights` array — works for users whose inventory rights come solely from a project-level role.

Both phases are guarded with null checks, isset checks, and type checks to prevent errors for blindfolded users, null users, or users without project roles.

## Alternatives Rejected

1. **Remove inventory rights from `$g_rights_product`** — This would change the fundamental rights propagation model and could affect other parts of the system.
2. **Add a new `hasRightDirect()` method** — Overengineered for this one use case.
3. **Use a raw SQL query** — Unnecessary; the role data is already loaded in memory.

## Verification

- User with project-level inventory_view only: link appears ✓
- User with global inventory_view (admin): link still appears ✓
- User with neither: link hidden ✓
- Inventory disabled on project: link hidden ✓
- Event Viewer: no new errors ✓
