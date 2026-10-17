# Risk Register — a project-scoped registry of risks (Refs #1930, parent #1066)

Net-new for 2.0.1: TestLink 1.9.20 has no first-class, editable risk registry.
The only risk-shaped artifacts are the vestigial `risk_assignments` /
`tc_risk` tables (schema only, no controller) and the read-only risk-coverage
dashboard already modernized under [**Risk-Based Testing**](#) (#1277), which
owns the separate `risk.*` i18n namespace and derives levels from
importance × urgency. This module delivers the missing **CRUD half** of the
parent "Risk-Based Testing" feature: a project-scoped register where a team can
record each risk — name, impact, likelihood, severity, priority, a **derived**
risk level, status, owner, mitigation and free notes — and optionally link it to
a requirement and a test case.

Documentation mirror of the wiki page; screenshots omitted from this file.

## Entry point

- Screen: `gui/templates/risk/riskRegister.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/risk/index.php`
- ASIDE menu: section **Risk Register** (icon `fas fa-exclamation-triangle`),
  single project-scoped item; shown whenever the user can read test cases
  (`mgt_view_tc` **or** `mgt_modify_tc`).
- URL of the action: `$actions->riskRegister` (`lib/functions/common.php`).
- Server label: `$TLS_title_risk_register` in all 19 `locale/*/strings.txt`.
- No legacy controller/template to retire — the module is net-new.

## Why a second "risk" screen

`Risk-Based-Testing` (#1277) is a **read-only analytics dashboard**: it rates
existing test cases (likelihood × impact) and reports coverage and metrics by
risk level. This screen is the **registry of record**: free-form project risks
with their own lifecycle (open / mitigated / accepted / closed) that are not tied
to a test case's rating. The two therefore use distinct i18n namespaces
(`risk.*` vs `riskreg.*`) so their labels never bleed into each other.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Total, linked, critical, high, medium and low — recomputed from the list on every load |
| Filters | Level (`critical` / `high` / `medium` / `low`) and status (`open` / `mitigated` / `accepted` / `closed`); explicit no-match empty state |
| Registry table | DataTable with Name / Level / Impact / Likelihood / Status / Linked to / Updated / Actions |
| New / Edit | Modal form: name, impact, likelihood, severity, priority (1–5 each), status, owner, mitigation, linked requirement, linked test case, notes. A live preview shows the **derived** level (`impact × likelihood`); a duplicate name is rejected with `duplicate_name` (409) |
| Details | Read-only modal showing every field, the level product and the linked refs |
| Delete | Bootstrap confirm (`{name}` interpolated); removes the risk |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `riskreg.noProject` / `riskreg.noRight` / `riskreg.errTitle` machine-code states for 400/401/403/404 |

The derived level is computed from `impact × likelihood`: **1–4 low, 5–9
medium, 10–14 high, 15–25 critical**. Severity and priority are free-form scores
kept for the record; they do not affect the derived level.

## Data model

One table, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly
imported database works unchanged; the DDL is also mirrored in
`install/sql/mysql/testlink_create_tables.sql` and the name is whitelisted in
`lib/functions/object.class.php`:

- `tc_risks` — one row per risk. `testproject_id` scopes the row to a test
  project; `name`, `impact`, `likelihood`, `severity`, `priority`, `status`,
  `owner`, `mitigation`, `notes` are the definition; `requirement_id` and
  `tcase_id` are optional links (validated to belong to the same project);
  `created_by`, `creation_ts` and `update_ts` provide a light audit trail.
  Indexes: `idx_risks_project (testproject_id)` and
  `idx_risks_status (testproject_id, status)`.

## BFF API — `api/risk/index.php`

Session auth + same-origin CSRF guard (`bffSameOriginGuard`), JSON in/out. Rights
are checked on the **addressed** test project **before** it is resolved (the
#1697 lesson: no existence oracle — an unknown project and a forbidden project
both fail closed):

| Route | Rights |
|---|---|
| `GET ?action=init&tproject_id=N` → `{context, domains, stats, refs, items}` | read |
| `GET ?action=list&tproject_id=N` → `{items}` | read |
| `POST ?action=save {tproject_id, id?, name, impact, likelihood, severity, priority, status, owner, mitigation, requirement_id?, tcase_id?, notes}` | write |
| `POST ?action=delete {tproject_id, id}` | write |

- read = `mgt_view_tc` **or** `mgt_modify_tc` on the project
- write = `mgt_modify_tc` on the project

Machine codes: `not_authenticated` (401), `no_right` (403), `no_testproject`
(400), `wrong_method` (405), `unknown_action` (400), `name_required` (400),
`bad_status` (400), `invalid_requirement` (400), `invalid_tcase` (400),
`duplicate_name` (409), `risk_not_found` (404), `bad_id` (400),
`insert_failed` (500), `tproject_not_found` (404). A `tproject_id` of 0 falls
back to the session project (intended).

The `refs` block of `init` lists the project's requirements and test cases
(recursive CTE over `nodes_hierarchy`, `node_type_id IN (3,7)` — test case and
requirement) so the modal pickers only ever offer same-project targets.

`save` with no `id` creates a new risk (`RISK_CREATE`); with an existing `id` it
updates it (`RISK_SAVE`). `delete` removes it (`RISK_DELETE`). All three emit
`AUDIT` Event Viewer rows (log_level 16).

## i18n

67 `riskreg.*` keys plus `footers.riskRegister` in **all 10** client bundles
(`gui/templates/i18n/*.json`), plus `$TLS_title_risk_register` in all 19 server
locales (`locale/*/strings.txt`). `ai/verify_i18n_coverage.sh` passes (8430 keys,
9/9 bundles). The distinct `riskreg.*` namespace was chosen precisely to avoid
colliding with the 73 pre-existing `risk.*` keys of the Risk-Based-Testing
dashboard.

## Verification

Suite `Modernize — Issue #1930` in `tmp/TLU_Test_Cases.md` (16/16). Verified in
the browser as admin: tiles, level + status filters and the empty state, create
(derived `CRITICAL 5 × 4 = 20`, both link chips, tiles update, `RISK_CREATE`
audit), the New-modal defaults plus empty-name and duplicate-name (409)
validation, details modal, edit (status open→mitigated, `update_ts` bumped,
`RISK_SAVE` audit), delete confirm→delete (`RISK_DELETE` audit). The 403 no-right
state was exercised with a dedicated right-less user (main area hidden, no New
button) and the 404 no-project state. German and Romanian locales render
translated labels (*Risikoregister*, *Registrul riscurilor*). BFF error paths
exercised over curl (401/403/404/400/405/409). The console is clean and the Event
Viewer shows only the intended AUDIT rows (no new Error/Warning). A DataTable
re-init bug (stacked header `aria-label`) was found while testing and fixed.
