# Task 1038 — "Test Plan usage" section in the Test Case Viewer (gap vs legacy)

**Issue:** [#1038](https://github.com/sebiboga/testlink-upgraded/issues/1038)
**Status:** IMPLEMENTED (2026-09-30)
**Screen:** ASIDE → Test Specification → *Test Case Viewer*
(`gui/templates/testcases/tcView.html?tcase_id=<id>[&tcversion_id=<id>][&editOnExec=1]`)
**BFF:** `api/testcases/index.php` (`action=view`)

## The gap

1.9.20 ends every version panel of the test case viewer with the
"Test Case version Test Plan Assignment" block
(`gui/templates/dashio/testcases/tcView_viewer.tpl:599-605`, same block in the
older `include/tcViewViewer.inc.tpl:627-632`):

```smarty
{if 'editOnExec' != $gui->show_mode &&
  $args_linked_versions != null && $tlCfg->spec_cfg->show_tplan_usage}
  {include file="{$tplConfig['quickexec.inc']}" args_edit_enabled=$edit_enabled}
{/if}
```

`quickexec.inc.tpl` renders a table — header `test_plan_usage`, columns
`version` and `sort_hint test_plan`, plus `sort_hint platform` **only when the
project has platforms** — with one row per *(version, test plan, platform)*
link: the version number, the escaped plan name followed by an anchor
(`$execFeatureAction` = `lib/general/frmWorkArea.php?feature=executeTest`,
`target="_parent"`, `title=goto_execute`, `$tlImages.execute` icon), and the
platform name when `$version_info.platform_id > 0`.

The data came from `testcase::get_linked_versions()`
(`lib/functions/testcase.class.php:1706-1795`, called at `:1077-1079`):
`testplan_tcversions` joined to `nodes_hierarchy` for the plan name, filtered
`NH.parent_id = <tcase id>`, returned as `version → plan → platform → row`.

The modern viewer had **none** of it: no section, and the `view` payload only
carried the boolean `hasTestPlans` (needed for the "Add to Test Plan" button),
so even the API could not answer "which plans is this version in?".

## What was implemented

**BFF — `api/testcases/index.php`, `action=view`**

* new payload key `tplanUsage` = `{ enabled: bool, rows: [ … ] }`;
* `rows[]` = `{ tcversion_id, version, testplan_id, tplan_name, platform_id, platform_name }`
  from one join over `testplan_tcversions` + `nodes_hierarchy` (version and plan
  nodes) + `tcversions` + `platforms` (LEFT JOIN, so a link without platform
  keeps its row with an empty platform cell), ordered by version then plan name;
* `enabled` reproduces the legacy gate: `spec_cfg->show_tplan_usage`
  (`config.inc.php:1258`, default TRUE) **and** the request is not an
  edit-on-execute request (`editOnExec=1`, legacy `show_mode == 'editOnExec'`),
  in which case the rows are left empty;
* a DB error degrades to `rows: []` (the section simply disappears) instead of
  breaking the whole viewer payload.

**Screen — `gui/templates/testcases/tcView.html`**

* `renderTplanUsage(v)` builds the legacy table per version card, appended
  after the Attachments block, using the `table.steps` styling already used by
  the steps table; it returns an empty string when the version has no links, so
  no empty section is drawn (legacy behaviour for an empty map);
* columns: `Version` (existing `tcview.version` key), `Test Plan`
  (`tcview.tplanUsagePlan`) with the execute shortcut, and `Platform`
  (`tcview.tplanUsagePlatform`) rendered **only when the project has platforms**
  — the modern equivalent of legacy `$gui->platforms != null`, which is the
  already-present `platformsProject` payload key;
* the platform cell is empty for `platform_id = 0` (legacy `platform_id > 0`
  guard);
* the execute link points at
  `/gui/templates/execute/execTest.html?feature=executeTest&tproject_id=<owning project>&tplan_id=<row plan>`
  and opens in a new tab. This is the modern resolution of the legacy route
  (`lib/general/frmWorkArea.php:42` maps `executeTest` to that screen) and,
  unlike legacy, carries the **row's** `tplan_id` — legacy omitted it and fell
  back to the session plan, so the shortcut could open a plan unrelated to the
  row;
* `loadView()` and `platRefreshMain()` now forward `editOnExec`, so the re-render
  after a platform assign/unassign keeps the legacy suppression.

**i18n** — 4 new keys (`tcview.tplanUsage`, `tcview.tplanUsagePlan`,
`tcview.tplanUsagePlatform`, `tcview.gotoExecute`) appended to all 10 locale
bundles (de, en, es, fr, it, ja, pt, ro, ru, zh); texts ported from the legacy
`locale/<L>/strings.txt` entries `testplan_usage`, `test_plan`, `platform`,
`goto_execute`.

**Deliberate difference from legacy** — legacy injected the *whole* link map of
the test case into *every* version panel (duplicated N times for N versions);
the modern screen lists each version's own rows in its own card, which is the
same information without the duplication.

## Verification

* `tmp/verify_1038.sh` → **21 PASS / 0 FAIL** (payload shape, both platform
  branches, `editOnExec` gate, single-version request, unlinked case,
  unauthenticated / bad-id paths, all 10 bundles valid and complete).
* Browser (headless Chrome): both version cards show the section, the tables
  read `1 | TPU Plan One | Chrome/Linux` and `2 | TPU Plan Two | (empty)`, the
  shortcut opens the row's own plan, `?locale=de` renders
  `TESTPLAN-NUTZUNG / Testplan / Plattform / Zur Ausführung`, the unlinked case
  shows no section, console clean, **0 new Error/Warning rows in `events`**.
* Suite `1038` in `tmp/TLU_Test_Cases.md`.

## Files

| File | Purpose |
|---|---|
| `api/testcases/index.php` | `tplanUsage` payload (query + legacy gate) |
| `gui/templates/testcases/tcView.html` | `renderTplanUsage()` + `editOnExec` forwarding |
| `gui/templates/i18n/*.json` (10) | 4 new keys |
| `tmp/fixtures_1038.sql` | project + 2 plans + platform + case with 2 linked versions |
| `tmp/verify_1038.sh` | re-runnable 21-assertion matrix |
| `docs/screenshots/issue-1038-tcview-testplan-usage.png` | screenshot |
