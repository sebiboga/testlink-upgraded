# Issue 457 — XML-RPC error messages lose their `(method) - ` prefix (`$msg_prefix` / `$messagePrefix` / `$msgPrefix` typos)

**Issue:** [#457](https://github.com/sebiboga/testlink-upgraded/issues/457)
**Branch:** `fix/issue-457` · **Commits:** `59279b726` (fix), `f9bf9f980` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Error responses from several XML-RPC API methods returned their fault text **without**
the `"(methodName) - "` context prefix, and every occurrence wrote an
`E_WARNING Undefined variable ...` row into the Event Viewer (`events` table):

```
RAW: Parameter version is required, but has not been provided                     ← uploadTestCaseAttachment (no prefix!)
RAW: Test Project (name:NO_SUCH_PROJECT_XYZ) does not exist.                      ← getTestProjectByName   (no prefix!)
RAW: (getTestPlanPlatforms) - Parameter testplanid is required, but has not ...  ← reference (correct)
```

Reproduced live against `http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php`
(PHP 8.3.33, struct param with named members, admin devKey). The Event Viewer
captured exactly:

```
E_WARNING
Undefined variable $msg_prefix - in lib/api/xmlrpc/v1/xmlrpc.class.php - Line 7260
```

## Root cause chain

1. The file carries three spellings of the same concept from different upstream eras:
   `$msg_prefix`, `$messagePrefix`, `$msgPrefix`.
2. In **8 functions**, error branches referenced a spelling that was never declared in that
   function scope (the declared one being a local assignment or a parameter).
3. PHP 8 semantics: undefined-variable read ⇒ E_WARNING + `null`, so `prefix . message`
   degraded to bare `message`. The warning handler (`watchPHPErrors`,
   logger.class.php:1407/:1483, `error_reporting(E_ALL)` at config.inc.php:331) logged each hit.

Why now: legacy 1.9.20 ran these reads on PHP 5/7 where they were silent notices;
this repo runs PHP 8.3 with full error reporting.

### Complete instance list (scripted function-scope scan, docblocks excluded)

| Function | Declares | Undefined use(s), fixed at lines |
|---|---|---|
| `addTestCaseToTestPlan` :3462 | `$messagePrefix` | `$msg_prefix` ×6 → 3498, 3508, 3523, 3573, 3627, 3650 |
| `getTestPlanPlatforms` :5152 | `$msg_prefix` | `$messagePrefix` → 5175 |
| `uploadTestCaseAttachment` :5579 | `$msg_prefix` | `$msgPrefix` → 5587 |
| `updateTestCaseGetTCVID` :7211 | *(none)* | `$msg_prefix` → 7222 (declaration added instead of rename) |
| `helperGetTestProjectByName` :7248 | param `$msgPrefix` | `$msg_prefix` → 7260 |
| `getTestCaseAssignedTester` :7353 | `$msg_prefix` | `$messagePrefix` → 7404 |
| `manageTestCaseExecutionTask` :7603 | param `$msg_prefix` | `$messagePrefix` → 7671 |
| `setTestCaseTestSuite` :8747 | `$msgPrefix` | `$msg_prefix` → 8768 |

## Approach — per-function alignment to the declared name

Chosen fix (**12 renames + 1 added declaration = 13 lines, message text only**):
each undefined reference was renamed to the name actually declared in that scope.
For parameter-carrying helpers the *parameter* name won (`helperGetTestProjectByName`,
`manageTestCaseExecutionTask`) because both call sites already pass a real value;
everywhere else the locally assigned name won. `updateTestCaseGetTCVID()` receives no
prefix from its single caller (`updateTestCase()` :7103), so it got the file-standard
local declaration `$msg_prefix = "(" . __FUNCTION__ . ") - ";`.

Rejected alternatives: (a) file-wide canonicalization of one spelling — ~380-line churn,
high conflict cost with concurrent CI agents; (b) `isset()` guards / null-coalescing —
hides future regressions instead of restoring intended message text.

Code review subagent verified scope of every replacement variable via brace-matched audit
of all 190 functions: PASS.

## Verification matrix

| # | Check | Result |
|---|---|---|
| R1 | `tl.uploadTestCaseAttachment` missing `version` → `(uploadTestCaseAttachment) - Parameter version is required...` | PASS |
| R2 | `tl.getTestProjectByName` unknown name → prefixed | PASS |
| R3 | reference `tl.getTestPlanPlatforms` still prefixed | PASS |
| R4 | events table count unchanged after all post-fix calls | PASS (stayed 2 pre-fix rows) |
| R5 | `php -l` clean + static re-scan reports 0 undefined-prefix uses | PASS |
| R6 | sibling `createBuild` invalid-tplan path correctly prefixed | PASS |
| R7 | `addTestCaseToTestPlan` early-error path prefixed | PASS |
| R8 | `getTestCaseAssignedTester` invalid-tplan path prefixed | PASS |

Full suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #457" (9/9 PASS).

## Side discoveries (filed separately)

- [#658](https://github.com/sebiboga/testlink-upgraded/issues/658) — `setTestCaseTestSuite()`
  builds its prefix from `$ret['operation']`, but `$ret` is list-appended ⇒ key never exists,
  prefix renders as `"() - "`. Pre-existing, orthogonal to this fix.
- [#659](https://github.com/sebiboga/testlink-upgraded/issues/659) —
  `$tcase_external_id` used before assignment at xmlrpc.class.php:3522.

## Files changed

- `lib/api/xmlrpc/v1/xmlrpc.class.php` (+13 / −12)
- `tmp/TLU_Test_Cases.md` (Suite 457)
- this page + `docs/Bugfix-Issue-457-Xmlrpc-Prefix-Typos.md`
