# Test Case Print — Modernized (Refs #1010)

The single **Test Case Print** screen (the "printer-friendly view" launched from the test-case
toolbar, legacy `lib/testcases/tcPrint.php`) is modernized into a standalone Dashio page
`gui/templates/testcases/tcPrint.html` backed by a new BFF route
`api/testcasesprint/index.php?action=tc_print`.

This was the last legacy member of the test-case toolbar family — tcEdit, tcAssign2Tplan,
tcExport and tcCompareVersions were already modernized. With this screen the whole
test-design test-case toolset runs on the modern stack.

## How it works

- **Navigation:** the Print button on the modern test-case viewer
  (`testcases/tcView.html`) opens `tcPrint.html?tproject_id=…&testcase_id=…&tcversion_id=…`
  in a popup. The legacy viewer printer-friendly buttons (`tcView_viewer.tpl`,
  `tcViewViewer.inc.tpl` → `openPrintPreview('tc', …)`) and
  `testcase.class.php::$gui->printTestCaseAction` were switched to the modern screen; a
  `$actions->printTc` entry was added in `lib/functions/common.php`.
- **The document is the legacy one.** The BFF includes `lib/testcases/tcPrint.php` at
  top-level scope (`chdir` + `ob_start`, exactly like the `print`/`download` actions for the
  Test Specification document), so the battle-tested `renderTestCaseForPrinting()`
  (lib/functions/print.inc.php) generates the identical SINGLE_TESTCASE output: external id +
  name, `[Version : n]`, author, summary, preconditions, numbered step actions + expected
  results, execution type, estimated duration, importance, requirements, keywords, platforms.
- **Rendering:** the screen embeds the returned `body_html` in a sandboxed srcdoc iframe.
  Print uses the iframe's own `print()`; Back returns to the originating `tcView.html`;
  Refresh regenerates. Locale switcher included (all 10 bundles get `tcprint.*` keys).
- **Context resolution:** the owning test project is taken from `tproject_id` when given,
  otherwise derived from the test-case tree path (`get_path()` → root `parent_id`), matching
  the legacy controller's behavior on deep links.

## Security

- Session auth (401) first; then **`mgt_view_tc` on the owning test project** gates the
  route (403) — verified in the browser with a `<no rights>` user.
- 404 `testcase_does_not_exists` / 400 missing-id paths always answer JSON (never mixed
  with the legacy document HTML).

## i18n

`tcprint.title`, `tcprint.inProject`, `tcprint.btnPrint`, `tcprint.btnBack`,
`tcprint.generating`, `tcprint.errLoad`, `tcprint.errNotFound`, `tcprint.errNoRights`,
`tcprint.errNotReady`, `tcprint.errEmpty`, `tcprint.rendered` — added to all of
`de en es fr it ja pt ro ru zh`.

## Test coverage

Regression suite **1010 · 14/14 PASS** in `tmp/TLU_Test_Cases.md`:
BFF happy path (document JSON), full render (prefix/id, version, author, summary,
preconditions, 4 steps, exec type, duration, importance, requirements/keywords/platforms),
Print button enables + invokes iframe print, Back href, Refresh, Romanian locale switch,
404 unknown test case, 400 missing id, 403 no-rights user, deep link without project
(tree-path owner resolution), modern viewer Print-button switch, legacy viewer
printer-friendly switch, i18n bundle completeness, Event Viewer clean.

## Related

- Fixing: login as a user without project context logs `getTestCasePrefix()` empty-id SQL
  error + PHP warnings — filed as GitHub issue **#1011** (pre-existing).