# Requirement Traceability Gaps (reqTraceabilityGaps)

Modernized screen for issue [#2047](https://github.com/sebiboga/testlink-upgraded/issues/2047) in TestLink 2.0.1.

- Screen: `gui/templates/results/reqTraceabilityGaps.html`
- BFF: `api/reqtraceabilitygaps/index.php`
- Rights: `mgt_view_req` on the addressed test project (read-only)
- API: `GET|HEAD ?action=init&tproject_id=P[&spec_id=S][&issue=I]`,
  `GET|HEAD ?action=projects`,
  `GET|HEAD ?action=report&tproject_id=P[&spec_id=S][&issue=I]`,
  `GET|HEAD ?action=detail&tproject_id=P&req_id=R[&spec_id=S][&issue=I]`,
  `GET|HEAD ?action=csv&tproject_id=P[&spec_id=S][&issue=I]`
- Fixture: `tmp/fixtures_2047.php`
- Test suite: `Issue #2047` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 stored requirement-to-test-case links in `req_coverage` but only exposed them one requirement at a time. It had no project-level answer to the release question: which requirements have no live links, which links point at superseded test-case versions, which were frozen by a requirement version bump, and which still fall short of their expected coverage? This 2.0.1 report turns those links into an auditable traceability gap list without changing `reqSpecCoverage`, which remains the execution-coverage report.

## Screen

- Toolbar: project context, Specification and Issue pickers, Refresh (reloads using the current toolbar values), Export CSV and Close.
- 10 KPI cards: requirements, requirements with gaps, uncovered, stale links, closed by execution, under-covered, coverage, open links, specs with gaps and all links.
- Project verdict banner (`no_data` / `clear` / `watch` / `at_risk`) and localized empty/error cards including the 403 `no_right` state.
- Searchable/sortable DataTable with requirement, spec, version, verdict, expected coverage, live/open/closed/inactive/historical/stale link counts, coverage and linked cases.
- Per-requirement evidence modal listing every link for the latest requirement version, with link status, active flag, historical flag and stale-version flag; historical rows are evidence only and never create a gap by themselves.
- `expected_coverage=0` is informational: Expected and Coverage render `n/a` and the requirement is excluded from the coverage denominator and uncovered/under-covered verdicts.
- CSV export with UTF-8 BOM, `;` separator, RFC-style quoting and formula-injection guard; disabled when there are no rows.
- Localized 400/401/403/404/405 states and a `TLi18n` locale switcher; all labels, KPIs, DataTables strings and messages come from all 10 client bundles with RO/DE/FR/ES/IT translations.

## Semantics

- The unit is one requirement at its latest version, resolved through the prefixed `latest_req_version_id` view. The addressed project is checked for `mgt_view_req` before project/spec resolution.
- A **live** link has `is_active=1` and `link_status` 1 (open) or 2 (closed by execution). Only live links count towards coverage. Historical rows (status 3/4 or `is_active=0`) remain visible in the evidence modal and in the `historical_links` column, but never inflate gaps when a healthy live link exists.
- A live link is **stale** when its `tcversion_id` is below the latest active version of the same test case, or when the linked version is no longer active.
- Link statuses are normalized as `1` → open, `2` → closed by execution, `3` → closed by new test-case version, `4` → closed by new requirement version.
- Verdict precedence: no live and no historical links → `uncovered`; any stale live link → `stale_links`; no live links but historical rows → `closed_links`; live links below expected coverage → `under_covered`; otherwise `ok`. Informational requirements (`expected_coverage=0`) skip the uncovered/under-covered checks.
- Coverage is `min(live links, expected) / expected * 100`, so extra links cannot exceed 100%. Requirements with no numeric target are excluded from the KPI denominator.
- The Issue filter and Specification filter narrow rows, detail and CSV. The KPI strip describes the selected project/spec scope; the verdict filter only narrows the rows.
- Project verdict is `no_data` with no requirements, `at_risk` with uncovered or stale rows, `watch` with any other gap, otherwise `clear`. Denials are written to AUDIT.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, missing/invalid project, invalid spec or issue | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `mgt_view_req` on the addressed project | 403 | `no_right` (AUDIT) |
| Unknown project / requirement outside filtered scope | 404 | `tproject_not_found` / `req_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |
| POST without `Origin`/`Referer` | 403 | same-origin guard denial |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; responses carry `nosniff` and `Cache-Control: no-store`. CSV cells starting with `=`, `+`, `-` or `@` are prefixed with an apostrophe.

## Wiring

- `cfg/reports.cfg.php` — `requirement_traceability_gaps`
- `api/aside/index.php` — Reports entry for `reqTraceabilityGaps.html`
- `api/resultsnav/index.php` — report launcher map row
- `lib/functions/common.php` — `$actions->reqTraceabilityGaps`
- `gui/templates/i18n/*.json` — 65 `rtg.*` + footer keys in all 10 bundles (`ai/verify_i18n_coverage.sh`: 9/9 PASS, 12999 keys)
- `locale/*/strings.txt` — `$TLS_link_report_requirement_traceability_gaps` (19 locales)

## Verification

Fixture `tmp/fixtures_2047.php` creates project RTG2047 (id 113 in the verified run, empty project id 2, two specs, six requirements, six links, beta v1/v2, an informational `RTG-NA` requirement and a historical row on `RTG-OK`) plus a role-3 user `rtg2047norights`.

Suite `Issue #2047` has 26 executed checks (2047.1–2047.26) covering the BFF contract, historical-link false-positive regression, `expected_coverage=0`, filters, deep links, detail, CSV, browser states, code review, Event Viewer and CHANGELOG. The repository suite gate reports 34 PASS / 0 FAIL / 0 SKIP with no suite lost. Event Viewer after the corrected fixture run contained only AUDIT rows; the single earlier ERROR was the now-corrected `testprojects.name` fixture probe. Two code-review passes were completed and all findings fixed in `00b7a7458` and `8f7560aba`.

## Files

- `gui/templates/results/reqTraceabilityGaps.html`
- `api/reqtraceabilitygaps/index.php`
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json`
- `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`, `lib/functions/common.php`
- `tmp/fixtures_2047.php`, `tmp/TLU_Test_Cases.md`
