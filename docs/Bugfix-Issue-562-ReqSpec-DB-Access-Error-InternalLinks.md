# Bugfix — Issue #562: req spec update/create crashes with DB access error when internal_links enabled

## Problem

Updating or creating requirements / requirement specifications fails with a raw
`DB Access Error - debug_print_backtrace()` page whenever
`$tlCfg->internal_links->enable` is TRUE (stock default).

## Root Cause

`tree::_get_path()` (`lib/functions/tree.class.php:488`) stops recursion when
`intval($row['parent_id']) <= 0`, so the tree root node (testproject) is never
included in the path returned by `get_path()`.

The legacy code derived `$tproject_id = $path[0]['parent_id']`. For top-level
requirement specs hanging directly off a test project, this yielded NULL/0
because the path does not include the root node.

Similarly, `requirement_mgr::create()/update()/create_tc_from_requirement()` used
`getTreeRoot($srs_id)` which calls `get_path()` with the same broken derivation.

When `$tproject_id` was NULL/0, `req_link_replace()` called
`$tproject_mgr->getTestCasePrefix(NULL)`, executing a malformed SQL query that
produced the raw DB Access Error page.

## Fix

A robust helper `req_tproject_id_for_node()` was added to
`lib/functions/requirements.inc.php:1015-1032`. It walks `nodes_hierarchy`
upward until a node with `parent_id <= 0` is found (the testproject root node),
then returns that node's id. All four crash sites now call this helper instead
of `getTreeRoot()` or `$path[0]['parent_id']`.

## Files Changed

- `lib/functions/requirements.inc.php` — added `req_tproject_id_for_node()` at line 1015
- `lib/functions/requirement_spec_mgr.class.php` — line 407: uses `req_tproject_id_for_node()`
- `lib/functions/requirement_mgr.class.php` — lines 355, 447, 921: uses `req_tproject_id_for_node()`

## Verification

- Created top-level Requirement Specification → success, no crash
- Updated (edited) top-level Requirement Specification → success, no crash
- Created Requirement under spec → success, no crash
- Updated (edited) Requirement → success, no crash
- Event Viewer: no new DB access errors or `debug_print_backtrace()` entries
- PHP server log: all requests `[200]`, no crash traces
- `internal_links->enable` confirmed TRUE (stock default)
