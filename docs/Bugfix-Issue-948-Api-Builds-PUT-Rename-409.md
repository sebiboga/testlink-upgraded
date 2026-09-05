# Bugfix — Issue #948: api/builds PUT rename always 409 warning_duplicate_build

## Problem

`PUT /api/builds/{id}` with a **globally-unique** new name always answered
**HTTP 409** `{"status":"error","message":"warning_duplicate_build","detail":"<new name>"}`
and the rename never persisted. Only the rename path was affected — create
(`POST`), toggles (`POST /{id}/flags`) and delete worked.

Reproduced on a fresh database (TestLink 2.0.1, builds project-scoped, `builds.testplan_id`
already dropped per #503/#834):

1. `POST /api/builds/` `{tplan_id:2, name:"Alpha"}` → `200` `{"status":"ok","id":1}`
2. `GET /api/builds/1` → `200` `name:"Alpha"`
3. `PUT /api/builds/1` `{name:"Beta"}` (unique app-wide) → `409 warning_duplicate_build detail=Beta`
4. `GET /api/builds/1` → still `name:"Alpha"` (rename discarded)

## Root Cause

Two return-type facts combined:

- `lib/functions/build.class.php:674` — `build::checkNameExistence($tproject_id,$build_name,$build_id=null,...)`
  returns a **status array**, not a boolean:

  ```php
  $status = array();
  $status['status_ok'] = $rn == 0 ? 1 : 0;   // 1 = name free, 0 = duplicate found
  return $status;
  ```

- `api/builds/index.php:490` (PUT handler) tested that array **as a boolean**:

  ```php
  // before
  if ($buildMgr->checkNameExistence($ctx['tproject_id'], $name, $buildId)) {
      http_response_code(409);
      out(['status' => 'error', 'message' => 'warning_duplicate_build', ...]);
  }
  ```

In PHP **any non-empty array is truthy** (verified: `var_dump((bool)['status_ok'=>1])` →
`bool(true)`), so the 409 branch fired on *every* rename — even when the check SQL
(`WHERE testproject_id=N AND UPPER(name)='BETA' AND id <> M`) correctly returned
zero rows, i.e. `status_ok=1` = no conflict. `build::update()` was never reached.

Why only PUT? The create path (`api/builds/index.php:287`) and the legacy GUI
(`lib/plan/buildEdit.php:651`) use `testplan::check_build_name_existence()`, which
returns a **scalar** `1|0` (`lib/functions/testplan.class.php:2496`) — the inline
`if (fn(...))` style is correct there. The mismatch was introduced when the PUT
handler was migrated to the project-scoped `build::checkNameExistence()` during
#503/#834 but the caller kept the boolean-boolean style.

## Fix

Applied in commit `7d745c0d5` on branch `fix/issue-948`.

`api/builds/index.php` PUT handler now reads the array member — the same correct
pattern `build.class.php:145-149` already uses in `createFromObject()`:

```php
// after
$chk = $buildMgr->checkNameExistence($ctx['tproject_id'], $name, $buildId);
if (!$chk['status_ok']) {
    http_response_code(409);
    out(['status' => 'error', 'message' => 'warning_duplicate_build', 'detail' => $name]);
}
```

Single call site, one file. Alternatives rejected: changing `checkNameExistence()`
to return a bool would have touched every caller (create, REST v2/v3, BFF) and
deviated from the class's documented array contract.

## Files Changed

- `api/builds/index.php` (PUT /{id} duplicate pre-check — 4 lines added, 1 removed)

## Verification

- `PUT /api/builds/1 {name:"Beta"}` (unique) → **200**; `GET` shows `name:"Beta"`.
- `PUT /api/builds/1 {name:"Gamma"}` where Gamma exists in the same project
  (build id 2) → **409** `warning_duplicate_build` (negative case preserved).
- `PUT /api/builds/1 {name:"echo"}` where a build named `echo` exists in a
  **different** project (build id 3, project 3) → **200** (project scoping holds).
- Case-only rename `Delta` → `delta` → **200** (UPPER() compare + `id<>self`).
- `POST` create, `GET /{id}`, `POST /{id}/flags`, `DELETE` → all still **200**.
- `events` table: no new ERROR/WARNING rows (log_level 1/2/3, last 30 min) = 0.
- `php -l api/builds/index.php` → no syntax errors.
- Regression suite appended to `tmp/TLU_Test_Cases.md` as
  **Regression — Issue #948**, 7/7 PASS.

## Related (separate issue, same root pattern)

The legacy REST surface has the identical array-misread on update:
`lib/api/rest/v2/tlRestApi.class.php:1348` and
`lib/api/rest/v3/RestApi.class.php:753` both test
`if ($this->buildMgr->checkNameExistence(...))` as a bool. Filed separately with
evidence; **not** fixed silently in this run (scope rule).