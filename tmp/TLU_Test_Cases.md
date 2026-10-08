
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
