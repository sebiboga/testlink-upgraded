# Task, Issue #1620 — Assign Test Project Roles: bounce to login on a 401 `session_expired` **on the first request**

**Screen:** `gui/templates/usermanagement/usersAssignProject.html` (Assign Test Project Roles)
**BFF:** `api/roles/index.php` (no change needed — `bffEnforceSession()` already answers `401`, #1614)
**Status:** implemented, verified, pushed on `task/issue-1620`

## The gap

Legacy 1.9.20 opened the screen through
`lib/usermanagement/usersAssign.php:23 testlinkInitPage($db,false,false,"checkRights")`, which runs
`checkSessionValid($db)` (`lib/functions/common.php:531-533` → `:281-310`) **before anything is
rendered**. A session idle for more than `config_get("sessionInactivityTimeout")` minutes therefore
never rendered the assignment form at all: the controller logged INFO and did
`redirect("login.php?note=expired&destination=<this screen>","top.location")` + `exit()`
(`common.php:295-308`). The controller ran the same check again right after the update block
(`usersAssign.php:97`).

The modern BFF cannot redirect, so the guard answers instead
(`api/_guard.php:175-196 bffEnforceSession()`, called at `api/roles/index.php:40`, after the
`userID` gate and before any route dispatch):

```
HTTP/1.1 401  {"status":"error","code":"session_expired","message":"session_expired"}
```

`usersAssignProject.html` already turned that into the legacy bounce — but only on **two of the
three** request paths it makes:

| path | handler | on a 401 |
|---|---|---|
| **project combo (FIRST request of the page)** | `loadProjects().fail()` | **was not handled** — fell into the generic `else` and painted the empty-state box |
| user grid | `loadUsers().fail()` `:523` | `sessionExpired(xhr)` → bounce (#1614) |
| save | `saveAssignments()` `:561` | `sessionExpired(xhr)` → bounce (#1614) |

`loadProjects()` is called first, from the `TLi18n.load` callback at `:364`, so it is exactly the
request legacy guarded pre-render. Its `.fail()` only branched on `403`:

```js
.fail(function(xhr) {
  if (xhr.status === 403) { showNoAccess(); }
  else { $('#emptyMsg').show(); }        // <-- the 401 landed here
});
```

**Measured dead state** (aged session + reload, this run):

```
url               : …/usersAssignProject.html?tproject_id=2   (no bounce)
.toolbar display  : flex                 combo: 0 options, value ""
#emptyMsg         : block  "Select a test project above to manage role assignments."
#toast            : ""                   #saveBtn.disabled: true (permanently)
#tabsBar          : 4 tabs, all of them dead
```

The user was left staring at a form that can never load anything, told to "select a test project"
from an empty combo, with no message explaining why. This is the *normal* way the state is reached:
the Dashio shell itself never refreshes the timer (`index.php` calls only `doSessionStart()`, no
`checkSessionValid()`), so a shell tab left open past the timeout is still rendered and its aside
link loads this screen straight into the dead state.

## The fix

`gui/templates/usermanagement/usersAssignProject.html`

1. `loadProjects().fail()` calls `sessionExpired(xhr)` **first**, before the `403`/generic branches
   — the same shape as `loadUsers()` and `saveAssignments()`.
2. New `showSessionExpired()` — the terminal state helper, modelled on the existing
   `showNoAccess()` / `showDisabled()`: hides the demo banner, the tab bar and the toolbar, empties
   and disables the project combo, destroys the DataTables instance and clears `assignTbl` /
   `currentItems`, hides the grid and all three message boxes (`#emptyMsg`, `#disabledMsg`,
   `#denyBox`), hides the demo note, disables Save and empties the footer.
   `sessionExpired()` calls it before the toast and the 600 ms redirect, so the 600 ms cannot leave
   a live toolbar / combo / Save button on screen. Legacy redirected *before* the first render, so
   "no assignment form at all" is the parity target; the tab bar goes with it because every tab
   link loads a screen that would 401 identically.
3. The `#projectSelect` `change` handler bails out while the combo is disabled, so the neutralised
   state can never re-arm a request against the dead session (and a combo emptied while a previous
   load is in flight cannot wipe a still-valid grid — the same trap already fixed in that handler by
   the #1707 review).
4. The `sessionExpired()` header comment no longer claims the bounce only covers "the initial user
   list load and a Save" — it now lists all three request paths.

**No i18n change:** the fix reuses `auth.sessionExpired`, already present in all ten locale bundles
(de, en, es, fr, it, ja, pt, ro, ru, zh) and already used by the two older paths.
**No BFF change:** `bffEnforceSession()` was already correct.

## Verification

Reproduce a stale session by ageing the stored `lastActivity`
(`$tlCfg->sessionInactivityTimeout = 9900` **minutes** → 594 000 s, so ageing by `-100000` s still
returns `200` — every valid call slides it forward, `common.php:286-288`; age by `-700000` s):

```bash
SF=$(sudo ls -t /var/lib/php/sessions/ | head -1)   # re-read: a bounce mints a NEW PHPSESSID
sudo sed -i "s/lastActivity|i:[0-9]*/lastActivity|i:$(( $(date +%s) - 700000 ))/" /var/lib/php/sessions/$SF
```

| check | result |
|---|---|
| first request (`loadProjects`) 401 | bounces to `login.php?note=expired&destination=%2Fgui%2Ftemplates%2Fusermanagement%2FusersAssignProject.html%3Ftproject_id%3D2`, login page shows "Session expired. Please log in again." |
| page state during the 600 ms window | tabs + toolbar hidden, combo empty + disabled, all 3 message boxes hidden, Save disabled, footer empty, DataTables destroyed; a `change` event on the disabled combo issues **0** requests |
| project switch (`loadUsers`) 401 | still bounces |
| save 401 | still bounces |
| stubbed 403 on the first request | deny box "You do not have enough rights to access this feature.", no toast, no redirect — the branch below the new early-return is intact |
| `sessionExpired({status:403})` / `{status:500}` | `false`, no side effects — no false positive |
| healthy session | 2 rows, combo populated, toolbar `flex`, 4 tabs, Save disabled until a change — unchanged |
| user empties the combo | grid cleared + `#emptyMsg` shown — the disabled-combo guard does not swallow it |
| Event Viewer / logs | browser console: 0 error+warning; `grep -icE "PHP (Warning\|Notice\|Fatal\|Deprecated)" logs/userlog0.log logs/userlog1.log` → 0 / 0; newest `events` rows are `log_level 16` `audit_login_succeeded` (own logins) |
| `node --check` on the inline script | OK |

Suite `1620` in `tmp/TLU_Test_Cases.md`: **10 PASS / 0 FAIL / 1 not exercisable**
(the `showDisabled()` branch needs a caller with no assignable project; documented, not claimed).

Fixtures used (freshly imported DB has no test project, so the form was unreachable):
test projects `#2 "Issue 1620 Project"` / `#3 "Issue 1620 Project B"` (public) plus user
`#2 tester1620` (global role 7).

## Note for the other screens

`usersAssignProject.html` was the last of the user-management screens to miss the 401 branch on its
first request; `usersAssignPlan.html` got the full four-path treatment in #1646. When porting this
helper to a new screen, check **every** request path, not just the one that renders the grid.
