# Execution History (execHistory) — Modernized

## Overview
The Execution History popup shows the full set of executions for a given test case across accessible test plans. The legacy Smarty popup (`lib/execute/execHistory.php`) was replaced by a modern Dashio standalone screen backed by a REST BFF. A session-guarded redirect shim forwards authenticated users to the modern screen.

Refs: #1806

## Architecture (2.0.1)
- Modern UI: `gui/templates/execute/execHistory.html`
- BFF API: `api/execute/index.php?action=history&tcase_id=N[&only_active_test_plans=0|1]`
- Legacy shim: `lib/execute/execHistory.php`
- Callers: `gui/javascript/testlink_library.js::openExecHistoryWindow()` opens modern HTML

## Features
- All executions for a test case (all versions), access-restricted to user-accessible test plans. Optional `only_active_test_plans=1` filter.
- Metadata: date/time, duration, test plan, build, platform (if present), executed by (or "deleted user"), status badge, version, run mode (Manual/Automated).
- Actions per row: Show/Hide details, Print preview (`execPrint.html?id=`), Edit execution notes (`editExecution.html` with params) when permitted.
- Details: execution notes, custom fields, attachments (download), bugs (BTS links, step markers).
- i18n via `TLi18n`, locale switcher.
- API returns `neverExecuted`, `displayPlatformCol`, per-execution data and edit-notes grants.

## Screenshots
![Execution History popup](execHistory_popup.png)
![Execution History details expanded](execHistory_details.png)
![Execution History only active test plans](execHistory_onlyactive.png)
