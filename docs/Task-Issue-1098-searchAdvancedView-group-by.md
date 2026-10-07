# Task — Issue #1098: Group-by-test-suite / group-by-req-spec + toolbar in `searchAdvancedView` results (gap vs legacy)

**Screen:** ASIDE > Search > *Advanced search* —
`gui/templates/search/searchAdvancedView.html?tproject_id=<id>`
**BFF API:** unchanged (`api/search/index.php`, `action=fulltext`)
**Wiki mirror:** `Advanced-Search.md` (GitHub Wiki)

## Gap

Legacy built the Advanced Search results with `tlExtTable`:

* **Test Cases** — `lib/search/search.php:225` `buildTCExtTable()`:
  `setGroupByColumnName(lang_get('test_suite'))` (the suite path column),
  `setSortByColumnName(lang_get('test_case'))`, `sortDirection='DESC'`,
  `showToolbar=true`, `toolbarShowAllColumnsButton=false`;
* **Requirements** — `lib/search/search.php:402` `buildRQExtTable()`: group
  by `lang_get('req_spec')` (the requirement-spec path), sort by `requirement`
  DESC, same toolbar;
* the grid's `GroupingView.showGroupName` defaults to `true` so the group
  header is `{column header}: {value}` and
  `lib/functions/exttable.class.php:589-591` sets
  `groupTextTpl: '{text} ({[values.rs.length]} {[values.rs.length > 1 ? "Items" : "Item"]})'`
  → `Test Suite: Suite Alpha (2 Items)`;
* toolbar `Ext.ux.TableToolbar` (`gui/javascript/ext_extensions.js:184-240`):
  *Expand/collapse groups*, *default state*, *Reset filters*.

The modernized screen rendered **flat** plain-HTML rows
(`searchAdvancedView.html:492-511` `renderTC`, `:525-539` `renderRQ`): the
suite/req-spec path was just a repeated cell, with no group headers, no item
counts and no toolbar.

## What is implemented now

| Legacy behaviour | Modern implementation |
|---|---|
| `setGroupByColumnName('test_suite')` (TC) | client-side grouping on `row.path` in `renderGrouped()` (`searchAdvancedView.html:544`), preserving server order |
| `setGroupByColumnName('req_spec')` (RQ) | same grouping on `row.path`, group label *Requirement Specification* (`cf.node.requirement_spec`) |
| `groupTextTpl '{text} (N Items)'` | header row `Test Suite: Suite Alpha (2 Items)` / `Requirement Specification: Spec A (Smoke) (1 Item)`; `searchAdv.groupHeader` templates `{label}: {value} ({count})`, singular/plural via `groupItem`/`groupItems` |
| Collapsible groups | click a `tr.grp-header` toggles only that group (`.collapsed` class + caret rotation) |
| Toolbar *Expand/collapse groups* | `expandCollapseAll(secId)` toggles every group at once |
| Toolbar *Default state* / *Reset filters* | `resetFilters(secId)` restores the default view — all groups expanded |
| `tsSummary`/`TS`, `rs*`/`RS` tables | untouched (legacy does **not** group them); `renderSimple` remains flat |

The grouped column stays visible in the body rows (legacy
`hideGroupedColumn` default `false`), so no information is lost.

**No BFF change was required**: `api/search/index.php` already returns `path`
per TC row (`:584`, from `get_full_path_verbose(..., 'path_as_string')`) and
per requirement row (`:623`).

## Screenshots

`docs/screenshots/issue-1098-searchadv-grouped.png` (repository) and
`issue-1098-searchadv-grouped.png` (GitHub Wiki) — Test Cases grouped per
suite with one group collapsed + toolbar. `...-grouped-rq.png` — Requirements
grouped per requirement specification.

## i18n

6 new keys in **all 10** bundles: `searchAdv.expandCollapseGroups`,
`searchAdv.resetFilters`, `searchAdv.groupHeader`, `searchAdv.groupItem`,
`searchAdv.groupItems`, `searchAdv.ungrouped`. i18n coverage gate PASS 9/9.

## Test data

`tmp/fixtures_1098.php` (idempotent, git-ignored): test project **SA1098**
(`id=1`, prefix `SA1098`, requirements enabled) with suites `Suite Alpha`
(2 cases) / `Suite Beta` (2 cases) — all matching `Smoke` — plus req specs
`RQ1098-A` / `RQ1098-B` with one `Smoke` requirement each, so both a 2-group
TC grid and a per-spec RQ grid are exercised.

## Test suite

`tmp/TLU_Test_Cases.md`, suite `## Task — Issue #1098` — PASS
(grouping + counts for TC and RQ, single-group toggle, expand/collapse all,
reset filters, flat TS/RS fallback, BFF row-set unchanged, console 0 errors,
no Event Viewer Error/Warning rows).