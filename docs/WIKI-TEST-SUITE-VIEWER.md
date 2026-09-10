# Test Suite Viewer — Modernized Screen

Modernization of the legacy read-only **test suite viewer** reachable via
`lib/testcases/archiveData.php?edit=testsuite&id=<suite_id>` — GitHub issue
[#818](https://github.com/sebiboga/testlink-upgraded/issues/818).

The legacy popup (opened from the test-case search results of
`searchAdvancedView.html` via `openTsEdit()`) is replaced by a standalone
Dashio page (`gui/templates/testcases/suiteView.html`) backed by a plain-PHP
REST BFF API (`api/suiteview/index.php`).

**Path:** Advanced Search → Test-Suites result row → **suite name** (opens the
viewer popup).
**URL:** `gui/templates/testcases/suiteView.html?id=<suite_id>&tproject_id=..`
**BFF API:** `GET ?action=info` on `/api/suiteview/`
**Rights:** `mgt_view_tc` on the owning test project (resolved by walking up
`nodes_hierarchy` from the suite node — the popup may be opened for a suite of
a project different from the session context, mirroring legacy
`archiveData.php` behaviour).
**Tracking issue:** [#818](https://github.com/sebiboga/testlink-upgraded/issues/818)

> **Scope note.** This is intentionally the SMALLEST coherent sub-screen of the
> legacy `archiveData.php` tool: the read-only test-suite viewer only. The
> `edit=testproject` and `edit=testcase` routes are NOT ported — the modernized
> `tcView.html` (test case view popup) and `testSpec.html` (tree) already cover
> that ground. `reqMilestones.php` (status-table "BROKEN!" row) no longer exists.

---

## Behavioral parity with the legacy controller

The BFF reproduces the read-only suite viewer:
`testsuite::show()` + `containerView.tpl` / `tsuiteViewerRO.inc.tpl`:

- **Suite identity** — node name + id, parent node name (or empty for a
  root-level suite), node type guard (`testsuite` type only; a test-case node
  id is rejected with 404).
- **Suite details** — `testsuites.details` text.
- **Child content** — count of direct child suites and the list of directly
  linked test cases with their **latest version** (highest `tcversions.version`
  whose `tcversions.id` node is a child of the test-case node): external id
  (`PREFIX-N`, e.g. `SV-1`), name, version number, active flag, status,
  importance (canonical TestLink levels: `HIGH=3 / MEDIUM=2 / LOW=1`, rendered
  as High/Medium/Low badges), summary.
- **Keywords** — test-suite level keywords (`object_keywords` for the suite
  node — mirrors legacy `testsuite::getKeywords()`, which reads by `fk_id` only;
  real UI writes store `fk_table='nodes_hierarchy'`), deduped, ordered.
- **Attachments** — attachment metadata of the suite (title, file name, size,
  date), newest first. The legacy suite manager is bound to the
  `nodes_hierarchy` attachment table, so the BFF filters
  `fk_table='nodes_hierarchy'` (suite attachments uploaded through the real
  workflow are stored there, not under `testsuites`).

## Screen layout

| Section | Description |
|---------|-------------|
| **Header** | "Test Suite Viewer" + owning test project name + locale switcher |
| **Toolbar** | Refresh, "Open in Test Specification" (→ `testSpec.html?tproject_id=..`), Export Test Cases, Export Test Suite, **Table view** (when `mgt_modify_tc` on the owning project), context (#id) |
| **Overview card** | Identifier (suite name + `#id`), Parent, Child test suites, Test cases |
| **Details card** | Suite `details` (fallback `(no details)`) |
| **Test cases card** | DataTable (External ID, Name, Version, Importance badge, Summary) with search + pagination |
| **Test cases table view card** | Full grid for bulk-set (admin/designer with `mgt_modify_tc`): checkbox column, External ID, Name, Version, **Status**, Importance, **Execution Type**, Summary + per design-time custom field a set-input (checkbox + value select/text); bulk toolbar (Status / Importance / Execution Type selects + "Apply to selected") |
| **Keywords card** | Keyword chips (hidden when empty) |
| **Attachments card** | Attachment rows with size + date (hidden when empty) |
| **Footer** | Generated-on timestamp |

## Test cases table view (gap vs legacy, issue #1371)

Legacy parity target: the `testcases_table_view` action of
`lib/testcases/containerEdit.php:215-224` (button in
`containerViewTestSuiteTextButtons.inc.tpl`, only when
`modify_tc_rights == 'yes'`) → `moveTestCasesViewer()`, which rendered a full
selectable grid of the suite test cases (MAX version each, regardless of the
active flag) with design-time custom-field set inputs
(`html_table_inputs(..., addCheck=true)`) and a bulk toolbar whose Save ran
`doBulkSet()` (status / importance / execution_type + `design_values_to_db`).

The modern screen reproduces it:

| Piece | Location |
|-------|----------|
| **Rights** | `info` returns `can_manage` = `mgt_modify_tc == 'yes'` on the owning project; the toolbar button is hidden otherwise (`suiteView.html`). |
| **Grid BFF** | `GET /api/suiteview/?action=table&id=<suite>&tproject_id=<pid>` — one row per test case (MAX `tcversions.version`, inactive versions INCLUDED, matching the legacy grid), each with `tcversion_id`, `tcase_id`, `name`, `external_id_display`, `version`, `active`, `status`, `importance`, `execution_type`, `summary` and `cf.{cfId}` design values; plus `cfs` definitions and `domains` (localized status/importance/execution_type maps). |
| **Bulk write BFF** | `POST /api/suiteview/?action=bulk_set` `{id, tproject_id, rows:[{tcversion_id,tcase_id}], status, importance, execution_type, cfs:{cfId:value}}` — gated on `mgt_modify_tc` (HTTP 403 otherwise); per selected version applies `setStatus`/`setImportance`/`setExecutionType` when the value > 0, then `cfield_mgr->design_values_to_db()` for each provided CF value (legacy `doBulkSet` ordering, `containerEdit.php:1434`). |
| **UI** | "Table view" toolbar button toggles the full grid; header checkbox selects/deselects all; DataTable with search + pagination; bulk toolbar under the grid + success/error feedback (`suvw.tbl*` keys, all 10 locales). |

Screenshots (issue #1371):
`![table-view](screenshots/issue-1371-table-view.png)`,
`![before](screenshots/issue-1371-before.png)`.

## Flow

1. On load the page reads `id` (accepts `id` / `testsuite_id` / `item_id`) and
   optional `tproject_id` from the query string.
2. `GET ?action=info&id=..&tproject_id=..` → the BFF resolves the suite, walks
   up to the owning test project, enforces `mgt_view_tc`, and returns the
   suite/tc/keywords/attachments payload.
3. The popup renders; the TC name link and the toolbar "Open in Test
   Specification" open the modernized `tcView.html` / `testSpec.html`.

## Error handling

- Unauthenticated → 401 "Not authenticated".
- Missing/invalid `id` → 400 "Invalid test suite id".
- Unknown suite / non-suite node → 404 "Test suite not found".
- Owning project unresolvable → 404 "Owning test project not found".
- No `mgt_view_tc` → 403 "You are not authorized to view this test suite".
- Front-end: red banner + hidden content for load failures; "No test suite id
  provided." when the page is opened without an id.

## Security

- **Permission:** `mgt_view_tc` enforced server-side on the owning test
  project (never trusted from the client).
- **XSS:** all DOM-bound values pass through `escapeHtml()`; DataTables uses
  HTML escaping by default.
- **SQL injection:** all ids cast with `intval()`.
- **CSRF / same-origin:** `bffSameOriginGuard()`; the popup only issues GETs.
- **Event Viewer cleanliness:** a `frr()` helper normalizes `fetchFirstRow()`
  `null`/`false` returns so 400/404 paths never emit E_WARNING rows into the
  `events` table (found + fixed during testing).

## Link switch

`searchAdvancedView.html` `openTsEdit()` now opens
`/gui/templates/testcases/suiteView.html?id=<id>&tproject_id=..` instead of
`/lib/testcases/archiveData.php?edit=testsuite&id=<id>`. No other modernized
screen routes suite viewing through `archiveData.php` (grep-clean).

## i18n

All user-facing strings use `suvw.*` keys present in all ten locale bundles
(`de, en, es, fr, it, ja, pt, ro, ru, zh`). 38 keys (25 original + 13 table-view
keys added with issue #1371); bundles validated with
`python3 -m json.tool`.

## Test coverage

See **Suite 819** in `tmp/TLU_Test_Cases.md` (21/21 PASS).