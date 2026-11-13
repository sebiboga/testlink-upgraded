# Test Plan Execution Frequency / Re-run analysis (Modernized) — Issue #1988

The modern Dashio page **`gui/templates/results/execFrequency.html`**, backed by the
BFF **`api/execfrequency/index.php`**, is a **net-new 2.0.1 report**: TestLink 1.9.20
reported the **latest status** of each test case (passed / failed / blocked / not run)
but never **how often** an assigned test case is actually re-executed, so over-tested
(re-run) and under-tested (never run) plans stayed invisible. This report shows, per
assigned test-case version, its execution count, re-runs, builds, testers, first/last
run and an under/over-tested verdict.

## Layout
- **Header** (teal Dashio) with the report title and the locale switcher in the footer.
- **Toolbar**: Refresh, **Export CSV**, the **Build**, **Platform** and **High re-run at**
  filters, Apply and Close.
- **Report context** panel: test project, test plan, build, platform.
- **Verdict banner**: `healthy`, `under_tested`, `over_tested` or `no_data`, with the
  counts and percentages that justify it.
- **KPI cards**: Assigned units, Executed, Never run (+ %), Total executions, Re-runs,
  Avg per executed, High re-run, Testers involved, Builds involved.
- **Per-case DataTable** (13 columns, searchable, sortable): test case, external id,
  suite path, version, platform, executions, re-runs, builds, testers, first run,
  last run, last status, verdict.
- **Per-suite roll-up DataTable**: units, executed, never run, executions, high re-run,
  average executions.
- **State cards**: Invalid request / Not found / Access denied / Server error, each with
  the machine code of the BFF.

## Verdict semantics (computed by the BFF, the client only renders it)
- The unit of the report is one assigned **`testplan_tcversions`** link (a version on a
  platform), not the test case.
- Executions are counted inside the plan, optionally scoped to one **build** and/or one
  **platform**. A link with `platform_id = 0` covers every execution platform of the
  version, so its executions on all platforms count.
- `reruns = executions - 1`.
- Per-unit verdict:
  - **never_run** — 0 executions.
  - **single** — exactly 1 execution.
  - **rerun** — 2 .. threshold-1 executions.
  - **high_rerun** — `>= threshold` executions.
- `rerun_threshold` is clamped to **2..100**, default **4**.
- Plan verdict precedence:
  - **no_data** — no assigned units.
  - **under_tested** — at least 20% of the assigned units were never executed.
  - **over_tested** — at least 20% of the assigned units are `high_rerun`.
  - **healthy** — otherwise.

## Filters
- `build_id` — only the executions of that build.
- `platform_id` — only the executions of that platform (applies to the platform-less
  links too).
- `rerun_threshold` (2..100, default 4) — the high re-run cut-off (an invalid value
  falls back to the default).

## Data model (BFF `api/execfrequency/index.php`)
- **Routes**: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]` /
  `?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&rerun_threshold=T]`.
  No write verb.
- **Rights**: `testplan_metrics` on the **owning** project of the plan — the project the
  plan really belongs to, not the one asserted by the caller. The denial is logged at
  `AUDIT`, not `ERROR`.
- **Error contract**: `400 invalid_request` (invalid `tplan_id`, unknown action),
  `401` (`not_authenticated` / `session_expired`), `403 no_right`, `404`
  (`tplan_not_found`, `project_mismatch`), `405 method_not_allowed`. A build/platform of
  another project is ignored (it falls back to the whole plan), so the report is never an
  existence oracle.
- **No-oracle order**: the plan is resolved first (opaque 404), then rights are checked on
  its owning project (403), and only afterwards the client `tproject_id` assertion can
  answer `project_mismatch` (404).

## Wiring
- `cfg/reports.cfg.php` — `exec_frequency` report entry (`link_report_exec_frequency`,
  `gui/templates/results/execFrequency.html`, format_html).
- `api/aside/index.php` — the Reports submenu link.
- `api/resultsnav/index.php` — `exec_frequency` → the modern URL.
- `lib/functions/common.php` — `$actions->execFrequency`.
- `locale/*/strings.txt` (19 locales) — `$TLS_link_report_exec_frequency`.

## i18n
- `efq.*` (71 keys) + `footers.execFrequency` in all 10 client bundles
  (`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`); `ai/verify_i18n_coverage.sh`
  passes 9/9.

## Verification
- Fixture `tmp/fixtures_1988.php` (project EFQ1988: 6 assigned units — never run, single,
  rerun, high re-run, platform-scoped, all-platform — plus an empty plan and a role-3
  user without `testplan_metrics`).
- Browser EN/RO: default scope (6 units / 5 executed / 13 executions / healthy), build +
  threshold re-scope (`over_tested` banner), CSV alignment, empty plan (`no_data`),
  no-right state card; console clean; Event Viewer 0 new Error/Warning.
- Suite `Issue #1988` appended to `tmp/TLU_Test_Cases.md`.

## Screenshots
- `docs/screenshots/1988-execfrequency-normal.png` — default report (EN).
- `docs/screenshots/1988-execfrequency-over.png` — build 1 + threshold 2 (`over_tested`).
- `docs/screenshots/1988-execfrequency-ro.png` — Romanian locale.
- `docs/screenshots/1988-execfrequency-norights.png` — 403 Access denied state.
