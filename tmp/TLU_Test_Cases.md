
## Suite 1878: Modernize Admin top-menu User Management entry (usersView)

### Test Case 1878.1: Top-menu Admin lands on modern usersView.html
- Given logged in as admin (session has testproject 1/tplan 2)
- When clicking the Admin top-menu item (cfg/const.inc.php guiTopMenu[6]) — the URL should be gui/templates/usermanagement/usersView.html
- Then the modern User Management screen loads (title “User Management”, toolbar “+ Create User”, “Export”, tabs for User/Role/Assign)
- Pass/Fail: page header contains “User Management”; network shows no redirect loop; console clean (no ERROR/WARN attributable to navigation).

### Test Case 1878.2: Legacy lib/usermanagement/usersView.php → 302 to modern
- Given logged in (session)
- When GET http://localhost:8082/lib/usermanagement/usersView.php
- Then HTTP 302 (or 200 with JS top.location redirect? legacy uses testlinkInitPage; authenticated: we saw 302). Expect Location header to gui/templates/usermanagement/usersView.html with tproject_id/tplan_id from session; landing page is the modern screen.
- Pass/Fail: Location contains “usermanagement/usersView.html”.

### Test Case 1878.3: Anonymous legacy URL bounces to login
- Given not logged in
- When GET lib/usermanagement/usersView.php
- Then redirect to login.php?note=expired&destination=%2Flib%2Fusermanagement%2FusersView.php (same contract as eventviewer shim)
- Pass/Fail: login page shown; note=expired present.

### Test Case 1878.4: Forward legacy context (operation/user_id)
- Given logged in
- When GET lib/usermanagement/usersView.php?operation=show&user_id=1
- Then modern screen URL includes operation=show&user_id=1 (forwarded by shim)
- Pass/Fail: parameters preserved.

### Test Case 1878.5: Cross-check legacy templates not used
- Verify no active code references lib/usermanagement/usersView.php from modern screens (except allowed comments); cfg/const.inc.php points to .html.
- Pass/Fail: grep shows top-menu points to .html.

### Test Case 1878.6: Event Viewer clean after navigation
- After 1878.1–1878.4, check Event Viewer (events_mgt) for new ERROR/WARNING rows.
- Pass/Fail: 0 new ERROR/WARNING attributable to this change.



## Task — Issue #1102: quick-search results group-by-test-suite + ExtTable toolbar

**Precondition** (fresh DB): `php tmp/fixtures_1101.php` creates test project `QSDemo` (id 1, prefix `QS`, suites Alpha / A1 / Beta, 5 test cases) and `php tmp/fixtures_1102.php` renames them so the quick free text `queen` matches one case per suite and nests `A1` under `Alpha` → paths `Alpha` (QS-1, QS-2), `Alpha/A1` (QS-3), `Beta` (QS-4, QS-5). Logged in as admin at `http://localhost:8082`, screen `gui/templates/search/searchQuickView.html?tproject_id=1`.

### Test Case 1102.1: Results are grouped per test suite with item counts
- Steps: type `queen` in the quick field, click **Find**.
- Expected: rows grouped under headers `Alpha (2 Items)`, `Alpha/A1 (1 Item)`, `Beta (2 Items)` (legacy `groupTextTpl '{text} (N Items)'`), Test Suite column hidden (`hideGroupedColumn=true`), default order suite-path ASC then Test Case DESC (legacy `tcSearch.php:342-343`).
- Actual: 3 `tr.dtrg-group` headers with exactly those labels, 5 data rows, `resTbl.order() = [[0,"asc"],[1,"desc"]]`, `column(0).visible() = false`. **PASS**

### Test Case 1102.2: Single group expand/collapse by clicking its header
- Steps: click the `Alpha` group header, then click it again.
- Expected: first click hides the 2 `Alpha` rows (chevron right), second click shows them again (chevron down).
- Actual: `vis 5 → 3 (icons RDD) → 5 (icons DDD)`. **PASS** (initially failed: the toggle set `collapsedGroups[key]=false` while `isGroupCollapsed` is presence-based — fixed by deleting the key, same as `searchView.html:273`).

