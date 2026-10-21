# Risk Coverage Matrix (Modernized) — Issue #1951 (parent #1066)

The modern Dashio page **`gui/templates/risk/riskCoverageMatrix.html`** is the coverage half of the Risk-Based Testing feature: the risk registry (`gui/templates/risk/riskRegister.html`, `api/risk/index.php`) captures risks, but never says whether the risky area is actually covered by **executed** tests. This READ ONLY report closes that gap.

## Layout
- **Header** with locale switcher.
- **Verdict banner**: `ready` / `at_risk` / `not_ready` / `no_data` with a short explanation of the counts.
- **KPI tiles**: Risks, High risk / low coverage, Avg coverage, Avg mitigation, Residual risk, Defects, Not linked.
- **Toolbar**: Refresh, scope selector (Whole project / a single test plan), Level filter, Coverage filter (Covered / Partial / Uncovered / Not linked), Flagged only.
- **DataTable**: Risk, Level, Score, Covered by, Coverage (bar), Mitigation (bar), Residual (red), Flags, Actions. Sortable; default sort by score desc.
- **Detail modal**: risk fields, coverage/mitigation/state/residual/exec breakdown (versions/executed/passed/failed/blocked/defects), and the requirement coverage block when linked to a requirement.
- **Footer**: `TestLink 2.0.1 - Risk Coverage Matrix` (i18n) + generated timestamp.

## Data model (BFF `api/riskcoveragematrix/index.php`)
- **Routes**: `GET|HEAD ?action=init&tproject_id=N[&tplan_id=M]` / `?action=report=...` / `?action=projects`. No write verb at all.
- **Rights**: `mgt_view_tc` OR `mgt_modify_tc` checked on the **ADDRESSED** project **BEFORE** it is resolved (#1697 no-oracle). Unauthenticated → `401`; unknown action → `400`; wrong verb → `405` (`Allow: GET, HEAD`); unknown project / no rights → `404` / `403` with machine codes; unknown plan → `404`.
- **Coverage**: share of the linked test case versions actually executed. **Mitigation**: share whose latest result is a **pass** (a failing test exposes a risk, it does not retire it). **Residual risk**: `score × (100 − mitigation) / 100`. **Flag**: `level ≥ high` AND `coverage < 50 %`. **Verdict**: `ready` if no flags, avg mitigation ≥80 % and no failing linked tests; `at_risk` if avg mitigation ≥50 %; else `not_ready`; `no_data` if no risks.
- **Scope**: whole project or a single test plan (the executions are restricted to the selected plan).
- **Schema**: `tc_risks` is created lazily via the shared helper `api/_riskschema.php` (shared with `api/risk/index.php`).

## Wiring
- `$actions->riskCoverageMatrix` in `lib/functions/common.php`.
- ASIDE **Risk Register** section (`api/aside/index.php`) now has two items: **Risk Register** and **Risk Coverage Matrix**.
- Client i18n `rcmtx.*` + `footers.riskCoverageMatrix` in all 10 bundles; server `$TLS_title_risk_coverage_matrix` in all 19 locales.

## Bug fixed along the way (#1952)
The shared lazy-schema helper returned the map of `tlObjectWithDB::getDBTables()` instead of the table name, so both BFFs interpolated the literal `Array` and read/wrote a table named `Array` (the real `tc_risks` stayed empty) while logging `E_WARNING Array to string conversion`. Fixed in `api/_riskschema.php`; the stray rows were migrated into `tc_risks` and `Array` dropped. Event Viewer 0 new Error/Warning after the fix.
