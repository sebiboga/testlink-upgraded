# Modernize: Reorder Test Cases (`tcReorder`)

**Refs #1660** · status: DONE · BFF: `api/tcreorder/index.php` · screen: `gui/templates/testcases/tcReorder.html`

## What this screen is

Re-ordering the test cases inside a test suite. In 1.9.20 it was not a screen
at all — it was the drag-and-drop of the ExtJS tree that formed the *left
frame* of the Test Specification work area:

| Legacy piece | Role |
|---|---|
| `lib/testcases/listTestCases.php` | the left frame, rendering `tcTree.tpl` |
| `gui/templates/dashio/testcases/tcTree.tpl` | the ExtJS tree widget |
| `gui/javascript/execTree.js` → `writeNodePositionToDB()` | drag-drop client |
| `lib/ajax/dragdroptprojectnodes.php` | the write backend |
| `containerEdit.php` (`reorderTestCasesDictionary` / `reorderTestCasesByExtID`) | the sort action |

Because it was a tree gesture, the capability had no addressable URL, no
dialog, and no right of its own. It is now an ordinary screen.

## Why the legacy backend had to be retired

`lib/ajax/dragdroptprojectnodes.php` performed **no authorization at all**:

- `doAction=changeParent` called `tree::change_parent()` on an arbitrary node id
- `doAction=doReorder` called `tree::change_order_bulk()` on an arbitrary
  comma-separated list of node ids

There was no rights check and no test-project ownership check, so *any*
authenticated user — including one holding no test-case management right
whatsoever — could re-parent nodes **across test projects** (and touch
requirement and test-plan nodes) or rewrite arbitrary `node_order` values.

The modern BFF closes that: it checks `mgt_modify_tc` on the **owning** project
and proves every submitted node id to be a test case of the addressed container
before writing.

## The screen

Dashio layout: teal header + dark toolbar (Refresh / Back to Test Specification
/ Close), a context card (Test project, Container, Reorder by, Test cases), a
container card with the suite selector and the two sort buttons, the order
table, and explicit loading / empty / session-expired / access-denied /
not-found states. `TLi18n` locale switcher in the toolbar.

| Control | Behaviour |
|---|---|
| **Container** selector | project root + every suite of the project, alphabetical |
| **Move to top / up / down / bottom** | per row, immediate (no confirm), boundary buttons disabled |
| **Sort by name** | confirmation naming the criterion, then `natsort` order |
| **Sort by external ID** | same, numeric-aware order |

Rows show position, name, external id and the four move buttons. Boundary state
is recomputed from the server response after every write, never guessed
client-side.

## BFF contract

`api/tcreorder/index.php`

| Route | Notes |
|---|---|
| `GET ?action=init&tproject_id=N[&container_id=M]` | context, container, suites, criterion, ordered test cases, rights |
| `POST ?action=move` | `node_id`, `position` = `top\|bottom\|up\|down` |
| `POST ?action=sort` | `by` = `NAME\|EXTERNALID` |
| `POST ?action=reorder` | `nodelist` — the hardened drag-and-drop parity endpoint |

Guards: session auth, `bffSameOriginGuard()`, `bffEnforceSession()` (the legacy
inactivity timeout — this endpoint *writes*), and `mgt_modify_tc` on the owning
project. Status contract: `401` anonymous / `403` no rights / `403` container
from another project / `400` bad param / `404` unknown project, container or
node / `405` wrong verb. Every write answers `no_change` instead of `ok` when the
id order did not actually move, so the screen never toasts "saved" for a no-op.

## The 2.0.1 tree schema — the trap for every modernized screen

The first version of this BFF was written against 1.9.20 and **every read was
dead SQL**. 2.0.1's `nodes_hierarchy` carries only
`id, name, parent_id, node_type_id, node_order`; the columns
`testproject_id`, `testcase_id`, `tcversion_id` and `testsuite_id` are gone:

```
Unknown column 'nh.testproject_id' in 'SELECT'
```

The real relationships:

- a test case **node id is the test case id**
- a version node (`node_type_id = 4`) has `parent_id` = the test case node, and
  `tcversions.id` = the version node id
- external id = `MAX(tc_external_id)` over those version rows
- suite metadata is in `testsuites(id, details)`
- the project **name** lives in `nodes_hierarchy.name`; `testprojects` keeps only
  `prefix`
- **ownership must be proved** by walking `parent_id` up to `node_type_id = 1`
  — there is no column to read

