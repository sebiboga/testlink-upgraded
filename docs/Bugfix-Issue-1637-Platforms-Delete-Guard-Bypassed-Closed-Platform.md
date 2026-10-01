# Bugfix — Issue #1637: `api/platforms` DELETE deleted a platform that was still linked to a test plan

**Branch:** `fix/issue-1637`  ·  **Commits:** `716d96587` (fix), `8ac6cc966` (suite + CHANGELOG)
**File changed:** `api/platforms/index.php` (+22/−1, one hunk in the DELETE route)
**Test suite:** `T1637` in `tmp/TLU_Test_Cases.md` — 17/17 PASS

## Symptom

`DELETE /api/platforms/index.php/{id}?tproject_id=N` deleted a platform that was **still linked to a
test plan**, leaving an orphan `testplan_platforms` row, whenever the platform had `is_open = 0`
**or** `enable_on_execution = 0`. The "is being used! You cannot remove it now" guard
(`422 DELETE_BLOCKED`) fired only for platforms that were *open* — the guard was silently disabled
for closed/disabled platforms, the opposite of the legacy rule it enforces.

Silent by design of the failure: `200 {"status":"ok"}`, no Event Viewer row, no PHP notice. A test
plan kept pointing at a `platform_id` that no longer existed, surfacing later as a phantom platform in
execution/report pickers.

## Approach

**Root cause.** The guard looked the target platform up inside a *display* query:

```php
// api/platforms/index.php:343 (before)
$all = $mgr->getAll(['include_linked_count' => true]);
$linked = 0;
foreach ((array)$all as $row) {
    if (intval($row['id']) == $id) { $linked = intval($row['linked_count']); break; }
}
if ($linked > 0) { /* 422 DELETE_BLOCKED */ }
```

`tlPlatform::getAll()` merges **its own** defaults over the caller's options
(`lib/functions/tlPlatform.class.php:277-283`):

```php
$default = array('include_linked_count' => false,
                 'enable_on_design'    => false,
                 'enable_on_execution' => true,   // becomes " AND enable_on_execution = 1"
                 'is_open'             => true);  // becomes " AND is_open = 1"
$options = array_merge($default, (array)$options);
```

and only an explicit `null` disables a filter
(`lib/functions/tlPlatform.class.php:292-301`: `if (null == $options[$ena]) { continue; }`).
`include_linked_count` is *not* a filter, so passing it did nothing about the flags.

Resulting SQL: `WHERE PLAT.testproject_id = N AND enable_on_execution = 1 AND is_open = 1`. A platform
with `is_open=0` or `enable_on_execution=0` is **not in `$all`** → the `foreach` never matches →
`$linked` stays `0` → **the `if ($linked > 0)` guard is never reached** → `$mgr->delete($id)` runs and
`tlPlatform::delete()` (a bare `DELETE FROM platforms WHERE id=…`, no cascade) orphans the link.

**Why it broke now.** 2.0.1 rewrote legacy `platformsDelete.php` as a BFF. Legacy checked the
platform's testplan *usage* directly (`warning_cannot_delete_platform`,
`gui/templates/dashio/platforms/platformsView.tpl:21`) and could not be defeated by a display flag.
The rewrite reused `getAll()` — whose defaults exist to serve the **display** use case
(testlink.testcase.platforms / specview pickers) — as the **integrity guard's** data source. A display
filter silently became a data-integrity filter.

**The list route in the same file already had the answer.** `api/platforms/index.php:132-135` passes
the three `null` overrides, so `GET` reported `deletable:false` for exactly the platforms that
`DELETE` happily removed — the two routes contradicted each other about the same platform in the same
second.

## The fix

```php
// api/platforms/index.php:349 (after)
$all = $mgr->getAll(['include_linked_count' => true,
                     'enable_on_design'    => null,
                     'enable_on_execution' => null,
                     'is_open'             => null]);
$linked = 0;
$found = false;
foreach ((array)$all as $row) {
    if (intval($row['id']) == $id) { $linked = intval($row['linked_count']); $found = true; break; }
}
if (!$found) {                                   // fail CLOSED
    http_response_code(500);
    out(['status' => 'error',
         'message' => 'Platform usage could not be determined, delete refused',
         'error_code' => 'DELETE_CHECK_FAILED']);
}
if ($linked > 0) { /* unchanged: 422 DELETE_BLOCKED */ }
```

