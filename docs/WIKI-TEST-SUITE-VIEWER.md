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
| **Toolbar** | Refresh, "Open in Test Specification" (→ `testSpec.html?tproject_id=..`), Export Test Cases, Export Test Suite, **Generate spec (HTML)**, **Generate spec (Word)** (when `testplan_metrics` on the owning project), Import Test Cases/Test Suite + **Table view** (when `mgt_modify_tc`), context (#id) |
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

## Import launchers (Refs #1370)

The suite-level import entry points of the legacy operations panel are restored
(legacy `containerViewTestSuiteTextButtons.inc.tpl:72-77` `importItem` and
`:129` import-TC span):

- **Import Test Cases** — opens
  `tcImport.html?containerID=<suite>&tproject_id=<pid>` (flat import, legacy
  `$importTestCasesAction`).
- **Import Test Suite** — opens
  `tcImport.html?containerID=<suite>&tproject_id=<pid>&useRecursion=1` (deep
  suite import, legacy `$importToTSuiteAction`).

Both buttons are gated by `mgt_modify_tc` on the owning project (legacy
`modify_tc_rights`; the BFF already exposes it as `can_manage`) — for a
read-only user they are not rendered. The launcher reuses the already-modern
`tcImport.html` + `api/testcasesimport` (no BFF change); the target suite is
shown in the import screen header and the uploaded cases land under that suite.

## Generate testsuite-spec document — HTML + MS Word (Refs #1369)

The testsuite-level test-spec document generation of the legacy operations panel
(`containerViewTestSuiteTextButtons.inc.tpl:66-70` report / report_word buttons,
URLs `containerView.tpl:54-58`: `lib/results/printDocument.php?type=testspec&
level=testsuite&allOptionsOn=1&format=0|4&id=<suite>`) is ported into the modern
toolbar:

| Piece | Location |
|-------|----------|
| **Buttons** | "Generate spec (HTML)" (format=0) + "Generate spec (Word)" (format=4) ghost buttons in the suiteView toolbar, rendered only when `info.can_print` is true. |
| **Rights** | `info` now returns `can_print` = `testplan_metrics` on the OWNING test project — exact legacy `printDocument.php checkRights()` parity (explicit project id, because the popup may be opened for a suite of another project). |
| **Launcher** | `openSuiteSpec(format)` opens `gui/templates/testcases/printTestDoc.html?type=testspec&level=testsuite&id=<suite>&tproject_id=<pid>&format=0|4&toc=y&headerNumbering=y&header=y&summary=y&body=y&author=y&keyword=y&cfields=y&requirement=y` — **all 9 print options =y**, the modern equivalent of legacy `allOptionsOn=1` (full document). |
| **Document** | Reuses the already-modern print stack: `api/testcasesprint` (BFF, session + `testplan_metrics`) wrapping the battle-tested legacy generator `lib/results/printDocument.php`. HTML renders in a real-time iframe viewer; Word triggers the `.doc` blob download. |

The scope is the single suite (with its nested sub-suites); sibling suites are
excluded — verified on the fixture (Suite Alpha + Subsuite Gamma included, Suite
Beta's "Create Report" case excluded).

Server side stays authoritative: even if the UI gate were bypassed, the print
BFF returns **HTTP 403 "No permission"** for a user without `testplan_metrics` on
the project.

Screenshots (issue #1369):
`![buttons](screenshots/issue-1369-suite-spec-buttons.png)`,
`![doc](screenshots/issue-1369-testsuite-spec-html-doc.png)`,
`![hidden](screenshots/issue-1369-viewer-no-print-buttons.png)`.

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
(`de, en, es, fr, it, ja, pt, ro, ru, zh`). 44 keys total (including the 13
table-view keys added with issue #1371, the 2 import-launcher keys added with
issue #1370 and the 2 generate-spec keys `suvw.genSpecHtml`/`suvw.genSpecWord`
added with issue #1369); bundles validated with `python3 -m json.tool`.

## Test coverage

See **Suite 819** in `tmp/TLU_Test_Cases.md` (21/21 PASS). The import launchers
(#1370) are covered by the **Issue #1370** suite in the same file (10/10 PASS).
The generate-testsuite-spec feature (#1369) is covered by the **Issue #1369 /
Suite 1524** suite (13/13 PASS).
## Test-case management operations (issue #1368)

The viewer exposes the legacy Test-Suite-Viewer test-case operations from the
toolbar and the table view:

- **+ Create Test Case** — deep-links
  `testSpec.html?tproject_id=<p>&containerID=<suite>&create=1`; testSpec
  auto-selects the suite in the tree and opens the create-test-case form
  (modern entry to legacy `create_tc` → `tcEdit.php?doAction=create&containerID=`).
- **Reorder Test Cases** — `POST api/suiteview/index.php?action=reorder_testcases`;
  honours `$tlCfg->testcase_reorder_by` (`EXTERNAL_ID` default / `NAME` natsort),
  legacy `reorderTestCasesViewer`. An explicit `by` override is accepted.
- **Create from Issue XML** — opens
  `tcCreateFromIssues.html?containerID=<suite>&tproject_id=<p>` pre-targeted at
  the suite (legacy `create_tc_from_issue_xml` span).
- **Table view — Move / Copy / Delete selected** — target-suite picker lists all
  suites of the project except the current one. `move_testcases` reparents,
  `copy_testcases` performs a deep copy (testcase + version + steps/CF), and
  `delete_testcases` removes the node and its version (executed test cases are
  refused, mirroring `testproject_delete_executed_testcases`). Legacy
  `do_move_tcase_set` / `do_copy_tcase_set` / `do_delete_testcases`.

All operations are gated on `mgt_modify_tc` on the owning project
(`can_manage`); the three toolbar buttons are hidden otherwise. New BFF
endpoints live in `api/suiteview/index.php` and 17 `suvw.*` keys were added to
all ten locale bundles.

**Bug fixed during testing:** `TV_TABLE` was referenced but never declared in
`suiteView.html`, aborting `renderTableView()` before DataTable initialization
(console `ReferenceError`). Declared the variable; filed as issue #1527.
