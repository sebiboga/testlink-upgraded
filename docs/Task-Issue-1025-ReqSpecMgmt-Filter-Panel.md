# Task 1025 — requirements filter panel in `reqSpecMgmt.html` (gap vs legacy)

**Issue:** [#1025](https://github.com/sebiboga/testlink-upgraded/issues/1025)
**Status:** IMPLEMENTED (2026-09-28) — branch `task/issue-1025`
**Regression suite:** `tmp/TLU_Test_Cases.md` → *Suite 1025*, **40/40 PASS**

## The gap

TestLink 1.9.20's Requirement Specification Management screen shipped a full
filter panel, `gui/templates/dashio/include/inc_filter_panel.tpl`, driven by
`tlRequirementFilterControl` (`lib/functions/tlRequirementFilterControl.class.php`)
and applied server-side by `get_filtered_req_map()`
(`lib/functions/treeMenu.inc.php:1753-1889`).

The 2.0.1 rewrite of `gui/templates/requirements/reqSpecMgmt.html` dropped the
entire panel. Nothing in the modern screen could narrow the specification list:
no Document ID, Title, Status, Requirement Type, Spec Type, Expected Coverage,
Relation, Test Case ID or Custom Field filter, and no Apply / Reset /
Simple-Advanced / auto-refresh behaviour. Both the BFF (`action=specs`,
`action=reqs`) and `action=options` ignored every `filter_*` parameter, so the
feature was missing at both layers.

## What was implemented

### BFF — `api/reqspec/index.php`

| Symbol | Purpose |
|---|---|
| `reqTcPrefixGlue()` | project Test Case prefix, fetched **only** when a `filter_*` param was actually sent (cheap gate) |
| `readReqFilters()` | normalises `$_REQUEST` into the legacy filter shape |
| `reqFilterSql()` | the legacy JOIN set + WHERE clause |
| `reqFilteredMap()` | resolves the whole requirement map once, for either action |

`readReqFilters()` reproduces the three legacy quirks exactly:

* **Any disables the filter.** `0` anywhere in a multi-select (status, type,
  spec type, relation) returns `null` for that filter, i.e. unfiltered.
* **A bare Test Case prefix is not a filter.** `filter_tc_id` equal to the
  project prefix (e.g. `REQA-`) is treated as "not filled in".
* **Coverage 0 is not a filter** either; only a value `> 0` is applied.

`reqFilterSql()` rebuilds the legacy predicates:

* `doc_id` → `(R.req_doc_id LIKE … OR RS.doc_id LIKE …)` — **parenthesised**,
  because the legacy template emitted the un-grouped `AND … OR …` chain and
  MySQL binds `AND` tighter than `OR`; without the group this one filter would
  silently swallow every other condition.
* `title` → `NH_R.name LIKE …` on the requirement node name.
* `status` / `type` / `spec_type` → `IN (…)` on `RV.status`, `RV.type` and a
  joined `req_specs_revisions.type`.
* `coverage` → `RV.expected_coverage = N`.
* `relation` → `JOIN req_relations RR`, resolving
  `<typeId>_source` / `<typeId>_destination` / a bare `<typeId>` (equal).
* `tc_id` → `JOIN req_coverage` + `nodes_hierarchy` on the Test Case, matching
  the external Test Case id with the project prefix stripped.
* `filter_cf_<fieldId>` → the linked requirement Custom Field.

Every string is passed through `$db->prepare_string()` before concatenation.

`reqFilteredMap()` is invoked for **both** `action=specs` and `action=reqs`, so
a specification whose own plus subtree requirements no longer match is pruned
from the list — the legacy `get_filtered_req_map()` behaviour. One call resolves
the entire subtree map; the first implementation ran a per-spec query.

`action=options` now also publishes the filter domains: `tcPrefix`,
`childRequirementsManagement`, `expectedCoverageManagement`, `filterCFields`,
`relationTypes`, `reqStatuses`, `reqTypes`, `specTypes`, the defaults and
`expectedCoverageByType`.

### Front end — `gui/templates/requirements/reqSpecMgmt.html`

Dashio filter panel with all 9 legacy controls, the project Custom Field inputs,
per-filter matching-count badges, **Apply** / **Reset Filters**, and
**Simple Filters** ⇄ **Advanced Filters** (Advanced turns the single selects into
multi-select listboxes; Simple warns before trimming a multi-selection). The
"Update list after every operation" setting is honoured.

**Apply is authoritative** — typing in a control does not re-query until Apply is
pressed, which is what the legacy button-driven panel did. This was a deliberate
change from the first implementation, which re-filtered on every keystroke.

### i18n

20 new `rsf.*` keys in **all 10** locale bundles: `de, en, es, fr, it, ja, pt,
ro, ru, zh`. All validated with `python3 -m json.tool`.

## Findings from the code review

1. **Equal relations were unselectable.** The first implementation published the
   "equal" relation under a key the relation-type `<select>` could not emit, so
   it could never be chosen. Fixed: equal relations are published under their
   **bare** relation id (`3` → "related to"), which is also what legacy
   `init_relation_type_select()` sends, and which `reqFilterSql()` resolves to
   "either side of `req_relations`".
2. **Per-spec N+1 query** for the subtree map, replaced by a single resolution.
3. **Hidden Custom Field inputs stayed active** — a hidden field still submitted
   its value. Fixed so only visible inputs are read.
4. **Advanced → Simple discarded a multi-selection silently.** Now warns first
   (`rsf.selectionTrimmed`).

Also fixed while testing: `testproject::getTestCasePrefix()` was being called
statically, which is a PHP 8 fatal (`Non-static method … should not be called
statically`).

## Deliberate deviation

Filtering is evaluated on the **latest** spec revision / requirement version.
Legacy `get_filtered_req_map()` matched **any** revision, but the modern spec
and requirement tables are built from the latest revision, so matching an older
revision could return rows the table never shows. The deviation is documented in
the code at the filter call site.

## Verification

Suite 1025, **40/40 PASS**: 2 syntax/JSON gates, the 401 path, 22 BFF filter
cases across both `action=specs` and `action=reqs`, the four legacy quirks
(`Any`, prefix-only Test Case id, bare equal relation, subtree pruning), an SQL
injection probe (returns 0 rows, no error, no injection), 7 live browser
interactions (Apply, Reset, Advanced toggle, Custom Field toggle, an impossible
combination, and requirement-level filtering inside an opened spec), a clean
browser console, and an Event Viewer check with **0 new Error/Warning rows**.

Fixture used (project 1, prefix `REQA-`): specs `SPEC-A` (id 10, type 1),
`SPEC-B` (id 11, type 2), `SPEC-C` (id 12, type 3), two requirements each, one
requirement Custom Field (`Req Origin`) and one "related to" relation.

The 5 pre-existing `log_level = 1` rows in `events` are **not** from this
feature: all 5 are MariaDB `1064` from `requirement_spec_mgr::get_by_id`, fired
while the fixture spec still had an empty `latest_req_version_id`
(`RSPEC_REV.id = `). They stopped permanently once the fixture revision rows were
created, and no post-fix request produced a new event row.
