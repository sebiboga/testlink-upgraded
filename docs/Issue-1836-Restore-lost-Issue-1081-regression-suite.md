# Issue #1836: Restore lost Issue #1081 regression suite (append-only)

## Problem statement
Commit `797396d9d` (fix(#1691)) performed a full-file rewrite of `tmp/TLU_Test_Cases.md` (+0/-128 vs `f42e37830`) instead of appending, destroying `## Task — Issue #1081: generated-on timestamp footer in searchReqSpec`. Total heading count unchanged (85→85) so count-based checks missed it.

## Root cause
Append-only rule violated for shared `tmp/TLU_Test_Cases.md`; stale snapshot caused set substitution. Detection gap (count-based blind) noted in ai/AGENTS.md rule 9.

## Fix
- Appended lost #1081 suite verbatim from `f42e37830` (exact heading) to `tmp/TLU_Test_Cases.md`.
- Appended new `## Regression — Issue #1836: restore lost Issue #1081 regression suite (append-only)`.
- Verified with `ai/verify_test_suites.sh` (merge-base set comparison).

## Verification
- Restored: `grep -c 'Issue #1081' tmp/TLU_Test_Cases.md` = 2
- `TLU_REQUIRE_SUITE="Issue #1081"` → 7 PASS / 0 FAIL / 0 SKIP
- `TLU_REQUIRE_SUITE="Issue #1836"` → 7 PASS / 0 FAIL / 0 SKIP
- No suites lost vs merge-base; no lines removed; fences balanced; no heading without body.

## Files
- `tmp/TLU_Test_Cases.md`

## Commits
- `9f96bd573` fix(#1836): restore lost Issue #1081 regression suite (append-only), add Issue #1836 regression suite (Refs #1836)

## Conclusion
Fixed by append-only restoration; gate passes.
