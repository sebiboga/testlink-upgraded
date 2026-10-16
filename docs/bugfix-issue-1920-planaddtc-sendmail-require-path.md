# Bugfix Issue #1920 — `planaddtc/save` `send_mail` path: `require_once` "Failed to open stream" (wrong one-level relative path) + two latent hazards in the same block

Class of defect: a one-level relative include from a nested directory — `dirname(__FILE__) . '/../lib/...'` issued from `api/plans/` resolves into `api/`, not the repo root — combined with a `catch (Throwable)` that turned the failure into a silent no-op behind HTTP 200.

## Symptom

`POST /api/plans/index.php/planaddtc/save` with `send_mail: true` logged, on every such save:

```
E_WARNING require_once(<root>/api/plans/../lib/plan/planAddTC.php): Failed to open stream:
No such file or directory - in <root>/api/plans/index.php - Line 2027
```

into the `events` table (Event Viewer), while the endpoint answered `200 {"status":"ok"}` and the tester-notification mail was silently skipped.

## Root cause chain

1. `api/plans/index.php:2027` — `require_once dirname(__FILE__) . '/../lib/plan/planAddTC.php';`
   `dirname(__FILE__)` = `<root>/api/plans`; a single `..` yields `<root>/api/`, so the target
   `<root>/api/lib/plan/planAddTC.php` does not exist (`lib/plan/planAddTC.php` sits at the repo root;
   the correct path is `../../lib/plan/planAddTC.php`).
2. PHP raises `E_WARNING ... Failed to open stream` → TestLink's error handler
   (`lib/functions/logger.class.php:1483`) writes it to `events` (log_level 2) → then throws
   `Error: Failed opening required`, swallowed by the endpoint's `catch (Throwable)`
   (`api/plans/index.php:2043-2045`) → HTTP 200, mail skipped.
3. Latent hazard (a): the block was gated on `if ($sendMail)` alone, but `$features2` is only
   built inside the assignment branch (`testerID>0 && buildID>0 && fresh links`). Legacy
   `addToTestPlan()` only mails when the assignment produced features (`lib/plan/planAddTC.php:1070-1077`).
   Fixing only the path would have traded the require warning for
   `E_WARNING: Undefined variable "$features2"` at the `send_mail_to_testers(...)` call.
4. Latent hazard (b): the BFF's `$features2['add']` omitted `tcase_id` / `tcversion_id`, which
   `send_mail_to_testers()` reads (`lib/plan/planAddTC.php:835,837,838`) and legacy sets
   (`lib/plan/planAddTC.php:1046-1048`) — once the require worked, that meant 6 undefined-key
   warnings plus `ERROR 1064 ... IN ()` from `testcase::get_by_id_bulk()` (`testcase.class.php:2660/2670`).

## Fix and why this method

Minimal, legacy-parity changes in one block (`api/plans/index.php`):

1. **Path**: `'/../lib/plan/planAddTC.php'` → `'/../../lib/plan/planAddTC.php'`.
   *Rejected alternatives:* an absolute `ROOT_DIR` include (inconsistent with how sibling BFF
   files already include legacy code) or moving `send_mail_to_testers()` into a shared lib (a
   refactor far beyond a minimal fix).
2. **Gate**: `if ($sendMail && !empty($features2['add']))` — mail runs only when the assignment
   above produced features, exactly like legacy's `testerID > 0` + `$called` loop; `empty()` is
   safe on the undefined variable, so a plain `send_mail` save without a tester emits nothing.
3. **Legacy keys**: `$features2['add'][...]['tcase_id']` / `['tcversion_id']` restored via
   `array_flip($itemsToLink['tcversion'])`, mirroring `lib/plan/planAddTC.php:1046-1048`, so
   `send_mail_to_testers()` runs warning-free with real case/version ids.

`lib/plan/planAddTC.php` remains safe to include in-request: its own relative/bare includes
resolve (request CWD = `api/plans` for `../../config.inc.php`; `cfg/const.inc.php:43-49` appends
`lib/*` to `include_path` for `common.php`/`specview.php`/`email_api.php`/`Zend/...`),
`testlinkInitPage()` passes the live session, and with `item_level=null` / `doAction=default`
the top-level `$smarty->display()` block is skipped — only the function definitions load.

## Files changed

- `api/plans/index.php` — send_mail block (path, gate) + `tcase_id`/`tcversion_id` keys in `$features2`.
- `tmp/fixtures_1920.php`, `tmp/repro_1920.sh`, `tmp/verify_1920.sh` — fixture + regression matrix harness.
- `tmp/TLU_Test_Cases.md` — suite *Regression — Issue #1920* (gate: 7 PASS / 0 FAIL).
- `CHANGELOG` — 2.0.1 KEY BUGFIX entry.

## Verification

`bash tmp/verify_1920.sh` on a fresh fixture (project/plan/suite/TC1 linked/TC2 unlinked/open build):

| Case | Result |
|---|---|
| (a) `send_mail:true`, no tester (the issue's repro) | 200, **0 new require_once events** |
| (b) `send_mail:true` + `testerID:1` + `build_id` + fresh TC | 200 `added:1`, all mail-path warnings + SQL 1064 gone |
| (c) save without `send_mail`, already-linked pair (dup skip) | 200 `added:0`, 0 events |
| (d) remove linked TC | 200 `removed:1`, 0 events |

In-scope new `log_level<16` events after the fix: **0**. The 3 remaining warnings in case (b)
come from `assignment_mgr.class.php:179-182` — a pre-existing, separate defect (the BFF passes
the wrong map shape to `assign()`, so **no** `user_assignments` row is ever written) discovered
during this run and filed as **#1922** per FIX-ISSUE §4 (out of scope here, reachable without
`send_mail`).
