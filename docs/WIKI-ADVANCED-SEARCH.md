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
* **Requirement Specs** — `Name [rN]` (edit popup) and stripped scope;
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
fail with a DB access error. **Fixed in issue #547**: both CI workflows
(`fix-bug.yml`, `modernize.yml`) now provision the function from
`install/sql/mysql/testlink_create_udf0.sql` right after the default-data
import and assert its presence via `information_schema.routines`, so advanced
search works out of the box on every fresh import.
