# Exit Criteria Evaluation (Modernized) — Issue #1953 (parent #1088)

The modern Dashio page **`gui/templates/strategy/exitCriteriaEvaluation.html`** is the **evaluation** twin of the Exit Criteria *definitions* screen (`gui/templates/strategy/exitCriteria.html`, `api/exitcriteria/index.php`). The definitions screen captures the criteria (metric, target value, direction, entry/exit text); this READ ONLY dashboard answers the question they exist for: **are those criteria actually met by the execution of a test plan?**

## Layout
- **Header** with locale switcher.
- **Toolbar**: project selector, test-plan selector, build selector, platform selector, **Evaluate** and **Refresh** buttons.
- **Verdict banner**: `ready` / `not_ready` / `manual` / `no_data` with a body sentence and the counts (`Met / Not met / Manual / N/A`).
- **KPI tiles**: Criteria, Measurable, Met, Not met, Manual, Met %.
- **Plan-results strip**: Assigned, Executed, Not run, Passed, Failed, Blocked, Progress %, Pass rate %.
- **By test level** table: the six canonical levels (unit, integration, system, acceptance, regression, release) with their criteria / met / not met / manual / N/A counts.
- **Criteria DataTable**: name, level, metric, actual value, target, status and an action button; sortable and searchable.
- **Details modal**: test level, metric, the comparison line (`85.7% ≥ 80% (minimum)`), the status, the entry criteria and the exit criteria.
- **Convention hints**: the three rules that decide how a metric is judged (percentage minimums, count maximums, manual criteria).
- **Footer**: `N exit criteria.` (i18n) + the generated timestamp.

## Data model (BFF `api/exitcriteriaevaluation/index.php`)
- **Routes**: `GET|HEAD ?action=projects` / `?action=init&tproject_id=N` / `?action=evaluate&tplan_id=M[&tproject_id=P][&build_id=B][&platform_id=PL]`. No write verb at all.
- **Rights**: `mgt_view_tc` OR `mgt_modify_tc` checked on the **ADDRESSED** project **BEFORE** it is resolved (#1697 no-oracle). Unauthenticated → `401`; unknown action → `400`; wrong verb → `405` (`Allow: GET, HEAD`); unknown project / no rights → `404` / `403` with machine codes; unknown plan → `404`; a plan belonging to another project → `404` `project_mismatch`.
- **Unit of work**: a `testplan_tcversions` assignment row (a test case version assigned to the plan).
- **Result of a test case version**: the **latest** execution (`MAX(executions.id)`) for that (tcversion, platform) inside the selected plan and build.
- **Metric semantics**: a **percentage** metric (`progress`, `pass_rate`, `req_coverage`) is a **minimum** — the criterion is met when `actual ≥ target`; a **count** metric (`failed`, `blocked`) is a **maximum** — met when `actual ≤ target`; `metric = none` is never judged automatically (Manual review); a criterion without a target value is Not evaluated.
- **Verdict**: `no_data` when the plan has no assigned test case; `manual` when no criterion is measurable; `not_ready` when at least one measurable criterion is not met; `ready` otherwise.
- **Scope**: the whole plan, optionally restricted to one build and/or one platform.

## Wiring
- `$actions->exitCriteriaEvaluation` in `lib/functions/common.php`.
- ASIDE **Test Strategy** section (`api/aside/index.php`): **Exit Criteria** and **Exit Criteria Evaluation**.
- Client i18n `ecv.*` (92 keys) + `footers.exitCriteriaEvaluation` in all 10 bundles; server `$TLS_href_test_strategy_exit_evaluation` in all 19 locales.

## Bugs found and fixed while testing
1. **Dead build / platform filters and a dead Evaluate button** — the global evaluation function was named `evaluate()`. Inline `onchange` / `onclick` handlers resolve a name through `document` **before** `window`, so `document.evaluate` (the XPath API) shadowed it: the console logged 3 × `Uncaught TypeError: Failed to execute 'evaluate' on 'Document': 2 arguments required, but only 0 present.` and all three controls did nothing. The function was renamed to `runEvaluation()` (the BFF `action=evaluate` contract is unchanged).
2. **A deep link to an unknown project or plan silently fell back to the first accessible project** instead of reporting the error. An explicit `?tproject_id=` / `?tplan_id=` that is not in the accessible set now shows the **Not found** state card (the fallback is kept only when the screen picks the project itself).

## Screenshots
- `docs/screenshots/1953-exitcriteriaevaluation-normal.png` — the dashboard (EN).
- `docs/screenshots/1953-exitcriteriaevaluation-details.png` — the criterion details modal.
- `docs/screenshots/1953-exitcriteriaevaluation-ro.png` — the dashboard (Romanian).
