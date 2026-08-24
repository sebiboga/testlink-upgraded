# Issue 462 — xmlrpc `checkPlatformIdentity()`: raw platform map pushed into `errors[]` unconditionally on the platformname path

**Issue:** [#462](https://github.com/sebiboga/testlink-upgraded/issues/462)
**Branch:** `fix/issue-462` · **Commit:** `d31210b22` (fix)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Whenever an XML-RPC client identified a platform **by name**
(`platformname` instead of `platformid`), `checkPlatformIdentity()`
pushed the full `$platformInfo` map (all platforms known for the test
plan) into the API error accumulator *before* deciding whether the call
succeeded. On any failure — a bad name, or any later check in the same
request — clients received that raw assoc array inside what is otherwise
a list of `{code,message}` IXR_Error structs.

Measured live (fresh DB, fixtures created via the API itself):

```json
// tl.getLastExecutionResult {testplanid:6, testcaseid:8, platformname:"NO_SUCH_PLATFORM"}
[
    {"2": "PLAT_A"},
    {"code": "3040", "message": "Platform (name=NO_SUCH_PLATFORM/id=) is not linked to test plan (name:TP462)."}
]
```

Element `[0]` is not an error — it is leaked internal data. On success
paths the push also happened but stayed invisible because callers return
`$resultInfo` instead of `$this->errors`.

## Root cause chain

1. `xmlrpc.class.php:4976` (pre-fix) — inside `if($name_exists) {`,
   `$this->errors[] = $platformInfo;` ran unconditionally, before the
   membership test at :4977 (`in_array($name, $platformInfo)`).
2. Callers return the whole accumulator on failure, e.g.
   `getLastExecutionResult()` :1655 `return $status_ok ? $resultInfo : $this->errors;`
   → the dump reached the wire whenever the name lookup failed or any
   later check failed after a successful name lookup.
3. Blast radius: 8 public methods call the helper — `getLastExecutionResult`,
   `getAllExecutionsResults`, `reportTCResult`, `getTestCaseAssignedTester`,
   `getTestCaseBugs`, `manageTestCaseExecutionTask`, `getRequirements`,
   `getExecutionSet` (7 public, 1 private helper). No code anywhere reads individual `$errors[]`
   entries internally, so nothing depended on the dump.

## Approach — why this fix

Chosen fix: **delete line 4976** (1 deletion, no other change).

Alternatives rejected:
- *Move the push into the `if(! $status)` block* — still leaves non-error-shaped
  raw data next to a fully descriptive `IXR_Error(3040)` that already names the
  requested platform and test plan; keeps polluting the channel.
- *Broad rewrite of error formatting* — out of scope for a bug fix; violates the
  minimal-change rule.

No diagnostic information is lost: the `PLATFORM_NOT_LINKED_TO_TESTPLAN`
message already carries `platformname`, `platformid` and the test plan name.

## Verification

Reproduction harness: minimal hand-rolled XML-RPC client over POST
(no php-xmlrpc extension available), one struct parameter per call;
fixtures via `tl.createTestProject/createTestPlan/createPlatform/
addPlatformToTestPlan/createTestSuite/createTestCase/addTestCaseToTestPlan`.
Note: API-created platforms default `enable_on_design/enable_on_execution = 0`,
and `getLinkedToTestplanAsMap()` filters `enable_on_execution` — flip to 1
or the plan's effective platform set is empty and even valid names fail.

| # | Case | Post-fix result | Verdict |
|---|------|-----------------|---------|
| M1 | unknown `platformname` | exactly one IXR_Error 3040, no map | PASS |
| M2 | valid `platformname` | normal success result | PASS |
| M3a | no platform param (optional method) | success | PASS |
| M3b | reportTCResult without platform param | single MISSING_REQUIRED_PARAMETER 200 | PASS |
| M4/M4b | unknown numeric `platformid` (path untouched) | single IXR_Error 3040 | PASS |
| M5 | reportTCResult round-trip with valid platformname | execution recorded (`Success!`) | PASS |
| M5b | read back via getLastExecutionResult + platformname | recorded execution returned | PASS |

Event Viewer audit: identical pre-existing E_WARNING pairs at
xmlrpc.class.php:1379 fire before and after the commit → legacy behavior of
`_checkTCIDAndTPIDValid()` (platform-key `0` assumption), filed separately as
[#667](https://github.com/sebiboga/testlink-upgraded/issues/667); createTestCase
step-key warning already tracked as #666. Nothing attributable to this change.

## Files changed

- `lib/api/xmlrpc/v1/xmlrpc.class.php` — 1 deletion (the stray errors push)
