# Bug fix — Issue #1578: codetracker repo-enumeration endpoints (/2/branches|tags|commits|pulls|test_connection) let view-only users drive the stored token server-side

## Symptom
The modern Code Tracker BFF's read routes were correctly `$canManage`-gated
after #1576, but the **server-side use of the stored token** was not: a user
holding ONLY `codetracker_view` (right 52) could trigger
`GET|POST /api/codetracker/index.php/{id}/{branches,tags,commits,pulls,test_connection}`
(api/codetracker/index.php:285-327) which calls `githubInterfaceFor()` on their
behalf and performs authenticated GitHub API calls with the tracker's stored
token (list branches/tags/commits/pulls of a PRIVATE repo, or run the
connection test). No secret is disclosed (error strings are fixed, fetched data
is repo-meta), but it defeats the intent of the #970/#1576 gates: a view-only
user can *use* the manager's credentials server-side for private-repo
enumeration.

## Reproduction (this run, fresh DB)
1. Fixtures: user `ctviewonly` (role 10 holding ONLY right 52
   `codetracker_view`, verified via `role_rights`), `admin` (role 8).
   GitHub tracker `repro-1578-gh` (id 2, type 200) created via the API with
   cfg `<codetracker>\n  <repository>https://github.com/acme/secret</repository>\n  <branch>main</branch>\n  <token>ghp_SUPERSECRETTOKEN123</token>\n</codetracker>`.
2. Logged in as `ctviewonly` (isolated browser context), one fetch batch:
   list + `/2/branches` + `/2/tags` + `/2/commits?branch=main` +
   `/2/pulls?state=open` + `POST /2/test_connection`.
3. Pre-fix (measured): list → 200 `canManage:false` (read gate OK after
   #1576); `/2/branches|tags|commits|pulls` → **502** `Unable to fetch …`
   and `/2/test_connection` → **200** `connected:false` — i.e. the BFF
   constructed the GitHub interface from the stored cfg and made live calls
   (they only fail because the fixture token/repo are fake and outbound is
   sandboxed). No 403 anywhere. Post-fix: all five → **403** `No permission`.

## Root cause chain
- `api/codetracker/index.php:37` — the page-level gate lets any session holding
  `codetracker_view` OR `codetracker_management` onto all routes. Correct for
  reads, insufficient for token-driving routes.
- `api/codetracker/index.php:285-327` — the `/ {id} /  {branches|tags|commits|
  pulls|test_connection}` fallback route (issue #433) has NO `$canManage`
  guard; it loads the tracker and calls `githubInterfaceFor($mgr, $id)`.
- `api/codetracker/index.php:226-238` (`githubInterfaceFor`) builds
  `new $impl($tracker['type'], $tracker['cfg'], $tracker['name'])` — the stored
  cfg (plaintext `<token>ghp_…</token>`) is handed to the GitHub interface.
- `api/codetracker/index.php:295-323` then executes `getBranches()`,
  `getTags()`, `getCommits()`, `getPullRequests()`, `isConnected()` —
  authenticated outbound calls made with the manager's stored token on behalf
  of a view-only user.
- `denyWrite()` (`:67-73`) is invoked only for `create` (:174), `update`
  (:201), `test_github` (:243 — inline unsaved config, cover-your-own token,
  already manager-only) and `delete` (:330). The repo-enumeration family was
  simply not in that list.

**Why it breaks NOW**: #970 closed mutations and #1576 closed raw-cfg reads,
but the *server-side use* half stayed open: #1576 stops the token from leaving
the server for viewers; this gap lets it *do work* on the server for viewers.
The issue-#433 code predates those refactors and inherited none of their
guards.

**Blast radius**: exactly the 5 actions of the block
`api/codetracker/index.php:285-327` (branches, tags, commits, pulls,
test_connection). Frontend `codetrackerView.html` never calls any of them
(grep: only `/test_github` at :332), so gating breaks no view-only UI — parity
with legacy `codeTrackerView.tpl:51-61` (wrench connection check rendered only
`if $gui->canManage != ""`) and `codeTrackerEdit.php:181-184` (edit gated on
`codetracker_management`). No i18n impact (response messages reuse the existing
`No permission`).

## Fix approach
1. `api/codetracker/index.php` — in the `/ {id} /{action}` block, immediately
   after `$action = strtolower($segments[1]);` (BEFORE `getByID` and any
   `githubInterfaceFor()` construction, so the stored token is never even
   loaded for viewers), add the same gate used by #970:
   `if (!$canManage) { denyWrite($user, $userId, $action); }`
   → HTTP 403 + Event-Viewer `audit_security_user_right_missing` WRITE trail,
   identical to create/update/delete/test_github.
2. No other file touched — minimal, no refactor, no i18n keys (no new
   user-facing strings).

### Alternatives considered and rejected
- Gating only `test_connection` — the other four (branches/tags/commits/pulls)
  are equally stored-token-driving; the legacy UI hides the whole
  connection-check family for viewers, so all five are manager-only.
- Returning 404 instead of 403 — inconsistent with the established #970 write
  gate (403 + audit trail); the events table is the only denial trace for the
  JSON BFF.

## Verification (measured)
- `php -l api/codetracker/index.php` → no syntax errors.
- `ctviewonly` (post-fix): all five endpoints → HTTP 403
  `{"status":"error","message":"No permission"}`; `events` table gained exactly
  5 `audit_security_user_right_missing` WRITE rows for `ctviewonly`
  (log_level 16 = AUDIT, no ERROR/WARNING). List + detail unchanged
  (200, `canManage:false`, `cfg:""`, `github.token` `********`).
- `admin`: list 200 `canManage:true` with full raw cfg; `/2/branches` → 502
  and `/2/test_connection` → 200 `connected:false` (identical to pre-fix —
  manager kept the stored-token capability); `/test_github` unchanged (200).
- Screen renders for both roles (view-only: no Create/Actions; admin: Create
  button + edit/delete icons); view-only screen fires NO
  `/2/branches|tags|commits|pulls|test_connection` XHRs; browser console clean
  (pre-existing a11y notices only).
- Regression suite 1578 5/5 PASS appended to `tmp/TLU_Test_Cases.md`.

## Files changed
- `api/codetracker/index.php` — stored-token route gate (+11 lines vs HEAD).
- `CHANGELOG` — `[KEY BUGFIX - SECURITY] - #1578` entry.
- `tmp/TLU_Test_Cases.md` — `Regression — Issue #1578` suite (5/5 PASS).
- Wiki mirror page `Bugfix-Issue-1578-Codetracker-Stored-Token-Authz.md`
  (with screenshots) + this docs mirror.

## Result
Issue #1578 fixed error-free and pushed (`fix/issue-1578`); regression suite
5/5 PASS; Event Viewer clean (only the expected AUDIT denial rows, no
ERROR/WARNING).