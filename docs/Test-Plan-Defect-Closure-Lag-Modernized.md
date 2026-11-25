# Test Plan Defect Closure Lag — Modernized (2.0.1)

Issue: #2063. Screen: `gui/templates/results/defectClosureLag.html`. BFF: `api/defectclosurelag/index.php`.

This net-new read-only Report answers how long a defect-linked failed execution took to be closed by a later passing execution of the same test-case version/platform. In 1.9.20 this had to be reconstructed by opening individual execution popups and comparing timestamps by hand.

## Behavior

- Reads literal defect IDs from `execution_bugs` joined to `executions` in one test plan.
- Classifies each link as unverified, verified (a strictly later pass exists) or escaped (linked to an already-passing execution).
- Computes closure lag in whole days, maximum/average lag, open-defect ageing against a 30-day threshold, per-defect verification rate and an `at_risk` / `watch` / `clear` / `no_data` verdict.
- 12 KPI cards: defects, bug links, verified, unverified, escaped, average/maximum closure days, aged open defects, verification rate, affected cases, builds and platforms in scope.
- 13-column defect DataTable with localized DataTables controls and a keyboard-accessible evidence modal capped at 200 rows.
- Build, platform, verdict, defect-id and aged-open filters; Refresh; UTF-8 CSV with BOM, `;` delimiter and formula guard.
- Localized 400/401/403/404/405 state cards for invalid requests, missing session, missing rights, unknown plan and cross-project deep links.

## Security

- `bffSameOriginGuard()` and `bffEnforceSession()` on every route.
- Plan is resolved first; `testplan_metrics` is checked on the OWNING project before the client-supplied `tproject_id` assertion.
- Denials are logged at AUDIT; responses are GET/HEAD-only and never disclose another project's data.

## Wiring and i18n

- `cfg/reports.cfg.php` entry `defect_closure_lag`, `api/aside/index.php` title mapping and `api/resultsnav/index.php` map.
- `$actions->defectClosureLag` in `lib/functions/common.php`.
- 91 `dcl.*` + footer keys in all 10 client bundles; gate `bash ai/verify_i18n_coverage.sh` passes 9/9 with 13357 keys.
- `$TLS_link_report_defect_closure_lag` in all 19 server locale files.

## Verification

- Fixture `tmp/fixtures_2044.php`: 4 defects, 5 links, 2 verified, 2 unverified, 1 escaped, 3 affected cases.
- Suite `Issue #2063`: 23 PASS, 1 REVIEWED (lower-privilege browser login could not be created in this run; BFF right check reviewed in code).
- PHP lint, `node --check`, i18n coverage and suite gate all pass; Event Viewer has no new Error/Warning entries.
- Browser-verified: deep link, KPIs, DataTable, evidence modal and close, Verified-only filter, Refresh, CSV export, filters, anonymous/invalid/unknown/mismatch/POST paths; no console errors.
