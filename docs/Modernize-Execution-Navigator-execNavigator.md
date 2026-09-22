# Modernize-Execution-Navigator-execNavigator (#1562)

The standalone **Execution Navigator** — `lib/execute/execNavigator.php` (+
`gui/templates/dashio/execute/execNavigator.tpl`) — is modernized as a standalone
Dashio screen backed by a REST BFF. It was the last standalone `lib/execute/*`
screen without a modern twin; it renders the execution-status tree of a test plan
(the old ExtJS "Execution Navigator" left frame) and deep-links into
`execTest.html`, `execDashboard.html`, `execExport.html` and
`resultsImport.html`.

## Deliverables

- **Screen:** `gui/templates/execute/execNavigator.html` — Dashio page: tee-al
  header with tree context (build + opened-nodes links, one-click expand/collapse,
  reopening "Execution Navigator" / "Open test specification" html links), a
  filter panel (Result Passed/Failed/Blocked/Not run/No result + TC-ID prefix
  filter, Apply/Reset), and the legacy tree pipeline: suite/case nodes with
  exec-status counters `(passed,failed,blocked,notrun)` and `[n]` version
  brackets; leaf click opens `execTest.html`, root click opens
  `execDashboard.html`; **Export execution report** → `execExport.html` (tree /
  4 results); **Import results** → `resultsImport.html`. Localized footer
  (`footers.execNavigatorView`), TLi18n locale switcher, access-denied /
  load-error states.
- **BFF:** `api/execnavigator/index.php` — `GET ?action=init` (session auth +
  `bffSameOriginGuard`), ports the legacy pipeline
  `tlTestCaseFilterControl($db,'execution_mode')` + `execTree()` with
  `checkAccessToExec()` rights (testplan_execute OR exec_ro_access on the owning
  project, admin shortcut) + `loadExecDashboard` EXDS load-down semantics. JSON
  contract: 401 (anon) / 403 (no exec rights on owning project, CSRF) / 400
  (unknown action, tproject↔tplan mismatch, missing params) / 404 (unknown test
  plan) / 405 (non-GET). `debug=1` returns the raw context for triage. Sends
  `setting_testplan` again on filter apply so the filters survive apply.
- **Link switch:** `$actions->execNavigatorView` in `lib/functions/common.php`
  (execute area, `tplanID > 0`); the footer link points at the modern screen.
- **Shim:** `lib/execute/execNavigator.php` kept as a session-guarded 302
  redirect onto the modern screen (anon → login) so legacy deep links
  (`ltx.php?load&item=exec...`) still resolve.
- **i18n:** `exnav.*` (31 keys) + `footers.execNavigatorView` in all 10 bundles
  (en/ro/de/es/fr/it/ja/pt/ru/zh). Initial close-out pass found all 31 `exnav.*`
  values were untranslated English copies in every non-EN bundle and the footer
  key was missing everywhere — both were fixed (`c38b836e9`), validated with
  `python3 -m json.tool`; RO locale verified in-browser ("Navigator de
  execuție").

## Verification

- Browser (admin): tree renders with counters + version brackets; filters
  (Passed / Not run / TC-ID `ENAV1562-1` / Reset) narrow correctly; leaf →
  `execTest.html?tcase_id=4&tcversion_id=5`; root → `execDashboard.html`;
  export → `execExport.html?…&exportContent=tree|4results`; import →
  `resultsImport.html`; RO locale renders translated labels; console clean.
- BFF curl contract: 401 anon, 403 `norights` user, 404 `tplan_id=999`,
  400 tproject↔tplan mismatch + bad action, 405 POST → 403 CSRF, `debug=1` 200.
- Event Viewer clean after the run (only pre-existing audit/login rows, 0
  ERROR/WARNING).
- Fixture `tmp/fixtures_1562.php` re-runs clean on a fresh DB (project 1
  "ExecNav Demo" / ENAV1562, plan 2, open + closed builds, Win11 platform).

## Test cases

Suite **1562 (Screen — Execution Navigator)** in `tmp/TLU_Test_Cases.md` —
12/12 PASS.

Screenshots: `docs/screenshots/execnavigator_tree_admin.png`,
`docs/screenshots/execnavigator_ro_locale.png`.

## Bugs related

- **#1567** — legacy SQL defect surfaced while building this screen: duplicate
  `EB` alias in `getLinkedForExecTree()` (`lib/functions/requirement.inc.php`)
  → MySQL error 1066. Fixed separately (`8df9348b8`), out of scope of the modern
  screen's parity.