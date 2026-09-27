# Bugfix — Issue #1656: `requirement_spec_mgr` still read the pre-2.0.1 `req_specs` shape and every call died on SQL 1054

**Files:** `lib/functions/requirement_spec_mgr.class.php`, `lib/requirements/reqSpecCommands.class.php`
**Fixed by:** `c39d50b6a` (the fix), `324007532` (code-review follow-up)
**Verified:** 2026-09-27 — fresh database, 22-case regression suite (exit 0), browser-verified
in headless Chrome as `admin`; see `tmp/TLU_Test_Cases.md` § *Regression — Issue #1656*

Screenshots:

* `docs/screenshots/issue-1656-legacy-reorder-dberror.png` — **before**: the legacy
  "Change Requirement Specifications order" screen answers with a raw
  `DB Access Error` backtrace page.
* `docs/screenshots/issue-1656-legacy-reorder-fixed.png` — **after**: the drag & drop
  tree with both specifications listed.
* `docs/screenshots/issue-1656-srs-create-duplicate-rejected.png` — **after**: the
  duplicate-title guard that used to `die()` now rejects a repeated title/doc_id.

---

## 1. Symptom

`requirement_spec_mgr::get_all_in_testproject()` and
`requirement_spec_mgr::get_by_title()` **could not execute at all** on the 2.0.1 schema.
Both `SELECT` column lists named seven columns that do not exist in `req_specs`, so every
call raised

```
1054 - Unknown column 'RSPEC.scope' in 'SELECT'
```

and — this is the part that matters more than the issue reported —
`database::exec_query()` calls **`die()`** on a failed query
(`lib/functions/database.class.php:224`), so the caller did not get an empty list, it got
a hard stop.

Two live consequences, both measured:

| # | screen | pre-fix behaviour |
|---|---|---|
| 1 | **Reorder requirements specifications** — `lib/requirements/reqSpecEdit.php?doAction=reorder&tproject_id=N` | the raw `DB Access Error` backtrace page, leaking the absolute repository path (CWE-200). No tree at all. |
| 2 | **Create / Edit requirements specification** — `…?doAction=create|edit&tproject_id=N` | *wider than the issue stated.* `check_title()` → `check_main_data()` → `create()` / `update()` all route through `get_by_title()`, so **every SRS write died too** — and with it, the **duplicate-title check was never enforced**: `check_title()` never got to return its verdict. |

Plus one `log_level=1 / source=DATABASE` row in the `events` table per call — five error
events for five method calls, straight into the Event Viewer.

Measured evidence (pre-fix, `events`):

```
ERROR ON exec_query() - database.class.php <br />1054 - Unknown column 'RSPEC.scope' in 'SELECT'
- /* Class:requirement_spec_mgr - Method: get_by_title */
  SELECT RSPEC.id,testproject_id,RSPEC.doc_id,RSPEC.scope,RSPEC.total_req,RSPEC.type,
  RSPEC.author_id,RSPEC.creation_ts,RSPEC.modifier_id, RSPEC.modification_ts,NH.name AS title
  FROM req_specs RSPEC, nodes_hierarchy NH WHERE NH.name='SRS Alpha' AND RSPEC.id=NH.id
  AND RSPEC.testproject_id=2 AND RSPEC.id=NH.id
Query failed: errorcode[1054]
```

## 2. Root cause — schema drift, two methods left behind by a half-finished migration

On 2.0.1 the identity of a requirement specification and its *content* live in two tables:

```
$ mysql … -e "desc req_specs;"
id, testproject_id, doc_id                          <-- identity only

$ mysql … -e "show create table req_specs_revisions;"
parent_id, id, revision, doc_id, name, scope, total_req, status, type,
log_message, author_id, creation_ts, modifier_id, modification_ts
                              ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                              the 7 "missing" columns live HERE
```

`requirement_spec_mgr` was **half-migrated** to that layout. Its own
`get_by_id()` (`:189-215`) and `getByDocID()` (`:1799-1820`) already join
`req_specs_revisions` and read the content from there:

```sql
-- get_by_id(), :189-215 — already correct
FROM req_specs RSPEC
JOIN req_specs_revisions RSPEC_REV ON RSPEC_REV.parent_id = RSPEC.id
JOIN nodes_hierarchy NH_RSPEC      ON RSPEC.id = NH_RSPEC.id
…
SELECT … RSPEC_REV.scope, RSPEC_REV.total_req, RSPEC_REV.type, RSPEC_REV.author_id …
```

`get_all_in_testproject()` (`:362`) and `get_by_title()` (`:798`) are the **two methods
that were missed** — same class, same table, two different eras of schema assumption. They
read `RSPEC.scope`, `RSPEC.total_req`, `RSPEC.type`, `RSPEC.author_id`, `RSPEC.creation_ts`,
`RSPEC.modifier_id`, `RSPEC.modification_ts` off `req_specs`.

