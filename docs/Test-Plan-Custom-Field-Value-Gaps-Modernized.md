# Test Plan Custom Field Value Gaps (tpwcGaps) — Modernized (2.0.1)

Issue: **#2074**. Screen: `gui/templates/results/tpwcGaps.html`. BFF: `api/tplanwithcf/index.php`.

This net-new read-only report answers a question 1.9.20 could not answer in one place: for a chosen test plan, which assigned test-case versions are missing values for their test-plan-design custom fields, and what values are already present?

## Why a new report

`plannWithCF`/`tplanWithCF.html` shows the raw custom-field values inside one plan, and the newer `tplanCfCoverage` shows a per-plan coverage count across a project. Neither exposes a per-case gap list: a manager still had to open every row of `tplanWithCF.html` and compare each field by eye. `tpwcGaps` turns the same `cfield_testplan_design_values` data into a filtered, sortable gap list with raw values, KPIs and a verdict.

## Screen

- Toolbar: test-plan/project context, Custom field filter (All / individual fields), Scope filter (All assigned / With values / Missing values), search, Refresh, Export CSV and Close. The screen requires a `tplan_id` deep link, normally supplied by ASIDE or the `tplanCfCoverage.html` launcher.
- KPI strip: assigned versions, with values, without values, value coverage %, linked design custom fields, filled cells and rows in the current scope. The first six KPIs describe the whole plan; `shown` follows the filters.
- Verdict banner: `empty` (no custom fields, no assignments or no filled cells), `partial` (some cells are still empty) and `complete` (every assigned version has every design field filled). The label is localized independently of the CSS state class.
- DataTable: prefixed test case with version badge, full suite path, `filled / total` badge and one column per linked design custom field showing `completat` / `lipsă` plus the trimmed raw value; prefixed case names link to `tcEdit.html`.
- `Deschide valorile brute` opens `tplanWithCF.html` for the same project and plan; per-plan gap links were also added as the final column of `tplanCfCoverage.html`.
- UTF-8 CSV with BOM, `;` delimiter and formula-injection guard; localized 400/401/403/404/405 state cards and a `TLi18n` locale switcher.

## Semantics

- The unit is one `testplan_tcversions` assignment of the selected plan.
- Fields in scope are project-linked test-case-node custom fields with `enable_on_testplan_design = 1`; display order comes from `cfield_testprojects.display_order`, not from `custom_fields`.
- A value counts as filled when its trimmed string is non-empty; a row is "with values" when at least one of its fields is filled.
- `coverage_pct = filled_cells / (assigned × custom_fields) × 100`, rounded to one decimal; `without_values = assigned - with_values`.
- Scope `filled` / `missing` narrows the displayed rows. When a custom field is selected the scope applies to THAT field (`With values` = field filled, `Missing values` = field empty); with `All assigned` it still limits rows to that filled field. Search matches the prefixed case name and full suite path.
- The plan verdict is computed from plan-wide counters, so filtering the table never changes the plan-level conclusion.
- Rights (`testplan_metrics`) are checked on the OWNING project before the client-supplied `tproject_id` assertion.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET\|HEAD` success | 200 | `ok` |
| Invalid/missing `tplan_id`, bad `scope`, bad `cf_id`, unknown action, `q` over 200 chars | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `testplan_metrics` on the owning project | 403 | `no_right` (AUDIT) |
| Unknown plan / mismatched `tproject_id` | 404 | `tplan_not_found` / `project_mismatch` |
| Non GET/HEAD | 405 | `method_not_allowed` |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, `nosniff` and `no-store` headers, integer-only IDs and AUDIT denials.

## Wiring

- `api/tplanwithcf/index.php` — `action=init` and `action=report`.
- `cfg/reports.cfg.php` — `tpwc_gaps`; `api/aside/index.php` — Reports entry with both project and plan IDs.
- `lib/functions/common.php` — `$actions->tpwcGaps`.
- `gui/templates/results/tplanCfCoverage.html` — per-plan gap launcher in the final DataTable column.
- `gui/templates/i18n/*.json` — `tpwg.*` (59 keys) + `footers.tpwcGaps` in all 10 bundles; `ai/verify_i18n_coverage.sh` passes 9/9 with 13611 keys.
- `locale/*/strings.txt` — `$TLS_link_report_tpwc_gaps` in all 19 server locales.

## Verification

- Fixture `tmp/fixtures_2074.sql`: project 2074 with plan 20740 (4 assignments, 4 filled cells and one missing pair) and plan 20742 (no assignments), two design custom fields and a role-3 `norights` user.
- Suite `Issue #2074`: 14 cases covering normal/filtered/empty/complete states, scoped custom-field filters, injected-label escaping, raw-values link, Close, CSV, error/rights contract, RO locale, menu/launcher, gates, Event Viewer and cross-screen regression — all PASS. The suite gate reports 34 PASS / 0 FAIL / 0 SKIP.
- Bugs found during testing: #2075 (`CF.display_order` selected from the wrong table), #2076 (empty plan showed the complete verdict), #2077 (coverage launcher omitted `tplan_id`) and #2078 (code review: XSS in field titles, contradictory `scope=missing` + `cf_id` filter, 50% "complete" verdict, two unmapped error codes, unsupported placeholder, overstated docs, locale hygiene); all were filed with the `bug` label, fixed and closed.
- PHP lint, extracted `node --check`, JSON validation and the i18n/suite gates pass. Event Viewer has no new Error/Warning entries after the #2075 fix; its two ERROR rows are the already-fixed pre-fix DB errors.
