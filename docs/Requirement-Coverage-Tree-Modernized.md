# Requirement Coverage Tree — Modernized (2.0.1)

**Refs #1765** (tracking) · **#1766** (bug found + fixed) · builds on #1695 (`reqSpecListTree`).

## What it is

The coverage navigator answers one question per project: **which requirements are covered by a
test case, and which are not?** In 1.9.20 it was the ExtJS tree built by
`tlTestCaseFilterByRequirementControl::build_tree_menu()`, and it was also the drag-and-drop
**source** of the assignment flows (create test suite / test case, assign keywords, assign
requirements). Every level was lazily loaded from `lib/ajax/getreqcoveragenodes.php`.

Legacy shape:

| Level | Legacy node |
|---|---|
| root | test project → `javascript:EP(<tproject_id>)` |
| 2 | requirement specification → `javascript:ERS(<id>)` — `"<doc_id>:<title> (<n>)"` |
| 3 | requirement → `javascript:ER(<id>)` — `"<req_doc_id>:<title>"` |

## Files

| File | Role |
|---|---|
| `gui/templates/requirements/reqCoverageTree.html` | the modern screen (Dashio HTML + jQuery + inline CSS) |
| `api/reqcoveragetree/index.php` | the REST BFF (`init`, `children`, `coverage`, `projects`) |
| `lib/ajax/getreqcoveragenodes.php` | the retired 1.9.20 loader — now a non-mutating, session-guarded 302/405 shim |
| `lib/functions/common.php` | `$actions->reqCoverageTree` (the screen's common action) |
| `gui/templates/i18n/*.json` | `covt.*` (63 keys) + `footers.reqCoverageTree` in all 10 bundles |

## The screen

* **Project strip (always visible)** — a readable-project switcher. It deliberately survives a
  `403`/`404`, because those are exactly the states in which the caller must reach another project.
* **Toolbar** — Refresh, Expand all, Collapse all, *Assign test cases*, *Specification management*,
  *Move / reorder*, Close. All three links follow the selected project.
* **Context card** — project, prefix, specifications, requirements, user, plus
  `Requirements enabled|disabled` and `Integration on|off` chips.
* **Coverage summary** — Specifications / Requirements / Covered / Uncovered tiles and the
  *Only uncovered requirements* filter.
* **Tree** — `test project → specification → #1699 container → requirement`, lazily loaded and
  cached; every specification and container carries a live `x / y covered` chip.
  Requirements show `covered by N` or `uncovered`.
* **Coverage panel** — per requirement: the test case name, external id, test-case version, the
  requirement version, `active coverage` / `closed by execution` status, and *Open test case*.
  An uncovered requirement shows an explicit empty state.
* **States** — loading, empty, no project, requirements-disabled, access denied, not found,
  bad request, read-only banner (assign/reorder disabled with an explanation), session expiry.

## The BFF

```
GET /api/reqcoveragetree/index.php?action=init&tproject_id=N
GET /api/reqcoveragetree/index.php?action=children&tproject_id=N&node_id=M
GET /api/reqcoveragetree/index.php?action=coverage&tproject_id=N&req_id=R
GET /api/reqcoveragetree/index.php?action=projects
```

* Session auth + `bffSameOriginGuard()` + `bffEnforceSession()` (a tab idle past
  `sessionInactivityTimeout` stops reading data).
* `mgt_view_req` **or** `mgt_modify_req` on the addressed project, checked **before** the project is
  resolved, so a foreign or non-existent id cannot be told apart.
* Every node id is proven to live inside that same project (`owningProjectId()` walks
  requirement → version → specification → project, and resolves a #1699 container through its
  parent specification).
* `coverage` additionally requires `mgt_view_tc` / `mgt_modify_tc`: it returns test case names and
  ids, and the two right groups are independent.
* Coverage only counts `is_active = 1` links; a link closed by execution stays visible in the panel
  but does not make the requirement "covered".
* 400 / 401 / 403 / 404 / 405 with stable machine codes.

## What was fixed

**Bug #1766 — nested containers (#1699) were unusable.** `owningProjectId()` treated every
`node_type_id = 1` node as a project root and returned its own id, so a container never matched the
addressed project: expanding it answered `404 unknown_node`, a dead end. The container's
requirements were also missing from the specification count, the per-node chips, the coverage
percentage and the totals. Both are fixed (a container resolves through its parent specification;
`specStats()` counts the containers a specification parents).

Defects found while testing (all fixed):

| Symptom | Cause |
|---|---|
| one click produced two identical GETs and duplicated rows | the row **and** its button carried the same handler attribute; the delegated queue fires once per matching ancestor |
| *Collapse all* did not collapse; an expanded spec kept the collapsed icon | cached children rendered unconditionally, and the twisty read a hoisted-but-unassigned `open` |
| doc id rendered `CVT-CVT-1` | a requirement `doc_id` is already qualified; only the test case `external_id` needs the prefix |
| a `403` showed "the server could not answer this request" | jQuery routes 4xx to `error`, so the machine code had to be recovered from the body |
| icons invisible | `fa-plussquare` / `fa-file-text-o` render nothing in Font Awesome 6 |
| toolbar links acted in the previous project | `buildLinks()` ran once at load |
| a `403`/`404` was a dead end | the project switcher lived in the hidden card |
| Event Viewer warnings | `fetch_array()` answers `false`, not an array |

## Mandatory code review (subagent)

4 BLOCKER (test-case right on `coverage`, missing `bffEnforceSession()`, stale `tproject_id` in the
toolbar links, the dead-end 403/404 state) and 12 MINOR (stale `children` answers after a switch, a
node-existence oracle in the type gate, inactive projects in the switcher, duplicated clicks, a
broken "open specification" link on a container row, `BUSY` not driving the toolbar, machine codes
rendered as UI text, dead code) — all fixed.

## Tests

`tmp/TLU_Test_Cases.md`, suite **T1765 = 50/50 PASS**: 20 API contract/access-control cases
(including the no-tc-right `403`, the container load, the executed-only link, the inactive link, the
`400`/`401`/`403`/`404`/`405` matrix and the retired-loader shim), 24 browser cases (lazy tree,
expand/collapse, coverage panel, filter, project switch, all state cards, read-only user, RO locale,
FA6 glyphs) and 6 static/Event-Viewer cases. Event Viewer clean.

## Screenshots

* `docs/screenshots/issue-1765-reqCoverageTree-tree.png` — tree with a specification expanded and the
  coverage panel open
* `docs/screenshots/issue-1765-reqCoverageTree-403.png` — access-denied state, project switcher still usable
* `docs/screenshots/issue-1765-reqCoverageTree-readonly.png` — view-only banner with assign/reorder disabled

## Entry points

`$actions->reqCoverageTree` in `lib/functions/common.php`. Like its siblings the legacy ExtJS frame
had no ASIDE row of its own (the coverage tree was embedded in the assignment popups), so there is no
menu row to move; the toolbar links (Assign test cases / Specification management / Move-reorder) and
the retired loader's 302 are the ways in.