A **third** defect sat in the same statement, in the only caller:

```php
// lib/requirements/reqSpecCommands.class.php:342  (the one and only caller)
$order_by = ' ORDER BY NH.node_order,REQ_SPEC.id ';
//                                  ^^^^^^^^ the query's alias is RSPEC, not REQ_SPEC
```

so even with the columns present the caller would have failed with
`1054 - Unknown column 'REQ_SPEC.id' in 'ORDER BY'`.

This is not a recent regression: on the 2.0.1 schema these two methods have never been
reachable. It only *looked* like a rendering bug because the pre-2.0.1 schema did have those
columns on `req_specs`.

### Blast radius (measured, not assumed)

| caller | reachable from the UI? | evidence |
|---|---|---|
| `reqSpecCommands::reorder()` → `get_all_in_testproject()` | **yes** — `project_req_spec_mgmt.tpl:14` | browser repro above |
| `check_title()` → `get_by_title()` | **yes** — `create()` `:124`, `update()` `:410` | the SRS create/edit path |
| `get_metrics()` `:308` → `SELECT total_req FROM req_specs` | **no** — 0 references repo-wide | same drift, dead code → filed as **#1657** |

Only consumers of the returned rows are `reqSpecReorder.tpl` (dashio and tl-classic), which
read `.id` and `.title`. No modernized screen under `gui/templates/*/` or `api/**` calls
these two methods.

## 3. The fix

Both methods now join the **latest revision** of each specification, in the exact form the
modernized BFF already ships (`api/reqreorder/index.php:157`):

```php
// get_all_in_testproject() / get_by_title()
" FROM req_specs RSPEC " .
" JOIN nodes_hierarchy NH ON NH.id = RSPEC.id " .
" LEFT JOIN req_specs_revisions RSPEC_REV " .
" ON RSPEC_REV.parent_id = RSPEC.id " .
" AND RSPEC_REV.revision = (SELECT MAX(RSPEC_REV2.revision) " .
" FROM req_specs_revisions RSPEC_REV2 " .
" WHERE RSPEC_REV2.parent_id = RSPEC.id) "
```

plus, in `get_by_title()`, the removal of a duplicated `AND RSPEC.id=NH.id` that was
emitted twice (old `:806` and `:819`), now expressed once as the `JOIN` condition.

And in the caller, `lib/requirements/reqSpecCommands.class.php:342`:

```diff
-$order_by = ' ORDER BY NH.node_order,REQ_SPEC.id ';
+$order_by = ' ORDER BY NH.node_order,RSPEC.id ';
```

### Why these choices

* **`MAX(revision)`, not `MAX(revision_id)`.** The first iteration of the fix used
  `MAX(id)`, matching the sibling `getByDocID()`. Code review pointed out that
  `get_by_id()` (via `get_last_child_info()`, `:2203`) and the modernized BFF
  (`api/reqspec/index.php:335,880`, `api/reqreorder/index.php:157`) resolve
  `MAX(revision)`. The two agree only as long as revision-row id order matches
  revision-number order — and when it does not, the **legacy screen and the modernized
  screen show a different `scope` / `total_req` for the same specification**. Switching to
  the correlated `MAX(revision)` subquery makes all four agree.
* **Correlated subquery, not a `GROUP BY parent_id` derived table.** `EXPLAIN` showed the
  derived-table form building a `DERIVED` node — a full index scan plus `Using temporary;
  Using filesort` over *all* of `req_specs_revisions` across *all* test projects, on every
  reorder page load, because `RSPEC.testproject_id = N` cannot be pushed into an
  aggregating derived table. The correlated form is evaluated per outer row and walks the
  unique `(parent_id, revision)` index (`req_specs_revisions_uidx1`) instead.
* **`LEFT JOIN`, not `JOIN`.** Deliberate: `create()` (`:137-158`) inserts the `req_specs`
  row **and** the `nodes_hierarchy` node *before* `create_revision()`, and returns
  `id = -1` when the revision insert fails. A specification with no revision row is
  therefore reachable in production, and it must stay visible in the reorder list exactly
  as it was before the revision split. Covered by regression cases 15/15b/15c.
* **Aliases `RSPEC` and `NH` preserved**, so the caller's `ORDER BY NH.node_order` keeps
  working and no template change is needed.
* **Docblocks completed** with the keys the queries now return (`doc_id`, `revision`,
  `node_order`) and a note that the `order_by` argument is raw SQL interpolated verbatim and
  must be a trusted literal — a pre-existing sink the next caller could otherwise trip over.
