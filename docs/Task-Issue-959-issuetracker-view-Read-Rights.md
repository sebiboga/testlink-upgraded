# Task 959 — Implement `issuetracker_view` read-right check in the Issue Trackers screen (gap vs legacy)

**Issue:** [#959](https://github.com/sebiboga/testlink-upgraded/issues/959)
**Status:** IMPLEMENTED & VERIFIED (2026-09-23) — branch `task/issue-959-issuetracker-rights`

## The gap

Legacy `lib/issuetrackers/issueTrackerView.php:61-66` gates the **whole page** on
`checkRights()` = `hasRight('issuetracker_view') || hasRight('issuetracker_management')`
(passed to `testlinkInitPage`, `lib/functions/common.php:1010`). A user holding
neither right is never shown the screen: the check fails → `audit_security_user_right_missing`
audit event is logged → the page redirects `top.location` home.

The modern BFF `api/issuetracker/index.php` used to authenticate **only** on
`$_SESSION['userID']` presence (old lines 16-21); no `$user` object was built and
`hasRight()` appeared 0 times in the file. Every route (list, `/{id}`,
`/meta/types`, the CRUD POST/PUT/DELETE and all `/oauth/*` endpoints) was served
to ANY authenticated user.

## Measured evidence gathered during INVESTIGATION (before the fix)

Fixture: user `norights` (role 3 `<no rights>`, ZERO `role_rights` rows) created
via SQL for this run.

- **API:** `curl -b cj http://localhost:8082/api/issuetracker/index.php` (logged in
  as `norights`) → **HTTP 200** `{"status":"ok","items":[],"total":0}`.
- **Browser:** `/gui/templates/issuetracker/issuetrackerView.html` rendered the
  FULL screen for `norights` — toolbar buttons `+ Create Issue Tracker` + `GitHub`,
  complete DataTable with Name/Type/Server URL/Active/Actions, footer
  `0 issue trackers | Generated on …`. No redirect, no denial message, no console errors.

## Server-side enforcement (Refs #959)

`api/issuetracker/index.php` now mirrors the legacy page-level gate:

```php
require_once('users.inc.php');                       // tlUser available (like api/cfields/index.php:9)
...
$user = tlUser::getByID($db, $userId);               // null → 401 "User not found"
...
$canView = $user->hasRight($db, 'issuetracker_view') || $user->hasRight($db, 'issuetracker_management');
if (!$canView) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}
```

The gate sits before route dispatch, so **every** route — `GET /`, `GET /{id}`,
`GET /meta/types`, `POST/PUT/DELETE` CRUD, and all `/oauth/*` (start, callback,
repos, token-status, create) — returns HTTP 403 `{"status":"error","message":"No permission"}`
for a user holding neither right. This mirrors legacy `checkRights()` exactly
(view-only users are NOT over-blocked: they keep access; restricting their CRUD to
management-only belongs to the separate task issue #960). The 403 path also trails
the event into the Event Viewer with the legacy label
`logAuditEvent(TLS('audit_security_user_right_missing', login, script, 'view'),
'VIEW', userId, 'users')` (legacy `checkUserRightsFor`,
`lib/functions/common.php:1010-1032`, logged the same label before redirecting home;
a JSON BFF cannot redirect, so the audit trail is the parity).

## Client-side denial rendering

`gui/templates/issuetracker/issuetrackerView.html`:

- `showDenied(xhr)` — mirrors `cfieldsView.html:219-224`: on `xhr.status === 403`
  hides `.toolbar, .table-wrap` (removing the Create/Edit table and the Create Issue
  Tracker + GitHub buttons) and renders the localized message `it.msg.noPermission`
  in the footer in red.
- `.fail(function(xhr){ showDenied(xhr); })` wired into `loadTrackers()` and
  `loadMeta()` — both fire on first render, so a forbidden user immediately sees
  the denial instead of a silent empty table.

## i18n

One key added to **all 10** locale bundles
(`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`):
- `it.msg.noPermission` — "You do not have permission to view issue trackers. Contact your administrator."

All bundles re-validated with `python3 -m json.tool`.

## Verification evidence

- **norights** (no issuetracker right): API list + meta → **HTTP 403**; browser
  renders only the localized red denial message (toolbar + table hidden); console
  shows only the 3 expected handled 403 resource logs; no JS exceptions.
- **viewonly** (role with ONLY right 32 `issuetracker_view`): API list + meta → **HTTP 200**
  (OR-gate not over-restrictive).
- **admin** (role 8 `admin`): full screen renders as before — toolbar, DataTable,
  footer `0 issue trackers | Generated on …`; regression clean.
- Event Viewer / `events`: only log_level 16 INFO rows (`audit_login_succeeded`,
  `audit_user_logout`) — **0 Error/Warning** from the issuetracker BFF.
- Inline JS extracted + `node --check` clean; `php -l api/issuetracker/index.php` clean.

Screenshot: `docs/screenshots/issue-959-denied-norights.png` (mirrored to wiki).

Fixture users `norights` / `viewonly` created via SQL during the run (DB is freshly
imported each CI run).

Refs #959.