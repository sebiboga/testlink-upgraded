# Issue 672 — api createTestCase: unguarded `$steps[$jdx]['step_number']`/`['actions']` reads — E_WARNING, SQL 1064 crash and orphaned rows when a step omits a contract key

**Issue:** [#672](https://github.com/sebiboga/testlink-upgraded/issues/672)
**Branch:** `fix/issue-672`
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every `tl.createTestCase` XML-RPC call whose step struct omits the required
`step_number` or `actions` key produced, per missing key:

1. an E_WARNING into the Event Viewer
   (`Undefined array key "step_number" ... testcase.class.php - Line 801`,
   same for `"actions"` at line 802);
2. then a **DB Access Error fatal**: `create_step()` built an INSERT with
   NULL step_number/actions → SQL syntax error 1064 → TestLink dumped its HTML
   debug backtrace INSTEAD of the XML-RPC response (IXR clients see
   `parse error. not well formed`);
3. and because the TC + tcversion rows are written before the steps loop,
   every failed attempt left an **orphaned partial test case** (version with
   zero steps) in the database.

So one omitted key = warning + broken API response + data corruption. Found
during code review of #666's fix (which guarded only `expected_results`).

## Root cause chain

1. `xmlrpc.class.php` `createTestCase()` validates only top-level params
   (`author`, `summary`, presence of `steps`) and forwards the steps array raw
   to `tcaseMgr->create()` at :2563.
2. `testcase.class.php:800-810` loop (casting the first step to array only when it
   arrives as an object, :794-798) reads
   `$item->steps[$jdx]['step_number']` (:801) and `['actions']` (:802) without
   any guard; `expected_results`/`execution_type` ARE guarded (isset ternaries).
3. `create_step()` interpolates the NULLs straight into the INSERT
   (undefined-key reads interpolate as empty strings → `VALUES(16,,…)` → broken
   SQL; the `NULL` args show in the backtrace frame), `exec_query()`
   fails, TestLink fatals mid-response at testcase.class.php:5820.

## Blast radius

| Call site | Exposure |
|-----------|----------|
| xmlrpc.class.php:~2582 → `create()` (tl.createTestCase) | primary path — the ONLY caller forwarding client-controlled raw steps |
| requirement_mgr.class.php:1066, tcImport.php:459, tcCreateFromIssue*.php, testcaseCommands.class.php:334, api/testcasesimport/index.php:361, api/testcases/index.php:902 | build steps server-side with all keys (verified `$normSteps` in the BFF) — unaffected |

## Approach — validate the API contract at the boundary

Added a per-step validation block in `TestlinkXMLRPCServer::createTestCase()`
right after the existing top-level `keys2check` loop: for each element of
`steps` (cast `(array)` so IXR struct-decoded arrays/stdClass both work),
require presence of `step_number` and `actions`; on a missing key append

```php
$this->errors[] = new IXR_Error( MISSING_REQUIRED_PARAMETER,
    $msg_prefix . sprintf( MISSING_REQUIRED_PARAMETER_STR, "steps[{$sdx}]->{$reqKey}" ) );
```

and set `$status_ok = false`, which aborts BEFORE any DB write. The client now
gets e.g. `(createTestCase) - Parameter steps[0]->actions is required, but has
not been provided` instead of warnings plus a destroyed response.

Alternatives rejected: defaulting inside the `testcase::create()` loop
(#666 style) — it would silence the warning but still create junk steps with
empty actions and mask client bugs; normalizing keys silently — hides real
contract violations. Reuses the existing MISSING_REQUIRED_PARAMETER(200)
constant pair, consistent with all other parameter checks in this class.
API error strings are server-side English constants, not UI locale strings —
no i18n bundle change.

## Code-review hardening (same fix)

The review subagent flagged two residual gaps of the same crash family, closed in
the same block:

* non-numeric `step_number` (e.g. `"abc"`) passed presence-only validation and was
  interpolated raw into the INSERT → same 1064 fatal. Now rejected unless
  `is_numeric()` (numeric strings like `"2"` still accepted);
* a scalar `steps` param (not an array of structs) silently bypassed validation and
  created a zero-step version. Now rejected with an explicit error.

Empty `steps: []` keeps its pre-existing behavior — tracked separately as #629.

## Verification

Live matrix against http://localhost:8082 (fresh fixtures Proj672FIX/P67X,
`events` truncated first):

| # | Case | Result |
|---|------|--------|
| 672.R1 | step omits BOTH keys | 2× clean error naming steps[0]->step_number & ->actions; no events lvl≤2; no orphan rows — PASS |
| 672.R2 | omits only `actions` | single clean error; no DB rows — PASS |
| 672.R3 | well-formed single step | Success! id 20; tcsteps row (1,'do x','see y') verified — PASS |
| 672.R4 | multi-step w/o expected_results (#666 path) | Success!; steps (1,'a1',''),(2,'a2','e2') — defaults intact — PASS |
| 672.R5 | non-struct steps element | clean errors, no fatal — PASS |
| 672.RG666 | misnamed `expectedresults` + correct keys present / verbatim expected_results | #666 behavior unchanged ('' default; 'VERBATIM666' stored) — PASS |
| 672.EV | Event Viewer across whole matrix | COUNT(*) WHERE log_level IN (1,2) → 0 — PASS |

Syntax gate: `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` clean.
Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #672" (8/8 PASS).

Commits: `2c6c444df` (fix), `60a9b3b1f` (regression suite), docs commit follows.
