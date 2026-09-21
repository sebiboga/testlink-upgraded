# Test Case Print — Modernized (Refs #1010)

The single **Test Case Print** screen (the "printer-friendly view" launched from the test-case
toolbar, legacy `lib/testcases/tcPrint.php`) is modernized into a standalone Dashio page
`gui/templates/testcases/tcPrint.html` backed by a new BFF route
`api/testcasesprint/index.php?action=tc_print`.

This was the last legacy member of the test-case toolbar family — tcEdit, tcAssign2Tplan,
tcExport and tcCompareVersions were already modernized. With this screen the whole
test-design test-case toolset runs on the modern stack.

> **Parity sign-off (#1335, task bookkeeping issue — "Nothing missing"):** re-verified on a
> fresh DB (2026-09-21) with fixture `tmp/fixtures_1335.php` + suite **1574 · 9/9 PASS** in
> `tmp/TLU_Test_Cases.md`. BFF happy path (full SINGLE_TESTCASE doc: prefix/external id,
> `[Version : n]`, author, summary, preconditions, steps, execution type, est. duration,
> importance, custom-field values, requirements, keywords, platforms), deep-link owner
> resolution without `tproject_id`, 400/404/403 error paths (localized banners on screen),
> Print/Back/Refresh, `?locale=ro` switch, modern `tcView.html` Print-button entry point and
> Event hygiene all confirmed. No product code changes were needed. Screenshot:
> `docs/screenshots/issue-1335-tcprint-normal.png`.

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

- Regression suite **1010 · 14/14 PASS** in `tmp/TLU_Test_Cases.md`:
  BFF happy path (document JSON), full render (prefix/id, version, author, summary,
  preconditions, 4 steps, exec type, duration, importance, requirements/keywords/platforms),
  Print button enables + invokes iframe print, Back href, Refresh, Romanian locale switch,
  404 unknown test case, 400 missing id, 403 no-rights user, deep link without project
  (tree-path owner resolution), modern viewer Print-button switch, legacy viewer
  printer-friendly switch, i18n bundle completeness, Event Viewer clean.
- Parity re-verification suite **1574 · 9/9 PASS** (Refs #1335): see the sign-off note above.

## Related

- Fixing: login as a user without project context logs `getTestCasePrefix()` empty-id SQL
  error + PHP warnings — filed as GitHub issue **#1011** (pre-existing).
- Bookkeeping: #1335 (analyzer "nothing missing") → closed after this re-verification;
  legacy cleanup tracked in #1336 (Delete legacy tcPrint).