### Test Case 1102.3: Toolbar Expand/Collapse Groups (cross-group)
- Steps: click **Expand/Collapse Groups** twice.
- Expected: 1st click collapses every group (0 data rows visible, toolbar info `Groups collapsed`, button active); 2nd expands all (`Groups expanded`, button inactive).
- Actual: `vis 0 / RRR / "Groups collapsed" / active` then `vis 5 / DDD / "Groups expanded" / tbtn`. **PASS**

### Test Case 1102.4: Toolbar Show all Columns / Hide Test Suite column
- Steps: click **Show all Columns**, then click again.
- Expected: suite-path column becomes visible and header + cells unhide; second click re-hides it and the label returns to `Show all Columns`.
- Actual: `column(0).visible() true → false`, button label `Hide Test Suite column → Show all Columns`. **PASS**

### Test Case 1102.5: Toolbar Reset Filters clears the result grid filter
- Steps: type `QS-4` into the result table filter box (1 row shown), click **Reset Filters**.
- Expected: filter input and DataTables search cleared, all 5 rows visible again, info `Filters cleared` (legacy `grid.filters.clearFilters()`).
- Actual: `resTbl.search()` `QS-4` → `""`, `vis 1 → 5`, info `Filters cleared`. **PASS**

### Test Case 1102.6: Sort keeps the group column primary (legacy allowMultiSort=false)
- Steps: sort by the Test Case column (`resTbl.order([[1,'asc']])`).
- Expected: the suite-path column stays the primary criterion so groups do not fragment; multi-sort disabled.
- Actual: `order = [[0,"asc"],[1,"asc"]]` (pinGroupOrder re-applied the group key). **PASS**

### Test Case 1102.7: Plain-table fallback (DataTables RowGroup unavailable) keeps grouping
- Steps: neutralize `$.fn.dataTable.RowGroup`, re-run the search, click a group header and **Expand/Collapse Groups**.
- Expected: rows still render as plain `<tbody>` with `tr.dtrg-group` headers + counts; header click and toolbar toggle still hide/show the group rows.
- Actual: `resTbl === null`, 3 group headers with counts, 5 data rows; header click hid the first data row + chevron-right; toolbar expand restored `vis 5`, info `Groups expanded`. **PASS**

### Test Case 1102.8: Empty result + Reset form + no new events
- Steps: search `zzzznomatch`, then search `queen` again, then click **Reset**; finally check the `events` table.
- Expected: 0 matches → `#noResults` shown, `#gridToolbar` and table hidden; re-search recovers grouping; Reset hides toolbar/table and clears criteria; no new ERROR/WARNING events.
- Actual: `noResults=block, toolbar=none, wrap=none, count=(0 matches)`; recovery `groups=3, toolbar=flex, rows=5`; after Reset `toolbar=none, wrap=none`; `select log_level,count(*) from events` → `16 | 2` only (audit logins), no level 1 (ERROR) / 2 (WARNING); browser console: only the pre-existing "form field should have id or name" notice from the DataTables filter input (present before the change). **PASS**

Measured evidence (browser evaluation on `searchQuickView.html?tproject_id=1` after searching `queen`, Test Case 1102.1):

