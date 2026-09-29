# Bugfix — Issue #1705: `getReqsOnSpecNotLinkedToLatestTCV()` returned the inverted set

**Branch:** `fix/issue-1705`
**Files changed:** `lib/functions/requirement_spec_mgr.class.php`, `CHANGELOG`,
`tmp/TLU_Test_Cases.md` (suite *"Regression — Issue #1705"*)
**Severity:** major (data correctness in a public class API) — but **no 2.0.1
screen was broken**: the modernized Assign Requirements grids build the same
list in `api/reqtcassign::freeRows()`, so this was a *latent* defect inherited
from upstream 1.9.18.

---

## Symptom

`requirement_spec_mgr::getReqsOnSpecNotLinkedToLatestTCV($specId, $tcaseId)`
— "the requirements of this specification that are **not** linked to the latest
active test case version" — returned exactly the inverse of its contract:

- a requirement that **is** linked to the test case was listed as available
  (with a readable title), and
- a requirement that is **not** linked was listed with a **NULL** title (and
  NULL `scope` / NULL `version`).

Measured on a 1-linked + 1-free fixture (project `RS1705`, spec 6, test case 3,
latest active tcversion 4, `REQ-1705-A` id 8 linked, `REQ-1705-B` id 10 free):

```
=== getReqsOnSpecNotLinkedToLatestTCV(spec=6, tcase=3) ===   # pre-fix
rows returned: 2
  [0] id='8'  req_doc_id='REQ-1705-A' title='Requirement A (linked) [v1] ' scope='scope of A' version='1'
  [1] id='10' req_doc_id='REQ-1705-B' title='NULL'             scope='NULL'              version='NULL'
```

After the fix:

```
rows returned: 1
  [0] id='10' req_doc_id='REQ-1705-B' title='Requirement B (free) [v1] ' scope='scope of B' version='1' can_be_deleted='0'
```

---

## Root cause — two independent defects in one statement

`lib/functions/requirement_spec_mgr.class.php:2689` (pre-fix line numbers), the
whole method body was one `SELECT`:

```php
" FROM nodes_hierarchy NH_REQ " .
" JOIN requirements REQ ON REQ.id = NH_REQ.id " .
" LEFT JOIN req_coverage RCOV ON RCOV.req_id = NH_REQ.id " . $tcversionJoin .
" LEFT JOIN req_versions REQVER ON REQVER.id = RCOV.req_version_id " .   // (a)
" WHERE NH_REQ.parent_id=" . intval($id) .
" AND NH_REQ.node_type_id = …"                                          // (b) no exclusion
```

### A. the requirement version was taken through the coverage row

`REQVER` was joined `ON REQVER.id = RCOV.req_version_id`. For a requirement that
is *not* linked, `RCOV.req_version_id` is `NULL`, so `REQVER.scope` and
`REQVER.version` are `NULL`, and

```sql
CONCAT(NH_REQ.name,' [v', REQVER.version,'] ') AS title
```

evaluates to `NULL` — in SQL `CONCAT` is NULL as soon as **one** argument is
NULL. The same NULL drove

```sql
(CASE WHEN REQVER.version IS NULL THEN 1 ELSE 0 END) AS can_be_deleted
```

so the flag marked exactly the *healthy*, unlinked requirements as deletable.

### B. nothing removed the linked requirements

`req_coverage` was attached with a `LEFT JOIN` and no `NOT EXISTS`, no
`IS NULL` test and no `link_status` restriction anywhere in the statement, so a
requirement that **is** linked survived the join. A second, quieter consequence:
the one-row-per-requirement invariant was lost — a requirement linked to *N*
test cases came back *N* times.

The same defect reproduced at SQL level (the method's own statement, run in the
`mysql` client; `node_type_id` 7 = requirement per the `node_types` table):

```
id  scope        title                          req_doc_id    version  can_be_deleted
8   scope of A   Requirement A (linked) [v1]    REQ-1705-A    1        0
10  NULL         NULL                           REQ-1705-B    NULL     1
```

`git log -S` dates the method to `cb365cd5e` *"CRITICS CHANGES TO DB SCHEMA -
Pretest for official release 1.9.18"* — imported verbatim from upstream and
never called inside this fork, which is why it survived.

**A third, smaller defect in the same method:** the options were merged from
the wrong variable, `(array)$options`, while the parameter is `$opt`. Measured
consequences: the caller's options were dropped, and *every* call raised
`E_WARNING "Undefined variable $options"` — captured as Event Viewer `events`
id 2 by the pre-fix reproduction run.

---

## The fix

