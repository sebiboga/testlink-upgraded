# Bugfix — Issue #1849: the shared suite file kept losing whole suites, and the gate could not see it (check 3 + restore landing)

## Summary

`tmp/TLU_Test_Cases.md` is TestLink's only regression record (rule 9: append-only,
shared by every concurrent CI agent). Two commits titled like appends were in fact
whole-file **replacements**:

| commit | subject | numstat | headings |
|---|---|---|---|
| `136fc4426` | `testcases: append suite for #1089 …` | `+12 / -6332` | 88 → 1 |
| `a2f83249f` | `test(#1878): suite 1878 …` | `+27 / -2524` | 49 → 1 |

`ai/verify_test_suites.sh` could not detect either once it had landed on the default
branch. A prior run added a fix (check 3 + a restore) but it lived only on the orphaned
branch `origin/fix/issue-1849` and never reached `sebiboga`; the issue was reopened.
This fix lands the remedy on the default branch and repairs what was still missing.

## Root cause

1. `tmp/` is git-ignored (`.gitignore:47`), so the suite file is only force-tracked
   (`git add -f`) and a whole working-tree copy can be staged in one go — the *replace*
   half of the write/write race among ~5 CI workflows plus local agents.
2. The mandated gate anchored its baseline to `git merge-base HEAD origin/<default>`
   (`ai/verify_test_suites.sh:224-227`). A commit that clobbered the file is *already
   inside* that merge-base, so baseline == candidate and the set-difference check 2a
   reports `0 lost`. Measured: `PASS` on a file that was missing 35 suites.

A baseline that MOVES cannot detect a pre-existing loss. That is the whole bug.

## Method chosen

**Check 3 — history-wide clobber detection** in `ai/verify_test_suites.sh`. A
whole-file overwrite has an unmistakable `--numstat` signature (many deletions, few
additions), so the newest such commit is located in one pass:

```
git log --format='C %H' --numstat <range> -- tmp/TLU_Test_Cases.md
```

- candidates: `deletions >= TLU_HIST_MIN_HEADINGS (20) && additions*2 < deletions`
- **confirmed** by real heading counts (parent ≥ 20 headings and the commit dropped
  headings vs its parent), so a normal content edit cannot produce a false FAIL;
- the candidate must still contain the newest confirmed commit's **parent** headings;
- a failed check prints the exact recovery command:
  `git show <sha>^:tmp/TLU_Test_Cases.md` — append only the blocks whose `^## ` heading is
  absent, do not append the whole file;
- it never fails open on the paths it can evaluate: no git clone, a shallow clone, or no
  readable history ⇒ *unrunnable* (a FAIL unless `--allow-skip`), not a PASS. Known gap: a
  clobber performed in a **merge commit's own diff** is invisible to `--numstat` (follow-up bug).

Only the **newest** replacement is enforced. Older ones belong to the retired
`## N. Name (Suite ID: M)` heading scheme.

### Alternatives rejected (measured)

- **Union of every historical blob** — the file went through several retired heading
  eras, so the union is 844 headings of which 831 are *not* losses. A permanently-red
  gate gets ignored. Rejected.
- **Rebase the orphaned branch** — it was cut before `sebiboga` was rebuilt to 212
  headings; rebasing its `+6277` suite-file commit auto-merges into contaminated output
  (the same failure already recorded on this issue for `fix/issue-1686`). The file is
  append-only: append the exact blocks, never rebase the file. Rejected.
- **Delete-commit false positive** — `a572b8c75` (`0 / -15157`) *deleted* the file; it is
  classified as a removal, not a replacement, because restoring a deliberately deleted
  file is wrong. Handled by `git cat-file -e <commit>:<path>` before classifying.

## Files changed

| file | change | purpose |
|---|---|---|
| `tmp/TLU_Test_Cases.md` | `+2839 / -0` (append-only), then `+49 / -0` for the suite | restore the 15 + 35 suites still missing on `sebiboga`; add this run's regression suite |
| `ai/verify_test_suites.sh` | `+158 / -5` | check 3, header/CONTRACT/ENVIRONMENT docs, `--help` range fixed (`2,41p` → `2,66p`) |
| `CHANGELOG` | one `[KEY BUGFIX]` entry | rule 22 |

## Verification

```
$ TLU_REQUIRE_SUITE="Issue #1849" bash ai/verify_test_suites.sh
baseline: 801de3402 (212) -> candidate: 263 suites
  PASS  no suite lost vs merge-base with origin/sebiboga (= 0)
  PASS  own suite heading present (Issue #1849)
clobber baseline: 8f5e4be39^ (49 headings) -> a2f83249f (1 headings)
  PASS  no suite lost vs the pre-image of the newest destructive commit a2f83249f (= 0)
G1805 result: 8 PASS / 0 FAIL / 0 SKIP   EXIT=0
```

- The file the OLD gate accepted (`origin/sebiboga` tip) now **FAILS**: `1 FAIL (35 lost)`.
- Outside a git clone the check is **unrunnable** (`FAIL … not inside a git clone`).
- Restores are append-only: `git show --numstat` = `865 0` and `1974 0`.
- `comm -23` against both `136fc4426^` (88) and `a2f83249f^` (49) heading sets = **0**.
- Structural: 158 fences (even), 0 headings without a body, 1 pre-existing duplicate
  (`## Task — Issue #1134`, untouched). No application code touched; `events` = 0 rows.

## Follow-ups / out of scope

- `30aeecd0d` (595 → 1) — the earliest, largest clobber, retired heading scheme; tracked by #1851.
- The retired-era suites are deliberately not required back (unsatisfiable baseline).

## RESUME — re-verify in one command

```bash
bash ai/verify_test_suites.sh                         # EXIT=0 expected
git show origin/sebiboga:tmp/TLU_Test_Cases.md > /tmp/tip.md
bash ai/verify_test_suites.sh /tmp/tip.md; echo $?    # expected: FAIL (35 lost), exit 1
```
