# Modernize — Test Case Tree Navigator (`tcProjectTree`)

**Issue:** [#1770](https://github.com/sebiboga/testlink-upgraded/issues/1770)
**Screen:** `gui/templates/testcases/tcProjectTree.html`
**BFF:** `api/tcprojecttree/index.php`
**Legacy source:** `lib/ajax/gettprojectnodes.php` (now a session-guarded 302/405 shim)
**Changelog:** `CHANGELOG` (section "Screen — Test Case Tree Navigator `tcProjectTree`")

## Why this screen

In 1.9.20 the "Test Case Tree" was the **LEFT frame** of several work areas: "add/remove test cases" (`planAddTC_m1.tpl`), "update test plan TC assignments" (`planUpdateTC.tpl`), "test urgency" (`planUrgency.tpl`) and "execution assignment" (`tc_exec_assignment.tpl`). The frame was an ExtJS tree populated lazily by `lib/ajax/gettprojectnodes.php` with parameters `root_node`, `node`, `filter_node`, `show_tcases`.

The legacy loader had no authorization:
- only `testlinkInitPage()` (session check) ran
- no project-scope check and no `mgt_view_tc` / `mgt_modify_tc` right check
- `display_children()` filtered on `parent_id` alone, so the walk could be steered across projects
- the returned labels were written into ExtJS HTML nodes with only basic escaping

This is the same class of exposure as #1696 (`getrequirementnodes.php`) and #1765 (`getreqcoveragenodes.php`).

## Modern screen

Standalone Dashio page (`gui/templates/testcases/tcProjectTree.html`) backed by a read-only BFF. Key behaviors:
- **Lazy tree**: top-level suites loaded from `init`, nested suites and cases from `children` on demand (twisty + spinner)
- **Readable-project switcher**: the legacy frame used the session project and had none. The modern screen fetches `projects` via `action=projects` and lets the caller switch to any project for which they have read rights
- **State preserved in the URL**: `tproject_id`, `show_tcases` (`0|1`), `filter_node`. A reload restores the exact state
- **Live counters**: recursive "suites / test cases" chip, and per-suite counts (`tcase_qty`)
- **External IDs**: `tc_external_id` prefix appears when `treemenu_show_testcase_id` is on (1.9.20 parity)
- **Gestures**: Expand all / Collapse all / Hide/Show test cases / Refresh, suite focus filter, toolbar links to Test specification / Search / Project information, Close
- **Access control**: the BFF checks `mgt_view_tc` or `mgt_modify_tc` on the addressed project **before** resolving it (`403 no_right` for real and bogus project ids alike). Every requested node is proven to live under that project root
- **Security**: names are returned as JSON data and escaped on render by the screen (no inline HTML injection from the BFF). The legacy shim never replays the unauthorized read
- **Deep links**: project info, test specification (container), test case viewer

BFF actions:
- `GET ?action=projects` — readable test projects for the switcher
- `GET ?action=init&tproject_id=<N>[&filter_node=<M>][&show_tcases=0|1]` — context + project node + top-level children
- `GET ?action=children&tproject_id=<N>&node_id=<M>[&filter_node=<K>][&show_tcases=0|1]` — children of a project/suite
- `GET ?action=filter&tproject_id=<N>&node_id=<M>` — validate a filter_node (suite of that project)

Legacy endpoint `lib/ajax/gettprojectnodes.php` is now a non-mutating shim: GET/HEAD → 302 to the modern screen (preserves `root_node`→`tproject_id`, `filter_node`, `show_tcases`), POST/other verbs → 405.

## Fixes included in this modernization

- **#1771** — `tc_external_id` resolved through the wrong node level (version nodes hang off test cases). The BFF joins suite→case→version and pins `MAX(version node id)`.
- **#1772** — Hide/Show and suite filter were not written to the URL, so reload dropped them.
- **#1773** — "Expand all" stopped at the first level; sticky mode + recursive walk fixes it.
- **#1774** — exclusion list mistyped 8 instead of 7, so requirement nodes leaked; replaced by an allow-list (`testsuite`, `testcase`) so hidden node types never leak.

## Testing

- `python3 tmp/suite_1770.py` — **128 PASS**, stdlib only
- Browser pass (admin): expand/collapse/show-hide/filter/project switcher, every twisty, all open links, all state cards, no console errors/warnings
- Rights pass (`treenorights`): `projects` empty, init/children/filter → 403
- Event Viewer: no new Error/Warning rows

## Screenshots

- `1770-tcprojecttree-expanded.png` — fully expanded TREE1
- `1770-tcprojecttree-filtered.png` — filtered by `Tree Root`
- `1770-tcprojecttree-denied.png` — no-rights user
