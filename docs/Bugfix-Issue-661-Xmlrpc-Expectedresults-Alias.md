# Bugfix — Issue #661: XMLRPC `expectedresults` key alias silently drops step expected_results

## Problem

Calling XMLRPC `tl.createTestCase` or `tl.createTestCaseSteps` with a step using the key `expectedresults` (no underscore) produced an `E_WARNING` and persisted the step with an empty `expected_results` column — silent data loss. The method only accepted the underscore variant `expected_results`.

## Root Cause

Two XMLRPC methods pass step data directly to the testcase manager without normalizing alternate key spellings:

1. **`createTestCase()`** (`lib/api/xmlrpc/v1/xmlrpc.class.php:2595`) — passes raw steps to `tcaseMgr->create()`.
2. **`createVersion()`** (`lib/functions/testcase.class.php:803`) — reads `$item->steps[$jdx]['expected_results']` only. When the client sends `expectedresults`, the `isset()` guard (added for #666) returns false → default empty string → data loss.
3. **`createTestCaseSteps()`** (`lib/api/xmlrpc/v1/xmlrpc.class.php:6450`) — reads `$si['expected_results']` directly.

The `expectedresults` spelling is widely used in XML import files and official sample clients.

## Fix

Added input normalization at the XMLRPC API boundary in both `createTestCase()` and `createTestCaseSteps()`. When a step struct has `expectedresults` but not `expected_results`, the alias is mapped to the canonical key before the data reaches the testcase manager.

### Files Changed

- `lib/api/xmlrpc/v1/xmlrpc.class.php` — 2 normalization blocks added:
  - After step validation in `createTestCase()` (~line 2547-2557)
  - Before step processing loop in `createTestCaseSteps()` (~line 6430-6440)

## Verification

| Test Case | Description | Result |
|-----------|-------------|--------|
| TC-R661.1 | `createTestCase` with `expectedresults` stores correct value | PASS |
| TC-R661.2 | `createTestCase` with `expected_results` still works (no regression) | PASS |
| TC-R661.3 | `createTestCaseSteps` with `expectedresults` stores correct value | PASS |
| TC-R661.4 | Missing expected_results defaults to empty string | PASS |
| TC-R661.5 | No PHP warnings generated | PASS |

## Impact

- **Blast radius:** Affects every XMLRPC client sending `expectedresults` (no underscore) via `tl.createTestCase` or `tl.createTestCaseSteps`.
- **Backward compatibility:** No change for clients using the correct `expected_results` key. Empty/missing expected_results still defaults to empty string.