* **No i18n, BFF or template change.** Every message still comes from the pre-existing
  `lang_get()` keys; no `gui/templates/i18n/*.json` bundle was touched.

### Alternatives rejected

* **Re-add the 7 columns to `req_specs`.** Rejected: 2.0.1 deliberately moved per-revision
  data into `req_specs_revisions`; re-adding it would fork the data and break revision
  history. The already-migrated sibling methods in the same class prove the target shape.
* **Wrap the two calls in `try`/`catch`, or gate them on `DBUG_ON`.** Rejected: it hides
  the fault and keeps the wrong schema assumption; the screens would render empty, which is
  the symptom the issue already describes.
* **Delete the two methods as dead legacy code.** Rejected: both are live (reorder screen
  *and* the SRS create/edit path), and both have a documented return contract at `:339-361`
  and `:788-796`.

## 4. Verification

### Regression suite — 22 passed, 0 failed, 1 skipped (exit 0)

`php tmp/verify_1656.php <tproject_id>` on a fresh database. Full table in
`tmp/TLU_Test_Cases.md`; the highlights:

| case | check | result |
|---|---|---|
| 1, 1b, 1c | `get_all_in_testproject()` returns both specs, every documented key present | PASS |
| 2 | the reorder `order_by` still sorts by `node_order` (`10,20`) | PASS |
| 3 | a project without specifications returns `null`, **no** error event | PASS |
| 4 | `get_by_title('SRS Alpha')` returns **revision 2** with `scope="…rev2"`, not the stale revision 1 | PASS |
| 6 | an unknown title returns `null` | PASS |
| 7, 8 | `check_title()` rejects a duplicate, accepts a free title | PASS |
| 9, 10a, 10b, 11, 12, 14 | `create()` → `update()`/rename → list → `delete()` lifecycle, incl. re-ordering | PASS |
| 13, 13b | the already-migrated `get_by_id()` is unchanged | PASS |
| 15, 15b, 15c | a spec with **no** revision row is still listed and still blocks its title | PASS |
| 16 | legacy method and the modernized BFF agree on revision + scope | PASS (browser) |

### Live browser

* `reqSpecEdit.php?doAction=reorder&tproject_id=N` → `hasDBErr=false`, tree items
  `["", "SRS Alpha", "SRS Beta"]`.
* `reqSpecEdit.php?doAction=create&tproject_id=N` → filled the **real** legacy form and
  clicked **Create SRS** → *"Requirement Specification: SRS Via Real UI was successfully
  created"*; the `req_specs` and `req_specs_revisions` rows are written and an
  `audit_req_spec_created` event is recorded.
* Submitting the same title **and** doc_id again → *"There's already a req. spec (title:SRS
  Via Real UI) with this doc id (DOC-1656-UI)"*, `req_specs` unchanged — the uniqueness
  guard that used to `die()` is now actually enforced.
* Cross-layer check (case 16):
  `GET /api/reqspec/index.php?action=specs&tproject_id=N` returns
  `{id:9015, revision:2, scope:"scope of SRS Alpha rev2"}` and the legacy method returns the
  identical `revision` and `scope`.

### Event Viewer

```
pre-fix :  select count(*) from events where source='DATABASE';   -> 5   (one per call)
post-fix:  select count(*) from events where source='DATABASE';   -> 0
post-fix:  select log_level, source, count(*) from events group by log_level, source;
              16 | GUI - Test Project ID : <tp> | 1     (the healthy audit INFO only)
```

### Syntax gates

`php -l lib/functions/requirement_spec_mgr.class.php`,
`php -l lib/requirements/reqSpecCommands.class.php` — both
`No syntax errors detected`.

## 5. Defects found while testing — filed, not fixed

| issue | what |
|---|---|
| **#1657** | `requirement_spec_mgr::get_metrics()` (`:318`) still does `SELECT total_req FROM req_specs` — the identical schema drift. 0 callers repo-wide, so currently dead code, but a latent `die()` for whoever calls it next. |
| **#1658** | the legacy **create** screen logs two `E_WARNING` rows per load: `Undefined property: stdClass::$tproject_id` / `::$tplan_id` at `reqSpecEdit.tpl` lines 203/205 — `reqSpecCommands::doCreate()` never sets those gui keys, unlike `reorder()` which does. Render-time noise that makes the Event Viewer unusable for triage. |
| **#1659** | `install/sql/postgres/testlink_create_tables.sql:788` creates `req_specs_revisions_uidx1` on the **wrong table** (`req_revisions`), so on PostgreSQL the `(parent_id, revision)` uniqueness that every latest-revision query leans on is not enforced. MySQL is correct. |
