# Issue 1779 — `suiteMove` BFF: a container of an UNENTITLED project still answered `403`, an absent one `404`

**Issue:** [#1779](https://github.com/sebiboga/testlink-upgraded/issues/1779)
**Branch:** `fix/issue-1779` · **Fix commits:** `20f14eeef`, `34d5157b4` (+ test commit `b65cee007`)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** FIXED & VERIFIED (2026-10-02)

## Symptom

With a valid session and **no role on any test project**, two requests that must be
indistinguishable were not:

| request (as a `<no rights>` user) | answer |
|---|---|
| `GET /api/suitemove/index.php?action=init&tproject_id=1&container_id=3` (suite of project 1) | `403 {"code":"forbidden","message":"Insufficient rights on this test project"}` |
| `GET /api/suitemove/index.php?action=init&tproject_id=1&container_id=999999` (exists nowhere) | `404 {"code":"not_found","message":"Container not found"}` |

The same split existed on the two write paths — `POST ?action=reorder&tproject_id=1&container_id=3`
and `POST ?action=move&tproject_id=1&node_id=3` — and on the project-root container
(`container_id=1`). One sweep of `container_id` therefore enumerated **every suite and project-root
id of a test project the caller may not even look at**, with zero privileges. This is the twin of
[#1761](https://github.com/sebiboga/testlink-upgraded/issues/1761), closed on
`api/tcreorder` but never closed on `api/suitemove`.

## Root cause chain

1. `api/suitemove/index.php` — `case 'init'` (`:615-619`), `case 'reorder'` (`:922-926`) and
   `case 'move'` (`:733-742`) all hand the **caller-supplied** `container_id` / `node_id` to the
   shared resolver `suitMoveProject(&$db, &$user, $requestedId, $containerId, $leakGuard,
   $leakMessage)` (`:332`).
2. Inside the resolver the container **is** looked up unconditionally (`:329-360`): unknown id,
   wrong node type and orphaned node all answer the opaque `404 not_found "Container not found"`,
   and a `tproject_id` that disagrees with the container's real owner also answers `404` (`:361-363`).
   At that point `$tprojectId = $owner` (`:389`) — the project in play came from the caller's node id.
3. **The defect:** the guard meant *"this refusal must be opaque"* was armed on the **inverse**
   predicate,

   ```php
   // api/suitemove/index.php:373 (before)
   $leakGuard = (intval($requestedId) <= 0);   // "the caller named NO project"
   ```

   so as soon as the caller also named `tproject_id`, the flag was `false`.
4. The rights check (`:407-417`) therefore took the informative branch and answered
   `403 forbidden "Insufficient rights on this test project"` — **the oracle**.
5. `case 'move'` passed the flag **explicitly** with the same wrong predicate
   (`suitMoveProject($db, $user, $owner, 0, intval($tprojectId) <= 0)`, pre-fix `:735-736`), so `node_id` had the
   identical split.

### Why it breaks NOW

`#1759` introduced the guard, but armed it on *"the caller named no project"*, which closes only the
"no `tproject_id`" shape (`M16`–`M20` of `tmp/verify_1759.sh`). The comments added at that time
(*“the rights check is about the caller's OWN project and its 403 is correct, informative and leaks
nothing”*) were the wrong argument: naming project A does not entitle anybody to A's structure.
`#1761` then corrected exactly this in the sibling BFF by arming the guard on the **container**
(`api/tcreorder/index.php:276`), and `api/suitemove` was never aligned.

## Fix

### 1. arm the guard on the caller-supplied node id (`20f14eeef`)

```php
  // api/suitemove/index.php:388 (after)
  $leakGuard = ($containerId > 0);
  $tprojectId = $owner;
```

### 2. the `move` path always derives the project from the caller's `node_id` (`20f14eeef`)

```php
  // api/suitemove/index.php:741-742 (after)
  list($tprojectId, $tproject) =
      suitMoveProject($db, $user, $owner, 0, true);
```

### 3. the opaque answer must reuse the calling action's own "absent id" message (`34d5157b4`)

Status-only opacity was **not** enough, and row **R13** of the new matrix caught it:
`case 'move'` answers `"Suite not found"` for a `node_id` that exists nowhere
(`suitMoveNodeInfo()` null branch, `:718`), so a guard that answered `"Container not found"` still told
*exists* from *does-not-exist* at the message level:

```
POST ?action=move&tproject_id=1&node_id=3        -> 404 {"message":"Container not found"}   (before 3)
POST ?action=move&tproject_id=1&node_id=999999   -> 404 {"message":"Suite not found"}
```

```php
  // api/suitemove/index.php:332-333 (after)
  function suitMoveProject(&$db, &$user, $requestedId, $containerId = 0, $leakGuard = false,
                          $leakMessage = 'Container not found')
  ...
  // api/suitemove/index.php:412-417 (after)
          if ($leakGuard) {
              out(array('status' => 'error', 'code' => 'not_found',
                        'message' => $leakMessage), 404);
          }
  ...
  // the move action passes the message it already uses for an absent id
      suitMoveProject($db, $user, $owner, 0, true, 'Suite not found');
```

### Alternatives rejected

| Option | Why rejected |
|---|---|
| keep `403`, blank the message | the status code **is** the oracle |
| answer `403` only when the caller holds `mgt_view_tc` on the container's project | the caller must not learn that the project exists either; it also re-introduces the status split |
| make the `move` action answer `"Container not found"` for an absent id | would have had to touch a second, unrelated code path and would change the wording the screen already shows for a missing suite |
| `404` for **every** unresolved project | would hide the genuine "you have no rights in this project" answer from a request that named **no** node at all (see below) |

### What did **not** change

* A request naming **no** node (`?action=init&tproject_id=1`) keeps its informative
  `403 forbidden` — the #1759 rule that a *caller-named* project id must stay a uniform `403`
  (rows `R3`, `R8`, `R9`, `R15`).
* `?action=suites` passes `container_id = 0` and takes **no** node id from the caller, so it keeps its
  informative `403` as well (rows `R7`, `R21`) — no node id is hidden there.
* The #1759 fixes themselves (the ownership check at `:361-363`, the type/opaque messages, the
  `no_context` 400).
* `gui/templates/testcases/suiteMove.html` — `:267`/`:270` already map `403 → smv.stNotAllowed` and
  `404 → smv.stNotFound`. **No i18n key added/removed, no locale bundle touched.**

### Accepted trade-off

A **view-only user of its own project** that names a `container_id` now gets the opaque `404`
instead of the informative `403`. This is the same trade-off that `api/tcreorder` (#1761, row R25)
and `api/tcstepsreorder` (#1762, row S26) already accept; the user still learns "you may not work in
this project" from any request that names no container. Rows `M12`/`M12b`/`M12c` of
`tmp/verify_1759.sh` were updated in the same commit (`34d5157b4`) — `M12` now expects `404`,
`M12b` (no container) still expects the informative `403`.

## Verification

```bash
php  tmp/fixtures_1759.php   # two private projects, editor-of-A / view-only / no-rights users
bash tmp/verify_1779.sh      # -> 28 passed, 0 failed   (matrix added for this issue)
bash tmp/verify_1759.sh      # -> 28 passed, 0 failed   (1 SKIP: no test-case fixture, pre-existing)
```

Suite `Regression — Issue #1779` in `tmp/TLU_Test_Cases.md`: **28 PASS / 0 FAIL**.

| Case | Before | After |
|---|---|---|
| `init&tproject_id=1&container_id=3` (unentitled suite) | `403 forbidden` | `404 not_found` |
| `init&tproject_id=1&container_id=1` (unentitled project root) | `403 forbidden` | `404 not_found` |
| `reorder&tproject_id=1&container_id=3&nodelist=3,4` | `403 forbidden` | `404 not_found` |
| `move&tproject_id=1&node_id=3&position=down` | `403 forbidden` | `404 not_found "Suite not found"` (byte-identical to an absent id) |
| `init&tproject_id=1` (no container) | `403 forbidden` | `403 forbidden` |
| `suites&tproject_id=1` | `403 forbidden` | `403 forbidden` |
| `init&tproject_id=2` vs `init&tproject_id=424242` | both `403`, identical | both `403`, identical (#1759 M21) |
| `init` of an own suite / project root as the editor | `200` | `200` |
| admin `reorder` inside project A | `200`, order reversed | `200`, order reversed (`3,4 -> 4,3`) and restored |
| refused `reorder` of project B | `404`, B untouched | `404`, B untouched (`node_order` `5,6`) |
| `events` ERROR/WARNING rows | `0` | `0` (baseline, R28) |

### Harness traps found while writing the matrix (my own bugs, not app bugs)

* A curl GET with the parameters sent as a **body** (`-X GET -d …`) leaves `$_POST` empty server
  side, so the endpoint never sees `tproject_id`/`container_id` and answers `400 no_context` — GET
  parameters must go in the **query string** (this made 14 rows fail on the first run).
* Every session needs its **own** cookie jar, and the assertions must read the same variable the
  login loop wrote (mixing them produced 5 × `401 session_expired`).

## Related

* #1759 — the original cross-project `403` on `api/suitemove` (fixed; this issue is its remaining
  unentitled-project variant).
* #1761 / #1762 — the same oracle class on `api/tcreorder` / `api/tcstepsreorder` (fixed; their
  `tcreoProject()` is the reference implementation followed here).

## Code review round (subagent over `25d6d8f97..HEAD`)

**Verdict: SHIP** — "the fix itself is correct, minimal, uniform with `tcreoProject()`, regression-free
for every user who had a working screen, and needs no i18n work".

Applied from the review:

* **MAJOR-in-spirit (test quality):** row **R11** of `tmp/verify_1779.sh` was **vacuous** — it
  compared `container_id=$A` (the project root) against itself, because the substitution always
  replaced `$SA1`, which that request does not contain. The spec tuple now carries the id to swap
  per row and the row **fails** if the substitution did not actually change the request. The row is
  meaningful now: `container_id=1` and `container_id=999999` really are compared byte for byte.
* stale header comment above `M12` in `tmp/verify_1759.sh` reworded (it still announced that the
  genuine `403` survives, directly above the row that now expects `404`);
* the `file:line` citations in this page re-verified against the post-fix file (they drifted by ~7
  lines because of the new docblock); the dangling link to a non-existent #1761 page replaced by the
  issue URL; the `tmp/TLU_Test_Cases.md` pointer to this page corrected to the real file name.

**Reported as a NEW `bug` issue instead of fixed here** (pre-existing, needs rights on *some* project,
and expanding this run's scope is forbidden by `ai/FIX-ISSUE.md` §4):

* `api/suitemove/index.php:723` and `:729` answer `"Suite has no owning test project"` for an existing
  suite of a foreign project, while an id that exists nowhere answers `"Suite not found"` — the same
  message-level oracle on the `move` path as F1 below;
* `api/suitemove/index.php:820/824/832` — the `new_parent_id` (destination) checks answer
  `"Destination not found"` / `"Destination is not a test suite"` / `"Destination has no owning test
  project"`; the middle one additionally leaks the node type across projects.
