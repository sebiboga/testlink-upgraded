# tcAssign2Tplan — Modernized (Issue #1321)

Modernized DataTables parity for "Assign Test Case to Test Plan"
(`gui/templates/testcases/tcAssign2Tplan.html`).

## Was
The plan grid was a plain HTML table — no sort, search, pagination, or
per-page length menu (a regression vs legacy `dashio/testcases/tcAssign2Tplan.tpl`
which used `DataTables.inc.tpl` with `DataTablesSelector="#item_view"` and
length menu `[[25,50,75,-1],[25,50,75,"All"]]`).

## Now
- DataTables 1.13.7 (Bootstrap 5 theme, CDN) included: CSS
  `dataTables.bootstrap5.min.css`, JS `jquery.dataTables.min.js` +
  `dataTables.bootstrap5.min.js` (same CDN pattern as `suiteView`/`tcScripts`).
- `renderPlans()` re-initializes `#planTable` with:
  - `pageLength: 25`, `lengthChange: true`, length menu `[25,50,75,All]`
  - `searching: true`, search box (localized via `common.search`)
  - `orderable` columns, checkbox column non-orderable
  - destroys any prior instance (`DataTable(...).destroy()`) before re-init,
    avoiding "cannot reinitialise DataTable" on repeated adds/re-renders.

## Verification
- Legacy/modern diff measured during INVESTIGATION (issue comment).
- JS+i18n statically validated (syntax check, all bundle keys present).
- Browser round-trip requires a seeded testproject/testplan/testcase — the
  CI-injected DB is a fresh import with no such fixture yet, so full UI
  interaction scripting is pending a fixture (`tmp/TLU_Test_Cases.md` lists
  the manual steps).

---

# tcAssign2Tplan — Navigation Icons (Issue #1320)

Restored the legacy quick-navigation icons next to the test-case identity in
the toolbar (legacy `dashio/testcases/tcAssign2Tplan.tpl:47-51` dropped them
during modernization).

## Was
Toolbar showed only `Test Case: <name>` as plain text — no way to jump to the
Execution History or the Test Case Viewer while the assign popup is open.

## Now
`gui/templates/testcases/tcAssign2Tplan.html` toolbar renders two icon links
right after the test-case identity (hidden until the screen loads):
- clock icon (title `ta2p.execHistory`) → `openHistory()` →
  `window.open('/gui/templates/execute/execHistory.html?tcase_id=..&tproject_id=..', 'execHistory_<id>', 800x600)`.
- pencil icon (title `ta2p.design`) → `openDesign()` →
  `window.open('/gui/templates/testcases/tcView.html?tcase_id=..&tproject_id=..', 'tcView_<id>', 900x700)`.

This mirrors legacy `openExecHistoryWindow()` / `openTCaseWindow()`
(`gui/javascript/testlink_library.js:1720/1003`) using the established Dashio
popup convention (`tcAssignments.html:362-368`, `tcasesWithCF.html:102-110`).
i18n keys `ta2p.execHistory` + `ta2p.design` added to all 10 locale bundles.

## Verification
- Both icons open the correct modern screens (`execHistory.html`, `tcView.html`)
  for the same test case/project; tooltips localize (tested with `?locale=ro`).
- Add-flow regression passes; Event Viewer shows no new Error/Warning rows.
- Test suite recorded in `tmp/TLU_Test_Cases.md` (Task — Issue #1320, 6/6 PASS).

![tcAssign2Tplan navigation icons](screenshots/issue-1320-toolbar-icons.png)
