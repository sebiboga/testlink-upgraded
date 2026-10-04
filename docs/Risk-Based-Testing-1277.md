# Risk-Based Testing (likelihood × impact, risk coverage, metrics by risk level)

Implements the ISTQB risk-based testing model requested by
[ISTQB Compliance Review #1054](https://github.com/sebiboga/testlink-upgraded/issues/1054)
and tracked as task issue [#1277](https://github.com/sebiboga/testlink-upgraded/issues/1277).

## Why this was built from scratch

TestLink 1.9.20 had **no** structured risk model. The only risk-shaped things in the
tree were:

* the vestigial `risk_assignments` table
  (`install/sql/mysql/testlink_create_tables.sql:413`, dropped again by
  `alter_tables/1.8/mysql/DB.1.2/db_schema_update.sql:31`, referenced only by the
  table whitelist `lib/functions/object.class.php:288` — zero PHP call sites), and
* the crude per-test-case priority proxy computed from importance × urgency and
  filtered per test plan (`lib/functions/testcase.class.php:4688,6826`).

So this is not a dropped port: the module is new in 2.0.1 and the legacy proxy is
kept as the **fallback rating** for test cases nobody has rated yet.

## The model

| Attribute | Scale | Weight |
|---|---|---|
| Likelihood of failure | 1 rare … 5 almost certain | 1 |
| Impact if it fails | 1 negligible … 5 severe | 1 |

`risk score = likelihood × impact` → **1..25**

| Level | Score |
|---|---|
| Low | 1 – 5 |
| Medium | 6 – 11 |
| High | 12 – 25 |
| Not rated | no explicit rating (falls back to the 1.9.20 importance × urgency proxy: importance 1/2/3 = High/Medium/Low) |

Thresholds are computed server side (`api/riskcoverage/index.php:83`) and pushed to
the screen, so the matrix the user sees is the matrix the API applies.

## The screen

`gui/templates/results/riskCoverage.html` — Dashio shell (teal header, dark
toolbar, flat DataTables, level pills), three tabs:

1. **Requirement Risk Coverage** — every requirement of the test project with the
   number of covering test cases, executed count, coverage %, a coverage status
   (Covered / Not tested / Uncovered), max + avg risk and **residual risk** (the
   highest-rated covering test case that has not been executed yet). This is the
   "covered vs uncovered requirements against their risk rating" view: sort by max
   risk to see the expensive gaps first.
2. **Test Case Risk Register** — the editable grid: rated flag, likelihood, impact,
   score, level pill and execution status per test case, with a **Rate** button that
   opens a modal with the two selects and a live 5×5 likelihood/impact matrix (the
   current cell is outlined).
3. **Metrics by Risk Level** — execution metrics grouped by risk level (total,
   executed, executed %, passed, failed, blocked, pass rate) plus the test cases in
   view, and a **risk-level filter** in the toolbar that restricts both tables.

Toolbar: test project selector, test plan selector (plan-less mode falls back to the
whole project), risk-level filter and a threshold legend.

## The BFF

`api/riskcoverage/index.php` — plain PHP, session auth, JSON I/O, same-origin guard.

| Route | Purpose |
|---|---|
| `GET ?action=projects` | projects the user may read |
| `GET ?action=plans&tproject_id=N` | test plans of the project |
| `GET ?action=init&tproject_id=N[&tplan_id=M]` | context, per-section rights, thresholds, levels |
| `GET ?action=coverage&tproject_id=N[&tplan_id=M]` | requirement risk-coverage rows + summary |
| `GET ?action=register&tproject_id=N[&tplan_id=M]` | test case risk register + level distribution |
| `GET ?action=metrics&tproject_id=N[&tplan_id=M][&level=X]` | execution metrics per risk level, filterable |
| `GET ?action=tc_risk&tc_id=N` | one test case rating (edit modal) |
| `POST ?action=save_tc_risk {tc_id, likelihood, impact}` | rate a test case |

Rights: reads are gated on `mgt_view_req` (coverage), `mgt_view_tc` (register) and
`testplan_metrics` (metrics); a write needs `mgt_modify_tc` on the owning test
project. Unauthenticated → 401, missing rights → 403, forged `tc_id` → 404, a
likelihood/impact outside 1..5 → 400.

### Storage

A lazily created `tc_risk` table (`CREATE TABLE IF NOT EXISTS`, the established BFF
pattern of `api/nfrtype/index.php:96`, `api/reviews/index.php:93`,
`api/testclosure/index.php:88`), so a freshly imported DB keeps working and
`install/sql/mysql/testlink_create_tables.sql` is untouched:

```
tc_risk(tc_id PK, testproject_id, likelihood, impact, updated_by, updated_ts)
```

The table name had to be added to the whitelist in
`lib/functions/object.class.php:289` (`tlObjectWithDB::getDBTables()` throws
"Wrong table name(s)" otherwise).

Every rating save emits an AUDIT event (`RISK_SAVE`, pattern of
`api/nfrtype/index.php:384-390`), so the Event Viewer tracks the module:

```
Risk rating saved: RSK High Risk Checkout (likelihood 4 x impact 2 = score 8, level medium)
```

## Discovery path

The screen is listed in **Metrics & Reports** as `risk_coverage`
(`cfg/reports.cfg.php` `reports_list['risk_coverage']`, mapped to the modern screen
in `api/resultsnav/index.php:164`), and is reachable directly at
`/gui/templates/results/riskCoverage.html?tproject_id=<P>&tplan_id=<PL>`.

## i18n

* 74 `risk.*` keys + `footers.riskCoverage` + the shared `common.*` labels in **all 10**
  client bundles (`gui/templates/i18n/*.json`), appended — no line removed in any file.
* `link_report_risk_coverage` in **all 19** `locale/*/strings.txt` files, because
  `cfg/reports.cfg.php` titles are resolved by the server-side `lang_get()`, not by
  the client bundles (using a client key there produced `LOCALIZATION` events
  "string 'risk.header' is not localized for locale 'en_GB'").

## Screenshots

![requirement risk coverage](../../docs/screenshots/issue-1277-risk-coverage.png)
![risk register](../../docs/screenshots/issue-1277-risk-register.png)
![metrics filtered by risk level](../../docs/screenshots/issue-1277-risk-metrics-filtered.png)

## Test suite

`tmp/TLU_Test_Cases.md` → suite **"Task — Issue #1277"** (14 cases, all PASS): the
init/projects/plans routes, the three view routes, the rating write, the three tabs
in the browser, the modal + matrix, the risk-level filter, the navigator entry, the
locale switcher and the negative paths (401 / 403 / 400 / 404).
