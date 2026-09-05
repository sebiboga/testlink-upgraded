# Issue #1011 — login as user without any test-project context logs SQL error + PHP warnings (getTestCasePrefix empty id)

**Issue:** [#1011](https://github.com/sebiboga/testlink-upgraded/issues/1011)
**Commit:** `abb99067e` (fix)
**Status:** VERIFIED-FIXED (2026-09-05)

## Symptom

Logging in as a user without any test-project context (role `<no rights>` on a fresh DB,
or any flow that hands `testcase::getPrefix()` an unresolvable node id) logs two event
groups into the `events` table:

```
log_level=2  GUI       E_WARNING\nTrying to access array offset on null - in .../lib/functions/testcase.class.php - Line 2172
log_level=1  DATABASE  ERROR ON exec_query() - database.class.php <br />1064 -
            /* Class:testproject - Method: getTestCasePrefix */ SELECT prefix FROM testprojects WHERE id = (empty)
```

The SQL 1064 then aborts the request with the TestLink "DB Access Error" debug backtrace.

## Investigation

Reproduced on a fresh DB with a fixture user `noview` (id=2, `role_id=3` = `<no rights>`,
no project role, only private test project) plus a pure code-level repro that exercises
exactly the failing sequence (`testcase::getPrefix(null, null)` and
`testcase::getPrefix(99999999, null)`). The plain `index.php` landing of a fresh DB does
not fire it because `initProject()` always commits the first project into the session;
the exception is a session/context where the id handed to `getPrefix()` cannot be
resolved to a tree path (empty/forged/nonexistent id, or no project access).

Measured events reproductions are logged in the investigation comment on the issue.

## Root cause chain

1. `lib/functions/testcase.class.php:2166` — `getPrefix($id, $tproject_id = null)`.
2. When `$tproject_id` is null: `$path2root = $this->tree_manager->get_path($id)` —
   `testcase.class.php:2171`.
3. `tree_manager->get_path()` (`lib/functions/tree.class.php:408`) returns `null`/`[]`
   whenever `_get_path()` finds no node (`tree.class.php:462-475`: a node missing from
   `nodes_hierarchy` sets `$node_list = null`).
4. Unconditional `$root = $path2root[0]['parent_id']` — `testcase.class.php:2172` →
   PHP 8 E_WARNING "Trying to access array offset on null".
5. `$root` stays `null` → `$this->tproject_mgr->getTestCasePrefix(null)` —
   `testcase.class.php:2174`.
6. `testproject::getTestCasePrefix($id)` (`lib/functions/testproject.class.php:998-1004`)
   interpolates raw: `WHERE id = {$id}` ⇒ `WHERE id = ` ⇒ MySQL 1064, request dies.

Blast radius: every caller that may pass a null id or lack project context —
`testcaseCommands.class.php:576,619`, `xmlrpc.class.php:4720`,
`testplan.class.php:647,5868,6778,6860`, `print.inc.php:2357` (legacy print path), plus
internal callers at `testcase.class.php:2328,2978,2997,5717,7308`. The issue was surfaced
by the Test Case Print permission-path testing (Refs #1010).

## Fix (minimal, defensive — matches the issue suggestion)

Guard `getPrefix()` so the empty-tree-path case is handled before any dereference:

```php
$path2root = $this->tree_manager->get_path($id);
// No project context (user without any test-project access) or a
// nonexistent node id: get_path() returns null/empty. Bail out instead
// of indexing $path2root[0] (E_WARNING) and running
// getTestCasePrefix(null) which builds "WHERE id = " (SQL 1064).
if (is_null($path2root) || count($path2root) == 0) {
  return array(null, null);
}
$root = $path2root[0]['parent_id'];
```

Returning `array(null, null)` mirrors the pre-existing "project with no prefix" shape
(`getTestCasePrefix()` already returns `null` for a prefix-less project), and all callers
treat a null first element as "no prefix" (they concatenate `$pfx[0] . glue . external_id`).
The fast path (`$tproject_id` given) and the valid deep-link path (non-empty path) are
byte-identical to before.

## Why this method (alternatives rejected)

- Not fixing `getTestCasePrefix()` itself to validate `$id`: it would mask contract
  violations call-site by call-site; the abort must happen at the point where the
  project context is *derived*, i.e. `getPrefix()`.
- Not forcing a default project: that would silently grant context a user does not have
  and could diverge from `hasRight()` outcomes.
- Not a bigger refactor of `getPrefix()`/path resolution — a targeted null-guard matches
  the project's established defensive pattern (see `tlUser.class.php`, `navBar.php`
  guards from previous bugfixes).

## Verification

- `getPrefix(null, null)` and `getPrefix(99999999, null)` → `array(NULL, NULL)`, **zero**
  WARNING/ERROR rows in `events`.
- `getPrefix(102, null)` (valid deep link, fixture project 100/suite 101/tc 102) →
  `array('FIXTURE', '100')` — derivation unchanged.
- `getPrefix(102, 100)` (fast path) → `array('FIXTURE', 100)` — unchanged.
- Login as `noview` (no project context) → landing frameset renders, `events` shows only
  INFO AUDIT rows.
- Modern Test Case Print screen as `noview` (BFF `action=tc_print&testcase_id=0`) → HTTP
  400, no events.
- `php -l lib/functions/testcase.class.php` passes. No i18n strings touched.
- Event Viewer / `events` table after all regression runs: no new Error/Warning rows.
- Regression suite `TC-1011.1`..`TC-1011.7` appended to `tmp/TLU_Test_Cases.md`
  (**7/7 PASS**).

## Screenshots

- After fix — Event Viewer clean: `docs/screenshots/issue-1011-eventviewer-clean.png`

## Files changed

- `lib/functions/testcase.class.php` (+9/−0) — empty-tree-path guard in `getPrefix()`.
- `tmp/TLU_Test_Cases.md` — regression suite `TC-1011.*` (7/7 PASS, local file).
- `docs/screenshots/issue-1011-eventviewer-clean.png` — evidence.

## Follow-up

- Sureface of the same bug class found during review in dead code:
  **#1016** — `formatTestCaseIdentity()` references undefined `$tc_id` and unguarded
  `$path2root[0]` (unreachable, filed separately).