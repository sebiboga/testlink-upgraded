# Bugfix — Issue #1763: `tlUser::hasRight()` never evaluated the private-test-project flag

**Issue:** [#1763](https://github.com/sebiboga/testlink-upgraded/issues/1763) — *tlUser::hasRight() called without
$getAccess in the modern BFFs: the private-test-project flag is never evaluated, so a user with no project role at
all passes the mgt_modify_tc check*

**Severity:** major — authorization bypass (read **and** write) on private test projects
**Fix commit:** `bc0f2295d` · **Branch:** `fix/issue-1763` · **Files changed:** `lib/functions/tlUser.class.php`

---

## 1. Symptom

A user holding **no** role at all on a **private** test project was served that project: its test suite list came
back with `can_modify: "yes"`, and a `POST ?action=reorder` was accepted and rewrote `nodes_hierarchy.node_order`.

The report as filed did not reproduce, because the user it used (`sm1759norights`, global role 3 `<no rights>`) is
already denied for an unrelated reason — see §3.

## 2. Reproduction

Fixture: two **private** test projects (`testprojects.is_public = 0`), each with two top level suites, plus users:

| user | global role | holds | project role |
|---|---|---|---|
| `sm1763td` | 4 `test designer` | `mgt_modify_tc`, `mgt_view_tc`, … | **none** |
| `sm1763guest` | 5 `guest` | `mgt_view_tc` | **none** |
| `sm1759norights` | 3 `<no rights>` | — | **none** |
| `sm1759a` | 3 | — | `mgt_modify_tc` on project 1 |
| `admin` | 8 `admin` | everything | **none** |

```
GET  http://localhost:8082/api/suitemove/index.php?action=init&tproject_id=1&container_id=1
POST http://localhost:8082/api/suitemove/index.php?action=reorder&tproject_id=1&container_id=1
```

Measured **before** the fix, on the private project 1:

| caller | `init` | `reorder` |
|---|---|---|
| `sm1763td` | **200** `can_modify:"yes"` | **200** `{"status":"ok","changed":true}` — wrote `node_order` |
| `sm1763guest` | 403 | 403 |
| `sm1759norights` | 403 | 403 |
| `admin` | 200 | 200 |

## 3. Root cause

```
lib/functions/tlUser.class.php:808   hasRight(&$db,$roleQuestion,$tprojectID,$tplanID,$getAccess=false)
lib/functions/tlUser.class.php:824   $accessPublic is populated ONLY if ($getAccess)
lib/functions/tlUser.class.php:875   else { if(!is_null($accessPublic) && $accessPublic['tproject'] == 0) return false; }   <-- DEAD
lib/functions/tlUser.class.php:841   $allRights = the GLOBAL right set
lib/functions/tlUser.class.php:901   checkForRights($allRights,$roleQuestion)
api/suitemove/index.php:382          !$user->hasRight($db, 'mgt_modify_tc', $tprojectId)   // 3 args
```

The accessibility flag was **opt-in**: only the 5-argument form filled it. Every modern BFF uses the 3-argument
form (100+ call sites), so `$accessPublic` stayed `null`, the denial at `:875` could never fire, and a caller with
no `user_testproject_roles` row fell through to their **global** rights.

Two corrections to the original report, both material:

* **`$allRights` degrades to an empty array, not `null`** (`:841-847`). That is why the reported
  `<no rights>` user was already denied: role 3's global set is empty, so `checkForRights(array(), …)` is false.
  The hole only opens for a user whose **global** role holds the right — roles 4, 5, 6, 7 and 9. That is the
  common case, not the corner case.
* **The missing admin exception is as important as the missing evaluation.** `admin` holds no
  `user_testproject_roles` row either, so enforcing the check unconditionally would lock the global administrator
  out of every private project. Legacy already exempts it, in three places:

```
lib/functions/roles.inc.php:318          ($user->globalRoleID != TL_ROLES_ADMIN) && !$tproject['is_public']
lib/functions/testproject.class.php:575  $globalRoleID != TL_ROLES_ADMIN
lib/functions/tlUser.class.php:1053-1063 // Admin exception … is_public == 0 && has_role == 0  -> unset
```

`tlUser.class.php:1058` states the invariant in one line: **private project + no project role ⇒ no access.**

### Blast radius

`grep -c "hasRight(" api/**` → **100+ call sites** (`testcases`, `suiteview`, `reqedit`, `nfr`, `tcscripts`,
`tcmovecopy`, `suitemove`, …). Legacy callers were not directly exploitable — legacy pages filtered the *project
set* first — but a reachable 3-argument call on a private project the user has no role on is a bypass there too,
and the same admin exception keeps `admin` byte-identical.

## 4. The fix, and why this approach

One change, in the primitive only.

1. **Drive the flag from the id actually passed.** `$accessPublic['tproject']` is populated whenever
   `$testprojectID > 0` instead of only when `$getAccess` is true. `$getAccess` keeps its meaning for the
   **test plan** flag, so no caller changes its answer about plans.
2. **Add the legacy admin exception** to the denial: refuse only when
   `is_public == 0` **and** no project role **and** `globalRoleID != TL_ROLES_ADMIN`.
3. **Read `is_public` tolerantly, memoised** — new `private getTprojectPublicAttr()`. `testproject::getPublicAttr()`
   *throws* `Exception("Test Project ID does not exist!")`, and `hasRight()` is called with client-supplied ids
   from ~100 endpoints, so a naive version of this fix would turn today's clean 403/404 into an uncaught-exception
   500. The helper catches that exception and returns `null` ("no flag, no opinion"), leaving the endpoint's own
   existence check in charge of the unknown-id case. It is also `static`-cached per user instance: one request
   that evaluates four rights on one project now issues one query instead of four.

### Alternatives rejected

* **Pass the 5th argument at every BFF call site** (the issue's first suggestion): 100+ edits, unverifiable in one
  pass, and every site *not* patched stays vulnerable. It treats the symptom, not the defect.
* **Enforce unconditionally, no admin exception**: locks `admin` out of every private project (verified).
* **Move the check into a new per-route helper**: duplicates the rights logic and would need re-auditing per
  endpoint — more surface, less safety, for the same result.

## 5. Verification

Suite **T1763**, 17 cases, `tmp/TLU_Test_Cases.md` — **17/17 PASS**. Highlights:

| case | before | after |
|---|---|---|
| `sm1763td` `init` on private p1 | **200 `can_modify:"yes"`** | **403** |
| `sm1763td` `reorder` on private p1 | **200 `changed:true` (wrote DB)** | **403** |
| `admin` (role 8, no project role) on private p1 | 200 | **200** — no regression |
| `sm1759a` (has a project role) on private p1 | 200 | 200 |
| `sm1763td` on a **public** project | 200 | 200 — public projects stay open |
| `tproject_id=999999` (unknown) | 404 | **404, no 500** |
| other BFFs (`api/testcases`, `api/projects`) | 200 | 200, no 500 |

Event Viewer: no new `Error`/`Warning`. (Four rows present mid-run were produced by this run's own first, broken
draft of the fixture file, not by the fix; they were deleted after the fixture was corrected.)

## 6. Remaining / not covered

The **test plan** accessibility flag (`$accessPublic['tplan']`, `:850-855`) is still opt-in through `$getAccess`, so
the same shape of bypass may exist for a private **test plan** reached with 4 arguments. This fix deliberately did
not touch it (out of the reported scope, and the plan endpoints were not audited in this run). Not filed as a bug
here because it was **not reproduced** — it is recorded in the test suite's "Not covered" note instead.