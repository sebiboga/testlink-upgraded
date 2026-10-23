# Modernize: Koa11y Accessibility Scan Results (a11yScans)

Refs #1959, parent feature #1058.

## Overview
Modernized accessibility scan registry under Dashio: import Koa11y/axe-compatible scan results, link them to a test plan/build/test case, review per-finding remediation, and track pass/warn/fail by build trend. 1.9.20 had no accessibility scan integration.

## Entry points
- ASIDE menu: **System -> Koa11y Accessibility Scan Results** (`a11yScans`), gated by `mgt_view_tc` or `mgt_modify_tc`.
- Direct URL: `gui/templates/accessibility/a11yScans.html?tproject_id=<id>`

## Screen
- Header: teal Dashio style with title/subtitle and locale switcher.
- Toolbar: **Refresh**, **Import scan** (hidden when user has no modify right).
- Context card + 6 KPI tiles (Total/Latest/Pass/Warn/Fail/Errors/Warnings/Notices).
- DataTable **Scan results** with Name/Target URL/Profile/Scan date/Links/Errors/Warnings/Notices/Verdict/Actions (View/Delete). Links show Plan/Build/TC names.
- DataTable **Trend by build** (scans/errors/warnings/notices/fail/warn/pass).
- States: No test project, Access denied, Error, Empty state.
- Modals: Import (name/target/profile/env/browser/scan date/tool version/report URL/plan+build/tcase/findings JSON or summary counts/notes), Scan details (findings list with severity badges + description/remediation), Delete confirmation.

## BFF
`api/a11yscans/index.php`
- `GET|HEAD ?action=init|list|scan` and `POST ?action=import|delete`
- Session auth + `bffSameOriginGuard` + `bffEnforceSession`
- Rights: `mgt_view_tc` or `mgt_modify_tc` for view; `mgt_modify_tc` for write. Checked on the **addressed** project before resolution (no existence oracle).
- Lazy DDL creates `tc_a11y_scans` and `tc_a11y_findings` (installer DDL + object whitelist added).
- Severity normalization (critical/serious/high -> error, moderate/medium/warn -> warning, else notice), verdict (errors>0 fail, warnings>0 warn, pass). Findings capped at 5000.
- Link validation: plan/build/tcase must belong to the addressed project.
- Audit events: `A11Y_SCAN_IMPORT`, `A11Y_SCAN_DELETE`.

## i18n
All labels use `TLi18n` flat keys `a11y.*` in all 10 client bundles (`en,de,es,fr,it,ja,pt,ro,ru,zh`) validated by `ai/verify_i18n_coverage.sh`. Server label `$TLS_title_a11y_scans` added to all 19 locales.

## Wiring
- `lib/functions/common.php`: `$actions->a11yScans`
- `api/aside/index.php`: System section entry `a11yScans`
- `install/sql/mysql/testlink_create_tables.sql`: `tc_a11y_scans`, `tc_a11y_findings`
- `lib/functions/object.class.php`: table whitelist

## Screenshots
- ![a11yScans](Modernize-Koa11y-Accessibility-Scan-Results-a11yScans.png)
- ![a11yScans-details](Modernize-Koa11y-Accessibility-Scan-Results-a11yScans-details.png)
- ![a11yScans-import](Modernize-Koa11y-Accessibility-Scan-Results-a11yScans-import.png)
- ![a11yScans-no-rights](Modernize-Koa11y-Accessibility-Scan-Results-a11yScans-no-rights.png)
