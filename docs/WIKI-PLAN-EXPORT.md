# Test Plan Export — Modernized Screen

Modernization of the legacy "export test plan" screen (`lib/plan/planExport.php`,
`gui/templates/dashio/plan/planExport.tpl`) — GitHub issue
[#754](https://github.com/sebiboga/testlink-upgraded/issues/754).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/plans/planExport.html`) backed by a plain-PHP REST BFF API
(`api/planexport/index.php`). The Test Plan Management toolbar **Export Test
Plan Links** button (`exportAction`) now opens the modern screen instead of the
legacy `planExport.php` redirect — one of the six HIGH-priority legacy redirects
tracked in issue #754.

**Path:** Plans → Test Plan Management → row **Export** icon
**URL:** `gui/templates/plans/planExport.html?tproject_id=..&tplan_id=..`
**BFF API:** `GET ?action=info` + `POST ?action=export` on `/api/planexport/`
**Rights:** any authenticated session that can read the plan (parity with
legacy — `planExport.php` performs no explicit right check beyond the session;
the info endpoint also reports `mgt_testplan_create` for the caller).
**Tracking issue:** [#754](https://github.com/sebiboga/testlink-upgraded/issues/754)

---

## Behavioral parity with the legacy controller

The BFF reproduces `lib/plan/planExport.php`:

- **Export content modes** (whitelisted exactly like the legacy
  `init_args()` → falls back to `linkedItems` for anything else):
  - `linkedItems` → `testplan::exportLinkedItemsToXML()` — linked test cases
    (minimal) and platforms.
  - `tree` → `testplan::exportTestPlanDataToXML()` — complete plan contents
    (test suite tree, test case summaries, preconditions, importance, etc.).
  - `4results` → `testplan::exportForResultsToXML()` — a format usable to
    import execution results.
- **Default filename** mirrors legacy `initializeGui()`:
  `<exportContent>_<tplan name>[+_<platform>][+_<build>].xml` (spaces → `_`).
- **File type** is always XML (`exportTypes` = `{XML: XML}`).

## Screen layout

| Section | Description |
|---------|-------------|
| **Header** | "Export Test Plan" + plan name |
| **Toolbar** | Test Project / Test Plan context + locale switcher |
| **Form card** | File name (pre-filled with the default), File type (XML), Export content dropdown (Linked items / Complete plan contents / For results import) + a hint line |

The **File type** row also carries a **"View file formats documentation"** link
(`/docs/tl-file-formats.pdf`, opens in a new tab) — parity with the legacy
`planExport.tpl:65` link `{$labels.view_file_format_doc}` beside the File type
select (legacy constant `PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT`,
`cfg/const.inc.php:917`). Label i18n key `pex.viewFileFormatDoc` in all ten
bundles; tracked in [#1386](https://github.com/sebiboga/testlink-upgraded/issues/1386).
| **Actions** | **Export** (streams the XML download) and **Cancel** (back/close) |

## Flow

1. On load the page calls `GET ?action=info` with `tplan_id`, `tproject_id`
   (and optional `platform_id`/`build_id`, `form_token`, `exportContent`). The
   BFF resolves the plan, builds the default filename and the export-content
   dropdown, and returns the context (also validates the plan exists → 404).
2. The user optionally changes the export content mode and/or file name, then
   clicks **Export**.
3. The front-end `POST ?action=export` with `X-Requested-With: XMLHttpRequest`
   and `responseType: blob`. The BFF generates the XML via the legacy
   `testplan` export methods and streams it as
   `application/xml` + `Content-Disposition: attachment` (filename
   CR/LF/quote-sanitized to prevent header injection), `Pragma: public`,
   `Cache-Control: must-revalidate`.
4. The browser saves the XML file (blob download). JSON error payloads are
   surfaced as a toast instead of being downloaded.

## Execution-window scoping of the 4results export (form_token)

Legacy parity with `lib/plan/planExport.php:142-151`: when the execution window
launches the export (`execNavigator.tpl` → `openExportTestPlan(...,'4results',
form_token)` / `inc_filter_panel.tpl:204`), it appends `&form_token=<tree token>`.
The BFF now honors that token:

- `POST ?action=export` with `form_token>0` reads
  `$_SESSION['execution_mode'][token]['testcases_to_show']` (the test cases
  currently shown in the execution tree) and passes it as the `tcaseSet` filter
  to `testplan::exportForResultsToXML()` → `getLinkedStaticView()` restricts
  the XML to `NH_TCASE.id IN (...)`. Only the `4results` mode is scoped;
  `linkedItems`/`tree` are unaffected.
- `GET ?action=info` additionally reports `form_token` + `scopedCount`, and the
  page keeps a hidden `<input name="form_token">` (parity with
  `planExport.tpl:48`) plus a green hint
  ("Export limited to the N test case(s) currently shown in the execution
  window", key `pex.scopedNote`, shown only for the 4results mode).
- Safety: the token is `intval`-cast before session-array indexing (no array
  injection); the scope set is read server-side from the session, never from the
  request. Without a token (plain Test Plan Management → Export) behaviour is
  unchanged: 4results exports every linked test case.
- Tracked in [#1385](https://github.com/sebiboga/testlink-upgraded/issues/1385).

## Error handling

- Missing/invalid `tplan_id` → 400 "Invalid test plan id".
- Unknown plan → 404 "Test plan not found".
- Unauthenticated → 401 "Not authenticated".
- Empty file name → client-side toast "File name is required." (no request).
- Export failures returned as JSON are read from the blob and shown as a toast.

## Security

- **Header injection:** outgoing `Content-Disposition` filename strips `CR`,
  `LF` and `"` (`basename()` + `str_replace`), matching the `tcExport` BFF.
- **XSS:** all DOM-bound values pass through `esc()`/`.text()`/`.val()`.
- **SQL injection:** all ids cast with `intval()`; export mode whitelisted.
- **CSRF / same-origin:** `bffSameOriginGuard()` + `X-Requested-With` header.

## Related redirect fixes (issue #754)

While modernizing Export, the `viewActions()` map in `api/plans/index.php` was
also updated (each of the six #754 redirects that has a modernized target):

- `exportAction` → `/gui/templates/plans/planExport.html` ✅ (this screen)
- `assignRolesAction` → `gui/templates/usermanagement/usersAssignPlan.html` ✅
- `gotoExecuteAction` → `gui/templates/execute/execTest.html` ✅
- `createAction` / `editAction` → `planEdit.php` (already covered by #750 modal)
- `importAction` → `lib/plan/planImport.php` (legacy, not yet modernized)
- `managerURL` → legacy `planEdit.php` (covered by #750)

Each modernized base URL uses a clean `tplan_id=` so the front-end appends the
real plan id — avoiding the duplicate-`tplan_id` bug that would feed the screen
plan 0.

## i18n

All user-facing strings use `pex.*` keys present in all ten locale bundles
(`de, en, es, fr, it, ja, pt, ro, ru, zh`).

## Test coverage

See **Suite 813** in `tmp/TLU_Test_Cases.md` (14/14 PASS).
Feature "file formats documentation link" (issue #1386) covered by **Suite 1498**.
