# Task 969 — Implement `codetracker_view` read-right check in the Code Tracker Management screen (gap vs legacy)

**Issue:** [#969](https://github.com/sebiboga/testlink-upgraded/issues/969)
**Status:** IMPLEMENTED & VERIFIED (2026-09-24) — branch `task/issue-969-codetracker-view-rights`

## The gap

Legacy `lib/codetrackers/codeTrackerView.php:68-70` gates the **whole page** on
`checkRights()` = `hasRight('codetracker_view') || hasRight('codetracker_management')`
(passed into `testlinkInitPage` → `lib/functions/common.php:1010-1032`). A user with
neither right never reaches the screen: the check fails → `audit_security_user_right_missing`
audit event logs → the page redirects home.

The modern BFF `api/codetracker/index.php:16-21` authenticated **only** on
`$_SESSION['userID']` presence; no `tlUser` object was built and `hasRight()`
appeared **0 times** in the file. Every route (list, `/{id}`, `/meta/types`,
CRUD POST/PUT/DELETE, `test_github`, `/oauth/*`) was served to ANY authenticated
user.

## Server-side gate (mirrors legacy + api/issa trockers #959)

`api/codetracker/index.php:24-37` — the gate sits BEFORE route dispatch, so EVERY
route (list, `/{id}`, `/meta/types`, CRUD, `/oauth/*`, `test_github`) returns 403
for a user holding neither right:

```php
$user   = tlUser::getByID($userId);
$canView  = $user->hasRight($db, 'codetracker_view') ||
            $user->hasRight($db, 'codetracker_management');
if (!$canView) {
    logAuditEvent(TLS('audit_security_user_right_missing', $userId,
        'api/codetracker/index.php', 'view'), 'VIEW', $userId);
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(403);
    echo json_encode(['status'  => 'error',
                      'message' => TLS('ct.msg.noPermission')]);
    exit;
}
```

Legacy parity: `checkRights` (codeTrackerView.php:68-70) uses the same OR —
`codetracker_view || codetracker_management`; a `codetracker_view`-only user still
reads the list (read-gated, not blocked). 401 for missing session mirrors the old
auth check.

## Front-end denied state (mirrors issuetrackerView.html #959)

`gui/templates/codetracker/codetrackerView.html`:
- `showDenied(xhr)`: on 403, hides the toolbar + table and paints the localized
  red denial message, mirroring `issuetrackerView.html:205-233` (#959).
- Wired into the `.fail()` of `loadMeta()` and `loadTrackers()`.
- i18n key `ct.msg.noPermission` added to ALL 10 locale bundles.


## i18n

New key `ct.msg.noPermission` added to all 10 locale bundles
(en/ro/de/es/fr/it/ja/pt/ru/zh). All bundles validated:
`python3 -m json.tool`.

## Verification (measured)

Fixture users: `ctguest` (role 3 `<no rights>`, 0 role_rights), `ctviewonly`
(role 99, only `codetracker_view` right #52), `admin`.

| User | `GET /api/codetracker/index.php` | `/meta/types` |
|------|----------------------------------|---------------|
| `ctguest` (no rights) | **403** `{"status":"error","message":"No permission"}` | **403** |
| `ctviewonly` (view only) | **200** | **200** |
| `admin` | **200** | **200** |

Browser (chrome-devtools MCP) as `ctguest`: red localized denial panel, toolbar +
table hidden, both XHRs → 403, Event Viewer clean (INFO-only
`audit_security_user_right_missing`), zero console errors. As `admin`: full screen
renders (regression clean).

Test suite `Task — Issue #969` in `tl/TLU_Test_Cases.md`: 5/5 PASS. Event Viewer clean.
