# Advanced Search — Modernized Screen

**Path:** ASIDE menu > Search > *Advanced search*
**URL:** `gui/templates/search/searchAdvancedView.html?tproject_id=<id>`
**BFF API:** `api/search/index.php` (`action=fulltext`; session-based auth, JSON I/O)
**Prerequisite:** `view_tc` grant on the current test project (same visibility
rule as legacy `getMenuVisibility()`); requirement sections appear only when
the project has *requirements enabled*.
**Replaces:** legacy `lib/search/searchMgmt.php` (form) +
`lib/search/search.php` (engine), `gui/templates/dashio/search/searchGUI.inc.tpl`
(ExtJS results grid). The BFF reuses TestLink's own `searchCommands` class, so
SQL semantics are identical to 1.9.20.

![Advanced search form](screenshot-advanced-search-view.png)

---

## What it does

The 1.9.20 "multiple entities" free-text search: **one search text** is looked
up across four node types of the **current test project only**:

| Entity | Fields searched |
|--------|-----------------|
| Test Cases | title, summary, preconditions, steps, expected results, external id |
| Test Suites | title, details |
| Requirement Specifications | title, scope |
| Requirements | title, scope, document id |

Multiple words are treated as separate terms and combined with the selected
operator (**AND** = all terms must match, **OR** = any term matches), exactly
like the legacy `and_or` select. HTML is stripped before matching through the
MySQL function `UDFStripHTMLTags` (same as legacy).

## Additional filters

| Filter | Behavior |
|--------|----------|
| Created by / Edited by | `LIKE` on author/updater login, first or last name |
| Keyword | exact keyword id (dropdown shown when the project has keywords) |
| Custom field + value | design-time custom fields for test cases AND requirements in one dropdown; date/datetime values converted like legacy, everything else `LIKE` |
| Test case status | Draft … Final domain on the latest tcversion (`tcWKFStatus`) |
| Requirement status | D/R/W/F/I/V/N/O domain on the latest requirement version (`reqStatus`, shown when requirements are enabled) |
| Creation date from/to | range on `tcversions.creation_ts` ("to" includes 23:59:59); dates sent in the project's configured date format |
| Modification date from/to | range on `tcversions.modification_ts` |

Legacy parity notes:

* at least one field checkbox must be checked (legacy re-rendered the form
  otherwise; the modern screen shows a localized warning instead);
* date filters apply to test case versions only — upstream builds a
  `dates4rq` filter for requirements but never consumes it
  (`searchCommands.class.php`), so this screen inherits the same behavior;
* an entirely empty search returns all latest test case versions server-side
  (legacy semantics kept in the API); the UI asks for at least one criterion
  before sending;
* the legacy *requirement type* filter is **not** offered: upstream never
  delivers it to the engine correctly (`lib/search/search.php:74` strips the
  `RQ` prefix from the wrong property, so the generated SQL can never match);
* requirement field checkboxes are only submitted when requirements are
  enabled on the project (legacy omitted the inputs entirely).

## Results

Results are rendered as one sectioned table per entity type, each with its
own columns:

![Advanced search results](screenshot-advanced-search-results.png)

* **Test Cases** — path, clickable `PREFIX-extid [vN] :: Name` (opens the
  read-only tcView popup), summary snippet, edit + execution-history buttons;
* **Test Suites** — name (edit popup via `archiveData.php?edit=testsuite`) and
  stripped details;
* **Requirement Specs** — `Name [rN]` (opens the specification detail screen
  `reqSpecView.html?id=<req_spec_id>`) and stripped scope;
* **Requirements** — doc id, name + version.revision tag, full path, scope.

A global match counter shows the total across entities; warnings
("empty test project", "no records found") reuse the localized strings of the
other search screens.

## i18n

All labels live under the `searchAdv.*` namespace; shared strings are reused
from the existing `search.*` keys. The 18 new keys were added to **all ten**
locale bundles (en, de, es, fr, it, ja, pt, ro, ru, zh) and every bundle was
validated with `python3 -m json.tool`.

## Testing

Test suite **28** in `tmp/TLU_Test_Cases.md` — 17/17 PASS (BFF parity,
AND/OR term logic, per-field scoping, keyword/CF/author/date filters,
validation paths, locale switching, aside link switch, Event Viewer clean).

Environment note: a fresh database import lacks the MySQL function
`UDFStripHTMLTags`, which makes **both legacy and modernized** advanced search
fail with a DB access error. **Fixed in issue #547**: `searchCommands` now
probes `information_schema.routines` and automatically falls back to plain
`LIKE` matching when the function is absent — identical semantics to upstream
option `$tlCfg->UDFStripHTMLTags=false`. Deployments that want HTML stripping
should provision it from `install/sql/mysql/testlink_create_udf0.sql`
(the sample-db restore scripts `docs/db_sample/restore_sample.sh` / `.bat`
do this automatically). Note for self-managed MySQL 8 servers with binary
logging enabled: creating routines additionally needs `SUPER` or
`log_bin_trust_function_creators=1` (MariaDB used by this project's CI has
binary logging off).
## Check / uncheck all per attribute group (issue #1095)

Each of the four attribute groups of the search form now has a **master
checkbox in its header** (left of the group title) that toggles every field of
that group in one click — the modern port of the legacy `toggle_all` icon +
`cs_all_checkbox_in_div()` from `searchGUI.inc.tpl:59-61,76-78,86-88,97-99`.


Behavior:

* clicking the master checks **all** fields of its group when at least one is
  unchecked (including the *indeterminate* state) and unchecks them all when
  every field is already checked — same alternation as the legacy icon;
