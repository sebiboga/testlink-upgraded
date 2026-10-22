# Test Specification Health (Modernized) — Issue #1956

Screen: `gui/templates/testcases/tcSpecHealth.html`
BFF: `api/tcspechealth/index.php`
ASIDE: **Test Specification** → *Test Specification Health* (new entry,
`$TLS_href_tc_spec_health`, present in all 19 server locales)
Action: `$actions->tcSpecHealth` in `lib/functions/common.php`

## Purpose

TestLink 1.9.20 asked the question *"does this name already exist?"* for the
**single test case being created** (`lib/testcases/nameCheck.php` → the modern
`nameCheck.html` screen) and showed nothing else. The project-wide answer was
never visible: a suite that carried three cases with the same name, a case with
an empty summary, a case that no requirement covers or a case that nobody ever
executed stayed invisible until the review meeting.

This screen closes that gap. For every **test case** of a test project it
computes the health checks it fails and shows them together with the data
needed to fix them (summary, steps, linked requirements, execution history).

The screen is **read only** — it never writes a test case.

## Health checks

| Check | Meaning | Severity |
|---|---|---|
| `duplicate_name` | another test case of the same project carries the same name (names are compared case-insensitively and after trimming, like the 1.9.20 duplicate check) | high |
| `empty_summary` | the **latest** version of the case has an empty summary | medium |
| `no_requirement` | no requirement **in force** is linked to the case (a link with `is_active = 0` does not count) | medium |
| `never_executed` | the case has no execution at all, in any test plan of the project | high |
| `stale` | the case was last executed more than *N* days ago (default 90, 1..3650) | low |

- A case that fails several checks gets the **highest** severity of them
  (high > medium > low).
- The **severity** of a check never changes its meaning: `duplicate_name` and
  `empty_summary` are structural defects, `no_requirement` and `never_executed`
  are coverage gaps, `stale` is only a reminder.
- A healthy case is **not listed** — the report shows only the cases that fail
  something; the KPI cards still count the whole project.

## Layout

- Teal Dashio header with the subtitle *quality of the test specification* and
  the client-side `TLi18n` locale switcher (10 locales) in the footer.
- Toolbar: **Refresh**, **Export CSV**, the **Suite** and **Check** filters, the
  **Stale after (days)** field, **Apply** and **Close**.
- **Report context** panel: test project, scope (suite), stale window, check.
- **Verdict banner**: *N of M test cases fail at least one health check*, an
  "all clean" variant and a "this project has no test case yet" variant.
- **Nine KPI cards**: test cases, with issues, issue rate %, duplicate names,
  duplicated cases, empty summary, no requirement, never executed, stale.
- **DataTable** (10 columns, searchable, sortable): test case, external id,
  suite path, version, the failed checks as badges, the severity, the
  executions, the last execution, the number of linked requirements and the
  **Details** action.
- **Details modal**: the check list of the case, the duplicate counter, the
  summary, the steps (actions / expected result), the linked requirements and
  the requirement versions. The modal moves the focus inside itself, traps
  `Tab`, makes the background `inert` and gives the focus back to the row
  button when it closes.
- **CSV export** of the current result set (UTF-8 BOM, `;` separator, a
  formula-injection guard).
- State cards for 400 / 401 / 403 / 404, each keeping the machine code of the
  BFF visible.

## Filters

- **Suite** — the scope of the report: the whole project or one suite
  (**including its sub-suites** — the ancestry is walked up from the suite of
  the case, so selecting a parent suite does not drop the cases of its
  children).
- **Check** — one health check or all of them. The **KPI cards and the banner
  always describe the whole analysed scope** (they are the health of the
  scope); the **table** shows only the rows that match the check.
- **Stale after (days)** — the staleness window (1..3650, default 90); the
  value is clamped in the browser before the request.

## The API

| Route | Answer |
|---|---|
| `GET ?action=init&tproject_id=N` | the context + the suites of the project |
| `GET ?action=report&tproject_id=N[&suite_id=S][&check=C][&stale_days=D]` | the summary + the rows |
| `GET ?action=case&tproject_id=N&tcase_id=T` | the details of one case (steps, requirements) |

