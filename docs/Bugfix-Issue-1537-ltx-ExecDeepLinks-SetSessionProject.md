# Issue 1537 — ltx.php item=exec deep links fatal: Call to undefined method testproject::setSessionProject()

**Issue:** [#1537](https://github.com/sebiboga/testlink-upgraded/issues/1537)
**Branch:** `fix/issue-1537-ltx-exec-setSessionProject`
**Status:** VERIFIED-FIXED

## Symptom

External execution deep links through `ltx.php` — the inner-frame path
(`?load&item=exec&build_id=..&feature_id=..`) and the outer email flow
(`?item=exec&feature_id=..&build_id=..`, built at
`lib/execute/execSetResults.php:1774`) — returned HTTP **500** with an empty body
whenever a valid testplan could be resolved. Test execution links shipped inside
email/notification messages were completely broken. Only the `xta2m` path was
unaffected (it had been repointed to the modern screen by #1255).

## Repro steps

1. Log in as admin at http://localhost:8082/index.php (admin/admin).
2. Fixtures: project `DLS2` (id=1), testplan `Plan DL` (id=7,
   `nodes_hierarchy` id=7 parent=1, node_type 5), testcase node 4, tcversion 5,
   platform id=1, build id=8, `testplan_tcversions` id=1 (testplan_id=7,
   tcversion_id=5, platform_id=1).
3. `GET /ltx.php?load&item=exec&build_id=8&feature_id=1`
4. **Before fix:** HTTP 500, empty body; app server log
   (`tmp/php_server.log`):
   `[500]: GET /ltx.php?load&item=exec&build_id=8&feature_id=1 - Uncaught Error: Call to undefined method testproject::setSessionProject() in ltx.php:334`.

**Expected:** HTTP 200 rendering the legacy frame shell with the exec dashboard
(execNavigator tree frame) exactly like TestLink 1.9.20.

## Root cause

`ltx.php:334` (`launch_inner_exec()`) called
`$tproject_mgr->setSessionProject($info['tproject_id']);`. The method
`testproject::setSessionProject()` no longer exists: it was removed from
`lib/functions/testproject.class.php` during the 2.0.1 refactor for ticket
TN2023-97 (commit `94c9adf5c`), which missed the two deep-link entry scripts.
`linkto.php:170` carried the same fatal and was fixed by #1533 (commit
`bbe577814`); `ltx.php:334` was the remaining call site — grep confirms it as the
only direct caller left in the whole tree (all other
`setSessionProject` hits are the unrelated `'setSessionProject' => true`
**option** passed to `create()`/`update()`).

The exec inner flow works because `init_args()` → `check_exec()` (ltx.php:241-252)
resolves `tplan_id`/`platform_id`/`tcversion_id` from the `feature_id` row in
`testplan_tcversions`; with a valid testplan the code then unconditionally hit the
removed method.

Legacy `setSessionProject()` (recovered from `fe154f2e6`) set
`$_SESSION['testprojectID']`, `testprojectName`, `testprojectColor`,
`testprojectPrefix`, `testprojectOptReqs/Priority/Automation`. The legacy exec
entry (`execNavigator.php` 2.0.1 dashboard reads `tproject_id` from its own args,
but the shared session block powers navBar/top-menu context and several legacy
reads) needs that block set before the inner frame renders.

## Fix

Replace the removed call with direct session assignment, using the project row
fetched with the already instantiated manager:

```diff
     $tproject_mgr = new testproject($dbHandler);
-    $tproject_mgr->setSessionProject($info['tproject_id']);
+    // Refs #1537: testproject::setSessionProject() was removed during the
+    // 2.0.1 refactor (commit 94c9adf5c) and called with an undefined method
+    // fatal. Restore its exact semantics (recovered from fe154f2e6): set the
+    // session testproject vars from the project row so the legacy inner-frame
+    // exec dashboard (execNavigator.php) keeps working for deep links.
+    $tproject = $tproject_mgr->get_by_id($info['tproject_id']);
+    if(!is_null($tproject))
+    {
+      $_SESSION['testprojectID'] = $tproject['id'];
+      $_SESSION['testprojectName'] = $tproject['name'];
+      $_SESSION['testprojectColor'] = $tproject['color'];
+      $_SESSION['testprojectPrefix'] = $tproject['prefix'];
+      $_SESSION['testprojectOptReqs'] = isset($tproject['option_reqs']) ? $tproject['option_reqs'] : null;
+      $_SESSION['testprojectOptPriority'] = isset($tproject['option_priority']) ? $tproject['option_priority'] : null;
+      $_SESSION['testprojectOptAutomation'] = isset($tproject['option_automation']) ? $tproject['option_automation'] : null;
+    }
     $op['status_ok'] = true;
```

**Alternatives considered and rejected:**
- `method_exists()` guard — silently skips setting the session, leaving the
  inner-frame/project context reading stale/missing session vars → different
  breakage.
- Repointing `item=exec` to a modern screen like `xta2m` (as done for #1255) —
  the exec inner frame intentionally loads the legacy exec dashboard
  (`execNavigator.php`) which still depends on these session vars and the 1.9.20
  frame contract; switching screens is a feature change, not a bugfix.

## Regression matrix (all executed, live)

Fixtures as above (project `DLS2` id=1, testplan 7, tcversion 5, platform 1,
build 8, feature 1).

| # | Case | Result |
|---|---|---|
| 1 | `ltx.php?load&item=exec&build_id=8&feature_id=1` (inner, feature_id) | **200** (was 500); frmInner with treeframe `execNavigator.php?...setting_testplan=7...` + workframe `execSetResults.php?...setting_testplan=7...`; server log line 02:28:46 `[200]` |
| 2 | `ltx.php?load&item=exec&build_id=8&tplan_id=7&platform_id=1&tcversion_id=5` (inner, 3-key) | **200** (was 500); execNavigator treeframe |
| 3 | `ltx.php?item=exec&build_id=8&feature_id=1` (outer email flow, no `load`) | **200** (was 500) |
| 4 | `ltx.php?load&item=xta2m&user_id=1&build_id=8&feature_id=1` (regression #1255) | **200**, modern screen |
| 5 | Browser end-to-end (headless Chrome, admin/admin) exec deep link | exec dashboard renders: "Execute Tests / Plan DL / Build 8 / DLS2-" (screenshot `issue-1537-exec-dashboard.png` in tmp/wiki-repo) |
| 6 | Event Viewer / `events` table | no new Error/Warning — only `log_level=16` INFO `audit_login_succeeded` rows (the session-project block is confirmed set by the treeframe rendering Plan DL/Build 8/DLS2, row 5) |
| 7 | Syntax gates | `php -l ltx.php` clean |

## New bug discovered while testing (separate issue #1538)

With the ltx.php fatal gone, the exec **workframe** (`execSetResults.php`) still
returns HTTP 500 `Uncaught Exception: Bad Test Project ID`
(execSetResults.php:706) on the deep-link URL. Measured root cause (probe debug):
`getSettingsAndFilters()` (execSetResults.php:2275) falls back, when there is no
`form_token` (the ltx-generated workframe URL has none, ltx.php:212-217), to
`$_REQUEST[$prop]` = `tplan_id`/`build_id`/`platform_id` — but the URL carries
`setting_testplan`/`setting_build`/`setting_platform`. Result: `AFTER-GSAF
tplan_id=0 ...` even though `REQUEST={"setting_testplan":"7"}` → no settings
resolved → project cannot be derived → exception. This is long-standing upstream
code (present since commit 2fe19c4b6), unrelated to the `setSessionProject`
removal. Filed as **#1538** and deliberately NOT fixed in this run (FIX-ISSUE rule:
never expand scope; log new bugs).

## How the method was chosen

Reproduced the HTTP 500 first (curl + fresh DB fixtures + the app's own
`tmp/php_server.log`), then pinned the fatal statically: the method exists nowhere
in `lib/functions/testproject.class.php` (grep → 0 hits) and git history shows its
removal in commit `94c9adf5c`. The removed method's semantics were recovered from
`fe154f2e6`. The minimal correct fix restores those exact semantics inline —
identical approach to the already-verified #1533 fix — with no refactoring and no
shared-infrastructure changes.