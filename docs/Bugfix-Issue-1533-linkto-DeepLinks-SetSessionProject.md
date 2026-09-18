# Issue 1533 — linkto.php inner-frame deep links (item=reqspec/testcase/testsuite) fatal: Call to undefined method testproject::setSessionProject()

**Issue:** [#1533](https://github.com/sebiboga/testlink-upgraded/issues/1533)
**Branch:** `fix/issue-1533`
**Status:** VERIFIED-FIXED

## Symptom

Any legacy inner-frame deep link `linkto.php?load&tprojectPrefix=<P>&item=reqspec|testcase|testsuite&id=..`
returned HTTP **500** with an empty body on every hit. External bookmarks /
shared links for requirement specifications, test cases and test suites were
completely broken. Only `item=req` deep links worked (they were repointed to the
modern direct-link resolver by issue #1532, before the fatal line).

## Repro steps

1. Log in as admin at http://localhost:8082/index.php (admin/admin).
2. `GET /linkto.php?load&tprojectPrefix=DLS2&item=reqspec&id=RS-DL`
   (any inner-frame deep link, non-`req`).
3. **Before fix:** HTTP 500, empty body; PHP log:
   `PHP Fatal error: Uncaught Error: Call to undefined method testproject::setSessionProject() in linkto.php:170`.

**Expected:** HTTP 200 rendering the legacy frame shell (`frmInner.tpl`) with the
appropriate tree + work frame, exactly like TestLink 1.9.20.

## Root cause

`linkto.php:170` (inner-frame branch) called
`$tproject->setSessionProject($tproject_data['id']);`. The method
`testproject::setSessionProject()` no longer exists: it was removed from
`lib/functions/testproject.class.php` during the 2.0.1 refactor for ticket
TN2023-97 (commit `94c9adf5c`), which also deleted its call sites inside
`create()`/`update()` but missed the two deep-link entry scripts. Grep confirms
only `linkto.php:170` and `ltx.php:334` still call it.

`item=req` was already shielded: both the outer-frame (`linkto.php:84-92`) and
inner-frame (`:160-168`) branches redirect to the modern resolver
(`gui/templates/links/directLink.html` + BFF `api/directlink`) before reaching
the fatal, which is exactly why requirement deep links worked while the other
three item types always 500'd.

Legacy `setSessionProject()` (from git `fe154f2e6`) set
`$_SESSION['testprojectID']`, `testprojectName`, `testprojectColor`,
`testprojectPrefix`, `testprojectOptReqs/Priority/Automation` and tLog'd. The
2.0.1 codebase otherwise sets `$_SESSION['testprojectID']` directly
(common.php:459, users.inc.php:55/65, api/*), but the legacy inner-frame target
screens still read the whole block — e.g. `reqSpecView.php:44-45` reads
`$_SESSION['testprojectID']` **and** `$_SESSION['testprojectName']`,
`archiveData.php:103` reads `testprojectID`.

## Fix

Replace the removed call with direct session assignment, reusing the
`$tproject_data` row already fetched by `get_by_prefix()` at `linkto.php:152`
(returns id/name/color/prefix/option_reqs/option_priority/option_automation):

```diff
-      $tproject->setSessionProject($tproject_data['id']);
-      $op['status_ok'] = isset($itemCode[$args->item]);
+      $_SESSION['testprojectID'] = intval($tproject_data['id']);
+      $_SESSION['testprojectName'] = $tproject_data['name'];
+      $_SESSION['testprojectColor'] = $tproject_data['color'];
+      $_SESSION['testprojectPrefix'] = $tproject_data['prefix'];
+      $_SESSION['testprojectOptReqs'] = isset($tproject_data['option_reqs']) ? $tproject_data['option_reqs'] : null;
+      $_SESSION['testprojectOptPriority'] = isset($tproject_data['option_priority']) ? $tproject_data['option_priority'] : null;
+      $_SESSION['testprojectOptAutomation'] = isset($tproject_data['option_automation']) ? $tproject_data['option_automation'] : null;
+      $op['status_ok'] = isset($itemCode[$args->item]);
```

**Alternatives considered and rejected:**
- `method_exists()` guard — silently skips setting the session, leaving the
  inner-frame screens reading stale/missing `$_SESSION['testprojectID']` /
  `testprojectName` → different breakage.
- Repointing reqspec/testcase/testsuite to the modern resolver — the BFF
  `api/directlink` resolves `req` only; the other item types have no modern
  resolver yet (that is the ongoing modernization work, not a bugfix).

Additionally, the legacy i18n key `testsuite_not_found` was missing from every
`locale/*/strings.txt` bundle (zero hits in the current tree and in git history).
`process_testsuite()` (linkto.php:486) calls `lang_get('testsuite_not_found')`
on every testsuite deep link — that produced a `log_level=32` "not localized"
LOCALIZATION Event Viewer warning per session, which became reachable now that
testsuite deep links no longer 500. Added
`$TLS_testsuite_not_found = "Test suite %s not found in test project (prefix:%s).";`
to all 14 full locale bundles (the 5 partial bundles fi_FI, id_ID, it_IT, ko_KR,
ro_RO fall back to en_GB via `lang_api.php` and were skipped).

## Regression matrix (all executed, live)

Fixtures: project `DLS2` (id=1), reqspec `RS-DL` (id=2, revision node id=6),
testsuite (id=3), testcase `DLS2-1` (id=4, tcversion id=5).

| # | Case | Result |
|---|---|---|
| 1 | `linkto.php?load&tprojectPrefix=DLS2&item=reqspec&id=RS-DL` | **200**, treeframe `reqSpecListTree.php`, workframe `reqSpecView.php?req_spec_id=2` (was 500) |
| 2 | `linkto.php?load&tprojectPrefix=DLS2&item=testcase&id=DLS2-1` | **200**, workframe `archiveData.php?edit=testcase&id=4&tproject_id=1` (was 500) |
| 3 | `linkto.php?load&tprojectPrefix=DLS2&item=testsuite&id=3` | **200**, workframe `archiveData.php?...print_scope=test_specification...&id=3` (was 500) |
| 4 | `reqSpecView.php?req_spec_id=2` (session assertions) | **200**, renders "[RS-DL] :: Spec DL" — proves `$_SESSION['testprojectID']`+`testprojectName` set |
| 5 | `linkto.php?load&tprojectPrefix=DLS2&item=req&id=..` (regression #1532) | **302** → `gui/templates/links/directLink.html?item=req` modern resolver, unchanged |
| 6 | `item=bogus` | legacy `Invalid item (bogus)`, no 500 |
| 7 | `tprojectPrefix=NOPE` | legacy `Testproject with prefix (NOPE) does not exist`, no 500 |
| 8 | Browser (chrome-devtools) end-to-end, all 3 items | outer shell + inner tree/work frames render; no console errors |
| 9 | Event Viewer / `events` table | no LOCALIZATION warning, no DB error, no fatal on the deep-link flow (only pre-existing legacy-template E_WARNINGs — filed as #1536) |
| 10 | Syntax gates | `php -l linkto.php` clean; `php -l locale/*/strings.txt` clean on all 19 bundles; diff = exactly 14 files × +1 line |

## Side findings (separate issues)

- **#1536** — `reqSpecView.php` / `archiveData.php` emit ~7 legacy-template
  E_WARNINGs per view (`$gui->tproject_id`, `$gui->tplan_id`,
  `$gui->tprojOpt->testPriorityEnabled`, `$gui->tprojOpt->automationEnabled`,
  null array offset in `tcViewViewer.inc.tpl`). Pre-existing in the upgraded
  templates (reproducible by direct URL, no linkto involvement); tracked for the
  CI.
- **#1537** — `ltx.php` (item=exec, execution deep links from email
  notifications) calls the same removed `setSessionProject()` at `ltx.php:334`
  → HTTP 500. Same root cause, separate entry script.

## How the method was chosen

Reproduced the HTTP 500 first (curl + fresh DB fixtures), then pinned the fatal
statically: the method exists nowhere in `lib/functions/testproject.class.php`
(grep `function setSession*` → 0 hits) and git history shows its removal in
commit `94c9adf5c`. The removed method's semantics were recovered from
`fe154f2e6` (the commit that introduced it). The minimal correct fix restores
those exact semantics inline from the already-fetched project row — no
refactoring, no change to shared infrastructure, and the legacy inner-frame
screens render exactly as they did in 1.9.20.