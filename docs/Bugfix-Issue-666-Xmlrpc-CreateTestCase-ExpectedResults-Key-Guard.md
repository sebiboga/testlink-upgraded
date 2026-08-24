# Issue 666 — api createTestCase: unguarded `$steps[$jdx]['expected_results']` read — E_WARNING when a step omits or misnames the key

**Issue:** [#666](https://github.com/sebiboga/testlink-upgraded/issues/666)
**Branch:** `fix/issue-666`
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every `tl.createTestCase` XML-RPC call whose step struct lacks the
`expected_results` key (omitted, or misnamed e.g. as `expectedresults`) fires
one E_WARNING per step into the Event Viewer:

```
E_WARNING
Undefined array key "expected_results" - in .../lib/functions/testcase.class.php - Line 803
```

The step is still created with empty expected results — impact is warning noise,
not data loss. Reproduced live on http://localhost:8082 with devKey auth:
API returned `Success!` and event row id=2 appeared (`log_level=2`, source GUI);
a control call with the correctly-named key produced no new warning.

## Root cause chain

1. Client sends raw step structs; `xmlrpc.class.php:2563` forwards them
   unnormalized to `tcaseMgr->create(..., $this->args[self::$stepsParamName], ...)`.
2. `testcase.class.php:369` `create()` stores them as `$item->steps` and loops at :795–807.
3. `testcase.class.php:803` passes `$item->steps[$jdx]['expected_results']`
   to `create_step()` **without any guard**.
4. PHP 8 semantics: missing-key read → E_WARNING → TestLink logs it to `events`.
   Two lines below (:804–806) the sibling `execution_type` read IS guarded with
   `isset(...) ? ... : TESTCASE_EXECUTION_TYPE_MANUAL` — the defensive pattern
   was simply not applied to `expected_results`.

## Blast radius

| Call site | Exposure |
|-----------|----------|
| xmlrpc.class.php:2563 → `create()` (tl.createTestCase) | primary path, any client omitting/misnaming the key |
| testcaseCommands.class.php:334 (web UI create) | well-formed today, equally exposed on regression |
| REST v1/v2/v3 | route through this loop via `createFromObject()` → `create()` (v1 tlRestApi.class.php:637, v2 :737, v3 RestApi.class.php:1190); v1 forwards client steps raw (:666-681) so this guard protects it fully; v2/v3 additionally have their own unguarded `$stepObj->$key` read upstream (:1099 / :1444) |

Single read site: grep for `->steps[$jdx]['expected_results']` matches only
testcase.class.php:803.

## Approach — mirror the existing execution_type guard

Minimal one-site fix at `lib/functions/testcase.class.php:803`, reusing the exact
ternary style already present two lines below:

```php
$this->create_step($tcase_version_id,
                   $item->steps[$jdx]['step_number'],
                   $item->steps[$jdx]['actions'],
                   isset($item->steps[$jdx]['expected_results'])
                     ? $item->steps[$jdx]['expected_results']
                     : '',
                   isset($item->steps[$jdx]['execution_type'])
                     ? $item->steps[$jdx]['execution_type']
                     : TESTCASE_EXECUTION_TYPE_MANUAL);
```

Alternatives rejected: normalizing keys in the XML-RPC layer (touches more files,
duplicates what `create_step()`'s defaults already tolerate); changing
`create_step()`'s signature (public-ish API surface). The default-to-`''` mirrors
what actually reached the DB before (null → empty column), so stored data is unchanged.

## Verification

Regression matrix executed live against the running app (baseline warn events = 1):

| # | Case | Result |
|---|------|--------|
| 666.R1 | misnamed `expectedresults` key | Success!, delta 0 warns, '' stored — PASS |
| 666.R2 | key omitted entirely | Success!, delta 0 warns, '' stored — PASS |
| 666.R3 | correct `expected_results` key | value persisted verbatim (`CORRECT POST-FIX VALUE`) — PASS |
| 666.R4 | multi-step mix correct+omitted | both steps created, delta 0 warns — PASS |
| 666.EV | Event Viewer across full matrix | only audit info + pre-fix row remain, no new Error/Warning — PASS |

Syntax gate: `php -l lib/functions/testcase.class.php` clean.

## Files changed

- `lib/functions/testcase.class.php` (+3/-1): isset-guard on the expected_results argument.

## Resume / re-test

Fresh DB → set `users.script_key='admin'`; create project+suite via API; POST
`tl.createTestCase` to `http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php` with a
step struct missing `expected_results`; assert Success! and zero new rows in
`events WHERE log_level=2`.
