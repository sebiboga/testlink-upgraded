# Issue 1538 — execSetResults.php deep-link workframe 500 'Bad Test Project ID': getSettingsAndFilters() fallback reads $_REQUEST[prop] instead of $_REQUEST[setting_*]

**Issue:** [#1538](https://github.com/sebiboga/testlink-upgraded/issues/1538)
**Branch:** `fix/issue-1538-getsettingsandfilters`
**Status:** VERIFIED-FIXED

## Symptom

After the #1537 `ltx.php` fatal was fixed, execution deep links — the inner flow
(`ltx.php?load&item=exec&build_id=..&feature_id=..`, workframe built at
`ltx.php process_exec():212-217`) and the modern assigned-tc links
(`mainPage.html:516`, `tcAssignments.html:354`) — rendered the execNavigator
dashboard (tree frame) but the exec **workframe**
(`lib/execute/execSetResults.php?level=testcase&version_id=..&id=..&setting_testplan=..&setting_build=..&setting_platform=..`)
returned HTTP **500** `Uncaught Exception: Bad Test Project ID`
(`execSetResults.php:706`). The testcase results pane never loaded. 100% of
form-tokenless deep links affected.

## Repro steps

1. Log in as admin at http://localhost:8082 (admin/admin).
2. Fixtures: `php tmp/fixtures_1538.php` (project `DL` id=37, testplan
   `Plan DL` id=38, testsuite `Fixture Suite` id=39, tcase node 40, tcversion 41,
   platform `Win10` id=9, build id=3, `testplan_tcversions` id=3 @ platform 9).
3. `GET /lib/execute/execSetResults.php?level=testcase&version_id=41&id=40&setting_testplan=38&setting_build=3&setting_platform=9`
   (no `form_token` — exactly the URL ltx.php builds for the workframe).
4. **Before fix:** HTTP 500, empty body; app server log
   (`tmp/php_server.log`):
   `[500]: GET /lib/execute/execSetResults.php?level=testcase&version_id=41&id=40&setting_testplan=38&setting_build=3&setting_platform=9 - Uncaught Exception: Bad Test Project ID in .../lib/execute/execSetResults.php:706`.

**Expected:** HTTP 200 rendering the execution pane for the resolved
testplan/build/platform with the testcase steps and Passed/Failed/Blocked
controls, exactly like a normal navigator flow.

## Root cause

`init_args()` (`execSetResults.php:546`) → `getSettingsAndFilters()`
(`execSetResults.php:2275`) maps props to cache keys:

```php
$settings = [
  'tplan_id'    => 'setting_testplan',
  'build_id'    => 'setting_build',
  'platform_id' => 'setting_platform',
];
```

The loop (`:2304`) seeds each prop from the session cache
(`isset($cache[$cacheKey])`); with **no form_token** the cache is null, so every
prop falls through to the form-tokenless fallback (`:2307-2311`):

```php
// BEFORE (broken): reads the prop names ...
$argsObj->$prop = isset($_REQUEST[$prop]) ? $_REQUEST[$prop] : null;
//                     ^^^^ tplan_id / build_id / platform_id — NOT in the URL
```

Deep links carry the **cache-key** names (`setting_testplan`/`setting_build`/
`setting_platform`), so `tplan_id` stayed null → `intval()` → 0 → the project
derivation at `:699-702` (`if (tproject_id<=0 && tplan_id>0)`) never ran →
`:705-706` threw `Bad Test Project ID`.

Git-history proof of regression: commit `ff21e9920` ("new class build.class.php,
not on testplan.class.php") had the original upstream fallback reading
`$_REQUEST[$sfKey]` (`setting_*`). Commit `857555dad` ("Fix test execution and
reporting flow") flipped it to `$_REQUEST[$prop]`, breaking every form-tokenless
deep link since. `git log -L 2304,2317:lib/execute/execSetResults.php` shows the
exact diff.

## Fix

Read the cache-key name first, keep the prop name as the secondary fallback for
legacy callers that pass `tplan_id` directly:

```diff
     if (is_null($argsObj->$prop)) {
       // let's this page be functional withouth a form token too 
       // (when called from testcases assigned to me)
-      $argsObj->$prop = isset($_REQUEST[$prop]) ? 
-                        $_REQUEST[$prop] : null;
+      $argsObj->$prop = isset($_REQUEST[$cacheKey]) ? 
+                        $_REQUEST[$cacheKey] :
+                        (isset($_REQUEST[$prop]) ? $_REQUEST[$prop] : null);
     }
```

Why this is correct and safe:
- **Settings group** (the bug): deep links and the filter panel submit the
  cache-key names (`inc_filter_panel.tpl:93,106,118` `name="setting_testplan|build|platform"`),
  so `setting_*` is now picked up — the 500 is gone.
- **Legacy "testcases assigned to me" callers** (`mainPage.html:516`,
  `tcAssignments.html:354`) pass `tplan_id` directly plus `setting_build`/
  `setting_platform` — the `$prop` fallback keeps them working.
- **Filter group**: the filter map is also prop≠cacheKey (`filter_status`→
  `filter_result_result`, `filter_assigned_to`→`filter_assigned_user`,
  `execution_type`→`filter_execution_type`, `priority`→`filter_priority`,
  `filter_cfields`→`filter_custom_fields`) and the panel submits the cache-key
  names (`inc_filter_panel.tpl:448,451,382,390,368,355`), so cache-key-first
  repairs the form-tokenless filter path too; nothing regressed because the
  `$prop` fallback remains.
- The session-cache path (`:2305`) is untouched — normal navigator flows are
  unaffected.

**Alternatives considered and rejected:** (a) settings-only special case —
needless complexity; the single cache-key-first rule covers settings and filters
uniformly. (b) Reverting to upstream exactly (`$_REQUEST[$cacheKey]` only) —
would break the `tplan_id` legacy callers that the `857555dad` change was
preserving. The dual-key fallback is the minimal fix that satisfies both.

## Regression matrix (all executed, live)

Fixtures as above (project 37 / testplan 38 / tcversion 41 / tcase node 40 /
platform 9 / build 3 / feature 3).

| # | Case | Result |
|---|---|---|
| 1 | Deep-link workframe URL (`setting_*` only, no form_token) | **200** (was 500); browser renders "Test Results on Build Build 8 - Platform : Win10 / Test Plan Plan DL / Test Case DL-1 :: Deep Link Case" with step row + Passed/Failed/Blocked (screenshot `issue-1538-exec-workframe-fixed.png`) |
| 2 | Legacy caller passing `tplan_id` directly (`…&tplan_id=38&setting_build=3&setting_platform=9`) | **200** — prop fallback preserved |
| 3 | `ltx.php?load&item=exec&build_id=3&feature_id=3` (real deep-link shell) | **200**; shell wires treeframe `execNavigator.php?...setting_*` + workframe `execSetResults.php?...setting_*` |
| 4 | Deep link + filter cache-key params (`filter_priority`, `filter_execution_type`) | **200**, no error |
| 5 | Event Viewer / `events` table after all page loads | no new Error/Warning (log_level ≥ 32); the only INFO rows (log_level 16) are the fixture script's own ASSIGN/CREATE/DELETE |
| 6 | Syntax gate | `php -l lib/execute/execSetResults.php` clean |

## How the fix was chosen

Reproduced the HTTP 500 first (curl + fresh DB fixtures + `tmp/php_server.log`
evidence: `[500] ... Uncaught Exception: Bad Test Project ID in
...execSetResults.php:706`), then pinned the regression statically with
`git log -L` on the fallback lines (upstream `ff21e9920` `$_REQUEST[$sfKey]` →
`857555dad` `$_REQUEST[$prop]`). Audited every caller of the form-tokenless path
(modern assigned-tc links, ltx.php workframe, filter panel) to confirm which
REQUEST keys each submits, then applied the one-line dual-key fallback. No
refactoring, no shared-infrastructure changes.