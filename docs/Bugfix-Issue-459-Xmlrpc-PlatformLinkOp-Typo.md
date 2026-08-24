# Issue 459 — `platformLinkOp()`: undefined `$tatus_ok` typo (E_WARNING event-log pollution on every failed platform link/unlink call)

**Issue:** [#459](https://github.com/sebiboga/testlink-upgraded/issues/459)
**Branch:** `fix/issue-459` · **Commit:** `a925530f1` (fix, ±1 line) + `6172bd1ac` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

The original report claimed the typo was "currently harmless" (found by code
reading, not reproduced). Live reproduction **disproved the harmless
verdict**: every FAILED `tl.addPlatformToTestPlan` /
`tl.removePlatformFromTestPlan` call returns the correct XML-RPC error
payload, but ALSO writes a bogus PHP warning into the Event Viewer
(`events` table):

```
mysql> SELECT id, log_level, description FROM events\G
     log_level: 2
  description: E_WARNING
Undefined variable $tatus_ok - in .../lib/api/xmlrpc/v1/xmlrpc.class.php - Line 7024
```

Repro (fresh DB, events table verified EMPTY before): set an admin devKey,
POST IXR request `tl.removePlatformFromTestPlan {devKey:<valid>,
testplanid:999999, platformname:"NoSuchPlatform"}`. Response = correct error
code 3000 ("The Test Plan ID (999999) provided does not exist!") — plus the
warning row above.

## Root cause chain

1. `lib/api/xmlrpc/v1/xmlrpc.class.php:6958` — private
   `platformLinkOp($args,$op,$messagePrefix)` backs both public methods:
   `tl.addPlatformToTestPlan` (`:6827`) and `tl.removePlatformFromTestPlan`
   (`:6841`).
2. `:6964` — `$status_ok` is the guard variable; error paths set it false at
   `:6979-6981` (unknown platform) and `:7013-7015` (unlink blocked by linked
   TC versions); `_runChecks()` failures (auth/testplanid) also leave it
   false.
3. `:7020-7022` — success path returns early (`if($status_ok) return $ret;`).
4. `:7024` — `if(! $tatus_ok) { return $this->errors; }` — `$tatus_ok`
   (missing the `s`) is never assigned anywhere in the tree (verified:
   exactly one grep hit).
5. Under PHP ≥8 the read raises E_WARNING; TestLink's own handler
   `watchPHPErrors` (`lib/functions/logger.class.php:1483`) persists it into
   `events`. Control flow was never wrong — when line 7024 is reached
   `$status_ok` is always false and `!null === true`, so the payload stayed
   correct. The damage is pure event-log pollution on EVERY failure mode of
   the two methods.

## Blast radius

Typo exists in exactly 1 file / 1 line. Callers of `platformLinkOp()`: the
two API methods above ⇒ all their failure modes (auth fail, bad testplanid,
missing `platformname`, unknown platform, unlink blocked by linked test
cases). No REST BFF or UI layer reaches this code.

## Approach — fix the spelling (minimal)

One line at `xmlrpc.class.php:7024`: `$tatus_ok` → `$status_ok`.

Rejected alternatives:
- deleting the final `if` block entirely (works today but loses the explicit
  error-return contract and silently breaks if control flow ever changes);
- restructuring into if/else (unnecessary churn for a bug fix).

## Verification (Suite 459 — 8/8 PASS, see tmp/TLU_Test_Cases.md)

Regression matrix executed live against http://localhost:8082 with fixtures
built through the API itself (project id=1 prefix `fx459`, plan id=2,
platform `PlatA`, suite id=3, tcversion id=5 linked via `testplan_tcversions`):

| # | Scenario | Result |
|---|----------|--------|
| R1 | bad auth devKey | 2000, no new events |
| R2 | nonexistent testplanid (primary repro) | 3000 identical payload, ZERO new events (was: +1 E_WARNING) |
| R3 | missing platformname | 200, no new events |
| R4 | valid link / duplicate link | `link done` / `nothing to do`, no new events |
| R5 | valid unlink | `unlink done` |
| R6 | unlink blocked by linked TC version | 12001 — exercises the fixed fall-through line, no new events |
| R7 | unknown platform name | 235, no new events |
| — | syntax gate | `php -l` clean |

Event Viewer watermark unchanged across the whole post-fix matrix.

## Side discoveries (filed separately, not touched here)

- [#663](https://github.com/sebiboga/testlink-upgraded/issues/663):
  successful unlink of a platform with no linked TC versions writes 2×
  E_WARNING "Trying to access array offset on null" at line 7008
  (`countLinkedTCVersionsByPlatform()` returns null on empty set).
- [#664](https://github.com/sebiboga/testlink-upgraded/issues/664):
  `createTestProject` with omitted/misnamed params writes E_WARNING
  "Undefined array key" at lines 2230-2231.