Confined to `getReqsOnSpecNotLinkedToLatestTCV()`:

1. **the version comes from the requirement itself** —
   `LEFT JOIN latest_req_version_id LRQV ON LRQV.req_id = NH_REQ.id` +
   `LEFT JOIN req_versions REQVER ON REQVER.id = LRQV.req_version_id`. This is
   the primitive `getAllLatestRQVOnReqSpec()` (`:2853`) already uses, so the
   `LEFT` is kept and `can_be_deleted = 1` regains its only legitimate meaning:
   a requirement with **no version row at all** (an orphan), i.e. nothing to link.
2. **the linked requirements are excluded explicitly** —
   `AND NOT EXISTS ( SELECT 1 FROM req_coverage RCOV WHERE RCOV.req_id =
   NH_REQ.id AND RCOV.is_active = 1 AND RCOV.tcversion_id = <ltcv> AND
   RCOV.link_status IN (1,2) )`. `is_active = 1` is required for correctness:
   `testcase::updateCoverage()` (`testcase.class.php:9017-9020`) and
   `requirement_mgr::updateCoverage()` (`:4501-4507`) set `is_active = 0` when a
   new tcversion/reqversion supersedes a link, and the "assigned" sibling
   `getReqsOnSpecForLatestTCV()` already filters on it. Statuses 1 and 2
   (`LINK_TC_REQ_OPEN`, `LINK_TC_REQ_CLOSED_BY_EXEC`, `cfg/const.inc.php:959-962`)
   are the live links; 3/4 are only ever written together with `is_active = 0`,
   and the list matches the "assigned" grid of `api/reqtcassign`.
3. the `$tcase_id === null` branch keeps the original "no test case can be
   chosen here" semantics by joining `latest_tcase_version_id` **inside** the
   subquery — a requirement linked to the latest version of *any* test case is
   excluded.
4. `(array)$options` → `(array)$opt`, plus a docblock stating the contract, the
   column set and the meaning of `can_be_deleted`.
5. `current((array)get_last_active_version())` + an `is_array()` guard: with no
   active version `current(null)` is `false`, the old code raised
   `Trying to access array offset on value of type bool` and `$ltcv` became `0`,
   which made the subquery match nothing and reported *every* requirement as
   free.

The public output shape is **unchanged** — `id, scope, title, req_doc_id,
version, can_be_deleted`, same order — so any external caller keeps working.
`$my['options']['order_by']` and `$my['filters']` remain unused by the
statement exactly as in 1.9.18 (left alone on purpose: wiring `order_by` in
would change the row order for callers outside this repo).

Comment-only sibling: the `// null => do not filter` comment above
`getReqsOnSpecForLatestTCV()`'s `array('link_status' => 1, …)` default
contradicted the code; the default is now documented and the **behaviour is
unchanged** (both in-repo callers pass the list explicitly).

### Methods considered and rejected

