# Search Test Cases (Quick Search) — Modernized Screen

**Path:** ASIDE menu > Search > *Quick search* / *Search Test Cases*
**URL:** `gui/templates/search/searchView.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/search/index.php` (session-based auth, JSON I/O)
**Prerequisite:** `view_tc` grant on the current test project (same visibility
rule as legacy `getMenuVisibility()`); the Search section is hidden otherwise.
**Replaces:** legacy quick-search form `lib/search/searchForm.php` +
`gui/templates/dashio/search/searchForm.tpl`, results from
`lib/testcases/tcSearch.php` (ExtJS grid).


---

## What it does

Searches **test cases in the CURRENT test project only**, combining all filled
criteria with a logical **AND** (`LIKE '%value%'` semantics on text fields) —
exactly like TestLink 1.9.20's quick search.

Notices shown above the form:

* all criteria are combined with AND;
* search is restricted to the selected test project;
* the test project prefix typed in the *Test Case ID* field is ignored
  (a bare number like `3` is auto-completed to `SFP-3`).

## Search criteria

| Criterion | Behavior |
|-----------|----------|
| Test Case ID | full external id (`SFP-2`) or bare number (`2`); unknown id → warning "Test case does not exist." |
| Version | exact match on tcversion.version |
| Title | `LIKE` on test case name |
| Created by / Edited by | `LIKE` on author/updater login, first name or last name |
| Summary / Preconditions | `LIKE` |
| Steps / Expected results | `LIKE` across tcsteps rows |
| Creation date from/to | range on `tcversions.creation_ts` (to-date includes 23:59:59); dates sent in the project's configured format |
| Modification date from/to | range on `tcversions.modification_ts` |
| Importance | High/Medium/Low — shown only when the project has *test priority enabled* |
| Status | Draft … Final domain (localized server-side, same source as legacy) |
| Keyword | exact keyword id — shown only when the project has keywords |
| Custom field + value | design-time custom fields linked to test cases; date/datetime values converted like legacy, everything else `LIKE` |
| Requirement document ID | `LIKE` on `req_doc_id` via req_coverage — shown only when requirements are enabled |

Result cap: if more than `testcase_cfg.search.max_qty_for_display` (200)
distinct test cases match, the search refuses to display and warns
"Too many results - please refine the search criteria." (legacy behavior).

## Results


DataTable with columns:

* **Test Suite** — full path of the parent suite;
* **Test Case** — clickable `PREFIX-extid [vN] :: Name`; opens the test case
  editor popup (`archiveData.php?edit=testcase&id=…&tcversion_id=…`);
* **Summary** — HTML stripped to plain text;
* **Version**;
* actions: edit popup + execution history popup
  (`execHistory.php?tcase_id=…`).

A match counter is displayed next to the results heading and in the footer.
When nothing matches, an empty-state message is shown instead of the table.

## Errors & warnings


| Situation | Message |
|-----------|---------|
| TC ID given but not found | "Test case does not exist." |
| Project has no test cases | "Test project is empty (no test cases)." |
| No rows match | "No test cases match the given criteria." |
| > 200 matches | "Too many results - please refine the search criteria." |

## Permissions

* Menu entry visible with `view_tc` (mapped to right `mgt_view_tc`) — admins,
  guests, testers, designers etc.; hidden for `<no rights>` users.
* BFF enforces the same rule: HTTP 403 `No permission` without it, HTTP 401
  when not authenticated.

## i18n

All labels/titles/placeholders/messages use the client-side `TLi18n` module
with keys under the `search.*` namespace, present in **all** locale bundles:
en, ro, de, fr, es, it, pt, ru, ja, zh. A locale switcher is available in the
header.

## Technical notes

* BFF endpoints:
  * `GET api/search/index.php?action=context&tproject_id=N` — prefix, keywords,
    custom fields, status domain, feature flags, max result count;
  * `GET api/search/index.php?action=search&tproject_id=N&…` — runs the search,
    returns `{count, rows[], warning}` where each row carries testcase_id,
    tcversion_id, full external id, version, summary and suite path.
* SQL mirrors legacy `tcSearch.php`: nodes_hierarchy join tcversions (+ LEFT
  JOIN steps), optional joins for keywords / custom field values /
  requirement coverage / author & updater users; `COUNT(DISTINCT NH_TC.id)`
  first, then the row fetch capped at the display limit.
* All user input is escaped through `$db->prepare_string()` before being placed
  into LIKE clauses (same hardening as the 1.9.20 security fixes).

---

# Quick Search — Separate Screen (one file per screen)

**URL:** `gui/templates/search/searchQuickView.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** same `api/search/index.php` (`action=tcQuickSearch`)
**Replaces:** the shared-screen approach where both Search items pointed to
`searchView.html`.

## Why a separate file
The ASIDE highlight logic (`syncAsideActiveLink()` in `main.tpl`) matches menu
links by pathname. While both Search items shared one screen, BOTH were
highlighted at the same time. Each screen now has its own file, so exactly one
item is active — consistent with every other ASIDE section.

## What it does
A focused mini-form for fast lookups inside the current test project:

* single input field accepting either a **title fragment** (`LIKE %value%`) or
  a **test case ID** (full external ID like `P2-1`, or bare number
  auto-completed with the project prefix);
* results table (external ID, version, title, path) rendered as a DataTable;
* clicking a result opens the **modernized TC viewer**
  (`gui/templates/testcases/tcView.html`), not the legacy popup;
* empty input → toast error; no matches → inline "no results" message;
* self-contained page: own CSS, TLi18n localization, Dashio header/toolbar.

## Routing
`lib/functions/common.php` builds both URIs:

```
$actions->tcSearch      = "/gui/templates/search/searchView.html?{$ctx}";
$actions->tcQuickSearch = "/gui/templates/search/searchQuickView.html?{$ctx}";
```

## i18n keys
`search.quickCaption`, `search.quickField`, `search.quickPlaceholder`,
`search.quickHint`, `search.quickEmpty` — present in all locale bundles.
