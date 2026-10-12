# Overall Metrics by Platform (Refs #1906)

Screen: `gui/templates/results/platformPieChart.html` ·
BFF: `api/platformpiechart/index.php` (`GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`) ·
Issue: #1906

![Overall Metrics by Platform](screenshots/issue-1906-ppc-report.png)

## The last per-platform chart endpoint

The ASIDE ledger (`docs/MODERNIZATION-STATUS.md`) shows **TODO = 0** — every screen
reachable from the ASIDE menu is already a modern Dashio page + REST BFF. The audit of
the remaining legacy `lib/**` renderers kept one family alive for a while: the pChart
PNG endpoints. **#1845** (priority bar chart) and **#1905** (top-level suites bar chart)
modernized two of them; **`platformPieChart.php`** was the per-platform sibling still
missing a standalone numbers report:

| Evidence | Finding |
| --- | --- |
| ASIDE/Reports hub audit | `api/resultsnav/index.php` maps every report key to a modern `gui/templates/**/*.html`; no legacy `lib/results/` URL remains |
| `charts_data` payload (`api/reports`) | returns `overall`, `platforms`, `by_keyword`, `by_suite` — the **pixels** of the per-platform pies live in `charts.html` |
| What `charts.html` does NOT give | a per-platform DataTable, a progress column, a totals row, a CSV export, a rights-hardened BFF, a standalone deep link |

So exactly like its **#1845** / **#1905** siblings, this is a *dedicated-screen*
modernization: the pie pixels already existed in a Chart.js section, but the legacy
endpoint's report — the exact numbers, the progress, the export — had no modern home and
no ownership-checked access.

## The legacy file and what it did

`lib/results/platformPieChart.php` (1.9.20) never rendered a Smarty template. It
included the vendored pChart library and answered a **PNG** pie chart for **one** test
plan platform (platform_id was a mandatory request argument, so a full picture meant one
HTTP request + one PNG per platform). The data came from
`tlTestPlanMetrics::getStatusTotalsByPlatformForRender($tplan_id)`: per-platform
execution-status totals over **active builds by default**, with the status set =
`results.status_label_for_exec_ui` and the slices labelled via `results.status_label`. A
platform with no test case assigned rendered an all-zero pie instead of failing.

## Security gap closed

The legacy gate was a bare `checkRights()` calling a **global**
`hasRight('testplan_metrics')` that never proved the plan the numbers belonged to;
`tproject_id` came from the session and `platform_id` from `$_REQUEST` with no ownership
check. The new BFF:

1. resolves the plan and derives the **owning** test project server-side;
2. checks `testplan_metrics` on that owning project **and** plan with
   `getAccess = true` (a right held only globally no longer reaches a private project);
3. treats `tproject_id` as a **client-side assertion** only — a stale deep link fails
   loudly (`project_mismatch`, 404) instead of silently reporting another project;
4. answers a documented JSON contract: `400 invalid_request`, `401 not_authenticated /
   session_expired`, `403 no_right`, `404 tplan_not_found / project_mismatch`,
   `405 method_not_allowed`, plus `nosniff` + `no-store`.

## The screen

`gui/templates/results/platformPieChart.html` follows the **#1905** pattern: a Dashio
shell (teal header, dark toolbar), the report context strip (project, plan, platform
count, version count), a "back to Charts" link, one stacked horizontal bar per platform
drawn with **plain CSS** (no chart library to ship), and a DataTable with the exact
numbers behind every bar plus a Totals row and a Progress (executed / total) column.
Buttons: **Refresh**, **Export CSV** (RFC 4180 quoting + UTF-8 BOM), **Close**. A footer
locale switcher drives `TLi18n`; every label is a bundle key (`ppc.*`,
`footers.platformPieChart`, `charts.openPlatformMetrics`) in all ten locales.

Because one test-case version can be assigned to several platforms, the BFF documents
(header + screen header) that the Totals row is the **sum of the shown platform
columns** — each row stays self-consistent — rather than a de-duplicated whole-plan
count.

Entry points:
- `gui/templates/results/charts.html` — new *Open Metrics by Platform* entry link in the
  *Overall Metrics by Platform* section (beside the per-platform pies), wired to the
  active plan;
- `lib/functions/common.php` — `$actions->platformPieChart` registered inside the
  `tplan_id` guard;
- the legacy endpoint `lib/results/platformPieChart.php` is now a session-guarded
  **302 shim** to the modern screen (document navigation → redirect; XHR/`<img>` caller →
  `405 modern_endpoint_only`, because the legacy answer was a PNG and a 302 would render
  broken pixels; missing `tplan_id` → 400; anonymous → login redirect preserving the
  destination).

## Testing

One fresh-DB end-to-end suite was appended to `tmp/TLU_Test_Cases.md`
(`## Task — Issue #1906`, fixture `tmp/fixtures_1906.php`). 19 steps, all PASS: full
two-platform chart render (Win11 1 passed + 1 failed + 1 blocked + 1 not-run = 75%,
Linux 1 passed + 1 failed = 100%, totals 6 / 2 / 2 / 1 / 1, progress 83.3%), empty-plan
state, CSV export, **Română** locale, `no_right` denial as a restricted (role 3) user,
the BFF contract matrix (200/400/401/403/404/405), the shim matrix (302/405/400), and
the charts.html entry link. The `events` table shows only the three intentional
no-right audit rows written by the denial tests. Code review (subagent) over the BFF +
screen + shim returned all **OK**, no FIX items.
