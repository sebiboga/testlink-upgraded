# Exit Criteria Evaluation (Modernized) — Issue #1953 (parent #1088)

The modern Dashio page **`gui/templates/strategy/exitCriteriaEvaluation.html`** is the **evaluation** twin of the Exit Criteria *definitions* screen (`gui/templates/strategy/exitCriteria.html`, `api/exitcriteria/index.php`). The definitions screen captures the criteria (metric, target value, direction, entry/exit text); this READ ONLY dashboard answers the question they exist for: **are those criteria actually met by the execution of a test plan?**

## Layout
- **Header** with locale switcher.
- **Toolbar**: project selector, test-plan selector, build selector, platform selector, **Evaluate** and **Refresh** buttons.
- **Verdict banner**: `ready` / `not_ready` / `manual` / `no_data` with a body sentence and the counts (`Met / Not met / Manual / N/A`).
- **KPI tiles**: Criteria, Measurable, Met, Not met, Manual, Met %.
- **Plan-results strip**: Assigned, Executed, Not run, Passed, Failed, Blocked, Progress %, Pass rate %.
- **By test level** table: one row per test level that actually carries criteria (the canonical levels — unit, integration, system, acceptance, release — plus any other level found in the data, which gets its own row instead of being dropped), with their criteria / met / not met / manual / N/A counts.
- **Criteria DataTable**: name, level, metric, target value, actual value, status and an action button; sortable and searchable.
- **Details modal**: test level, metric, the comparison line (`85.7% ≥ 80% (minimum)`), the status, the entry criteria and the exit criteria.
- **Convention hints**: the three rules that decide how a metric is judged (percentage minimums, count maximums, manual criteria).
- **Footer**: `N exit criteria.` (i18n) + the generated timestamp.