```json
{ "rows": 5, "groupRows": 3,
  "groups": ["Alpha(2 Items)", "Alpha/A1(1 Item)", "Beta(2 Items)"],
  "order": "[[0,\"asc\"],[1,\"desc\"]]", "col0visible": false,
  "toolbarButtons": ["Expand/Collapse Groups", "Reset Filters", "Show all Columns"] }
## Regression — Issue #1716: reqmgrsystem cfg-template endpoint returns LOCALIZE marker + logs localization events

**Precondition** — TestLink 2.0.1 at http://localhost:8082, DB `testlink` freshly imported, user `admin`/`admin` (locale `en_GB`), cookie jar from `POST login.php`. Baseline (pre-fix) captured on branch `fix/issue-1716` before any edit: `events` rows 2 and 3 (`log_level 32`, `string 'reqmgrsystem_*' is not localized for locale 'en_GB'`) and payload `{"sucess":true,"cfg":"LOCALIZE: <key>"}` for both keys.

**TC-1716-01 — type=1 (interface-not-implemented branch, `getreqmgrsystemcfgtemplate.php:41`)**
- Given a logged-in session
- When GET `lib/ajax/getreqmgrsystemcfgtemplate.php?type=1`
- Then `cfg` is a real sentence containing the implementation name, NOT `LOCALIZE:`
- Expected post-fix: `{"sucess":true,"cfg":"Configuration example not available: the 'contoursoapInterface' interface is not implemented"}`
- Actual: **PASS** (measured, pre-fix value was `LOCALIZE: reqmgrsystem_interface_not_implemented`)

**TC-1716-02 — unknown type (invalid-type branch, `:46`)**
- Given a logged-in session
- When GET `...getreqmgrsystemcfgtemplate.php?type=99` and `?type=42`
- Then `cfg` is `Invalid requirement management system type: <n>` with the number interpolated, no `LOCALIZE:`
- Actual: **PASS** (`...type: 99` / `...type: 42`)

**TC-1716-03 — bare URL (no `type` param)**
- Given a logged-in session
- When GET `...getreqmgrsystemcfgtemplate.php` (no query string)
- Then still `{"sucess":true,"cfg":"Invalid requirement management system type: 0"}` and no `LOCALIZATION` row
- Actual: **PASS** for the localization requirement; NOTE a separate pre-existing `E_WARNING Undefined array key "type"` at line 23 is logged (log_level 2) — filed separately as **#1879**, out of scope here

**TC-1716-04 — non-English locales render native text**
- Given `users.locale='it_IT'`, fresh login, GET `?type=1` and `?type=42`
- Then `Esempio di configurazione non disponibile: l'interfaccia 'contoursoapInterface' non è implementata` / `Tipo di sistema di gestione dei requisiti non valido: 42`
- Given `users.locale='ru_RU'`, fresh login, same two requests
- Then `Пример конфигурации недоступен: интерфейс 'contoursoapInterface' не реализован` / `Недопустимый тип системы управления требованиями: 42`
- Actual: **PASS** (locale restored to `en_GB` afterwards)

**TC-1716-05 — Event Viewer stays clean**
- Given the requests of TC-1716-01..04 across 4 fresh sessions
- When `SELECT id,log_level,description FROM events ORDER BY id DESC`
- Then no NEW `log_level=32` (`LOCALIZATION`) row appears (pre-fix rows 2/3 are historical; `lang_api.php:133` de-dupes per session, and after the fix the missing-key branch is never entered)
- Actual: **PASS** — events after id 3 are only `log_level 16` (login audits) plus the #1879 `log_level 2` warning from TC-1716-03

**TC-1716-06 — all 19 bundles still load as PHP**
- When `php -l locale/<each>/strings.txt`
- Then `No syntax errors detected` for all 19 (files are `require`d by `lang_load()`, `lang_api.php:244`), same as on the HEAD copies
- Actual: **PASS** (19/19); diff is exactly `+2` lines per file, no other bytes touched

**TC-1716-07 — UI regression: modern Req Mgmt System editor**
- Given logged in, open `gui/templates/reqmgrsystems/reqMgrSystemView.html`, click `+ Create`, click `show configuration example`
- Then the card shows `Interface contoursoapInterface not implemented` (BFF path `api/reqmgrsystems?action=cfg_template`, i18n key `rms.connNotImplemented`) — no `LOCALIZE:` anywhere, no console ERROR (only pre-existing favicon 404 + quirks-mode note)
- Actual: **PASS**

**TC-1716-08 — i18n JSON gate unaffected**
- When `bash ai/verify_i18n_coverage.sh`
- Then PASS 9/9 bundles (the fix only touches legacy `locale/*/strings.txt`, not `gui/templates/i18n/*.json`)
- Actual: **PASS**

