# Bugfix Issue #1813 — Execution Dashboard logged two `E_WARNING`s on every load for a test plan without builds

Class of defect: a `get_by_id()` result was dereferenced without first checking whether the
lookup actually returned a row. `build::get_by_id()` returns `false` when no build matches
the id, and the two reads that follow in `execDashboard.php` indexed that `false` directly.
In PHP 8 "array offset on `false`" is an `E_WARNING`, and TestLink's DB event logger persists
each one into the `events` table — so a fresh installation permanently showed Error rows in
the Event Viewer. Same class as #1580 / #979.

## Symptom

Opening **Execution Dashboard** for a test plan that has **no builds yet** (the default state
of a fresh install) wrote two `E_WARNING` rows per request into `events`:

```
E_WARNING  Trying to access array offset on false - in .../lib/execute/execDashboard.php - Line 205
E_WARNING  Trying to access array offset on false - in .../lib/execute/execDashboard.php - Line 206
```

The page itself still rendered `HTTP 200` — this is log-noise, not a crash. But it hides real
errors from administrators in the Event Viewer.

## Root cause

1. `lib/execute/execDashboard.php:85` — `$args->build_id = $tplan_mgr->get_max_build_id($args->tplan_id,1,1);`
2. `lib/functions/testplan.class.php:2178-2183` — `get_max_build_id()` initialises
   `$maxBuildID = 0` and returns `0` when the plan has no active+open build. So
   `$args->build_id === 0`.
3. `lib/execute/execDashboard.php:204` — `$build_info = $buildMgr->get_by_id($argsObj->build_id);`
4. `lib/functions/build.class.php:470-472` — `get_by_id()` runs `SELECT ... FROM builds
   WHERE id = 0` and `return $myrow;`, where `$this->db->fetch_array($result)` is **`false`**
   when there is no matching row.
5. `lib/execute/execDashboard.php:205-206` — the two reads
   `$build_info['notes']` and `$build_info['is_open']` dereference `false`.

This is a plain missing guard, **not** a regression from a recent commit: the lines were
unguarded since they were written and only became visible warnings because 2.0.1 runs on
**PHP 8**, where "array offset on `false`" was promoted from notice to `E_WARNING`. The very
next read (`execDashboard.php:208-209`) already guards with
`isset($dummy[$argsObj->build_id]) ? ... : ''`, confirming the intended defensive style.

## Fix (minimal)

Guard the result before indexing it, using the same shape as the existing guard four lines
below:

```php
$build_info = $buildMgr->get_by_id($argsObj->build_id);
if (is_array($build_info)) {
  $gui->build_notes = $build_info['notes'];
  $gui->build_is_open = ($build_info['is_open'] == 1 ? 1 : 0);
} else {
  $gui->build_notes = '';
  $gui->build_is_open = 0;
}
```

**Alternatives rejected:**
- *Make `build::get_by_id()` always return an array* — changes a shared API used by many
  callers (build management, XML-RPC, execution screens) and would hide the "not found" signal
  from callers that legitimately test for it. Far larger blast radius than the defect warrants.
- *Guard only the `build_id > 0` case at the call site* — `get_by_id()` can also return `false`
  for a positive id that matches no row (deleted build, wrong project scope), so the guard must
  be on the result, not the input.

## Verification (fresh DB)

Fixture created via SQL on the freshly imported database: test project `id=1` (prefix `FIX`),
test plan `id=2` with **0 builds** (the reported state). Session established with
`curl -X POST .../login.php -d 'tl_login=admin&tl_password=admin'`.

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | `php -l lib/execute/execDashboard.php` | no syntax errors | No syntax errors detected | PASS |
| 2 | pre-fix request (`?tplan_id=2&tproject_id=1`) | HTTP 200 + 2 E_WARNINGs | `HTTP 200`; events: `... Line 205`, `... Line 206` | PASS (bug confirmed) |
| 3 | post-fix request, plan without builds | HTTP 200, 0 new events | `HTTP 200`; `events` = 0 rows | PASS |
| 4 | post-fix request ×3 | 0 warnings total | `HTTP:200 200 200`; `events_count=0` | PASS |
| 5 | regression: plan WITH a build | HTTP 200, 0 events, build shown | `HTTP:200`; `events`=0; page contains `build-notes-here` and `B1` | PASS |
| 6 | Event Viewer / `events` count after run | 0 rows | 0 rows | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1813" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1813` in `tmp/TLU_Test_Cases.md`.

Screenshots: none new — the defect is server-side (no screen state changes); the evidence is
the `events`-table count, which is cleaner after the fix (0 rows vs. 2 per load before).

## Files changed

- `lib/execute/execDashboard.php` — guarded `build::get_by_id()` result (`is_array()`) before
  reading `notes`/`is_open`; defaults to `''` / `0`.
- `CHANGELOG` — 2.0.1 key-bugfix line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + this GitHub Wiki page.
