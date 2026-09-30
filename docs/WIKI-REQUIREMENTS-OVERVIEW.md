# Requirement Overview (reqOverview)

Modernized screen: **Requirement Overview** (ASIDE: Requirements Design →
Requirement Overview). Refs [#566](https://github.com/sebiboga/testlink-upgraded/issues/566).


## Grouping by Requirement Specification (Refs [#1034](https://github.com/sebiboga/testlink-upgraded/issues/1034))

The legacy controller grouped the ExtJS grid by the req-spec path and shipped a
grid toolbar; the modernized screen originally dropped both. They are now back:

| Legacy (1.9.20) | Modern (2.0.1) |
|---|---|
| `reqOverview.php:282` `$matrix->setGroupByColumnName($labels['req_spec_short'])` | DataTables **RowGroup** plugin (`dataTables.rowGroup.min.js`), `rowGroup.dataSrc = 'spec_path'` — one collapsible group header per req-spec path, including nested paths (`System Requirements/Nested Requirements`) |
| `exttable.class.php:591` `groupTextTpl: '{text} ({[values.rs.length]} {[values.rs.length > 1 ? "Items" : "Item"]})'` | group header text `{{text}} ({n} Item[s])` with the singular/plural split preserved (`Performance Requirements (1 Item)`) |
| `exttable.class.php:104` `toolbarExpandCollapseGroupsButton` | toolbar **Expand/Collapse Groups** — collapses/expands every group at once; clicking a single group header toggles only that group (chevron rotates) |
| `exttable.class.php:109` `toolbarShowAllColumnsButton` + `exttable.class.php:55` `hideGroupedColumn = true` | toolbar **Show all Columns** — the grouped *Requirement Specification* column is hidden by default and revealed on demand; the button label flips to the inverse action |
| `exttable.class.php:119` `toolbarRefreshButton` | toolbar **Refresh** — re-fetches the BFF payload, groups rebuilt |
| `exttable.class.php:286-288` default sort (coverage desc, else status desc) | `order: [[spec asc], [coverage desc]]` so groups keep the legacy alphabetical order and rows keep the legacy sort inside each group |

The group header is clickable and the toolbar reports its action in an info
line (*Groups collapsed* / *Groups expanded*). Search, paging and the
requirement popup links behave exactly as before, now inside groups.

## What it does

Lists every requirement of the active test project in a DataTables grid —
same data the legacy ExtJS table showed (`lib/requirements/reqOverview.php`),
rebuilt as a standalone Dashio page backed by a JSON BFF:

| Column | Notes |
|---|---|
| Requirement Specification | req-spec path of the requirement |
| Requirement | `docID : title` link + edit pen, opens `reqView.php` popup |
| Version | `[vN rM]` version/revision tag |
| Creation date | timestamp + author |
| Last update | timestamp + modifier, or "Never" |
| Frozen | Yes/No badge (blue = frozen, teal = open) |
| Coverage | only when *expected coverage management* is enabled; `pct% (covered/expected)`, red under 100 %, "n/a" for requirements without expected coverage |
| Type / Status | localized labels from project config |
| Relations | count per requirement, only when relations are enabled |

Custom fields linked to requirements at design time are appended as extra
columns automatically.

## Toolbar

* **Show all versions** — toggle between latest version only and all versions
  of each requirement. Legacy parity: the choice is persisted in
  `$_SESSION['all_versions']` and restored on reload; an explicit
  `all_versions=1|0` URL parameter wins.
* **Grid toolbar** (dark bar below the top toolbar): **Expand/Collapse Groups**,
  **Show all Columns** and **Refresh** — the three buttons the legacy ExtJS grid
  exposed (`reqOverview.php:289-294`). See *Grouping by Requirement Specification*.
* Locale switcher (all 10 bundles).


## Access & permission

* ASIDE entry switched from `lib/requirements/reqOverview.php` to
  `gui/templates/requirements/reqOverview.html`
  (`$actions->reqOverView` in `lib/functions/common.php`).
* Right required: `mgt_view_req` (BFF also accepts
  `req_tcase_link_management`, mirroring the shared requirements router).
  Without it the API answers HTTP 403 and the screen shows "No permission".

## i18n

22 new keys (`header.reqOverview`, `common.refresh`, `ro.*`,
`ro.col.*`) added to **all** locale bundles: en, de, es, fr, it, ja, pt,
ro, ru, zh.

Seven further keys were added with the grouping feature (Refs #1034) to **all**
10 bundles: `ro.grid.expandCollapseGroups`, `ro.grid.showAllColumns`,
`ro.grid.hideGroupedColumn`, `ro.grid.groupItem`, `ro.grid.groupItems`,
`ro.grid.groupsExpanded`, `ro.grid.groupsCollapsed`.


## BFF

`GET /api/requirements/index.php/overview?tproject_id=N[&all_versions=1|0]`

Returns `meta` (config flags + label maps + custom fields), `items[]`
(one row per requirement version), `total`, effective `all_versions`,
`elapsed_seconds`. Data assembly mirrors the legacy controller:
bulk latest-version revision fetch, cached spec paths, coverage counter
set, relations counters, bulk custom-field values with date formatting.

## Bugs found while testing

* #569 — `api/reqspec` `create_spec` attached specs to tree parent 0,
  orphaning them (invisible everywhere). Fixed to attach to the test
  project node.
* All-versions toggle reset on plain reload instead of using the session —
  fixed for legacy parity.
* #1734 — the grid wrapper `#tableWrap` shipped with an inline
  `display:none` and **nothing ever removed it**, so the requirement grid was
  never rendered on the screen (only the header, toolbars, notes and footer
  were visible). Fixed with `$('#tableWrap').show()` in the success path of
  `renderTable()`.

## Test evidence

Suite 45 in `tmp/TLU_Test_Cases.md` — 14/14 PASS.
Suite 1034 in `tmp/TLU_Test_Cases.md` — 22/22 PASS (grouping, the three
toolbar buttons, single-group toggle, search/ordering interaction, all-versions
toggle, ro locale, empty project, invalid project, console, Event Viewer,
syntax gate, i18n completeness, diff scope).
