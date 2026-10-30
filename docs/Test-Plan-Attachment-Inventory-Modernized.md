# Test Plan Attachment Inventory (modernized)

Tracking issue: **#1976** (net-new report; parent metric: #1073, Metrics &
Trends).

## What it is

A net-new TestLink 2.0.1 report under **Reports**. TestLink 1.9.20 stores
attachments in `attachments` (`fk_table` + `fk_id` → `attachment_repository`
content) and shows them only inside the editor of the object that owns them.
There was no way to answer "**what is attached to this test plan?**" — you had
to open the plan, every test case, every step and every execution one by one.

This screen aggregates the attachments of the plan's artifacts in one table.
It includes:

- the test plan itself (`testplans`),
- its linked test cases (`testcases`) and test-case versions (`tcversions`,
  where the test-case/version attachment dialog actually stores its files —
  `testcase.class.php` constructs the attachment container with
  `attachmentTableName = 'tcversions'`),
- the steps of the linked versions (`tcsteps`),
- the executions of the plan (`executions`).

## Screen

`gui/templates/results/attachmentInventory.html`

- Dashio teal header, dark toolbar (Refresh, Export CSV, Object type, Close).
- Report-context panel (test project, test plan, attachments, total size,
  object types, latest upload).
- 4 KPI cards: attachments, total size, object types, latest upload.
- Object-type filter (client-side on a hidden DataTables column, composes with
  the search box) with a live count per bucket.
- 8-column DataTable: file, object type, attached to, title, size, MIME type,
  added, download.
- Download links go straight to the existing attachment API
  (`/api/attachments/index.php?action=download&id=N`), which re-checks the
  rights of the attachment owner.
- CSV export (UTF-8 BOM, `;` separator, spreadsheet-formula neutralisation).
- Localized state cards for bad/missing context, 401 / 403 / 404 / 405 and an
  explicit empty state.

## BFF

`api/attachmentinventory/index.php` — plain PHP, session auth,
`bffSameOriginGuard`, `bffEnforceSession`, JSON I/O, `Cache-Control: no-store`.

| Action | Method | Parameters |
| --- | --- | --- |
| `init` | `GET\|HEAD` | `tplan_id`, optional `tproject_id` |

Rights: `testplan_metrics` on the **owning** test project of the plan
(`getAccess = true`). The client-supplied `tproject_id` is only an assertion and
can never widen the query.

Error contract: `not_authenticated` (401), `no_right` (403),
`tplan_not_found` / `project_mismatch` (404), `invalid_request` /
`method_not_allowed` (400/405).

## Semantics

- The object universe is resolved from the plan, never from client ids:
  - `testplans.id = tplan_id`
  - `tcversions` through `nodes_hierarchy` →
    `testplan_tcversions` (only versions currently linked to the plan)
  - `tcsteps` through `nodes_hierarchy` (a step node's parent is the
    test-case **version** node; `tcsteps` has no `tcversion_id` column)
  - `executions.testplan_id = tplan_id`
- `testcases.testcase_id = nodes_hierarchy.id`; `tcversions` has no
  `testcase_id` column, its test case is `nodes_hierarchy.parent_id`.
- Execution status is stored as `p/f/b/n`; the label keys are the verbose
  `passed/failed/blocked/not_run`, so the BFF maps the code before localizing.
  Without the mapping the Event Viewer logs `string 'test_status_p' is not
  localized`.
- Sizes are the stored `file_size`; the table shows human units and the total
  in bytes is returned in `totals.bytes`.
- Test suites are project-scoped (not plan-scoped) and intentionally stay out
  of this report.

## Wiring

- `cfg/reports.cfg.php`: `$tlCfg->reports_list['attachment_inventory']`.
- `api/aside/index.php`: Reports menu mapping for
  `link_report_attachment_inventory`.
- `lib/functions/common.php`: `$actions->attachmentInventory`.
- `locale/*/strings.txt`: `$TLS_link_report_attachment_inventory` (19 server
  locales).
- i18n: `ai.*` keys + `footers.attachmentInventory` in all 10 bundles.

## Verification

- Fixture: `tmp/fixtures_1976.php` (projects `AII1976` / `AII1976 EMPTY`,
  plans `AI Plan` / `AE Plan`, five real files under `upload_area/ai1976/`,
  user `ainorights`). Ids change on every run — re-read them before testing.
- BFF: init 200 (5 attachments, 300544 bytes, one per object type); empty plan
  200 with `items: []`; unknown plan 404 `tplan_not_found`; cross-project
  assertion 404 `project_mismatch`; unknown action 400; POST 405; no session
  401; `ainorights` 403.
- Download: `GET /api/attachments/index.php?action=download&id=11` → 200
  `application/pdf`, 24576 bytes (FS repository, `compression_type=1`).
- Browser (admin, EN + RO): KPIs, table and filter render; empty, missing
  context and denied states render; console clean; no raw i18n keys.
- i18n coverage gate: 9/9 bundles PASS (10,276 keys).
- Suite: `Issue #1976` in `tmp/TLU_Test_Cases.md` = 16/16 PASS.

## Screenshots

- `docs/screenshots/issue-1976-attachment-inventory-normal.png` (all objects).
- `docs/screenshots/issue-1976-attachment-inventory-filtered.png` (object type
  = Test case).
