# Execution History (execHistory) — Modernized

## Overview
The Execution History popup (`execHistory`) shows the full set of executions for a given test case across accessible test plans. The legacy Smarty popup (`lib/execute/execHistory.php`) was replaced by a modern Dashio standalone screen backed by a REST BFF. A session-guarded redirect shim in the legacy controller forwards authenticated users to the modern screen; anonymous deep links are sent to the login screen.

Refs: #1806

## Architecture (2.0.1)
- Modern UI: `gui/templates/execute/execHistory.html` (Dashio look & feel, DataTables not used; client-side filtering/rendering)
- BFF API: `api/execute/index.php?action=history&tcase_id=N[&only_active_test_plans=0|1]`
- Legacy shim: `lib/execute/execHistory.php` (redirects to modern UI preserving `tcase_id`, `tproject_id`, `onlyActiveTestPlans`)
- Callers switched: `gui/javascript/testlink_library.js::openExecHistoryWindow()` now opens `gui/templates/execute/execHistory.html`

## Behavior / Features
- Renders all executions of a test case (all versions) with access control: only executions belonging to test plans the user can access. Optional filter `only_active_test_plans=1` restricts to active test plans only (checkbox + URL param).
- Displays execution metadata: date/time, duration, test plan, build, platform (shown if any execution used a platform), executed by (with fallback for deleted user), status badge (Passed/Failed/Blocked/Other), version number, run mode (Manual/Automated).
- Per-row actions: toggle details, Print preview (`/gui/templates/execute/execPrint.html?id=<execId>`), Edit execution notes (`/gui/templates/execute/editExecution.html?exec_id=<execId>&tcversion_id=<tcv>&tplan_id=<tplan>&tproject_id=<tproj>` — available when `can_edit_notes` is true).
- Details panel shows: execution notes, execution custom fields, attachments (with download links), bugs (with BTS links and step markers when applicable). Empty details show localized "None".
- i18n: all labels via `TLi18n` (`exechist.*`, shared keys). Locale switcher present.
- API returns `neverExecuted` flag, `displayPlatformCol`, full execution objects with status codes/labels, tester info, edit-notes grants per test plan.

## Permissions / Edge Cases
- Session required for popup content (legacy init behavior preserved). Anonymous users hitting legacy shim land on login.
- `can_edit_notes` is granted per test plan based on `exec_edit_notes` right on owning project.
- Deleted testers rendered as localized "deleted user".
- `neverExecuted` case: executions list empty, header still shows test case info.

## Screenshots
![Execution History — popup](../images/execHistory_popup.png)
![Execution History — details expanded](../images/execHistory_details.png)
![Execution History — only active test plans](../images/execHistory_onlyactive.png)

## Regression
Regression Suite 1806 (11 test cases) covers: direct modern URL, legacy shim redirect, onlyActiveTestPlans param, toggle details, print preview, edit notes availability, filters, i18n, API history (with/without executions). All PASS.
