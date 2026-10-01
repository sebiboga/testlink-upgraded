# Issue 1648 — `api/tcassignments/rows`: HTTP 500 with an EMPTY body (`TypeError array_keys on null`)

**Issue:** [#1648](https://github.com/sebiboga/testlink-upgraded/issues/1648)
**Branch:** `fix/issue-1648`
**Fix commit:** `b17faa89e`
**Status:** VERIFIED-FIXED

## Symptom

`GET /api/tcassignments/index.php/rows?tproject_id=<id>&tplan_id=0&show_all_users=0&show_closed_builds=0`
answers **HTTP 500 with a 0-byte body** whenever the request yields no assignments — the
normal case for a test project that has no test plans. That is every freshly imported
installation (the standard import ships 0 test plans), so the first load of the modernized
**My Test Case Assignments** screen (`gui/templates/execute/tcAssignments.html`) dies:

```
PHP Fatal error:  Uncaught TypeError: array_keys(): Argument #1 ($array) must be of type array,
null given in /…/api/tcassignments/index.php:348
Stack trace:
#0 /…/api/tcassignments/index.php(348): array_keys()
#1 {main}
```

The screen rendered a blank table area and the browser console showed only
`Failed to load resource: the server responded with a status of 500 (Internal Server Error)`
— no message, nothing actionable.

## Root cause

A variable was **assigned inside a conditional and consumed outside it**:

| Step | Location | What happens |
|---|---|---|
| 1 | `api/tcassignments/index.php:111` | `$resultsCfg = config_get('results');` — unconditional, endpoint-wide. |
| 2 | `api/tcassignments/index.php:229` | `$rs = $tcaseMgr->get_assigned_to_user(...)` — legacy NULL-on-empty parity: returns **NULL** when the user has no assignment in that project/plan. |
| 3 | `api/tcassignments/index.php:232-233` | `$groups = [];` then `if (!is_null($rs)) {` opens a block wrapping the whole row-building loop. |
| 4 | `api/tcassignments/index.php:240` (pre-fix) | **`$statusCodes = $resultsCfg['status_code'];` lives INSIDE the block** (as does `$tplanMgrTmp` at `:241`). With `$rs === NULL` the assignment never runs. |
| 5 | `api/tcassignments/index.php:348` (pre-fix) | `'statusKeys' => array_keys($statusCodes)` in the final `out([…])` is **OUTSIDE** the block (the guard closes at `:341`) → `array_keys(null)` → PHP 8 `TypeError` → uncaught → the BFF dies before writing a body → **500, 0 bytes**. |

The guard exists to skip *row building*; the config read was never conditional and never
should have been. Regression source (measured with `git blame`):

```
$ git blame -L 232,241 -- api/tcassignments/index.php
ed7fdec0b6 (github-actions[bot] 2026-08-24 232)     $groups = [];
ed7fdec0b6 (github-actions[bot] 2026-08-24 233)     if (!is_null($rs)) {
023e60cedb (github-actions[bot] 2026-08-24 240)     $statusCodes = $resultsCfg['status_code'];

$ git blame -L 346,349 -- api/tcassignments/index.php
023e60cedb (github-actions[bot] 2026-08-24 348)         'statusKeys' => array_keys($statusCodes),
```

Commit `023e60cedb` ("fix: code-review findings …", Refs #660) added `statusKeys` to the
payload **and** placed the `$statusCodes` read inside the guard — in one commit, which is
why the mismatch was born there and stayed hidden: the only consumer of the value sits on
the success payload, so the bug is invisible whenever `$rs !== NULL`.

**Blast radius.** One unguarded consumer in that file (`$statusCodes` is re-assigned for
the `quick_result` route at `:378`, so writes were never affected). The `rows` route is
read-only — no data was ever partially written. The trigger is any NULL result set:
a project without test plans, a project whose plans have no linked TC versions, or simply a
user with no assignments — on a clean install, always.

Scope of the sweep: within this route the check is exhaustive — every value consumed after
the guard closes (`:341` pre-fix) was compared against where it is assigned, and no second
instance of the pattern exists (`$groups` :232, `$priorityEnabled` :183, `$showAllUsers` :187,
`$showClosedBuilds` :195, `$glueChar` :113, `$statusCodes` :240). A line-by-line sweep of the
**22** repo-wide `is_null($rs)` guard sites (`api/ltx`, `api/scriptedit`, `api/testcasesedit`,
`api/execute`, `api/results`, `api/reports`, `api/publiclink`, `api/keywords`, `api/keywordsxml`,
`api/keywordsedit`, `api/projects`, `api/projectedit`, `api/suiteview`, `api/testcases`,
`api/reqtcassign`, `api/reqtcbassign`, `api/reqexport`, `api/resultsimport`, `api/usersexport`,
`api/execsetresults`, `api/tcscripts`) was **not** done exhaustively in this run; #1776 came
out of the same file, so the neighbours are worth a dedicated pass.

**Bonus finding.** The pre-fix request did not only 500 — PHP also emitted
`Undefined variable $statusCodes` before the TypeError, so every failed load wrote a
`log_level=2` E_WARNING row into the Event Viewer (measured: event ids 3 and 5, one per
request). The fix removes those too.

## The fix

```diff
     $rs = $tcaseMgr->get_assigned_to_user($filterUserId, $tproject_id,
         $tplanParam, ['mode' => 'full_path'], $filters);

+    // status code map is needed by the payload below even when there is no
+    // assignment to build rows from ($rs === NULL, e.g. project without
+    // test plans): keep the read OUTSIDE the is_null() guard so
+    // array_keys() never receives null (issue #1648)
+    $statusCodes = (array)($resultsCfg['status_code'] ?? []);
+
     $groups = [];
     if (!is_null($rs)) {
         …
-    $statusCodes = $resultsCfg['status_code'];
     $tplanMgrTmp = new testplan($db);
```

### Why this approach (and what was rejected)

* **Rejected —** the issue's suggested one-liner
  `'statusKeys' => is_array($statusCodes) ? array_keys($statusCodes) : []` at `:348`.
  It silences the fatal, but then ships `statusKeys: []` — dropping a field that is part of
  the documented response contract of `GET /rows` — in exactly the case where the client is
  painting an empty-state page. It would trade a loud 500 for a silent contract break.
  *(Correction, from the code review of this diff: `statusKeys` has **no** frontend consumer —
  `grep -rn statusKeys gui/` matches nothing; `gui/templates/execute/tcAssignments.html:216-222`
  renders each row's status through its own 4-key `statusLabel()` map. So the *observable*
  difference between the two variants is nil today; the hoist is still the better fix because
  it keeps the response contract honest for any future consumer, and because it fixes the
  cause instead of papering over the consumer.)*
* **Chosen —** hoist the read above the guard. `$resultsCfg` is loaded unconditionally at
  `:111`, so the value is always available and the assignment has no side effects.
* **Defensive `(array)(… ?? [])`** so a misconfigured `results` block degrades to an empty
  key list instead of repeating the 500.
* For the non-empty path the behaviour is **byte-identical**: the cast is a no-op on an
  already-array config value and `array_keys()` order is preserved — verified by comparing
  the pre-fix and post-fix payloads for the populated fixture project.

No frontend and no i18n change was required: the screen never references `statusKeys` by
name, so no locale bundle was touched.

## Files changed

| File | Purpose |
|---|---|
| `api/tcassignments/index.php` | the fix (1 statement moved above the guard, +7 −1) |
| `tmp/fixtures_1648.php` | new fixture: project with plan + open build + a real `user_assignments` row, so the non-NULL branch is covered (gitignored, kept local) |
| `tmp/TLU_Test_Cases.md` | Suite 1648 — 10-step regression suite |
| `docs/screenshots/issue-1648-tcassignments-rows-500-before.png` | before: blank table area, dead request |
| `docs/screenshots/issue-1648-tcassignments-rows-500-after.png` | after: empty state rendered |

## Verification

| # | Case | Expected | Measured |
|---|---|---|---|
| 1 | project with 0 test plans (`tproject_id=1`) | 200 + `groups: []` | `HTTP=200 bytes=205`, `statusKeys` = the real 7 keys (was `HTTP=500 bytes=0`) |
| 2 | missing `tproject_id` | error body | `{"status":"error","message":"tproject_id is required"}` |
| 3 | unknown project | error body | `{"status":"error","message":"Test project not found"}` |
| 4 | unauthenticated | 401 | `HTTP=401` `{"status":"error","message":"Not authenticated"}` |
| 5 | project with plan + assignment | rows render | `groups: 1` (`Plan B`), 1 row with build/suite/version/platform/priority, `statusKeys` unchanged |
| 6 | `tplan_id=3`, `show_all_users=1`, `show_closed_builds=1`, `build_id=1`, `show_inactive_tplans=1&user_id=1` | unchanged | all `HTTP=200 groups=1 rows=1 statusKeys=7` |
| 7 | `POST quick_result` | unaffected | `{"status":"ok","data":{"result":"passed","tcversion_id":6}}`; the row then renders status **Passed** in the UI |
| 8 | browser, empty-plan project | empty state, clean console | network `[200]` for `/rows`, console `<no console messages found>`, DOM `No test cases assigned.` + `0 assignment(s)` |
| 9 | Event Viewer | no new Error/Warning | 3 repeats → `events max id before=7 after=7 (delta=0)` |
| 10 | `php -l api/tcassignments/index.php` | clean | *No syntax errors detected* |

## Related (filed, not fixed here)

* **#1776** — `out($data, $code = 200)` in 13 BFF endpoints overwrites the caller's
  `http_response_code()`, so cases 2 and 3 answer HTTP 200 with an error body. Pre-existing
  (identical `out()` at `HEAD`), a different symptom, and out of scope for this run.
