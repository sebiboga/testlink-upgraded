# Feature — Issue #986: DataTables grid (sort / search / pagination / column filtering) in `projectsView.html`

**Status:** implemented, verified, pushed
**Issue:** [#986](https://github.com/sebiboga/testlink-upgraded/issues/986) — *Implement DataTables sort/search/pagination/column-filtering in projectsView (gap vs legacy)*
**Branch:** `task/issue-986` · **Commit:** `143d8588d`
**Test suite:** `tmp/TLU_Test_Cases.md` → *Task — Issue #986* (25/25 PASS)

## 1. The gap

`gui/templates/projectsView.html` was the only "list" screen in the
modernization that had been left as a **hand-rolled `<table>`**. The legacy
1.9.20 screen got its grid from two shared Smarty includes, and neither
capability survived the port:

| Legacy source | Capability | Modern state before this change |
|---|---|---|
| `dashio/project/projectView.tpl:37` — `inc_head.tpl enableTableSorting="yes"` | DataTables + sortable columns | no DataTables asset loaded at all (`dtScripts: []`) |
| `projectView.tpl:50-51` — `DataTables.inc.tpl` + `DataTablesColumnFiltering.inc.tpl` with `DataTablesLengthMenu=$ll` (`input_dimensions.conf` → `pagination_length=20`) | length menu, record counter, pagination, per-column filters | all rows dumped at once, no counter, no pager |
| `projectView.tpl:87-89` — `{#SMART_SEARCH#}` / `{#NOT_SORTABLE#}` per `<th>` | marks which columns get a filter box / are sortable | no per-column UI at all |
| `projectView.tpl:100-105` — `<table id="item_view">` | the table DataTables binds to | `<table class="table" id="projectsTable">`, no DataTables |
| `lib/project/projectView.php:99` — `doAction` `search`/`list` + `no_records_found` feedback | server-side name search with a "no records" message | one `#searchInput` whose `keyup` handler hid rows by whole-row substring match |

Measured before the change (browser, `evaluate_script`):

```json
{"hasDataTables": false, "dtScripts": [],
 "headerCells": ["ID","Project Name","Prefix","Issue Tracker","Code Tracker","Status","Actions"],
 "rowCount": 8, "sortingIcons": false, "hasPagination": false, "hasInfo": false,
 "hasLengthMenu": false, "hasColumnFilterRow": false}
```

The root cause is structural: `renderProjects()` wrote raw `<tr>` markup
straight into `#projectsBody`, which is incompatible with the DataTables row
cache, so the grid had to be re-plumbed around the `data:` API.

## 2. What was implemented

No BFF change was needed — `api/projects/index.php:listProjects()` already
returns the whole list in one payload (`ORDER BY nh.name`) with `name`,
`prefix`, `description`, `isActive`, `isPublic`, `issueTracker`, `codeTracker`
and `reqMgrSystem`, exactly like legacy, whose DataTables also filtered the
already-rendered full list client-side. The change is front-end only.

### 2.1 Assets and grid CSS

* `gui/templates/projectsView.html:9` — `dataTables.bootstrap.min.css` **1.13.7**
* `gui/templates/projectsView.html:301-302` — `jquery.dataTables.min.js` +
  `dataTables.bootstrap.min.js` **1.13.7**
* `gui/templates/projectsView.html:98-124` — Dashio grid styling: dark
  (`#22242a`) header, teal paginate buttons, zero-margin wrapper rows

Same CDN and version as the already-modernized `gui/templates/plans/planView.html:9,163-164`,
so no new external dependency is introduced.

### 2.2 Data-driven rows + grid options

`projectRow(p)` (`:335-374`) returns a cell array; `renderProjects()` (`:376-435`)
feeds it to the DataTable:

```js
projectsTbl = $('#projectsTable').DataTable({
  data: rows,
  orderCellsTop: true,
  stateSave: true,
  pageLength: 20,
  lengthMenu: [10, 20, 50],
  dom: 'lrtip',
  language: dtLanguage(),
  columnDefs: [
    { targets: 0, className: 'text-center', orderable: false },
    { targets: 6, orderable: false }
  ]
});
```

* `pageLength: 20` mirrors `input_dimensions.conf` → `pagination_length=20`.
* `dom: 'lrtip'` is the legacy `dom` string: **no** `f`, because the modern
  screen already has its own search box (see §2.4) and two search inputs would
  be confusing.
* `columnDefs` marks **ID** and **Actions** `orderable: false`, the DT 1.13
  equivalent of the legacy `{#NOT_SORTABLE#}` cells.

### 2.3 Per-column filtering (`DataTablesColumnFiltering.inc.tpl` port)

* `:110-116` — the filterable headers carry `data-col-filter="1"`
  (Project Name, Prefix, Issue Tracker, Code Tracker, Status) — exactly the
  legacy `{#SMART_SEARCH#}` set.
* `buildColumnFilters()` (`:437-464`) is called **before** init, so the
  two-row header is stable for the instance lifetime. It
  `clone(false)`s the header row (with `false` → no sort handlers, the legacy
  trick from `DataTablesColumnFiltering.inc.tpl:34`), strips the sort classes
  from the clone, and injects one
  `<input type="text" placeholder="{proj.colFilterPlaceholder}">` per flagged
  column; unflagged columns get an empty `<th>`.
* The input handler calls
  `projectsTbl.column(idx).search(value, false, true).draw()` — the
  `use_regexp = false` / `use_smartsearch = true` branch of the legacy include.
* `restoreColumnFilterState()` (`:466-474`) repopulates the boxes from
  `state.loaded()`, the legacy `DataTablesColumnFiltering.inc.tpl:40-46` restore.

### 2.4 Global search

`:709-724` replaces the old row-hiding `keyup` handler with a 200 ms-debounced
bridge to `projectsTbl.search(query).draw()`. The modern search box therefore
becomes the DataTables global search — a superset of the legacy server-side
*"search on name"* `doAction` at `lib/project/projectView.php:99`.

### 2.5 Lifecycle

Every `loadProjects()` destroys any live instance **before** re-initializing,
keyed on the DataTables registry (`$.fn.DataTable.isDataTable`) rather than on
the `projectsTbl` variable — the same reasoning documented for
`planView.html:272-278` (Refs #1116): a live instance can exist while the
variable is `null` after an aborted init. An empty list destroys the grid,
hides the table and shows the existing empty state.

### 2.6 i18n

New keys in **all 10** bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`):

| Key | en |
|---|---|
| `proj.colFilterPlaceholder` | Filter column |
| `dt.aria.sortAscending` | activate to sort column ascending |
| `dt.aria.sortDescending` | activate to sort column descending |
| `dt.aria.sortNone` | *(empty)* |

The paging strings reuse the **existing** `rtf.dt*` keys
(`dtInfo`, `dtInfoEmpty`, `dtInfoFiltered`, `dtLengthMenu`, `dtZeroRecords`,
`dtFirst`, `dtLast`, `dtNext`, `dtPrev`) — they were already present in every
bundle, so no duplication was introduced.

## 3. Gotchas found while implementing

1. **`lengthMenu: [10,20,50,-1]` renders the literal label `-1`.** DataTables
   does not translate the "all rows" entry in array form. The `-1` option was
   dropped; legacy only ever passed a single length anyway.
2. **`columnDefs: [{targets:'_all', header: 1}]` breaks the titles.** At init
   the cloned filter row does not exist yet, so DataTables resolves the column
   titles against an empty row. The correct DT 1.13 equivalent of legacy's
   `orderCellsTop: true` is: build the filter row **before** init and leave the
   default `header: 0`, which keeps the label row as title/sort row.
3. **`nodes_hierarchy` is not optional when seeding projects.**
   `api/projects/index.php:projectSelect()` joins
   `nodes_hierarchy nh ON nh.id = tp.id`; a fixture inserted only in
   `testprojects` makes the endpoint answer `200 {"data":[]}` — an empty list
   that looks like a bug. `tmp/issue-986-fixtures.sql` seeds both tables.
4. **`jQuery('#searchInput').trigger('keyup')` does not reach a native
   `addEventListener` handler** (jQuery 3 only walks its own handler cache when
   the event has no native method). The global search therefore has to be
   exercised with a real key press — an initial jQuery-triggered "clear" test
   produced a false FAIL. The column-filter inputs use `jQuery.on()` and are
   unaffected.
5. **The cloned filter row survives `draw()`.** DataTables only rebuilds the
   header cells it owns, so the 5 inputs and their handlers stay alive across
   pagination, sorting and filtering (verified: 5 → 5 inputs, same DOM node).

## 4. Verification

Full suite in `tmp/TLU_Test_Cases.md` → *Task — Issue #986*: **25/25 PASS**.

Highlights (14-project fixture, so the pager is really exercised):

| Check | Measured |
|---|---|
| grid present | `isDataTable:true`, 2 thead rows, 5 filter inputs, `Showing 1 to 14 of 14 entries` |
| length menu 10 | `Showing 1 to 10 of 14 entries`, 10 rows |
| page 2 | `Showing 11 to 14 of 14 entries` → Lambda, Mu, Nu, Xi |
| sort name desc/asc | `Zeta, Xi, Theta…` / `Alpha, Beta, Delta…` |
| prefix filter `AL` | `["ALPHA"]` |
| real typing `BE` in prefix box | `["BETA","LAMBDA"]` |
| status filter `inactive` | `["Inactive","Inactive","Inactive"]` |
| smart search `alpha kappa` | `["Kappa Alpha Regression"]` (terms AND-ed) |
| global search `mu` | `["Mu Mobile Coverage"]` |
| toggle active | `Active → Inactive`, `isDataTable:true`, 5 filter inputs preserved |
| delete | `14 → 13`, row gone, grid still a DataTable |
| create via modal | `14 → 15`, new row present |
| empty list | table hidden, empty state visible, no grid leak |
| console | no `error` / `warn` |
| Event Viewer | `select count(*),max(id) from events` → `5 5`, unchanged |

Screenshots: `tmp/shots/issue-986-before.png` (plain table) and
`tmp/shots/issue-986-after.png` (length menu, teal filter row, counter, pager).

## 5. Out of scope

* The **Notes/description column** (legacy `projectView.tpl:88,113-115`) and the
  **API-id tooltip on the name icon** are tracked separately in **#987**.
* The requirement-feature quick toggle is **#988**; the API-key display in the
  edit modal is **#990**.
* Legacy's `fixedHeader: true` is a FixedHeader-extension option in DT 1.13 and
  is intentionally not ported; the shared include used it only for the sticky
  header, which the Dashio `position` styles do not need here.
