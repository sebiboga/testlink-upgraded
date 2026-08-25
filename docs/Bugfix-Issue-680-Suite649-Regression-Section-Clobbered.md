# Issue 680 — Suite 649 regression section clobbered in tmp/TLU_Test_Cases.md by commit 6960d3529

**Issue:** [#680](https://github.com/sebiboga/testlink-upgraded/issues/680)
**Branch:** `fix/issue-680` · **Commits:** `652d9e046` (restore), `fa964e716` (regression suite)
**Status:** VERIFIED-FIXED & CLOSED (2026-08-25)

## Symptom

The regression record for Issue #649 (`codeTrackerInterface::setCfg` empty-root XML false
failure) was missing from `tmp/TLU_Test_Cases.md` on the default branch:

```
grep -c "Result: Suite 649" tmp/TLU_Test_Cases.md   # -> 0
git log --oneline -- tmp/TLU_Test_Cases.md | grep -i 649   # -> 0aead081a added it (+14 lines)
```

Documentation/test-evidence loss only — no product code affected.

## Root cause chain

1. `0aead081a` (2026-08-23 23:44Z) appended the "Regression — Issue #649" section as the last
   14 lines of the file → EOF blob `412af3eff`.
2. `6960d3529` (2026-08-23 23:50Z, CI run for issue #434) was prepared against an older base
   where the file still ended at "Result: Suite 432". When landed onto a tree that already had
   Suite 649 at EOF, Git recorded a **whole-block replacement**: diff hunk
   `@@ -3663,16 +3663,18 @@` removed the 14-line Suite-649 block and put the 17-line Suite-434
   block in its place (blob `412af3eff` → `d9ebb0205`). Classic lost-update between two
   concurrent CI agents writing the same shared file.
3. 43 later commits touched the file and all appended at the new EOF — nothing ever restored
   Suite 649. Verified exactly one section was lost (Suite 434 itself survived at line 3666).

## Fix approach

Restoration from history rather than rewriting: the authoritative content survives in commit
`0aead081a` / blob `412af3eff`, so the verbatim 14-line block was extracted with
`git show 0aead081a:tmp/TLU_Test_Cases.md | tail -14` and appended byte-for-byte at the
current EOF of `tmp/TLU_Test_Cases.md` (after Suite 496), preserving chronological append
semantics. Historical test evidence is never edited — only re-inserted.

Alternatives rejected: cherry-picking `0aead081a` (would conflict against 43 newer commits for
zero benefit over a plain append); recreating the section from memory (risks corrupting the
evidence trail).

## Verification

| Check | Result |
|---|---|
| Pre-fix repro on `sebiboga` @ `dc278666b`: grep count = 0 | reproduced |
| Post-fix: `grep -c "Result: Suite 649"` = 1 | PASS |
| Restored tail byte-identical to `0aead081a` block (empty diff) | PASS |
| No collateral loss: Suite 434 count = 1, Suite 496 result intact | PASS |
| Markdown table integrity of restored block | PASS |

Regression suite: **Suite 680 — 5/5 PASS** in `tmp/TLU_Test_Cases.md`.
