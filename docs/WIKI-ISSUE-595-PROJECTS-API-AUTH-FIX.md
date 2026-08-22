# Projects API Missing Authentication — Fixed

## Issue #595

`api/projects/index.php` never started a session and never checked
`$_SESSION['userID']` — no `doSessionStart()`, no user lookup. Every route
(GET/POST/PUT/DELETE) executed fully unauthenticated. `api/trackers/index.php`
shared the same flaw (flagged in the issue comments).

**Verified pre-fix repro** (fresh DB, NO cookies at all):

```
curl -X POST -H 'Content-Type: application/json' \
     -H 'X-Requested-With: XMLHttpRequest' \
     -d '{"name":"UNAUTH_PROBE_595","prefix":"UP595"}' \
     http://localhost:8082/api/projects/
=> {"success":true,"id":1,"message":"Project created successfully"}

# same for the other verbs:
PUT    /api/projects/1  {"name":"HACKED"}   => 200, project renamed
DELETE /api/projects/1                      => 200, project deactivated
GET    /api/projects/                       => 200, full project list leaked
GET    /api/trackers/                       => 200
```

Anyone on the network could enumerate, create, modify or destroy test
projects. The `bffSameOriginGuard()` CSRF middleware added in #591 was already
wired into these files, but it only blocks *cross-site browser* requests — an
anonymous client trivially satisfies it with one header, so it is not an
authentication mechanism.

## Root Cause

The entry point deliberately bypasses the page bootstrap
(`testlinkInitPage()` redirects to login.php on an expired session, which
breaks JSON clients), but no replacement auth check was added — unlike every
other BFF entry point, which does `doSessionStart()` + `$_SESSION['userID']`
lookup + `tlUser::getByID()` + rights checks.

## The Fix — approach

Adopt the standard BFF bootstrap used by `api/builds/index.php`,
`api/cfields/index.php`, etc., in both files:

1. `require config.inc.php` + `common.php` (`tlUser` loads via the existing
   `tlAutoload` spl autoloader);
2. `doSessionStart()` → session cookie available;
3. `require ../_guard.php` + `bffSameOriginGuard()` (CSRF layer kept as before,
   now layered on top of real auth);
4. **401** when `$_SESSION['userID']` missing/≤0 or the user row is gone;
5. **403** without the `mgt_modify_product` right.

Why `mgt_modify_product` on ALL routes: legacy gates its whole Test Project
Management screen with exactly this right (`checkRights()` at the bottom of
`lib/project/projectEdit.php`, wired via `testlinkInitPage(...,"checkRights")`),
and both endpoints exist solely to feed that screen (`projectsView.html`). This
mirrors how `api/cfields/index.php` enforces its screen-wide `cfield_management`
right once, globally.

*Rejected alternative:* per-HTTP-verb rights granularity — legacy has none for
this screen; inventing finer granularity would diverge from 1.9.20 behavior
without any caller needing it.

Response shape follows the established BFF pattern:
`{"status":"error","message":"Not authenticated"}` / `"No permission"`.

## Verification matrix (post-fix)

| Request | Result |
|---|---|
| Anonymous GET list / GET by id | **401** Not authenticated |
| Anonymous POST with `X-Requested-With` header | **401** (CSRF-proof header alone buys nothing now) |
| Anonymous PUT / DELETE by id | **401** |
| Anonymous GET /api/trackers/ | **401** |
| Garbage PHPSESSID cookie | **401**, no fatal |
| User with role `<no rights>`: projects GET/POST, trackers GET | **403** No permission |
| Admin curl CRUD (create → rename+deactivate → delete) | 200, DB reflects changes |
| Browser `projectsView.html`: list, Create modal (tracker dropdowns load), edit rename+toggle Active, delete confirm | All flows work — jQuery `$.ajax` sends session cookie + `X-Requested-With` automatically; zero console errors |
| Authed POST without proof headers / hostile Origin | **403 Forbidden** (CSRF guard still layered on top of auth) |
| Event Viewer after suite | Zero new ERROR/WARNING events |

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #595" (12/12 PASS).

## Files Changed

| File | Purpose |
|---|---|
| `api/projects/index.php` | standard BFF bootstrap: session + 401 auth gate + 403 `mgt_modify_product` |
| `api/trackers/index.php` | same bootstrap/rights (same flaw, same consumer screen) |

