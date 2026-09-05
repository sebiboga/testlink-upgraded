# Bugfix — Issue #958: legacy REST (v2/v3) updateBuild always 409 — checkNameExistence array read as bool

## Problem

Le legacy REST `updateBuild` handler (v2 `lib/api/rest/v2/tlRestApi.class.php` and
v3 `lib/api/rest/v3/RestApi.class.php`) **always answered HTTP 409
`API_BUILDNAME_ALREADY_EXISTS`** whenever the request body contained `name` — even
for a globally-unique rename, and even for a no-op rename to the current name.
The update path was only reachable when the payload omitted `name` entirely.

Reproduced on a fresh database (TestLink 2.0.1, builds project-scoped per #503) with a
DB-backed harness booting `config.inc.php` + `database` + `new build($db)`
(`/tmp/repro958.php`):

1. Fixtures: `testprojects` id=1 (prefix RSTTP); `builds` id=1 `Build A` (tp=1).
2. Call the same expression the handler evaluates:
   `checkNameExistence(1, 'Build B', 1)` (unique rename, self excluded)
   → returns `array(1) { ["status_ok"]=>int(1) }` (no conflict).
3. The legacy branch `if( $this->buildMgr->checkNameExistence(...) )` treats any
   non-empty array as truthy → duplicate branch fires → `statusOK=false`, message
   `API_BUILDNAME_ALREADY_EXISTS`, HTTP 409. Rename blocked.
4. Also `checkNameExistence(1,'Build A',1)` (same-name self-excluded) → same 409.

## Root Cause

Two facts combine:

- `lib/functions/build.class.php:674-700` — `build::checkNameExistence($tproject_id,
  $build_name, $build_id=null, ...)` returns a **status array**, not a boolean:

  ```php
  $status = array();
  $status['status_ok'] = $rn == 0 ? 1 : 0;   // 1 = name free, 0 = duplicate found
  return $status;
  ```

- Both legacy REST update handlers test that array **as a boolean**:
  - v2 `lib/api/rest/v2/tlRestApi.class.php:1348`
  - v3 `lib/api/rest/v3/RestApi.class.php:753`

  ```php
  // before (identical in v2 and v3)
  if( $this->buildMgr->checkNameExistence(
           intval($build['testproject_id']),$item->name,$id) ) {
       $statusOK = false;
       ... $this->app->status(409);   // v2  / $response->withStatus(409); v3
  }
  ```

In PHP **any non-empty array is truthy** (measured: `var_dump((bool)['status_ok'=>1])`
→ `bool(true)`, also for `['status_ok'=>0]`), so the 409 branch fired on *every*
rename — even when the check SQL (`WHERE testproject_id=N AND UPPER(name)='…' AND
id <> M`) correctly returned zero rows, i.e. `status_ok=1` = no conflict. The actual
`build::update()` was never reached for any name-bearing request.

This is the same defect family as #948 (already fixed on the modernized BFF at
`api/builds/index.php:490-493` with `$chk = checkNameExistence(...); if(!$chk['status_ok'])`).
The legacy v2/v3 REST surface kept the unfixed variant. `createBuild` in both handlers is
NOT affected: it uses `tplanMgr->get_build_id_by_name()` which returns an int id (`> 0`
check). XMLRPC / testplan / testproject `checkNameExistence` call sites compare against
`0`/`''` and are safe.

## Fix

Apply the array-key read, mirroring the verified #948 idiom, in both handlers:

```php
// v2 lib/api/rest/v2/tlRestApi.class.php + v3 lib/api/rest/v3/RestApi.class.php
$checkName = $this->buildMgr->checkNameExistence(
                     intval($build['testproject_id']),$item->name,$id);
if( !$checkName['status_ok'] ) {   // 0 = genuine duplicate -> 409
    $statusOK = false;
    ...
    // v2: $this->app->status(409);   v3: $response->withStatus(409);
}
```

Semantics preserved: unique rename / same-name self-excluded / cross-case → `status_ok=1`
→ `!1=false` → proceed to update (200 path); genuine same-project duplicate → `status_ok=0`
→ 409 (the only case that was coincidentally correct before).

## Verification

- `php -l lib/api/rest/v2/tlRestApi.class.php` and `php -l lib/api/rest/v3/RestApi.class.php`
  → no syntax errors.
- DB-backed harness `/tmp/verify958.php` exercising the exact patched predicate → **5/5 PASS**:

  | Case | Expect | Got | |
  |------|--------|-----|---|
  | unique rename `Build A`→`Build B` (id=1, self-excl) | pass | pass | PASS |
  | same-name rename `Build A` (id=1, self-excl) | pass | pass | PASS |
  | same-name diff-case `build a` (id=1, self-excl) | pass | pass | PASS |
  | duplicate by id=2 `Build B` (same tp) | 409 | 409 | PASS |
  | same name in OTHER project (tp=2) | pass | pass | PASS |

- Event Viewer: `events` table shows no new ERROR/WARNING rows from the clean run.

## Files changed

- `lib/api/rest/v2/tlRestApi.class.php` — updateBuild name-existence check fixed.
- `lib/api/rest/v3/RestApi.class.php` — updateBuild name-existence check fixed.
- `tmp/TLU_Test_Cases.md` — regression suite `Regression — Issue #958` (7/7 PASS).
- `.ci_target_issue` — run target marker (→ 958).

Commits on branch `fix/issue-958-updatebuild-409`: `7697f5354` (fix), `7e6fddb57` (tests).

## Notes / scope

- HTTP-level reproduction of the legacy REST surface is not possible in the CI sandbox:
  v2 requires `Slim/Slim.php` (Slim 2 runtime no longer shipped; only Slim 4 for v3 lives
  in `vendor/`), and v3 `index.php` fatals on the custom-API example
  (`custom/api/RestApiCustomExample.class.php:9` undefined constant `lib`) — both
  pre-existing and out of scope. The fixed code path is exercised against the real DB
  with the identical predicate the handlers execute.