There is also **no table-prefix global** in 2.0.1; interpolating one raises
`E_WARNING Undefined global variable` on every query. Use
`tlObjectWithDB::getDBTables()`, which is also the only accessor that honours a
configured prefix.

## Sorting parity

`config_get('testcase_reorder_by')` ships as the string **`EXTERNAL_ID`**
(config.inc.php), *not* `EXTERNALID`. Legacy dispatches on it in
`containerEdit.php`:

- `NAME` → `reorderTestCasesDictionary()` → `natsort(strtolower(name))`
- otherwise → `reorderTestCasesByExtID()` → `ORDER BY tc_external_id`

So the criterion is normalized with **NAME as the opt-in**. A plain
`strcasecmp` would put `TC 10` before `TC 2`; a natural compare fixes that.
External ids compare **numerically** when both are numeric — legacy sorted the
VARCHAR column as text, and `'2'` before `'10'` is never what a user means.
Non-numeric ext ids (`TC-A`) fall back to a natural text compare.

## Retired legacy surface

Both controllers are now thin shims, and **neither replays a mutation**:

| Legacy URL | Anonymous | Authenticated |
|---|---|---|
| `lib/ajax/dragdroptprojectnodes.php` | → login (destination preserved) | `GET` → 302 to this screen; `POST` → `405` |
| `lib/testcases/listTestCases.php` | → login (destination preserved) | `302` → `edit_tc` / `keywordsAssign` / `assignReqs`; unknown feature → `400` |

## Wiring

- `$actions->tcReorder` in `lib/functions/common.php`
- rights-gated **Reorder Test Cases** button + `openTcReorder()` on
  `gui/templates/testcases/testSpec.html` (hidden without `mgt_modify_tc`)

## i18n

`tcreo.*` + `footers.tcReorder` + `tspec.reorderTestCases` /
`tspec.reorderTestCasesTitle` in **all 10** bundles (`python3 -m json.tool`
validated). Dead keys were removed rather than left behind: `tcreo.confirmReorder`
(its only caller `doReorderAll()` was unreachable) and `tcreo.reorderSaved`.

## Bugs found and fixed while building this

| Issue | Defect |
|---|---|
| **#1661** | 2.0.1 dropped four `nodes_hierarchy` columns — every read was dead SQL. Plus: the default container always 404'd, the suite selector was always empty (the tree walk omitted `parent_id`, which is exactly what the ownership proof starts from), a no-op write reported `ok`, and a container from another project was silently retargeted and reordered. |
| **#1662** | Both shims bounced anonymous users to a *relative* `login.php`, which resolved against `/lib/ajax/` and `/lib/testcases/` → **404**. The session-expiry path landed on a dead page. |
| **#1663** | `E_WARNING Undefined global variable $dbprefix` on every query — **1 289** event rows, which buried the two log lines that actually mattered. |
| code review | Inverted sort criterion (`EXTERNAL_ID` vs `EXTERNALID`, so the screen silently sorted by name on every install), no inactivity-timeout check on a write endpoint, `strcasecmp` where legacy used `natsort`, plus 5 MINOR and 7 NIT items. |

## Testing

Suite **1660** in `tmp/TLU_Test_Cases.md` — 62 cases, all PASS, across
rendering/data contract, move, sort (incl. modal cancel/apply), container
switching and empty states, errors and rights, legacy shim retirement, i18n and
the Event Viewer. Fixture `tmp/fixtures_1660.php` (project A `69` with a root
suite of 5, a nested suite, an empty suite and a test-plan node; project B `95`;
a *no rights* and a *view-only* user). Event Viewer clean — zero new rows after
the `getDBTables` fix.

## Screenshots

- `docs/screenshots/issue-1660-tcreorder-normal.png` — the screen with a populated container
- `docs/screenshots/issue-1660-tcreorder-sort-confirm.png` — the sort confirmation naming the criterion
- `docs/screenshots/issue-1660-tcreorder-after-move.png` — after *Move to bottom*
- `docs/screenshots/issue-1660-tcreorder-empty-container.png` — empty container state
- `docs/screenshots/issue-1660-tcreorder-access-denied.png` — view-only user, `mgt_modify_tc` missing
- `docs/screenshots/issue-1660-tcreorder-locale-ro.png` — Romanian locale

## Commits

`f7944810b` BFF → `0dd2b1754` screen + i18n → `e11a7a335` wiring + shims →
`d927d4306` schema rebuild (#1661) → `a8062fd4a` shim redirect (#1662) →
`22254e0ae` `getDBTables` (#1663) → `5190c6b67` suite → `e3b4ec6f8` code-review
fixes.