## Data model (BFF `api/exitcriteriaevaluation/index.php`)
- **Routes**: `GET|HEAD ?action=projects` / `?action=init&tproject_id=N` / `?action=evaluate&tplan_id=M[&tproject_id=P][&build_id=B][&platform_id=PL]`. No write verb at all.
- **Rights**: `mgt_view_tc` OR `mgt_modify_tc` checked on the **ADDRESSED** project **BEFORE** it is resolved (#1697 no-oracle). Unauthenticated → `401`; unknown action → `400`; wrong verb → `405` (`Allow: GET, HEAD`); unknown project / no rights → `404` / `403` with machine codes; unknown plan → `404`; a plan belonging to another project → `404` `project_mismatch`.
- **No plan-existence oracle**: when the request does not assert a project, the plan is looked up first and a plan the caller may not see answers with the same opaque `404 tplan_not_found` as a plan that does not exist. Only an *asserted* project that the caller may not see answers `403 no_right` — the answer therefore never reveals whether a plan id exists.
- **Unit of work**: a `testplan_tcversions` assignment row (a test case version assigned to the plan).
- **Result of a test case version**: the **latest** execution (`MAX(executions.id)`) for that (tcversion, platform) inside the selected plan and build.
- **Metric semantics**: a **percentage** metric (`progress`, `pass_rate`, `req_coverage`) is a **minimum** — the criterion is met when `actual ≥ target`; a **count** metric (`failed`, `blocked`) is a **maximum** — met when `actual ≤ target`; `metric = none` is never judged automatically (Manual review); a criterion without a target value is Not evaluated.
- **Verdict** (computed by the BFF, the client only renders it):
  - `no_data` — the plan carries no assigned test case at all, so there is nothing to judge;
  - `manual` — nothing could be judged automatically: either no criterion is measurable (`metric = none`), or every measurable criterion ended up **Not evaluated** (no target value, or the metric could not be computed for this plan) → a human has to decide;
  - `not_ready` — at least one measurable criterion is **not met**;
  - `ready` — every judged criterion is met.
- **Met %** is computed over the criteria that could really be judged (`met + not_met`), so a criterion that needs a human decision cannot dilute the percentage. The tile set is: Criteria / Measurable / Met / Not met / Manual / Met %.
- **Scope**: the whole plan, optionally restricted to one build and/or one platform.

## Wiring
- `$actions->exitCriteriaEvaluation` in `lib/functions/common.php`.
- ASIDE **Test Strategy** section (`api/aside/index.php`): **Exit Criteria** and **Exit Criteria Evaluation**.
- Client i18n `ecv.*` (91 keys) + `footers.exitCriteriaEvaluation` in all 10 bundles; server `$TLS_href_test_strategy_exit_evaluation` in all 19 locales.

## Bugs found and fixed while testing
1. **Dead build / platform filters and a dead Evaluate button** — the global evaluation function was named `evaluate()`. Inline `onchange` / `onclick` handlers resolve a name through `document` **before** `window`, so `document.evaluate` (the XPath API) shadowed it: the console logged 3 × `Uncaught TypeError: Failed to execute 'evaluate' on 'Document': 2 arguments required, but only 0 present.` and all three controls did nothing. The function was renamed to `runEvaluation()` (the BFF `action=evaluate` contract is unchanged).
2. **A deep link to an unknown project or plan silently fell back to the first accessible project** instead of reporting the error. An explicit `?tproject_id=` / `?tplan_id=` that is not in the accessible set now shows the **Not found** state card (the fallback is kept only when the screen picks the project itself).

## Suite correction (issue #1953)

Two measurements recorded in the earlier suites of this screen did not survive a
re-run of the fixture and were corrected in the appended suite
`Issue #1953 (correction)` (7/7 PASS). The screen itself was **not** changed.

| Wrong record | Correct value |
|---|---|
| `Issue #1953 (review fixes)` case 1953-R.11: selecting `EV Build 2` gives **3 Met / 1 Not met / 75% Met %** | `EV Build 1` (id 5) gives **3 Met / 1 Not met / 75% Met %**; `EV Build 2` (id 6) gives **2 Met / 2 Not met / 50% Met %** — the case had the two build ids swapped |
| `Issue #1953 (review fixes)` case 1953-R.2: the NA-only plan reads **0 Met / 0 Not met / 2 Manual** | the two criteria are **Not evaluated** (`na`), so the tiles read **0 Met / 0 Not met / 0 Manual / 0% Met %** and the counts strip "Met 0 · Not met 0 · Manual 0 · N/A 2" — the verdict is still `manual` (`evaluated = 0`) |

The authoritative numbers of the fixture (`ECV1953` = 55, `EV Plan` = 58,
`EV Build 1` = 5, `EV Build 2` = 6, `EV Platform 1` = 3, `ECV1953 NA ONLY` = 57,
`EN Plan` = 60) are:

| Scope | Tiles | Verdict |
|---|---|---|
| all builds | 7 Criteria / 6 Measurable / 1 Met / 3 Not met / 1 Manual / 25% Met % | `not_ready` |
| `EV Build 1` | 3 Met / 1 Not met / 1 Manual / 75% Met % | `not_ready` |
| `EV Build 2` | 2 Met / 2 Not met / 1 Manual / 50% Met % | `not_ready` |
| `EV Platform 1` | 4 Met / 0 Not met / 1 Manual / 100% Met % | `ready` |
| `ECV1953 NA ONLY` | 2 Criteria / 2 Measurable / 0 Met / 0 Not met / 0 Manual / 0% Met % | `manual` |

## Code review fixes (issue #1953, review round 1)
3. **A criterion with no target value was judged as met** — the evaluation loop read the computed actual value before checking whether the criterion has a target, so a `progress` criterion with `target_value = NULL` ended up with the status `met`. The loop now sets the status to `not_evaluated` and the actual value to `NULL` when the criterion has no target (or when the metric could not be computed) — the screen shows `-` in the *Actual* column.
4. **The verdict said "ready" when nothing could be judged** — with only Not-evaluated criteria the counters were all zero and the verdict fell through to `ready`. The BFF now also reports `evaluated = met + not_met` and answers `manual` when `evaluated = 0` (nothing to judge → a human has to decide).
5. **`Met %` diluted by criteria that were never judged** — the denominator is now `evaluated` instead of the number of measurable criteria.
6. **An unknown metric id crashed the evaluation** — a criterion pointing at a metric that is not in the catalogue produced an `Undefined array key` PHP warning in the Event Viewer and was silently judged as met. The metric id is now sanitized to `none` (Manual review).
7. **Plan-existence oracle** — see "No plan-existence oracle" above.
8. **A criterion on an unknown test level was dropped from the "By test level" table**, so the level totals did not add up to the number of criteria. Unknown levels now get their own row.
9. **Accessibility**: the four toolbar selects and the six modal fields had no associated label, the per-row action button had no accessible name, the DataTables search inputs had no `id` / `name` / `aria-label`, the pagination and the "entries per page" menu were still in English on a localized screen, the page had no `main` landmark and the shared locale switcher had no accessible name. All fixed (the locale switcher label is the new `common.language` key, present in all 10 bundles).
10. **Dead code**: the client-side criteria filter and its "no match" row were removed — the tiles are now rendered from the summary computed by the BFF, which is the single source of truth.

## Screenshots
- `docs/screenshots/1953-exitcriteriaevaluation-normal.png` — the dashboard (EN).
- `docs/screenshots/1953-exitcriteriaevaluation-details.png` — the criterion details modal.
- `docs/screenshots/1953-exitcriteriaevaluation-ro.png` — the dashboard (Romanian).