* the master reflects the real group state at all times: **checked** when all
  fields are on, **indeterminate** when only some are, unchecked when none are.
  It re-syncs after every individual checkbox change and after *Reset*;
* the master never reaches the BFF: its ids are `all_tc` / `all_ts` / `all_rs`
  / `all_rq`, which do not match the `tc_` / `ts_` / `rs_` / `rq_` prefixes
  `doSearch()` collects, so the submitted search keys are unchanged;
* the tooltip comes from the new `searchAdv.checkUncheckAll` key, present in
  all ten locale bundles (`Check / uncheck all`, `Bifează / debifează tot`, ...)
  and applied through `data-i18n-title`.



No BFF change was needed — `api/search/index.php` already consumed every
field flag. Verified 14/14 PASS (test suite *Task — Issue #1095* in
`tmp/TLU_Test_Cases.md`): toggle/indeterminate/reset matrix, requirements
enabled project (all four groups), `locale=ro` reload (translations applied,
headers and masters survive `TLi18n.apply()`), search regression and a clean
Event Viewer. Pre-existing console error found while testing and filed
separately as **#1856** (out of scope, not fixed here).

## Requirement Specification result click opens the specific specification (issue #1096)

Clicking a **Requirement Specifications** result now opens the **specific
specification** at
`reqSpecView.html?id=<req_spec_id>&tproject_id=<id>` — the target legacy built
with `openLinkedReqSpecWindow()` (`lib/search/search.php:316` →
`gui/javascript/testlink_library.js:1156-1166`).

Before (issue #1096): the handler `openReqSpecEdit(rsId)` received the spec id
from the result row and then **ignored it**, always opening the generic
management grid:

```js
function openReqSpecEdit(rsId) {
  var url = '/gui/templates/requirements/reqSpecMgmt.html?' + (tprojectId ? 'tproject_id=' + tprojectId : '');
  ...
}
```

After: the handler is renamed to `openReqSpecView()` (it no longer edits
anything) and passes the id through, mirroring the sibling handlers
`openTcEdit` / `openTsEdit` / `openReqEdit`:

```js
function openReqSpecView(rsId) {
  var url = '/gui/templates/requirements/reqSpecView.html?id=' + rsId +
            (tprojectId ? '&tproject_id=' + tprojectId : '');
  window.open(url, '_blank', 'width=1024,height=768,resizable=yes,scrollbars=yes');
}
```

Notes:

* no BFF change was needed — `api/search/index.php` already returned
  `req_spec_id` for every result row (`api/search/index.php:604`);
* `reqSpecView.html` reads the id from `id` **or** `req_spec_id`
  (`reqSpecView.html:310`), so the emitted `?id=` URL is unambiguous;
* verified 11/11 PASS (test suite *Task — Issue #1096* in
  `tmp/TLU_Test_Cases.md`): both result rows open their **own** specification
  (`id=9097` renders SPEC Alpha, `id=9098` renders SPEC Beta), the Test Case /
  Test Suite links of the same screen still open `tcView.html` / `suiteView.html`,
  the i18n coverage gate passes (no new keys), and the Event Viewer gained no
  Error/Warning entries;
* two defects found while testing were filed separately instead of being
  folded into this change: **#1865** (the *simple* Search screen
  `searchMgmt.html:387` links results with `reqspec_id=`, a parameter
  `reqSpecView.html` never reads) and the pre-existing console error
  `ReferenceError: p is not defined` (**#1856**).

## Deep-link auto-submit from a URL `target` parameter (issue #1097)

When the screen URL carries a search `target` term
(`searchAdvancedView.html?tproject_id=<id>&target=<term>`), the form now
**submits itself on load** exactly like legacy
(`lib/search/searchMgmt.php:99` `forceSearch` →
`dashio/search/searchGUI.inc.tpl:257-259`): the box is prefilled with the
term and `doSearch()` runs immediately, so deep links (navBar one-box
hand-off, bookmarked searches) land straight on results — no manual Find
click needed.

Before (issue #1097) the deep link was doubly broken:

* `searchAdvancedView.html` scoped `var p = new URLSearchParams(...)` inside
  the jQuery-ready closure, but the global `loadContext()` reads it — so the
  URL-prefill block threw `ReferenceError: p is not defined` and **never ran**
  (the box stayed empty). That separate bug was filed as **#1856** and is
  closed by this same change.
* even when the prefill had worked, nothing auto-submitted: `doSearch()`
  (`:368`) was only reachable through the Find button, unlike the sibling
  `searchMgmt.html` which already runs its navBar hand-off (`:261-266`).

The fix, `gui/templates/search/searchAdvancedView.html`:

1. `var p = null;` is now declared at **global** scope (the same pattern as
   `searchMgmt.html:154`); the ready-closure only reassigns it — prefill runs.
2. right after the URL-prefill block, the legacy forceSearch condition is
   mirrored verbatim: `if ($.trim(p.get('target') || '') !== '') { doSearch(); }`
   — a non-empty `target` (after trim) triggers the search automatically.

Notes:

* no BFF change was needed — `api/search/index.php?action=fulltext` already
  accepts `target` as a plain GET parameter;
* a deep link **without** `target` keeps the current bare-form behaviour
  (0 `action=fulltext` requests — exactly legacy, where `forceSearch` was
  false);
* verified 10/10 PASS (test suite *Task — Issue #1097* in
  `tmp/TLU_Test_Cases.md`): deep link auto-runs with the term prefilled, the
  fulltext request fires with no click, manual Find/Reset still work, the
  sibling `searchMgmt.html` navBar hand-off still auto-runs, console has zero
  errors (the `ReferenceError` is gone), i18n coverage gate passes (no new
  keys), Event Viewer gained no Error/Warning rows.
