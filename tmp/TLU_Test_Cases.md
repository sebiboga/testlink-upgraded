
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
```
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

### Test Case 1879.6: Edge inputs (array value, bare key, explicit 0) stay silent
- Steps: GET `?type[]=x`, `?type` (key with no `=`), `?type=0` after the fix.
- Expected: each `200` with a sane body, **0** new `events` rows (`intval()` on an array returns 1/0 silently on PHP 8.3; the key exists, so no `Undefined array key` either).
- Actual: `type[]=x` → `LOCALIZE: reqmgrsystem_interface_not_implemented` (array → 1), `type` and `type=0` → `LOCALIZE: reqmgrsystem_invalid_type`, all `[200]`; `SELECT ... WHERE id > <baseline>` → **no rows**. **PASS**

## Modernize — Issue #1880: User Create/Edit standalone screen (usersEdit)

Precondition: fresh DB, admin session cookie via `POST /api/auth/login {"login":"admin","password":"admin"}` (Origin `http://localhost:8082`), screen `gui/templates/usermanagement/usersEdit.html`, BFF `api/usersedit/index.php`.

### Test Case 1880.1: init create-mode returns the full form schema
- Steps: `GET ?action=init&mode=create`.
- Expected: 200 with roles, locales, auth domains, `mode:create`, rights, `noExpDateUsers`, `apiEnabled`, `externalPasswordMgmt`.
- Actual: 200, role default rendered client-side as `guest`, security card hidden in create mode. **PASS**

### Test Case 1880.2: init edit-mode returns the user, never a password
- Steps: `GET ?action=init&mode=edit&user_id=1` (admin, who is in `noExpDateUsers`).
- Expected: 200 with login/firstName/lastName/email/globalRoleID/locale/active + `noExpDate` flag; no password material in the payload.
- Actual: 200; expiration field rendered as admin-excluded. **PASS**

### Test Case 1880.3: HEAD is served (HEAD→GET mapping)
- Steps: `HEAD ?action=init&mode=create`.
- Expected: 200, no body.
- Actual: 200. **PASS**

### Test Case 1880.4: anonymous call is rejected before any DB work
- Steps: no cookie, `GET ?action=init&mode=create`.
- Expected: 401 `NOT_AUTHENTICATED`.
- Actual: `{"status":"error","message":"Not authenticated","error_code":"NOT_AUTHENTICATED"} [401]`. **PASS**

### Test Case 1880.5: rights matrix — a real no-rights login gets 403 on READ
- Steps: create `uedemo_no1880` (role 3 → global guest, id 7), log it in, `GET ?action=init&mode=edit&user_id=1`.
- Expected: 403 `NO_RIGHT` naming `mgt_users`, no user data leaked.
- Actual: `{"error_code":"NO_RIGHT","right":"mgt_users"} [403]`. **PASS**

### Test Case 1880.6: rights matrix — same user is refused on WRITE too
- Steps: same session, `POST ?action=create` (JSON body + Origin).
- Expected: 403 `NO_RIGHT` before any insert (DB row count unchanged).
- Actual: 403 `NO_RIGHT`; no `x1880` row created. **PASS**

### Test Case 1880.7: CSRF same-origin proof is enforced on writes
- Steps: admin session, `POST ?action=create` **without** `Origin`/`X-Requested-With`.
- Expected: 403 "missing or mismatched same-origin proof", no write.
- Actual: 403 CSRF message, no row. **PASS**

### Test Case 1880.8: create persists every field and strips legacy-illegal name characters
- Steps: `POST ?action=create` with `firstName:"A/B:C"`, valid email/role/locale/expiration.
- Expected: 200, `feedback_key:user_created`, DB shows `ABC` (legacy `/ \ : * ? < > |` blacklist), expiration stored as date.
- Actual: id returned; DB `first_name`-equivalent = `ABC`. **PASS**

### Test Case 1880.9: update persists and reports honestly
- Steps: `POST ?action=update&user_id=N` renaming firstName to `Browser2`, then re-`init`.
- Expected: 200 and value survives reload.
- Actual: 200, reload shows `Browser2`. **PASS**

### Test Case 1880.10: expiration date is validated BEFORE the write
- Steps: `POST ?action=update` with `expirationDate:"2026-99-99"`; then `"2026-11-31"`; then a valid date.
- Expected: 400 `invalid_expiration_date` for both impossible dates, DB unchanged; 200 for the valid one.
- Actual: `400` both, `200` + persisted for `2026-11-15`. **PASS**

### Test Case 1880.11: expiration date rejects a non-string (array parameter)
- Steps: `POST ?action=update` with `expirationDate:["x"]` (JSON array).
- Expected: 400, no fatal, no write.
- Actual: `400 invalid_expiration_date`, PHP error-free. **PASS**

### Test Case 1880.12: clearing the expiration date
- Steps: `POST ?action=update` with `expirationDate:""` on a dated user.
- Expected: 200 and the column is cleared (NULL/empty), not the string `""` echoed back as a date.
- Actual: cleared, `init` renders an empty picker. **PASS**