- Session based, **read only** (no write verb at all), `bffSameOriginGuard` +
  `bffEnforceSession`.
- The right **`mgt_view_tc`** is checked on the **addressed** project **before**
  the project is resolved (the #1697 no-oracle lesson): a caller without the
  right gets `403 no_right` for an existing *and* for a missing project.
- Machine codes: `not_authenticated` / `session_expired` (401), `no_right`
  (403), `tproject_not_found` / `tcase_not_found` (404), `invalid_request`
  (400), `method_not_allowed` (405).

## Data model (BFF `api/tcspechealth/index.php`)

- The **unit** is the test case (`nodes_hierarchy` node type 3); the analysed
  version is its **latest** version (node type 4 → `tcversions.id`).
- The tree is walked with a **recursive CTE** over `nodes_hierarchy`, so a
  suite scope includes the sub-suites.
- The **external id** is the project prefix + the **project-level** counter of
  the case (`tcversions.tc_external_id`, `TH-1`), the same contract the legacy
  test-spec print used.
- The **executions** of a case are counted over **all** the test plans of the
  project; the last execution timestamp is the newest one.
- The **requirements** of a case come from `req_coverage` — only the links
  that are **in force** (`is_active = 1`) count. ONE rule feeds the
  `no_requirement` check, the counter of the row and the list of the details
  modal, so the modal can never print "no requirement" for a case the report
  counts as covered.
- The **duplicate groups** and the **duplicated cases** are counted over the
  **whole project**, not over the filtered scope, so the counter of the modal
  ("3 test cases of this project carry this name") is the real one and the
  tiles do not change when a suite is filtered.

## Wiring

- `api/aside/index.php` — the new item of the **Test Specification** section,
  gated on `view_tc` (the right the BFF enforces).
- `lib/functions/common.php` — `$actions->tcSpecHealth`.
- `gui/templates/i18n/*.json` — `tsh.*` (81) + `footers.tcSpecHealth` in all
  **10** client bundles (`ai/verify_i18n_coverage.sh` 9/9 PASS).
- `locale/*/strings.txt` — `$TLS_href_tc_spec_health` in all **19** server
  locales.

## Bugs found and fixed while testing

1. **The closure scope bug** — the evaluation closure of the report did not
   list `$externalId` / `$projectPrefix` in its `use` list, so the external id
   column came back empty (the variables are file scope, they are not
   inherited). Fixed before the first commit.
2. **The empty project wording** — a project without any test case showed the
   table message *"No test case fails a health check in this scope"*, which
   claims that healthy cases were filtered out. The table now shows *"This test
   project has no test case yet"* when the project holds no case at all.

## Verification

- Browser (EN + RO): render, the 9 KPI cards, the suite filter, the check
  filter, the stale window, the details modal, the CSV export, the empty
  project, 400 / 403 / 404 state cards, the anonymous redirect to the login
  page — console clean (no error / warning).
- API: the report of the fixture project (`tmp/fixtures_1956.php`) returns
  `total_cases 8`, `cases_with_issues 7`, `issue_rate_pct 87.5`,
  `duplicate_groups 1`, `duplicate_cases 3`, `empty_summary 1`,
  `no_requirement 5`, `never_executed 6`, `stale 1` — the healthy case is not
  listed.
- Fixture: `tmp/fixtures_1956.php` (project `TSH1956` with the prefix `TH`,
  the empty project `TSH1956 EMPTY`, the user `tshnorights` with the role 3).
- Suite: `Issue #1956` in `tmp/TLU_Test_Cases.md`.

## Screenshots
- `docs/screenshots/1956-tcspechealth-normal.png` — the report (EN).
- `docs/screenshots/1956-tcspechealth-details.png` — the details modal.
- `docs/screenshots/1956-tcspechealth-empty.png` — a project without test case.
- `docs/screenshots/1956-tcspechealth-no-right.png` — the "Access denied" state card.
- `docs/screenshots/1956-tcspechealth-ro.png` — the report (Romanian).
