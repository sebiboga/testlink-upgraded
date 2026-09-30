# Issue 1759 — `suiteMove` BFF: `403` on a suite of another project leaked that suite's existence

**Issue:** [#1759](https://github.com/sebiboga/testlink-upgraded/issues/1759)
**Branch:** `fix/issue-1759` · **Fix commit:** `30b6056ea` (`api/suitemove/index.php`, +4/-2 code)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** FIXED & VERIFIED (2026-09-30)

## Symptom

`Move / Reorder Test Suites` answered

```
403 forbidden  {"code":"forbidden","message":"Container belongs to another test project"}
```

whenever the `container_id` of an `init` or `reorder` request belonged to a **different** test
project than the `tproject_id` the caller named — while the very same endpoint answers a plain
`404 not_found / Container not found` for a container id that does not exist anywhere.

Those two answers are the only difference a caller can observe between

* *“id 56 is a test suite that lives in a project you have no rights on”*, and
* *“id 56 does not exist”*.

So the endpoint was a **cross-project existence oracle**: one `403` per existing suite is enough to
enumerate the suite ids of any test project of the installation, while holding rights on nothing
but one's own project. Reachable on the read path (`GET ?action=init`) **and** on the write path
(`POST ?action=reorder`).

## Root cause chain

1. `api/suitemove/index.php:558` (`action=init`) and `:841` (`action=reorder`) both pass the
   client-supplied `container_id` into the shared resolver
   `suitMoveProject($db, $user, $requestedId, $containerId)` (`:302`).
2. `:307` → `suitMoveNodeInfo()` (`:213-236`) loads the container with an **unpredicated**
   `SELECT id, name, parent_id, node_type_id, node_order FROM nodes_hierarchy WHERE id = ?`
   (`:225-227`). No project predicate, so a row of project B is fetched exactly like a row of
   project A.
3. `:318` the real owner comes from the `parent_id` walk of `suitMoveOwningProject()` (`:239-266`).
4. `:325-328` on disagreement the resolver answered **`403 forbidden`** — *before* the rights check
   at `:347-350`, and before anything could be known about the caller.
5. The genuine insufficient-rights `403` at `:347-350` is correct and untouched.

### Why it breaks NOW

The two `move`-path ownership checks had already been hardened to `404` by commit `13f2ca183`
(“#1740: apply code-review fixes to Move/Reorder Test Suites”):

* `:653-658` — moved node of a foreign project → `404`
  (*“404, not 403: a 403 would tell a caller who has rights on project A that node N exists inside
  another project B.”*)
* `:745-751` — destination of a foreign project → `404`

That commit did **not** touch the resolver, which came in with the endpoint itself (`a45462e2c`)
and predates both. So the screen shipped with **three of four** ownership checks hardened and one
left answering `403`. The resolver's own docblock at `:293-301` even advertised the property the
code did not implement.

## Fix

One call site, `api/suitemove/index.php:325-337`:

```php
  // BEFORE
  if (intval($requestedId) > 0 && intval($requestedId) !== $owner) {
      out(array('status' => 'error', 'code' => 'forbidden',
                'message' => 'Container belongs to another test project'), 403);
  }

  // AFTER
  if (intval($requestedId) > 0 && intval($requestedId) !== $owner) {
      out(array('status' => 'error', 'code' => 'not_found',
                'message' => 'Container not found'), 404);
  }
```

**Why the message was changed too, and not just the status code:** a `403`-turned-`404` that still
says *“belongs to another test project”* would leave a softer wording oracle. The message is now
**byte-identical** to the non-existent-id answer produced 16 lines above at `:309-311`.

### Alternatives rejected

| Option | Why rejected |
|---|---|
| keep `403`, blank the message | the status code *is* the oracle; this fixes nothing |
| move the ownership check behind `hasRight()` | would still announce nothing about project B, but breaks the legitimate “container of project A, caller has no rights on A” case whose `403` is correct and informative |
| return `404` for *every* unresolved container | a missing `container_id` must stay a `400 bad_request` |

### What did **not** change

* `gui/templates/testcases/suiteMove.html` — `:263-269` already maps `404 → smv.stNotFound` and
  `:409` reserves `403` for the CSRF refusal. **No new i18n key, no locale bundle touched.**
* `suitMoveRequireSuite()` (`:519`), the `move`-path checks (`:653`, `:745`) and the rights 403
  (`:347`).

## Verification

`bash tmp/verify_1759.sh` — **19/19 PASS** (fixture `tmp/fixtures_1759.php`: two **private**
projects, an editor of A only, a view-only user and a `<no rights>` user). Suite
`Regression — Issue #1759` in `tmp/TLU_Test_Cases.md`: **23 PASS / 0 FAIL / 1 skipped**.

| Case | Before | After |
|---|---|---|
| `GET init tproject_id=52 container_id=56` (foreign suite) | `403 forbidden` | `404 not_found` |
| `GET init tproject_id=52 container_id=999999` | `404 not_found` | `404 not_found` (identical) |
| `POST reorder tproject_id=52 container_id=56 nodelist=56,57` | `403 forbidden` | `404 not_found`, project B `node_order` untouched |
| `GET init … container_id=54` (own suite) | `200 ok` | `200 ok` |
| `POST reorder … nodelist=55,54` (own project) | `200 ok` | `200 ok`, DB really reversed |
| view-only user, own project | `403 forbidden` | `403 forbidden` (rights 403 survives) |
| CSRF-less `POST reorder` | `403` | `403` |

Browser (`sm1759a`):

* foreign `container_id` → **“Not found — The requested test project, container or test suite does
  not exist.”** instead of the `smv.stNotAllowed` state.
* own `container_id` → normal screen; destination picker lists only project A's suites.
* **Move down** on the project root really flips `nodes_hierarchy.node_order` (`55→0, 54→1`),
  notice *“The test suite was moved down.”*
* 0 console errors/warnings; Event Viewer ERROR/WARNING count unchanged.

Screenshots: `docs/screenshots/issue-1759-foreign-container-not-found.png`,
`docs/screenshots/issue-1759-own-project-move-still-works.png`.

## Related (filed, not fixed here — minimal-fix rule)

* `api/tcreorder/index.php:234` and `api/tcstepsreorder/index.php:530` still answer
  `403 … belongs to another test project` — the same defect class in the sibling BFFs.
* `tlUser::hasRight()` is called without its `$getAccess` argument at
  `api/suitemove/index.php:347`, so the private-project flag is never evaluated and a user with no
  project role at all passes the rights check. Separate issue.
