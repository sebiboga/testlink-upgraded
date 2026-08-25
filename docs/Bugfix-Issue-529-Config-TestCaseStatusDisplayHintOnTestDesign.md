# Issue 529 — Undefined config option `testCaseStatusDisplayHintOnTestDesign` warning on every tcView render

**Issue:** [#529](https://github.com/sebiboga/testlink-upgraded/issues/529)
**Branch:** `fix/issue-529` · **Commits:** `ab4125d00` (fix), `49461dcc3` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-25)

## Symptom

Every test case view (tcView) render produced a WARNING event in the Event Viewer:

```
config option not available: testCaseStatusDisplayHintOnTestDesign
```

This fired for every user on every tcView page load — 1 warning per render.

The other two items reported in #529 (`remove_plat_msgbox_title`/`remove_plat_msgbox_msg` missing lang keys and `executed_me_and_also` missing lang key) were already fixed by issue #644 (commit `7430ce208`).

## Root cause chain

1. `lib/functions/testcase.class.php:7464` calls `config_get('testCaseStatusDisplayHintOnTestDesign')` unconditionally on every tcView render, assigning the result to `$goo->TCWKFStatusDisplayHintOnTestDesign`.
2. `config_get()` in `lib/functions/common.php:672-700` looks for the option in `$GLOBALS["g_testCaseStatusDisplayHintOnTestDesign"]` and `$GLOBALS["tlCfg"]->testCaseStatusDisplayHintOnTestDesign`. Neither exists — the option was never defined in any config file.
3. When not found, `common.php:675` logs `"config option not available: testCaseStatusDisplayHintOnTestDesign"` at WARNING level, which surfaces in the Event Viewer.

### What the option does

The return value is consumed in `gui/templates/tl-classic/testcases/tcView_viewer.tpl:107`:

```smarty
{foreach from=$gui->TCWKFStatusDisplayHintOnTestDesign key=wkfStatusVerbose item=lblKey}
```

It maps workflow status verbose codes to lang-key labels, displaying a hint overlay on the test case design view when a particular status is set. When empty, no hint is shown. The Dashio tcView template does not use this variable.

## Fix

Defined the option as an empty array in `config.inc.php` after line 1943, near the other `$tlCfg->testCaseStatus*` options:

```php
$tlCfg->testCaseStatusDisplayHintOnTestDesign = array();
```

This suppresses the WARNING by making the option discoverable by `config_get()`, while producing no visible change (empty array = no hints shown, same as current behavior).

## Files changed

| File | Change |
|---|---|
| `config.inc.php` | +6 lines: 5-line comment block + `$tlCfg->testCaseStatusDisplayHintOnTestDesign = array();` |

## Verification

- `php -l config.inc.php` PASS
- Live tcView render: **0 new** `"config option not available"` events in Event Viewer
- tcView renders correctly: HTTP 200, all UI elements present
- Regression suite: `Suite 529` in `tmp/TLU_Test_Cases.md` — 5/5 PASS
