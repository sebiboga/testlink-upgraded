# TC Print Without Project Context Fix

## Issue #552

Fixed: requesting the **single test case print popup**
(`lib/testcases/tcPrint.php`) with a **valid** `testcase_id` while the
session carries no project (`$_SESSION['testprojectID']` unset) and the URL
has no `tproject_id` parameter produced a **broken page**: HTTP 500 with a
raw `debug_print_backtrace()` dump instead of the printable test case.

This is the fresh-session deep-link scenario: e.g. a bookmark or an external
tool linking straight to

```
/lib/testcases/tcPrint.php?testcase_id=1002&tcversion_id=2002
```

## Symptom

The response body was only:

```
<pre>#0 .../lib/functions/print.inc.php(2326): testproject->get_by_id(0)
#1 .../lib/functions/print.inc.php(951): initStaticRenderTestCaseForPrinting(...)
#2 .../lib/testcases/tcPrint.php(64): renderTestCaseForPrinting(...)
</pre>
```

plus HTTP status 500. No printable content at all.

## Root Cause

* `tcPrint.php::init_args()` resolves the project id from
  `$_SESSION['testprojectID']`, then falls back to the `tproject_id`
  request parameter. In the deep-link scenario **both are absent**, so
  `$args->tproject_id` stays `0`.
* The rendering pipeline passes that `0` down as
  `$context['tproject_id']`; `initStaticRenderTestCaseForPrinting()`
  (`lib/functions/print.inc.php`) called
  `testproject::get_by_id($tprojectID)` unconditionally.
* `testproject::get_by_id()` treats a *falsy* id as fatal:
  `if (null == $id)` — and under PHP 8 the loose comparison
  `null == 0` is **true** — so it echoed a backtrace and threw
  `Exception: test project ID, is mandatory`, which nothing in the print
  flow catches → HTTP 500.

A valid test case always belongs to exactly one test project, so there was
never a reason to render without resolving it first.

## Fix Approach — resolve the real owner, then guard the shared renderer

Considered alternatives:

1. **Guard `$info` only** (`is_null()` check around
   `$info['issue_tracker_enabled']`) — stops the crash but renders the test
   case *without its project context*: wrong prefix source, issue tracker
   interface silently skipped. Rejected as primary fix.
2. **Derive the project id from the tree path in `tcPrint.php`** and keep a
   defensive guard in the shared renderer. Chosen: the deep link gets the
   *correct* full context (prefix, custom fields, issue tracker), and any
   other caller that ever passes `tproject_id=0` degrades gracefully instead
   of throwing.

Changes:

* `lib/testcases/tcPrint.php`: after validating the test case node, when
  `$args->tproject_id <= 0` derive the owning project from
  `tree::get_path($args->tcase_id)`. `get_path()` **excludes the tree root**,
  so element `[0]` is the child of the root and its `parent_id` is the test
  project id — the exact convention `testcase::getPrefix()` already uses.
* `lib/functions/print.inc.php` (`initStaticRenderTestCaseForPrinting()`):
  call `get_by_id()` only when `$tprojectID > 0`, and null-guard `$info`
  before reading `issue_tracker_enabled`.

## Verification (regression suite 37 in `tmp/TLU_Test_Cases.md`)

| Scenario | Before | After |
|---|---|---|
| Valid tc, no project context | HTTP 500 + backtrace dump | HTTP 200, correct page `FIX-1002` |
| Valid tc directly under project root | same crash | HTTP 200, correct page `FIX-1003` |
| Invalid testcase id | clean message (#541) | unchanged |
| Normal browser flow (session has project) | worked | unchanged |
| Event Viewer | n/a | zero new Error/Warning entries |

## Known adjacent gaps (filed separately, not fixed here)

* [#553](https://github.com/sebiboga/testlink-upgraded/issues/553) —
  `tlIssueTracker::getInterfaceObject()` array-offset-on-null when
  `issue_tracker_enabled=1` but no tracker linked.
* [#554](https://github.com/sebiboga/testlink-upgraded/issues/554) —
  `testproject::get_by_id()` warns on positive-but-nonexistent ids
  (`$result[0]` on null).

## Screenshots


