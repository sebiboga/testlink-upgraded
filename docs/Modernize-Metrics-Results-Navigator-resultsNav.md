# Modernize-Metrics-Results-Navigator-resultsNav (#1568)

The standalone **Metrics & Reports launcher** — `lib/results/resultsNavigator.php`
(+ `gui/templates/dashio/results/resultsNavigator.tpl`) — is modernized as a
standalone Dashio screen backed by a REST BFF. It was the last standalone
`lib/results/*` screen without a modern twin. It lists every report available
for a chosen test plan and deep-links into the modern dashboards
(`reportPrint.html`, `metricsDashboard.html`, `resultsByTSuite.html`,
`resultsMatrix.html`, `resultsTCFlat.html`, `charts.html`, …).

## Deliverables

- **Screen:** `gui/templates/results/resultsNavigator.html` — Dashio page: teal
  header ("Metrics & Reports", TLi18n locale switcher), toolbar with a test-plan
  selector (accessible active plans, legacy `getAccessibleTestPlans` combo), a
  report-type selector (HTML / Pseudo MS Word / Email (HTML), driven by
  `$tlCfg->reports_formats`), and Refresh. Body lists the available reports as a
  DataTable (Report / Direct link columns) with the legacy direct-link toggle
  (`lnl.php?apikey=…`). Legacy warnings are honoured: `report_tplan_has_no_tcases`
  and `report_tplan_has_no_build` (warn box). Access-denied (401 anon / 403 no
  rights) and load-error states included.
- **BFF:** `api/resultsnav/index.php` — `GET ?action=init&tproject_id=&tplan_id=&format=`
  (session auth + same-origin guard). It resolves the owning project from the
  plan, computes the req/BTS gating flags from the project options +
  `isIssueTrackerEnabled()` (hoisted out of the `status_ok` branch so both the
  empty-plan and healthy paths stay warning-free), replicates
  `reports_mgr->get_list_reports()` key/item pairing **including** its enabled +
  format gating so req/BTS-disabled reports don't shift the key↔item alignment,
  then maps the 25 legacy `cfg/reports.cfg.php` report keys onto their modern
  screens via a `legacy2modern` table (e.g. `report_exec_timeline →
  execTimelineStats.html`, `tcases_without_tester → casesWithoutTester.html`,
  `free_tcases → freeTestCases.html` — the twin distinction is kept). Modern
  hrefs are rooted at `/<screen>.html?tplan_id=&tproject_id=` — both when the
  cfg url is `lib/...` and when it already points at `gui/templates/...` (e.g.
  `resultsMoreBuilds`). JSON contract: 401 anon / 403 no rights on owning
  project (`testplan_metrics`, admin shortcut) + CSRF / 400 unknown action or
  no active plan / 404 unknown plan / 405 non-GET / 500 guarded.
- **Link switch:** `$actions->resultsNav` in `lib/functions/common.php` (after
  `metrics_dashboard`); `lib/general/frmWorkArea.php` `showMetrics` deep-links
  into the modern screen.
- **i18n:** `rsnav.*` (14 keys: title/subtitle/testPlan/reportTypes/
  colName/directLink/openReport/empty/loadFailed/planSelector/noRights/
  anon/warnNoTc/warnNoBuild) + `footers.resultsNavigator` in all 10 bundles.
  Real translations for en/ro/de/es/fr, English fallback for it/pt/ru/ja/zh.
  Validated `python3 -m json.tool`; RO locale verified in-browser ("Metrică și
  Rapoarte", "Rapoarte disponibile", "Raport"/"Link direct").

## Verification

- Browser (admin, fixture project 27 / plan 28): title "Metrics & Reports",
  toolbar selectors, table headers **Report / Direct link**, 24 report rows
  with modern rooted hrefs (0 `lib/results/` or doubled `gui/templates/gui/`
  prefixes), direct-link `lnl.php?apikey=…`; format switch to Email (HTML)
  narrows to the single `results_flat` ("Test Results Flat"); warning state
  (TC unlinked) renders the TPlan-has-no-TCs warn box and clears once relinked;
  RO locale renders correctly.
- BFF curl contract: 401 anon, 403 POST/CSRF, 400 unknown action, 400 no active
  plan, 404 unknown plan, 200 for `format=0/4/6` (24 / 3 / 1 rows).
- No-rights proven via role-less user (`rsnavguest`, `role_id NULL`) →
  `hasRightOnProj()` NULL → 403 branch.
- Event Viewer: initial run logged `E_WARNING Undefined $optReqs/$btsEnabled`
  (gate flags computed inside the `status_ok` branch but referenced by the
  gating loop unconditionally) — root-caused, fixed by hoisting, re-verified
  0 E_WARNING on both paths; events max id unchanged.
- Fixture `tmp/fixtures_1568.php` re-runs clean on a fresh DB: project 27
  "RSNAV1568", plan 28, suite 29, tc 30 / tcversion 31, TC linked to plan via
  `INSERT INTO testplan_tcversions`.

## Test cases

Suite **1568 (Screen — Metrics & Reports hub)** in `tmp/TLU_Test_Cases.md` —
7/7 PASS.

Screenshot: `docs/screenshots/1568_results_navigator.png`.