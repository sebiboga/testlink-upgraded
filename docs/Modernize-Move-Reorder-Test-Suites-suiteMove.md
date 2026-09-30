# Modernize: Move / Reorder Test Suites (`suiteMove`) — Refs #1740

Modern screen: `gui/templates/testcases/suiteMove.html`
BFF: `api/suitemove/index.php`
Entry point: Test Specification toolbar → **Move / Reorder Test Suites** (`testSpec.html`)
Legacy predecessor: `lib/ajax/dragdroptreenodes.php`

## Why

In 1.9.20 the move / re-parent / re-order of a test suite was performed by the ExtJS tree itself
through `lib/ajax/dragdroptreenodes.php` — a **GET** request that called `tree::move_node()` and
wrote `nodes_hierarchy.parent_id`. It had

* no rights check (`mgt_modify_tc` was never consulted),
* no proof that the moved node or the destination node belonged to the project,
* no same-origin check, so any bookmark, crawler or cross-site `<img>` hit could re-parent a suite.

The endpoint is now a **non-mutating** session-guarded shim (browser → 302 to the modern screen,
XHR → an escaped fragment); its legacy writes are never executed.

## API

| Route | Purpose |
|---|---|
| `GET ?action=init&tproject_id=N&container_id=M` | context (project `name (prefix)`, container, rights `can_modify`) + the ordered sub-suites with test-case / sub-suite counts |
| `GET ?action=suites&tproject_id=N[&exclude_id=K]` | the depth-indented list for the destination picker (excluding `K` **and its whole subtree**) |
| `POST ?action=move` | `{node_id, new_parent_id, position: top\|bottom}` |
| `POST ?action=reorder` | `{container_id, nodelist: "id,id,…"}` |

Contract: `401` anonymous, `403` no right / CSRF, `404` unknown or foreign node or project,
`400` bad parameter, `405` wrong verb, `409` cycle, and a no-op is a **success**
`200 {"status":"no_change"}` (same as `tcreorder`, `tcstepsreorder`, `reqtreereorder`).

## Guarantees

* `mgt_modify_tc` on the **owning** project.
* Every id proven to be a `node_type_id = 2` node of that project (the ownership walk also proves
  a suite really hangs off a test project).
* A container can never be moved into its own subtree (cycle guard).
* The destination picker walks **down** from the project node, so the walk itself proves
  ownership — no per-suite query (`#1757`).

## Bugs found while testing

| Issue | Defect |
|---|---|
| #1742 | every suite listed twice in the picker |
| #1743 | "move to first/last" refused for every legitimate move (`node_order` is not dense in 2.0.1) |
| #1744 | empty project name in the context bar |
| #1745 | **cycle guard inverted** — moving a container into its descendant detached the subtree |
| #1746 | a real reorder answered `no_change` |
| #1747 | `can_modify` ignored, write actions always rendered |
| #1748 | one `E_WARNING: Undefined array key "node_type_id"` per row |
| #1750 | toolbar tooltip reused a row-button title |
| #1751 | another agent's commit deleted all `smv.*` keys from the 10 bundles |

## Mandatory code-review fixes

| Issue | Defect |
|---|---|
| #1752 | the container picker filtered the **current** container out of its own selector |
| #1753 | a no-op answered `400 status:error`; the reorder no-op was decided after the write |
| #1754 | `fill()` re-escaped `TLi18n.t()`'s output — `R&D Suite` → `R$$&D Suite` |
| #1755 | `BUSY` latched forever on an unhandled path, disabling every action |
| #1756 | a write after the session timeout printed a raw server string instead of the login page |
| #1757 | the picker was O(all suites × depth) across the whole installation |
| #1758 | a malformed `new_parent_id` was silently degraded into an in-container reorder |
| #1759 | `403` on a foreign suite leaked that node's existence (now `404`) |

## Verification

Suite 1740 in `tmp/TLU_Test_Cases.md`: **57/57 PASS** (incl. 11 review-fix cases) + 30 API
contract checks. Event Viewer clean (0 new Error/Warning rows). Screenshots:
`docs/screenshots/issue-1740-suitemove-0{1,2,3}-*.png`.