**Measured post-fix evidence (this run)**
```text
?type=1  -> {"sucess":true,"cfg":"Configuration example not available: the 'contoursoapInterface' interface is not implemented"}
?type=99 -> {"sucess":true,"cfg":"Invalid requirement management system type: 99"}
?type=42 (it_IT) -> {"sucess":true,"cfg":"Tipo di sistema di gestione dei requisiti non valido: 42"}
events after fix: only log_level 16 rows (login audits) + log_level 2 row of #1879; 0 new log_level 32
php -l: 19/19 locale bundles "No syntax errors detected"; git diff --stat: 19 files, +2 lines each
ai/verify_i18n_coverage.sh: PASS 9/9 bundles
```

## Regression — Issue #1879: bare URL to getreqmgrsystemcfgtemplate.php must not raise E_WARNING

### Test Case 1879.1: Pre-fix baseline — bare request writes an Event Viewer row
- Precondition: fresh DB (this run), logged in as `admin`/`admin`, session cookie in `/tmp/opencode/c.txt`, `SELECT COALESCE(MAX(id),0) FROM events` = **3**.
- Steps (pre-fix): `curl -s -b c.txt "http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php"` (no `type` param), then `SELECT id,log_level,description FROM events WHERE id > 3;`.
- Expected (pre-fix, i.e. reproducing the bug): HTTP 200 with the sane "invalid type" body **plus** one `log_level 2` row `E_WARNING\nUndefined array key "type" - in .../getreqmgrsystemcfgtemplate.php - Line 23`.
- Actual: body `{"sucess":true,"cfg":"LOCALIZE: reqmgrsystem_invalid_type"}` `[200]`; events gained `id=4 log_level=2 E_WARNING ... Line 23`. **PASS** (bug reproduced)

### Test Case 1879.2: Post-fix — bare request answers identically, writes NO event
- Precondition: fix applied (`$type = intval($_REQUEST['type'] ?? 0);` at `:23`), `php -l` clean, events baseline `max(id)=5`.
- Steps: repeat the bare `curl` with no `type`.
- Expected: HTTP 200, response body byte-identical to pre-fix, **0** new `events` rows.
- Actual: `{"sucess":true,"cfg":"LOCALIZE: reqmgrsystem_invalid_type"}` `[200]`; `SELECT ... WHERE id > 5` → **no rows**. **PASS**

### Test Case 1879.3: Parameter variants stay silent and byte-identical
- Steps: GET `?type=`, `?type=99`, `?type=1`, `?type=abc` (same session, same baseline).
- Expected: each `200` with the same body it produced pre-fix; **0** new `E_WARNING` rows.
- Actual: `?type=`/`?type=99`/`?type=abc` → `LOCALIZE: reqmgrsystem_invalid_type`; `?type=1` → `LOCALIZE: reqmgrsystem_interface_not_implemented`; all `[200]`; 0 new rows (a `log_level 32` "not localized for locale 'en_GB'" row appears for `?type=1` — that is **#1716**, open and out of scope). **PASS**

### Test Case 1879.4: Regression — modern Req. Management System editor + config example
- Precondition: fixture row `INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Contour Demo',1,'{}');` (id=1).
- Steps: log in at `http://localhost:8082/index.php`, open `gui/templates/reqmgrsystems/reqMgrSystemEdit.html?id=1` context (create mode used here), click **show configuration example**.
- Expected: the BFF (`api/reqmgrsystemedit/index.php?action=cfg_template&type=1`) renders the documented #1625/#1626 degradation (`Interface contoursoapInterface not implemented`), no JS error, no new Error/Warning event.
- Actual: `#cfgExample` = `Interface contoursoapInterface not implemented`; console clean (only the pre-existing "No label associated with a form field" a11y lint); `events` gained only my own `audit_login_succeeded` (log_level 16) row. **PASS**

### Test Case 1879.5: Event Viewer clean after the whole run
- Steps: `mysql ... -e "SELECT id,log_level,description FROM events ORDER BY id DESC LIMIT 5;"` after all of the above.
- Expected: no `log_level` 1 (ERROR) or 2 (WARNING) rows newer than the pre-fix baseline id=4.
- Actual: newest rows are `6 (16, audit_login_succeeded)`; the only `log_level 2` row in the table is the pre-fix `id=4` created in 1879.1. **PASS**
