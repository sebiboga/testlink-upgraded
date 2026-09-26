# Feature gap — Issue #1614: session inactivity timeout on the tproject-roles BFF

**Screen:** `gui/templates/usermanagement/usersAssignProject.html` (Assign Test Project Roles)
**BFF:** `api/roles/index.php` → `GET /meta/tproject-roles`, `PUT /tproject-roles`
**Legacy origin:** `lib/usermanagement/usersAssign.php` (deleted under #947; survives in git at `ab387af72^`)

## What legacy did

Every legacy page — this one included — went through `testlinkInitPage()`, which
calls `checkSessionValid($db)`:

- `lib/usermanagement/usersAssign.php:23` → `testlinkInitPage($db,false,false,"checkRights")`
- `lib/functions/common.php:531-533` → `checkSessionValid($db)`
- `lib/functions/common.php:258-286` — the check itself:

```php
if (isset($_SESSION['userID']) && $_SESSION['userID'] > 0) {
    $now = time();
    if (($now - $_SESSION['lastActivity']) <= (config_get("sessionInactivityTimeout") * 60)) {
        $_SESSION['lastActivity'] = $now;      // slide the window forward
        ...
        $isValidSession = true;
    }
}
if (!$isValidSession && $redirect) {
    tLog('Invalid session from ' . $_SERVER["REMOTE_ADDR"] . '. Redirected to login page.', 'INFO');
    redirect("login.php?note=expired&destination=" . urlencode($_SERVER['REQUEST_URI']), "top.location");
    exit();
}
```

A **second, explicit** call sat right after the update block —
`lib/usermanagement/usersAssign.php:97`, with the legacy comment *"Must be done
here after having done update, to get current information"* — so legacy
re-validated the session after writing roles as well.

`$_SESSION['lastActivity']` is seeded at login (`lib/functions/doAuthorize.php:181`).

**Legacy guarantee:** a tab left idle longer than `sessionInactivityTimeout`
minutes can neither read the user list nor write role assignments, and the user
is bounced to `login.php?note=expired&destination=<this screen>`, which renders
the localized "Session expired. Please log in again." box.

## The gap

`api/roles/index.php:13` called `doSessionStart()` only; the sole gate was
`$_SESSION['userID'] > 0` (`:21-26`). Measured with
`sessionInactivityTimeout` temporarily forced to `0` (so any session is stale):

```
metaStatus: 200, metaItems: 1
putStatus:  200, putBody: {"status":"ok","feedback_key":"no_users_selected"}
```

Both the read **and** the write succeeded on a dead session.

Note: the issue body claimed `api/reports/index.php` already mirrored this. It
does not — the 4 BFF files matching `lastActivity` only **seed** it on their
API-key/anon paths. No BFF in the repo ever *checked* it
(`grep -rn sessionInactivityTimeout api/ lib/functions/` → only
`lib/functions/common.php:263`). The gap is repo-wide; the fix is scoped to the
roles BFF and the helper is shaped so the remaining BFFs are a one-liner each.

## The fix

### 1. `api/_guard.php` — shared helper `bffEnforceSession(&$db)` (lines 102-145)

```php
function bffEnforceSession(&$db) {
    if (checkSessionValid($db, false)) { return; }
    tLog('BFF: invalid or expired session from ' . ... . ' - answering 401 session_expired.', 'INFO');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(401);
    echo json_encode(array('status'=>'error','code'=>'session_expired','message'=>'session_expired'));
    exit;
}
```

- `$redirect=false` is mandatory: legacy's `redirect(...,"top.location")` is
  meaningless inside a JSON API, so the BFF answers and the screen bounces.
- On success it inherits `checkSessionValid()`'s side effect of sliding
  `$_SESSION['lastActivity']` forward — that is what keeps an *actively used*
  screen from expiring.
- It lives in the shared middleware (next to `bffSameOriginGuard()`) so the
  repo-wide sweep reuses it.

### 2. `api/roles/index.php:40` — enforce it

`bffEnforceSession($db);` sits right after the `userID` gate and **before** any
route dispatch and before the route-aware rights block (`:363+`), so a stale
session is rejected before any user list is read, before any role row is written
and before the rights-denied audit events can be emitted by a dead session. It
covers every route of the BFF, i.e. both the read (`GET /meta/tproject-roles`)
and the write (`PUT /tproject-roles`) path legacy protected.

### 3. `gui/templates/usermanagement/usersAssignProject.html` — the bounce

- `sessionExpired(xhr)` (line 178) — on `401` **or** `code === 'session_expired'`
  it shows the localized `auth.sessionExpired` toast and, 600 ms later,
  navigates to `/login.php?note=expired&destination=<pathname+search>`.
  It redirects the **top** window when embedded, mirroring legacy's
  `top.location` (pattern of `documentation/staticPage.html:96`), and returns
  `true` so callers bail out.
- `loadUsers().fail()` (line 333) — guard first, so a stale session never
  renders the misleading "Select a test project above" empty state.
- `saveAssignments() error:` (line 561) — guard first, so a user whose session
  died mid-write is not told the role update failed.

Triggering on any `401` (not only `session_expired`) is deliberate and
legacy-correct: `checkSessionValid()` returns false both when `userID` is absent
and when the window elapsed, and legacy redirected with `note=expired` in both
cases. It also matches the sibling modernized screens
(`plans/planUpdateTC.html:226`, `plans/planNav.html:176`).

### 4. i18n

No new key was needed: the toast reuses `auth.sessionExpired`, already present
in **all 10** locale bundles (`de, en, es, fr, it, ja, pt, ro, ru, zh`); the
login screen already renders the same key for the `note=expired` bounce
(`gui/templates/auth/login.html:145-155`). All 10 bundles validate with
`python3 -m json.tool`.

## Verification

With `sessionInactivityTimeout` forced to `0`, `admin/admin` freshly logged in:

```
GET /api/roles/index.php/meta/tproject-roles?tproject_id=1 -> 401
{"status":"error","code":"session_expired","message":"session_expired"}
PUT /api/roles/index.php/tproject-roles                      -> 401 (same body)
```

Browser then landed on:

```
http://localhost:8082/login.php?note=expired&destination=%2Fgui%2Ftemplates%2Fusermanagement%2FusersAssignProject.html%3Ftproject_id%3D1
  StaticText "Session expired. Please log in again."
```

With the shipped `sessionInactivityTimeout = 9900` restored, no regression:
`GET` → `200` (1 user), `PUT` → `200 {"status":"ok","feedback_key":"assign_roles_updated"}`.

Event Viewer after the pass: `select count(*),max(id) from events;` → `4 4`; the
only new row is `id 4`, `log_level 16` (INFO), the audit row of the happy-path
write. No new Error/Warning.

`config.inc.php` was used only as a temporary test lever and is byte-identical
after the run (`git diff config.inc.php` → empty).

Full 16-case suite with measured results: `tmp/TLU_Test_Cases.md`, suite
*Task — Issue #1614*.

## Follow-up (not part of this issue)

Every other BFF that only calls `doSessionStart()` has the same gap. A sweep
adding `bffEnforceSession($db);` next to each `doSessionStart()` + `userID` gate
would close it repo-wide; the API-key/anon paths of `api/reports`, `api/reportsprint`,
`api/reportsexport` and `api/testcasesprint` must be skipped, since there
`$_SESSION['userID']` is not a browser session.
