# Bugfix Issue #765: get_last_child_info SQL error + null-array warning when creating requirements on a top-level spec

## Problem

While seeding fixtures for the Requirement Viewer modernization (Refs #755),
the Event Viewer gained ERROR + E_WARNING entries every time a requirement
node id could not be resolved to a requirement spec (e.g. absent/deleted
requirement, or a top-level node whose parent id is not resolvable).

The `events` table captured, per offending call:

```
ERROR ON exec_query() ... 1064 ... /* Class:requirement_spec_mgr - Method:
get_last_child_info */ SELECT COALESCE(MAX(revision),-1) AS revision FROM
req_specs_revisions CHILD, nodes_hierarchy NH WHERE NH.id = CHILD.id
AND NH.parent_id =            <- empty parent id
```

plus

```
E_WARNING Trying to access array offset on null
  - lib/functions/requirement_mgr.class.php:3434
```

## Root Cause

`requirement_mgr::getTestProjectID($id, $reqSpecID=null)`
(`lib/functions/requirement_mgr.class.php:3427`), when `$reqSpecID` is null,
calls `get_node_hierarchy_info($id)` and then assigns
`$parent = $dummy['parent_id']` (`:3434`) **without checking `$dummy` for
null**. When the node is not found, `get_node_hierarchy_info()` returns null
(`tree.class.php:225`), so:

1. `$dummy['parent_id']` emits `E_WARNING: Trying to access array offset on null`.
2. `$parent` becomes `null`.
3. `requirement_spec_mgr::get_by_id($parent)` is called with `null`
   (`requirement_mgr.class.php:3436`), and `get_by_id()` calls
   `get_last_child_info($id)` (`requirement_spec_mgr.class.php:185`).
4. `get_last_child_info($id)` (`requirement_spec_mgr.class.php:2165`)
   interpolates the raw `$id` into `AND NH.parent_id = {$id}` without any
   validation → an empty `id` produces `NH.parent_id = ` → SQL syntax error 1064.

## Fix

Two minimal, defensive guards — no refactoring:

**1. `lib/functions/requirement_mgr.class.php` — `getTestProjectID()`**

Guard the `$dummy` result from `get_node_hierarchy_info()` before reading
`parent_id`, and return `null` (instead of crashing) when the project cannot
be derived, and tolerate a missing `testproject_id` in the target:

```php
$parent = $reqSpecID;
if( is_null($parent) )
{
  $dummy = $this->tree_mgr->get_node_hierarchy_info($id);
  if( !is_null($dummy) && isset($dummy['parent_id']) )
  {
    $parent = $dummy['parent_id'];
  }
}
if( is_null($parent) )
{
  // id did not resolve to a requirement spec => cannot derive project
  return null;
}
$target = $reqSpecMgr->get_by_id($parent);
return isset($target['testproject_id']) ? $target['testproject_id'] : null;
```

**2. `lib/functions/requirement_spec_mgr.class.php` — `get_last_child_info()`**

Defense in depth for **every** caller: reject an invalid `$id` (null / empty /
non-numeric / <= 0) and an unknown `child_type` before building the SQL, so a
broken `NH.parent_id = <empty>` clause can never be emitted:

```php
$child_type = $my['options']['child_type'];
if( !isset($target_cfg[$child_type]) || is_null($id) || $id === '' || !is_numeric($id) || intval($id) <= 0 )
{
    return null;
}
```

### Why this method (alternatives rejected)

- **Guard at the source (`getTestProjectID`)** — removes both the E_WARNING
  and the null-`$parent` propagation in one place. Rejected alternative: only
  fixing `get_last_child_info` would have silenced the SQL error but left the
  `E_WARNING Trying to access array offset on null` intact.
- **Early-return in `get_last_child_info`** — protects all 6+ call sites in
  the requirement classes from ever passing an empty id into interpolated SQL.
  Rejected alternative: casting/escaping `$id` inside the SQL still tried to
  execute a query with a `parent_id` that does not exist, which is semantically
  wrong and still emits a (harmless) DB round-trip; returning `null` is the
  correct "no such child" signal that callers already handle.

## Verification

- **Negative (bug) path:** `$reqMgr->getTestProjectID(999999)` → returns
  `NULL`; `events` table gains **0** ERROR / **0** E_WARNING rows (previously
  2 rows per call).
- **Defense-in-depth:** `get_last_child_info(null|''|'abc'|0|-5)` → all return
  `null`; `events` table gains **0** rows.
- **Positive (regression) path:** created test project + top-level req spec
  `'Order Management Spec'` + requirements RM-001..003 (exact seed scenario) —
  all created successfully, `getTestProjectID(valid req)` still returns the
  correct `testproject_id`; only the expected audit `CREATE` event (log_level=16),
  zero ERROR/WARNING.
- `php -l` passes on both modified files.
- Code review: PASS (correct, minimal, no regressions, no i18n needed — pure
  backend guards).
- 4 regression test cases in `tmp/TLU_Test_Cases.md` (suite 765) — all PASS.

## Files Changed

- `lib/functions/requirement_mgr.class.php` (`getTestProjectID`, +8/-1)
- `lib/functions/requirement_spec_mgr.class.php` (`get_last_child_info`, +7)

## Commit

- `235a31425` — fix(requirements): guard getTestProjectID + get_last_child_info against null/empty ids — Refs #765
- `7ac1332c6` — test: regression suite 765 — Refs #765