### Test Case 1880.13: self-update keeps the session consistent (legacy `setUserSession` parity)
- Steps: admin updates their own first name; the page stays logged in and `/api/auth/check` reports the new name.
- Expected: 200, session refreshed, no forced logout (unless the legacy path would).
- Actual: 200, session intact, no `self_logout`. **PASS**

### Test Case 1880.14: reset_password honours the SMTP-host guard (legacy parity)
- Steps: browser Reset password on a local-auth user (`$g_smtp_host = '[smtp_host_not_configured]'`).
- Expected: 400 `INVALID_SMTP_HOSTNAME`, localized flash, no password change.
- Actual: localized error flash on screen, user password unchanged in DB. **PASS**

### Test Case 1880.15: reset_password refuses externally-managed users
- Steps: `POST ?action=reset_password` for a user whose authentication domain is external.
- Expected: 400, no reset attempted.
- Actual: 400 `AUTH_METHOD`-class refusal. **PASS**

### Test Case 1880.16: gen_apikey honours api->enabled and the SMTP guard
- Steps: browser Generate API key (confirm modal), then again with api disabled.
- Expected: 400/403 per config, no key stored while SMTP is unconfigured.
- Actual: localized SMTP error flash; `api_keys` unchanged. **PASS**

### Test Case 1880.17: unknown action and wrong verb are stable machine codes
- Steps: `GET ?action=bogus` (admin); `POST ?action=init` (admin).
- Expected: 400 `UNKNOWN_ACTION`; 405 with `Allow: GET, HEAD`.
- Actual: `{"error_code":"UNKNOWN_ACTION"} [400]`; `HTTP/1.1 405` + `Allow: GET, HEAD`. **PASS**

### Test Case 1880.18: legacy `lib/usermanagement/usersEdit.php` is a non-mutating 302 shim
- Steps: GET the legacy URL in edit mode (`?operation=edit&user_id=1`) and create mode; POST it; GET it anonymously.
- Expected: 302 → `usersEdit.html?mode=edit&user_id=1` (context `tproject_id`/`tplan_id` preserved); create → `?mode=create`; POST → 405; anonymous → `login.php?note=expired`.
- Actual: all four as expected. **PASS**

### Test Case 1880.19: usersView wiring — "Open editor" entry points
- Steps: open `usersView.html` as admin; check toolbar button and the per-row external-link icon; click both; click them in demo mode.
- Expected: button + icon present and demo-gated by `applyDemoMode()`; create-URL has no `user_id`, row-URL carries the row id + session `tproject_id`/`tplan_id`.
- Actual: toolbar opens create mode, row icon opens edit mode of that user. **PASS**

### Test Case 1880.20: browser — create mode renders fully localized, no raw keys
- Steps: open `usersEdit.html?mode=create` as admin; switch locale EN→RO→EN.
- Expected: every label/title/placeholder/message from `TLi18n` (0 raw `ued.*` keys), entities decoded (`Français`, not `Fran&ccedil;ais`), role default `guest`, password hint visible, security card hidden.
- Actual: clean render both locales, 0 console errors. **PASS**

### Test Case 1880.21: browser — create → edit flip keeps context and success message
- Steps: submit a valid create.
- Expected: URL flips to `?mode=edit&user_id=N` **without** losing the success flash, login becomes readonly, security card + Event-history button appear.
- Actual: URL flipped, `uedemo2` created, success message retained after `load()`. **PASS**

### Test Case 1880.22: browser — dialogs, states, session loss
- Steps: open Reset-password confirm and API-key confirm; open the screen with a bogus `user_id`; expire the session and interact.
- Expected: confirm modals localized; 404 state card with the machine code; 401 redirects to `login.php?note=expired`.
- Actual: states render; SMTP errors surface as flashes. **PASS**

### Test Case 1880.23: i18n coverage gate
- Steps: 65 new keys (`ued.*` + `user.openEditor`) + `footers.usersEdit` written into **all 10** bundles; `bash ai/verify_i18n_coverage.sh`.
- Expected: PASS, 0 missing keys, no pre-existing key lost (diff vs HEAD shows additions/reorder only).
- Actual: PASS (7030 keys × 9 non-en bundles). `python3 -m json.tool` clean on all 10. **PASS**

### Test Case 1880.24: Event Viewer clean + code review (rule 16)
- Steps: full run above; `SELECT log_level,COUNT(*) FROM events WHERE fired_at > UNIX_TIMESTAMP()-1800`; mandatory subagent code review of HTML/JS/CSS + BFF.
- Expected: only `log_level 16` audit rows (`User '…' updated`), 0 Error/Warning; review finds no blockers.
- Actual: audit rows only; review = 0 blockers, minors #1/#2/#3/#5 fixed in code (unmapped `CREATE_FAILED`/`UPDATE_FAILED` now shown from `r.message`, expiration validated pre-write, name blacklist, HEAD→GET). Remaining accepted minors: #4 (create-mode `externalPasswordMgmt` default), #6 (shim 405 body), #8 (i18n re-sort churn). **PASS**
