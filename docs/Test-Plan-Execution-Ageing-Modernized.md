# Test Plan Execution Ageing report (modernized)

Tracking issue: **#1989** (net-new 2.0.1 report; TestLink 1.9.20/2.0.1 reported
the latest status of a test case but never how old that evidence is).

## What it is

TestLink keeps every execution timestamp of every assigned test-case version,
but no 1.9.20 screen answered "how stale is the execution evidence of this
test plan?". The results reports show the **latest status** only, so a plan
whose cases were all run two days ago and a plan whose cases were last touched
a year ago looked the same on the report list.

This read-only report units each assigned `testplan_tcversions` link (version on
a platform), takes the latest execution inside the plan (optionally scoped to a
build and/or platform), ages it in whole days and buckets it as
`never` / `fresh` (<= 7d) / `aging` (8–30d) / `stale` (31–90d) / `dormant`
(> 90d), with a per-suite roll-up and a plan verdict (`clear` / `fresh` /
`watch` / `at_risk`).


## Screen

`gui/templates/results/executionAgeing.html`

- Dashio teal header, dark toolbar (build and platform selects, Apply, Refresh,
  Export CSV, Close).
- Report-context panel: test project, test plan, build filter, platform filter.
- Verdict banner: `clear` (nothing to age), `fresh` (no never-run and no stale
  or dormant), `watch` (stale/dormant present but below the risk share),
  `at_risk` (never-run present OR stale+dormant share >= 20%).
- 10 KPI cards: assigned links, executed, never run, fresh, aging, stale,
  dormant, average age, oldest age, testers involved.
- Per-suite DataTable: links, never, fresh, aging, stale, dormant, average age,
  stale+dormant percentage.
- Main DataTable (11 columns, searchable, sortable): test case, external id,
  suite path, version, platform, last build, last status, last executed by,
  last executed, age (days), bucket — most severe first (never run, then oldest).
- CSV export `execution-ageing-<plan>.csv` (UTF-8 BOM, `;` separator,
  spreadsheet-formula neutralisation), localised bucket labels.
- Localized state cards for 400 / 401 / 403 / 404 / 405; locale switcher; all
  labels in `eage.*` keys.





## BFF

`api/executionageing/index.php` — plain PHP, session auth,
`bffSameOriginGuard`, `bffEnforceSession`, JSON I/O, `Cache-Control: no-store`.

| Action | Method | Parameters |
| --- | --- | --- |
| `init` | `GET\|HEAD` | `tplan_id`, optional `tproject_id` |
| `report` | `GET\|HEAD` | `tplan_id`, optional `tproject_id`, `build_id`, `platform_id` |

Rights: `testplan_metrics` on the **owning** test project of the plan. The
client-supplied `tproject_id` is only an assertion: it is checked against the
plan's owning project and can never widen the query.

Order of operations: resolve the plan (opaque 404 when unknown) → check
`testplan_metrics` on its owning project (403 `no_right`, logged at `AUDIT`) →
only then assert the client `tproject_id` (404 `project_mismatch`), so the
endpoint is not a plan/project existence oracle for unentitled callers.

Error contract: `not_authenticated` / `session_expired` (401), `no_right`
(403), `tplan_not_found` / `project_mismatch` (404), `invalid_request` (400),
`method_not_allowed` (405).

## Semantics

- The unit of the report is one assigned **`testplan_tcversions`** link (version
  on a platform), not the test case.
- A link with `platform_id = 0` means "any platform": it is reported once and
  its latest execution on any platform is used.
- The latest execution is the newest `execution_ts` for the (version, platform)
  inside the selected plan, optionally narrowed by `build_id` / `platform_id`.
- Age = whole days between today and the latest `execution_ts`; a link with no
  execution is `never` with a `null` age.
- Buckets: `fresh` <= 7, `aging` 8–30, `stale` 31–90, `dormant` > 90, `never`.
- Plan verdict precedence: `clear` (all links never or no links) → `fresh` (no
  never-run, no stale/dormant) → `at_risk` (never-run present OR stale+dormant
  share >= 20%) → `watch` (otherwise).

## Wiring

- `cfg/reports.cfg.php` — `execution_ageing` report entry.
- `api/aside/index.php` — the Reports submenu link.
- `api/resultsnav/index.php` — `execution_ageing` → the modern URL.
- `lib/functions/common.php` — `$actions->executionAgeing`.
- `locale/*/strings.txt` (19 locales) — `$TLS_link_report_execution_ageing`.

## i18n

`eage.*` (76 keys) + `footers.executionAgeing` in all 10 client bundles;
`ai/verify_i18n_coverage.sh` passes 9/9.

## Verification

- Fixture `tmp/fixtures_1981.php` reused (7 assigned links: blocked 35d, blocked
  10d, blocked 2d, passed 1d, never run, platform-1 and platform-2 cases; an
  empty plan; a role-3 user without `testplan_metrics`).
- Machine contract: default scope (7 assigned / 6 executed / 1 never / fresh 2
  / aging 3 / stale 1 / avg 12d / oldest 35d / `at_risk`), build and platform
  re-scopes, empty plan, 400/401/403/404/405 paths.
- Browser EN/RO: default scope, build re-scope, CSV export, DataTable
  search/sort, empty plan, no-right state card; console clean; Event Viewer 0
  new Error/Warning.
- Suite `Issue #1989` appended to `tmp/TLU_Test_Cases.md`.

## Screenshots

- `docs/screenshots/1989-execution-ageing-normal.png` — default report (EN).
- `docs/screenshots/1989-execution-ageing-scoped.png` — build 2 scope.
- `docs/screenshots/1989-execution-ageing-ro.png` — Romanian locale.
- `docs/screenshots/1989-execution-ageing-empty.png` — empty plan (`clear`).
- `docs/screenshots/1989-execution-ageing-norights.png` — 403 Access denied state.
