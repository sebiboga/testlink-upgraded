# Issue 461 — xmlrpc `getTestSuite()`: rights context from undefined `$dummy`, loop off-by-one, swallowed INSUFFICIENT_RIGHTS

**Issue:** [#461](https://github.com/sebiboga/testlink-upgraded/issues/461)
**Branch:** `fix/issue-461` · **Commit:** `15d9f2ec6` (fix)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every `tl.getTestSuite()` XMLRPC call wrote E_WARNINGs to the Event Viewer, and the
rights check made **wrong access-control decisions in both directions**:

```
E_WARNING Undefined variable $dummy          - lib/api/xmlrpc/v1/xmlrpc.class.php:8474  (per call)
E_WARNING Trying to access array offset on null - ...xmlrpc.class.php:8474             (per call)
E_WARNING Undefined array key <count>        - ...xmlrpc.class.php:8494                 (per call)
E_WARNING Trying to access array offset on null - ...xmlrpc.class.php:8494             (per call)
```

Measured live during reproduction (fresh DB, fixtures via API):

| Scenario | Pre-fix behavior |
|---|---|
| admin devKey | correct struct returned **but 4 warnings/call** |
| user whose `mgt_view_tc` comes only from a **project-level role** | **silent empty-string response** (`Undefined variable $ni`) — entitled user wrongly denied, no error at all |
| global-role-only user (`guest`, has `mgt_view_tc`) on a **PRIVATE** project | **suite data leaked** — the public/private gate was never evaluated |

## Root cause chain

1. `xmlrpc.class.php:8474` — the project lookup is stored in `$tproj`
   (`$tproj = $tprojectMgr->get_by_prefix($pfx)` :8467) but the rights context reads
   never-assigned `$dummy`: `$ctx[self::$testProjectIDParamName] = $dummy['id'];`.
   Under PHP 8 → 2 E_WARNINGs; context value = `null`.
2. `userHasRight()` (:449) — `isset($ctx[...])` on `null` is FALSE →
   `$tprojectid = intval(null) = 0`; args fallback can't recover it because
   `getTestSuite` takes `prefix`, not `testprojectid`.
3. `tlUser::hasRight()` (lib/functions/tlUser.class.php:836) —
   `$userTestProjectRoles[0]` misses → **only global role rights consulted**;
   project-level grants ignored.
4. `tlUser.class.php:812` — public/private attribute loaded only when
   `$testprojectID > 0` → private-project gate **never evaluated**.
5. `xmlrpc.class.php:8493` — `$l2d = count($items)` but loop condition
   `$ydx <= $l2d` reads index `$l2d`, one past the end.
6. Deny path: `if($status_ok && $this->userHasRight(...))` leaves local `$status_ok`
   true when rights fail, so `return $status_ok ? $ni : $this->errors;` returns
   undefined `$ni` (empty string over the wire) and swallows the
   `INSUFFICIENT_RIGHTS` error that `userHasRight()` had already appended.

## Approach — why this fix

Chosen fix (7+/3−, one method):

1. **Context**: `$ctx[self::$testProjectIDParamName] = $tproj['id'];` — the lookup
   result already exists; using it restores both the project-role lookup and the
   public/private attribute evaluation.
2. **Loop**: exclusive bound `$ydx < $l2d` — standard count-bounded iteration; the
   extra iteration could never match anything.
3. **Deny path**: return `$this->errors` immediately when `userHasRight()` fails,
   using the idiom already used by `getIssueTrackerSystem()` in the same class
   (:8529). The error struct is already appended by `userHasRight()` itself
   (:496-500); returning it costs one line and turns the silent empty response into
   a proper `IXR_Error 2010`.

Rejected alternatives: restructuring all six `if($status_ok && userHasRight(...))`
call sites (systemic upstream pattern — separate concern, would blow the minimal-fix
scope); suppressing warnings at handler level (hides real bugs). The related
`$tsuitid`/`$tsuiteid` typo inside `userHasRight()`'s fallback block discovered
during reproduction was filed separately as **#665**, not fixed here.

## Verification matrix (live, http://localhost:8082)

| # | Case | Result post-fix |
|---|---|---|
| R1 | admin devKey, name match | ✅ suite struct; 0 new events (pre-fix: 4/call) |
| R2 | project-level `test designer` grant, global `<no rights>` | ✅ suite returned (pre-fix: silent deny); 0 events |
| R3 | global `guest` only + PRIVATE project | ✅ denied with `IXR_Error 2010` "…right mgt_view_tc, test project id: 1…" (pre-fix: data leak); 0 events |
| R4 | unknown prefix | ✅ `IXR_Error 7013 TPROJECT_PREFIX_DOESNOT_EXIST`; 0 events |
| R5 | no name match | ✅ empty array; 0 events |
| EV | Event Viewer delta across R1–R5 | ✅ zero new Error/Warning rows |

Regression suite recorded in `tmp/TLU_Test_Cases.md` as **Suite #461** — Result: PASS.

## Files changed

- `lib/api/xmlrpc/v1/xmlrpc.class.php` — `getTestSuite()`: context variable, loop bound, deny-path return (+7 −3).
