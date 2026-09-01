# Test Plan Import — Modernized Screen

Modernization of the legacy "import test plan links" screen (`lib/plan/planImport.php`,
`gui/templates/dashio/plan/planImport.tpl`) — GitHub issue
[#815](https://github.com/sebiboga/testlink-upgraded/issues/815).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/plans/planImport.html`) backed by a plain-PHP REST BFF API
(`api/planimport/index.php`). The Test Plan Management toolbar **Import Test Plan
Links** button (`importAction`) now opens the modern screen instead of the legacy
`planImport.php` redirect — the last remaining HIGH-priority legacy redirect
tracked in issue #754.

**Path:** Plans → Test Plan Management → row **Import** icon
**URL:** `gui/templates/plans/planImport.html?tproject_id=..&tplan_id=..`
**BFF API:** `GET ?action=info` + `POST ?action=import` on `/api/planimport/`
**Rights:** `mgt_testplan_create` at test project level (same as legacy
`planImport.php` `init_args()` → `checkGUISecurityClearance()`). Callers
without it get HTTP 403 and the screen disables the upload.
**Tracking issue:** [#815](https://github.com/sebiboga/testlink-upgraded/issues/815)

---

## Behavioral parity with the legacy controller

The BFF reproduces `lib/plan/planImport.php`:

- **Import type** is always **XML** (imports test plan links to test cases and
  platforms — items must **already exist** on the target test project).
- **Upload field** `uploadedFile` (multipart), size limit
  `import_file_max_size_bytes` (default 800000) with the same error strings as
  legacy (`file_size_exceeded`, `please_choose_file_to_import`).
- **Platform handling** — `processPlatforms()` port: each `<platform>` declared
  in the XML is linked to the test plan **if it exists on the test project** and
  is not already linked; unknown platform names abort the platform step
  (`platform_not_on_tproject`, status "Not imported").
- **Test case linking** — `importTestPlanLinksFromXML()` port, using the same
  `testplan::link_tcversions()` / `testplan::setExecutionOrder()` calls:
  - TC resolved by `<externalid>` within the target test project; version must
    exist (`tcversion_doesnot_exist`).
  - If the target plan already has platforms, every link must declare a valid
    platform (else `link_without_platform_element` /
    `link_without_required_platform` / `platform_not_linked`, status "Not imported").
  - If the target plan has **no** platforms, links must come without a platform
    element (else `link_with_platform_not_needed`); platforms specified in the
    XML get linked first, then TC links apply against them.
  - TC versions with a status hidden by `tplanDesign.hideTestCaseWithStatusIn`
    are refused (`cant_link_to_tplan_feedback` / `tcversion_status_forbidden`).
  - Existing links are updated (execution order) rather than duplicated.
- **Result report** — same `resultMap` pairs `[message, status]` with the legacy
  status values `OK` / `Not imported`.

## Screen layout

| Section | Description |
|---------|-------------|
| **Header** | "Import Test Plan Links" + test plan name |
| **Toolbar** | Test Project context + locale switcher |
| **Form card** | File type (fixed XML), file picker (`.xml`), upload size hint (`Maximum file size: N KB`), explanatory note |
| **Actions** | **Upload file** (multipart POST to the BFF) and **Cancel** (back/close) |
| **Report card** | Result table (Message / Status columns) with OK (teal) vs Not imported (red) rows |

## Flow

1. On load the page calls `GET ?action=info` with `tplan_id` + `tproject_id`.
   The BFF resolves the plan (400 on missing id, 404 on unknown plan), enforces
   `mgt_testplan_create`, and returns project/plan names, the import size limit
   and — when the parent project has zero test cases — a pre-import warning.
2. The user picks an `.xml` file and clicks **Upload file**.
3. The front-end `POST ?action=import` (multipart, field `uploadedFile`,
   `X-Requested-With: XMLHttpRequest`). The BFF checks size, saves to
   `TL_TEMP_PATH/session_id()-planImport.xml`, runs the legacy import pipeline,
   deletes the temp file and returns `result_map`.
4. The report card renders each `[message, status]` row; the form card hides.

## Error handling

- Missing/invalid `tplan_id` → 400 "Error Test Plan ID".
- Unknown plan → 404 "Test plan not found".
- Unauthenticated → 401 "Not authenticated".
- No `mgt_testplan_create` right → 403 "No permission" + upload disabled.
- No file chosen → client-side toast "Please choose a file to import.".
- File larger than `import_file_max_size_bytes` → 413 + legacy size message.
- Malformed XML → BFF returns a single `simplexml_load_file_wrapper_error`
  row, status "Not imported".

## Security

- **Permission:** `mgt_testplan_create` enforced on both the info and import
  routes (legacy parity via `tlUser::hasRight`).
- **XSS:** all DOM-bound values pass through `esc()` / `.text()`.
- **SQL injection:** all ids cast with `intval()`.
- **CSRF / same-origin:** `bffSameOriginGuard()` + `X-Requested-With` header.
- **Upload security:** `move_uploaded_file()` into the session-scoped temp path;
  temp file removed after import.

## Related redirect fixes (issue #815)

The `viewActions()` map in `api/plans/index.php` was updated:
`importAction` → `/gui/templates/plans/planImport.html` ✅ (this screen).
With this, every HIGH-priority #754 redirect with a modernized target now points
at a `.html` screen (export/assign-roles/execute/import); only the plan
create/edit URLs remain legacy (`planEdit.php`, covered by the #750 in-list modal).

## i18n

All user-facing strings use `pli.*` keys present in all ten locale bundles
(`de, en, es, fr, it, ja, pt, ro, ru, zh`). Import result messages come from
the legacy `lang_get()` strings (`strings.txt`), identical to 1.9.20 output.

## Test coverage

See **Suite 815** in `tmp/TLU_Test_Cases.md` (12/12 PASS).