1. **Unfiltered guard** — pass `null` for all three options, byte-for-byte the option set the list
   route already used, so the guard iterates the project's unfiltered platform set.
2. **Fail closed** — absence must never be read as "not linked" on an integrity control. Unreachable
   today (`needOwnedPlatform()` proves the row exists and belongs to the project), but the fail-open
   assumption is *precisely* what allowed #1637, so it is removed rather than left latent.

**Why this method** — it is the proven-correct call already in the same file, so GET and DELETE now
agree *by construction*. No new SQL, no new `tlPlatform` method, no change to the shared legacy class,
no change to the other three `getAll()` callers, and no client contract change (the 422 message and
`error_code` are byte-identical ⇒ **no i18n key and no frontend change were needed**).

**Alternatives rejected**
- *New `getLinkedCount($id)` on `tlPlatform`* — also correct and O(1), but adds a 4th SQL string and a
  public method to a shared legacy class for a 4-line problem. Blast radius > defect size.
- *Change `getAll()`'s defaults* — would alter the 3 display callers (`lib/functions/specview.php:1421`,
  `lib/functions/testproject.class.php:3129`, `lib/functions/tlPlatform.class.php:601`).
- *Default `$linked` to "blocked"* — breaks the legitimate closed+unlinked delete and encodes the
  defect's own fragile assumption.
- *Cascade-delete `testplan_platforms` in `tlPlatform::delete()`* — hides the symptom, diverges from
  legacy parity ("remove it from the testplans using it first"), and silently destroys a test plan's
  configuration.

## Verification

| Case | Pre-fix | Post-fix |
|---|---|---|
| linked, `is_open=1`, exec=1 (only protected case) | `422` | `422` — no regression |
| linked, `is_open=1`, exec=0 | `200 ok` ❌ | **`422 DELETE_BLOCKED`** ✅ |
| linked, `is_open=0`, exec=1 | `200 ok` ❌ | **`422 DELETE_BLOCKED`** ✅ |
| linked, `is_open=0`, exec=0 | `200 ok` ❌ | **`422 DELETE_BLOCKED`** ✅ |
| **unlinked** + open / + closed | `200 ok` | `200 ok` — not over-blocked |
| platform of another project / unknown id | `404` | `404` — no auth regression |
| project with **no test plans** | `200 ok` | `200 ok` — empty set does not 500 |
| orphan `testplan_platforms` rows after the run | **2** | **0** |
| `GET` vs `DELETE` agreement | disagree | agree on every platform |
| Event Viewer | — | no new Error/Warning/Notice row |

![Platform Management: all linked platforms render a disabled delete control](https://raw.githubusercontent.com/wiki/sebiboga/testlink-upgraded/1637-platforms-delete-guard.png)

The Dashio screen above was never the weak link: it reads GET's `deletable` flag, which was always
correct. The hole was reachable only through the API directly (curl, a script, or a second tab racing
the GET) — which is why it survived so long.

## Residual (deliberately out of scope)

`tlPlatform::delete()` is still a bare `DELETE FROM platforms WHERE id=…` with no cascade. The guard
prevents **new** orphans; it does not repair orphans already created by other paths. That is a data
migration concern, not a defect in this route.

## Reproduce it yourself

```bash
curl -c jar -X POST http://localhost:8082/api/auth/login \
     -H 'Origin: http://localhost:8082' -H 'Content-Type: application/json' \
     -d '{"login":"admin","password":"admin"}'
# a platform with is_open=0 that is still in testplan_platforms:
curl -b jar -X DELETE -H 'Origin: http://localhost:8082' \
     "http://localhost:8082/api/platforms/index.php/9003?tproject_id=9001"
# -> 422 {"error_code":"DELETE_BLOCKED", ...}   (was: 200 {"status":"ok"})
```
The login key is `login`, not `username`, and `bffSameOriginGuard()` (`api/_guard.php:102`) rejects a
POST/DELETE that carries no same-origin `Origin`/`Referer`.