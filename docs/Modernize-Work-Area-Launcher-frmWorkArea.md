# Modernize: Work Area Launcher (`lib/general/frmWorkArea.php`) — redirect shim

**Refs #1575** — last `lib/` entry point removed from `lib/functions/common.php`.

## Context

In TestLink 1.9.20 the work area is a frameset: the ASIDE/left tree and the
test-plan navigator load controllers that call `lib/general/frmWorkArea.php` with
a `feature` argument; that controller then re-renders the inner frameset
(`frmInner.tpl`) around a tree pane and a content pane.

In TestLink 2.0.1 every user-facing screen has been modernized into a standalone
Dashio `gui/templates/**/*.html` page backed by a REST BFF. Every ASIDE entry and
every modern screen already links straight to its `.html` twin — the only thing
still pointing at the legacy work-area launcher was `lib/functions/common.php`
(the `$launcher` string, the `$gui->workArea` object and its copy-back loop used
by the dead `tl-classic` templates `mainPageLeft.tpl`/`mainPageRight.tpl`) and
stale bookmarks / deep links hitting `frmWorkArea.php?feature=…`.

`showMetrics` was the only feature that still routed through the launcher at
runtime (it was only set when a test plan was active and never overridden later).

## What changed

### `lib/general/frmWorkArea.php` → redirect shim (83 lines)

- `testlinkInitPage($db,TRUE)` keeps the session guard: anonymous users get the
  standard `login.php?note=expired&destination=…` JS redirect (verified).
- `$feature_map` translates every legacy feature name to its modern screen:

| legacy feature | modern screen (`gui/templates/`) |
|---|---|
| `editTc` | `testcases/testSpec.html` |
| `assignReqs` | `requirements/assignReqs.html` |
| `searchTc` | `search/searchView.html` |
| `searchReq` | `requirements/searchReq.html` |
| `searchReqSpec` | `requirements/searchReqSpec.html` |
| `printTestSpec` | `testcases/printTestSpec.html` |
| `printReqSpec` | `requirements/printReqSpec.html` |
| `keywordsAssign` | `keywords/keywordsAssign.html` |
| `planAddTC` / `planRemoveTC` | `plans/planAddTCView.html` |
| `planUpdateTC` | `plans/planUpdateTC.html` |
| `show_ve` | `plans/planNav.html` |
| `newest_tcversions` | `plans/showNewestTcVersions.html` |
| `test_urgency` | `plans/testUrgency.html` |
| `tc_exec_assignment` | `execute/tcExecAssignment.html` |
| `executeTest` | `execute/execTest.html` |
| `showMetrics` | `results/resultsNavigator.html` |
| `reqSpecMgmt` | `requirements/reqSpecMgmt.html` |

- Redirect URL = `basehref + screen + ?tproject_id={session}&tplan_id={session}`;
  request `tproject_id`/`tplan_id` override the session values when > 0.
- Unknown feature → `tLog(...,'ERROR')` + `exit()` (exact legacy parity).

### `lib/functions/common.php` — launcher mechanism removed

- `$launcher` + `$gui->workArea` object + copy-back loop deleted (replaced by
  explicit nulls: `executeTest`/`planUpdateTC`/`showNewestTCV`/`assignTCVExecution`/
  `showMetrics`).
- `$tplan_id > 0`: `showMetrics` now points straight at
  `/gui/templates/results/resultsNavigator.html?{ctx}` (Refs #1568) and
  `setTestUrgency` at `/gui/templates/plans/testUrgency.html?{ctx}`.
- All later modern `$actions->*` overrides (testSpec, reqSpecMgmt, planUpdateTC,
  assignTCVExecution, showNewestTCV, executeTest, keywordsAssign, searchReq,
  searchReqSpec, printReqSpec, printTestSpec, resultsNav, planNav, assignReq,
  tcSearch, fullTextSearch) verified intact.
- `$gui->launcher` is intentionally kept: only the dead `tl-classic` templates
  build `?feature=` URLs from it, and those now correctly hit the shim.
- `$gui->uri = $actions;` (line ~2200) unchanged — the aside BFF keeps working.

## Verification

Browser (admin session, fixture `tmp/fixtures_1575.php`: tproject=13 tplan=14
build=3 suite=15 tc1={16,17} tc2={19,20}):

- Every `?feature=` deep link → the expected modern screen (all 18 map entries),
  incl. `planUpdateTC → planUpdateTC.html` (canonical, matches aside BFF) and
  `show_ve → planNav.html`.
- No-session deep link → login redirect preserving `destination`.
- `feature=INVALID_FEATURE` → stays on source (tLog + exit, legacy parity).
- Aside/Reports menu fully rendered; dashboard loads; no console errors.
- Event Viewer: no new Error/Warning rows (fixture `link_tcversions` warning
  rows scrubbed; the `Wrong page argument feature` INFO row is legacy parity).

Code review (subagent) caught and fixed: unguarded `$_SESSION['testprojectID'/
'testplanID']` reads (isset guards) and `planUpdateTC` → `planUpdateTC.html`.

Suite **1575** appended to `tmp/TLU_Test_Cases.md`; test cases exercised in the
browser. Screenshot: `tmp/shot_1575_showMetrics.png`.