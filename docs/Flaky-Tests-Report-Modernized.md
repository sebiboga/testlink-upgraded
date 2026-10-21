# Flaky / Unstable Test Cases (Modernized) — Issue #1954

The modern Dashio page **`gui/templates/results/flakyTests.html`**, backed by the
BFF **`api/flakytests/index.php`**, is a **net-new 2.0.1 report**: TestLink 1.9.20
showed only the **current** status of a test case (the last execution) and never
the **pattern** of its execution history, so a test case that fails once in a
while stayed invisible. This report classifies every **test-case version** linked
to a test plan as *flaky*, *stable*, *unproven* or *not run*, and shows the whole
execution history behind the verdict.

## Layout
- **Header** (teal Dashio) with the report title and the locale switcher in the footer.
- **Toolbar**: Refresh, **Export CSV**, the **Build**, **Platform** and **Min. executions**
  filters, Apply and Close.
- **Report context** panel: test project, test plan, build, platform, min. executions.
- **Verdict banner**: the number of flaky versions among the executed ones, a "all clean"
  variant and a "nothing was executed" variant.
- **KPI cards**: Linked versions, Executed, Flaky, Stable, Unproven, Not run, Flaky rate %,
  Avg. executions.
- **DataTable** (13 columns, searchable, sortable): test case, external id, suite path,
  verdict, executions, passed, failed, blocked, flips, last status, last run, builds, History.
- **Execution-history modal**: date, status, build, platform, tester, duration and notes of
  every execution of the version, in chronological order.
- **State cards**: Invalid request / Not found / Access denied / Server error, each with the
  machine code of the BFF.

## Verdict semantics (computed by the BFF, the client only renders it)
- The unit of the report is the **test-case VERSION** (`tcversions.id`, which is also the
  `nodes_hierarchy` node id of the version, node type 4) — not the test case: a version
  linked on two platforms is **one** row.
- Every execution row of the analysed scope counts.
- **flaky** — at least one **passed** AND at least one **failed** execution, and at least
  `min_exec` executions (default 2).
- **stable** — all the executions of the scope have the same status (also a version with
  only blocked executions).
- **unproven** — fewer executions than `min_exec`: the pattern cannot be judged yet.
- **not_run** — linked to the plan but never executed in the scope.
- A **blocked** execution is an execution but never a failure: it never makes a version
  flaky.
- **Flips** = the number of status changes between two consecutive executions (window
  function `LAG`, ordered by `execution_ts, id` — the same order as the history modal).
- **Last status / Last run** are taken from the same (chronologically last) execution.

## Filters
- `build_id` — the executions of that build.
- `platform_id` — narrows the **universe** as well: the platform is part of the
  assignment (`testplan_tcversions.platform_id`), so only the versions linked on that
  platform are listed at all.
- `min_exec` (1..100, default 2) — the threshold of the *unproven* verdict.

## Data model (BFF `api/flakytests/index.php`)
- **Routes**: `GET|HEAD ?action=init&tproject_id=N[&tplan_id=M]` /
  `?action=report&tplan_id=M[&tproject_id=P][&build_id=B][&platform_id=PL][&min_exec=K]` /
  `?action=history&tplan_id=M&tcversion_id=V[...]`. No write verb.
- **Rights**: `testplan_metrics` on the **owning** project of the plan — the project the
  plan really belongs to, not the one asserted by the caller.
- **Error contract**: `400 invalid_request` (missing/invalid `tplan_id`, `min_exec` out of
  range, a `build_id`/`platform_id` of another project, unknown action), `401`
  (`not_authenticated` / `session_expired`), `403 no_right`, `404` (`tplan_not_found`,
  `project_mismatch`, `tcase_not_found`), `405 method_not_allowed`. A POST without the
  same-origin proof is refused with `403`.
- **Suite path**: recursive walk **up** the `nodes_hierarchy` tree from the **test-case**
  node (node type 3), joined against the plan assignment — a depth guard (`depth < 32`)
  keeps a corrupted tree from aborting the query.
- **External id**: `testprojects.prefix` + the counter of the version (`FL-1`).
- **Build names**: the map also contains the **deactivated** builds, so an execution
  recorded on a closed build is not rendered as `#<id>`.

## Wiring
- `cfg/reports.cfg.php` — `$tlCfg->reports_list['flaky_tests']` (title
  `link_report_flaky_tests`, `format_html`).
- `api/aside/index.php` — the ASIDE entry of the Reports section.
- `api/resultsnav/index.php` — the `flaky_tests` entry of the Reports launcher
  (without it the cfg url was resolved against the launcher page and answered 404).
- `lib/functions/common.php` — `$actions->flakyTests`.
- Client i18n `flaky.*` + `footers.flakyTests` (82 keys) in all 10 bundles; server
  `$TLS_link_report_flaky_tests` in all 19 locales.

## Bugs found and fixed while testing
1. **The suite path was empty** — the recursive walk started from the *version* node
   instead of the *test-case* node (`tv.id = tn.parent_id` → `vn.parent_id = tn.id`), and
   the anchor selected `tn.parent_id` instead of `tn.id`; every row showed `-` as the suite.
2. **The report was unreachable from the Reports launcher** — the relative `url` of the
   cfg entry was resolved against the launcher page
   (`/gui/templates/results/gui/templates/results/flakyTests.html?` → 404); the
   `flaky_tests` entry of `$legacy2modern` now roots it and adds the context.
3. **The history modal rendered raw i18n keys** — `flaky.colBuild` / `flaky.colPlatform`
   were missing from the bundles (`FLAKY.COLBUILD`); they are now defined in all 10.
4. **A stale export** — after a failed reload the previous result set was still
   exportable (the CSV carried the rows of another plan); the result set is now dropped
   and the Export button disabled in every error state.
5. **The modal had no focus management** — `aria-modal="true"` without `tabindex`, focus,
   a Tab trap or a focus restore; the background is now `inert` while the modal is open.
6. **The Close button did nothing** — `window.close()` is a no-op when the report is
   opened as a normal link; it now closes a script-opened window and otherwise returns to
   the TestLink home.
7. **Contrast** — the teal header, the buttons and the verdict badges failed WCAG AA
   (white on `#4ECDC4` = 1.93:1); Lighthouse now reports **Accessibility 100 / 0 failed
   audits**.

## Screenshots
- `docs/screenshots/1954-flakytests-normal.png` — the report (EN).
- `docs/screenshots/1954-flakytests-history.png` — the execution-history modal.
- `docs/screenshots/1954-flakytests-no-right.png` — the "Access denied" state card.
- `docs/screenshots/1954-flakytests-ro.png` — the report (Romanian).
