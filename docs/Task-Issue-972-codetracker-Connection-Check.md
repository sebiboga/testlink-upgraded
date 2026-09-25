# Task 972 — Restore Code Tracker per-tracker connection checks

**Issue:** [#972](https://github.com/sebiboga/testlink-upgraded/issues/972)
**Status:** IMPLEMENTED & VERIFIED (2026-09-25) — branch `task/issue-972`

## The gap

The legacy Code Trackers list exposed a manager-only wrench for each stored
tracker. Selecting it ran the tracker's interface against its stored
configuration and displayed a teal heartbeat or red skull-crossbones result.
The modern Dashio list rendered neither the wrench nor a status result, even
though the BFF already exposed the connection action.

Legacy sources:

- `lib/codetrackers/codeTrackerView.php:23-24` builds the manager context.
- `gui/templates/dashio/codetrackers/codeTrackerView.tpl:51-61` gates the
  connection affordance and renders the heartbeat/skull result.
- `tlCodeTracker::getImplementationForType()` supplies the stored interface.

## Implementation

### BFF

`api/codetracker/index.php:110-116` now reads and validates the stored tracker
type before constructing an interface. The action dispatcher at
`api/codetracker/index.php:338-368` restricts `/{id}/{action}` to the known
GitHub actions, returns structured 404/400 responses for unknown IDs, unknown
actions, and unsupported stored types, and keeps the existing manager rights
gate. The stored-connection probe is POST-only so the shared BFF same-origin
CSRF guard cannot be bypassed by a cross-site safe request. `api/codetracker/index.php:404-414` invokes `isConnected()` for
`test_connection` and converts unexpected `Throwable` failures into HTTP 502
JSON instead of allowing a fatal response.

### Screen

`gui/templates/codetracker/codetrackerView.html:221-301` adds a manager-only
wrench and a per-row status holder. `checkConnection(id)` shows a spinner,
suppresses duplicate requests while one is pending, preserves the pending/result
state across DataTable rebuilds, and renders localized heartbeat/skull icons
for success, configured failure, and transport failure.
Names and displayed tracker metadata are escaped before insertion. The view-only
path remains free of the Actions column and connection affordances.

### Compatibility and localization

`lib/functions/tlCodeTracker.class.php:557-561` now supplies an empty type
description for an unsupported stored type instead of indexing an undefined
array entry. This prevents the list endpoint from generating Event Viewer
warnings when malformed legacy data is encountered.

The existing connection result keys are reused. The new `ct.checkConnection`
and `ct.connCheckFailed` keys were added at line 405/408 in all ten locale
bundles: `de`, `en`, `es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`, and `zh`.

## Verification

The numbered suite in `tmp/TLU_Test_Cases.md`, **Task — Issue #972**, passed
**9/9**:

1. Manager list and no automatic probe.
2. Reachable tracker renders heartbeat.
3. Unreachable tracker renders skull-crossbones.
4. Duplicate activation is suppressed.
5. POST-only method/CSRF enforcement, unknown ID/action/type, and transport-error paths return structured errors.
6. View-only user has no manage affordances and receives HTTP 403.
7. All locale bundles and PHP/JavaScript/diff gates pass.
8. Repeated malformed-type probing adds no new Error/Warning event.
9. A successful edit invalidates a stale connection result.

Measured static checks:

- `php -l api/codetracker/index.php` — passed.
- `php -l lib/functions/tlCodeTracker.class.php` — passed.
- `python3 -m json.tool` for all ten touched locale bundles — passed.
- Inline JavaScript syntax check — passed.
- `git diff --check` — passed.
- Browser console after the final normal page load — no errors or warnings.

The first malformed-type probe before the compatibility fallback created four
historical E_WARNING rows in `events` at `tlCodeTracker.class.php:559-560`.
After the fallback, the warning count was 4 before and 4 after the repeated
probe, so the new Event Viewer delta was zero. The intentional reachable and
unreachable fixtures (IDs 1 and 2) remain; the temporary invalid-type row and
view-only user/role were removed.

## Screenshots

The following files are stored under `docs/screenshots/` and mirrored in the
Wiki clone:

- `issue-972-admin-list.png` — manager list with wrench affordances.
- `issue-972-connection-status.png` — reachable and unreachable row results.
- `issue-972-viewonly.png` — view-only list with no management controls.

## Test data and resume

Environment: `http://localhost:8082`, MariaDB `testlink` at
`127.0.0.1:3306`, manager credentials `admin/admin`. The final branch is
`task/issue-972`; push it with:

```text
git push origin HEAD:task/issue-972
```

After the push, close issue #972 only after confirming the final commit and
verification checklist. Do not stage `.ci_target_issue` or
`config_db.inc.php`.
