# Bugfix — Issue #1776: `out($data, $code = 200)` reset the HTTP status of every error branch

## Symptom
Every error branch of 14 BFF endpoints answered **HTTP 200** with a JSON body saying
`{"status":"error", …}`. For any client that keys off the status line — `fetch` (`res.ok`),
DataTables ajax error handling, monitoring/alerting — a validation failure, an unknown route
or a rights denial was indistinguishable from a success.

```
$ curl -s -b jar -H 'Referer: http://localhost:8082/' -w 'HTTP=%{http_code}\n' \
    'http://localhost:8082/api/tcassignments/index.php/rows'
HTTP=200    {"status":"error","message":"tproject_id is required"}   # expected 400
$ curl … 'http://localhost:8082/api/tcassignments/index.php/rows?tproject_id=99999'
HTTP=200    {"status":"error","message":"Test project not found"}    # expected 404
```

## Root cause
Two calling conventions coexist in the BFF layer:

* **A** — `http_response_code(400); out(['status' => 'error', …]);`
* **B** — `out(['status' => 'error', …], 400);`

The helper implemented B but was written as if it implemented neither:

```php
function out($data, $code = 200) {
    http_response_code($code);   // always overwrites what the caller set
    echo json_encode($data);
    exit;
}
```

With convention A the `$code = 200` default re-set the status one statement after the caller
had chosen it, so the branch reached the wire as a 200. No PHP notice, no log entry — a valid
response with an error body.

`api/tcassigned/index.php:56` and `api/keywordsxml/index.php:86` already carried the correct
guard; the 14 endpoints listed below did not.

## Blast radius (measured)
* **25 live broken sites** — `api/execassignment/index.php` 13, `api/tcassignments/index.php` 11,
  `api/tcassign2tplan/index.php` 1 (all 400/403/404/422/500 error branches).
* **154 convention-B call sites** that pass the code explicitly and had to keep working
  (`suitemove` 32, `tcreorder` 25, `tcstepsreorder` 25, `testcasesedit` 18, `tcsummary` 15,
  `tcbulkop` 12, `execassignmentcopy` 10, `tcunassignall` 7, `tcassign2tplan` 6, …).
* **Not affected**: every endpoint whose `out()` takes no `$code` at all (`mainpage`, `plans`,
  `results`, `suiteview`, `users`, `requirements`, `testcases`, …) — their status line is never
  touched by the helper. The 401 session guards never reach `out()` at all.

## Fix
The helper no longer owns the status line — it only sets it when a code is handed to it:

```php
function out($data, $code = null) {
    if (!is_null($code)) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}
```

Applied to the 13 endpoints of the issue plus `api/logviewer/index.php:82` (`lvOut`, same
latent signature; its DB-error 500 branch at `:332-337` already passed `, 500)` explicitly, so
it was aligned only to remove the trap for future callers).

Rejected alternative — dropping `http_response_code()` from the helper entirely: that would
have *created* 154 broken branches while fixing 25, because it silently drops the status of
every convention-B call. `is_null()` was kept over `tcassigned`'s `$code > 0` because
`http_response_code(0)` is not an error in PHP (it falls back to 200) while `$code > 0` would
leak a previously-set status and swallow typos.

Diff: 14 files, +55 / −29, one function per file, no other line touched.
Regression suite: `bash tmp/verify_1776.sh` → **34 PASS / 0 FAIL**
(suite entry in `tmp/TLU_Test_Cases.md`).

## Verification highlights
| request | before | after |
|---|---|---|
| `tcassignments/rows` (no `tproject_id`) | 200 | **400** |
| `tcassignments/rows?tproject_id=99999` | 200 | **404** |
| `tcassignments/unknownroute` | 200 | **404** |
| `execassignment/items` (no `exec_assign_testcases` right) | 200 | **403** |
| `tcstepsreorder/unknownroute` (no `tcversion_id`) | 200 | **400** |
| `tcassign2tplan/unknownroute` (convention B) | 404 | **404** (no regression) |
| `tcassignments/init?tproject_id=9001` (success) | 200 | **200** + payload |
| `tcassignments/rows` without cookie | 401 | **401** |

Event Viewer: no new Error/Warning rows attributable to this fix. `php -l` clean on all 14 files.

## Related
* **#1777** (filed here): `api/tcassignments/index.php/rows` fatals with
  `TypeError: array_keys(): Argument #1 must be of type array, null given` at `:350` → HTTP 500
  with an **empty** body. Pre-existing, happens before `out()` is reached, found while
  building the success-path probe for this fix.
* **#1775**: the `E_WARNING Undefined array key "tplan"` rows seen in the Event Viewer when the
  matrix exercises a plan-scoped rights check — a separate, already-filed defect.