| alternative | why rejected |
|---|---|
| lift `api/reqtcassign::freeRows()` into the class (the report's suggestion) | PHP-side set arithmetic over 3 extra queries, and it drops the orphan `can_be_deleted` case; the SQL fix reaches the same result in one query |
| `get_requirements()` + `array_diff_byId()` in PHP (what 1.9.20's `reqTcAssign` did) | needs the full requirement list as a second query and re-introduces O(n) set logic that `NOT EXISTS` does natively |
| drop the `is_active` filter (as the shipped `freeRows()` does) | would keep superseded links counted as links and hide re-linkable requirements |
| change `getReqsOnSpecForLatestTCV()`'s `link_status` default | both in-repo callers override it; silently changing a public method's filter for hypothetical external callers is out of scope for a minimal fix |
| change the returned column set / add `req_version_id` | not needed to fix the defect; the contract is public |

---

## The code review that caught a BLOCKER in this very diff

The mandatory review (rule 16) found that the comment edit had landed in
`get_requirements()` instead of `getReqsOnSpecForLatestTCV()` — the file
contains three byte-identical option blocks and my `oldString` matched the
first. That changed *that* method's filter default to
`array('link_status' => 1, …)`, which `requirement_mgr::get_by_id()`
(`requirement_mgr.class.php:164-174`) turns into a raw `AND link_status = '1'`
against `requirements` / `nodes_hierarchy` / `req_versions` / `req_specs` —
none of which has that column. Measured, deliberately, before the revert:

```
1054 - Unknown column 'link_status' in 'WHERE' - SELECT NH_REQ.id FROM …   (events 9, 10)
```

That would have broken `GET /api/requirements/index.php/assign-reqs` (the Assign
Requirements data of `tcEdit.html`, `testSpec.html`, `tcView.html`,
`assignReqs.html`), `api/reqdoc`, `api/reqexport`, `api/reqcreatetestcases`,
`reqSpecCommands`, `reqCommands`, `reqSpecPrint`, `reqExport` and `planAddTC` on
every call. Reverted byte-identical, the comment moved to the method it
describes, and a permanent guard (`get_requirements()` still returns its rows)
was added to the matrix. Live negative control after the revert:

```json
{"assign_reqs": {"assigned":["REQ-1705-A"],"unassigned":["REQ-1705-B"]},
 "reqtcassign": {"status":"ok","assigned":["REQ-1705-A"],"free":["REQ-1705-B"]}}
```

The unvalidated filter-key sink that made this possible is filed separately as
**#1709**, the untouched `current(null)` twin in
`getReqsOnSpecForLatestTCV()` as **#1708**.

---

## Verification

`php tmp/verify_1705.php` (re-runnable; restores the seeded `req_coverage` state
and removes its own fixtures) — **19/19 assertions PASS**; suite
*"Regression — Issue #1705"* in `tmp/TLU_Test_Cases.md` — **23/23 PASS**.

| # | case | result |
|---|---|---|
| 1-5 | (spec, tcase) with 1 linked + 1 free | only the free requirement; non-empty `title` / `scope` / `version`; `can_be_deleted = 0` — **PASS** |
| 6 | output shape | exactly the 6 legacy columns, same order — **PASS** |
| 7 | `$tcase_id = null` | linked requirement excluded, free one returned — **PASS** |
| 8 | `is_active = 0` (superseded link) | requirement available again — **PASS** |
| 9 | `link_status = 2` (closed by execution) | still hidden — **PASS** |
| 10 | `link_status = 3` (frozen) | available again — **PASS** |
| 11 | everything linked | empty result set (`NULL`, the legacy `get_recordset` convention) — **PASS** |
| 12-13 | link on another test case | does not hide the requirement for this one; the exclusion is per test case — **PASS** |
| 14 | orphan requirement (no version row) | listed, `can_be_deleted = 1` — **PASS** |
| 15 | baseline after all mutations | back to the case-1 answer — **PASS** |
| 16 | sibling `getReqsOnSpecForLatestTCV()` (what `api/reqtcassign` uses) | unchanged, 1 assigned row — **PASS** |
| 17-18 | `GET /api/reqtcassign/index.php?action=init&tproject_id=1&tcase_id=3` + the modern screen | HTTP 200, 1 assigned / 1 free, **0 console errors/warnings** — **PASS** |
| 19 | Event Viewer | no new Error/Warning row from app usage — **PASS** |
| 20 | `php -l lib/functions/requirement_spec_mgr.class.php` | no syntax error — **PASS** |
| 21 | the `$opt` argument | merged, no warning — **PASS** |
| 22 | `get_requirements()` (the review guard) | 2 rows, no SQL error — **PASS** |
| 23 | test case with no active version | no PHP warning, whole spec available — **PASS** |

**Event Viewer note.** `events` ids 7-10 are this run's *own probe artifacts*
(the deliberate reproduction of the review's BLOCKER above), ids 1-3 predate the
fix (id 2 is the pre-fix `Undefined variable $options` warning, id 3 the admin
LOGIN). Post-fix app usage adds nothing.

**Two expectations in the first draft of the matrix were wrong, not the fix** —
a `NOT EXISTS` result set is not sorted, and `testcase::create()` creates a new
test case rather than a new version of one. The per-case `req_coverage` dump
exposed both; the script was corrected and the fix was not touched.

---

## Out of scope (filed as separate bugs)

- **#1708** — `requirement_spec_mgr::getReqsOnSpecForLatestTCV()` (`:2625`) has
  the identical `current(get_last_active_version())` → `false` defect, still
  warning and emptying the Assigned grid for a test case with no active version.
  Left untouched in this run ("no drive-by changes in a method I did not need to
  touch"); the same guard was applied to the method this issue is about.
- **#1709** — `requirement_mgr::get_by_id()` interpolates the `$filters` **array
  key** raw into SQL: an unvalidated key is an arbitrary WHERE fragment, and a
  mistyped key is a runtime 1054. This sink is what amplified the review's
  BLOCKER into six broken BFF routes.
- `api/reqtcassign::freeRows()` filters coverage rows without
  `RCOV.is_active = 1`, i.e. a superseded link can still hide a requirement from
  the modern "available" grid. The class method fixed here is the reference
  behaviour; aligning `freeRows()` is a follow-up.
