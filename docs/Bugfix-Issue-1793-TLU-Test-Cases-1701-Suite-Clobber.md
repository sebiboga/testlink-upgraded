# Bugfix — Issue #1793: the `Issue #1701` regression suite was destroyed by a concurrent-agent full-file rewrite of `tmp/TLU_Test_Cases.md`

* **Issue**: [#1793](https://github.com/sebiboga/testlink-upgraded/issues/1793)
* **Branch**: `fix/issue-1793`, three commits — `255c0d2f4` (restore + rulebook),
  `1e251f91b` (docs + CHANGELOG), `6903f424e` and the review-fix commit (harness
  + #1793 suite)
* **Area**: test-suite maintenance / `ai/AGENTS.md` rulebook (no application code touched)
* **Severity**: minor for the product, **major for the safety net** — a
  verification suite that guarded the 1.9.20-parity of the issue-tracker
  connection-check diagnostics was no longer re-runnable from the repository, so
  a regression on that path would have passed CI silently.

## Symptom

`git show origin/sebiboga:tmp/TLU_Test_Cases.md | grep -c "Issue #1701" || true`
returns `0`:
the suite documenting *a failed issue-tracker connection check must record why it
failed, and the log row must name its source* was missing from the tracked test
file, while its sibling suite (`#1740`) was present.

## Measured evidence

```console
$ grep -c "Issue #1701" tmp/TLU_Test_Cases.md      # before the fix
0
$ grep -c "Issue #1740" tmp/TLU_Test_Cases.md      # control: sibling present
1

$ git log --oneline origin/sebiboga -S"Issue #1701" -- tmp/TLU_Test_Cases.md
ce093fa54 docs(#1740): regression suite + screenshots for …   <-- removed it again
47905e3e8 test(#1701): regression suite for the issue-tracker connection-check diagnostic
30aeecd0d feat(opencode): leftover changes from a task-implementation run
861a74549 test(#1701): regression suite for the issue-tracker connection-check diagnostic

$ git show --stat ce093fa54 -- tmp/TLU_Test_Cases.md
 tmp/TLU_Test_Cases.md | 221 +++++++++++++++++++++++++++++---------------------
 1 file changed, 129 insertions(+), 92 deletions(-)

$ git show ce093fa54 -- tmp/TLU_Test_Cases.md | grep -m1 '^@@'
@@ -1626,103 +1626,140 @@ role 3 = read-only user tlu1037norights, only mgt_view_tc(6)
```

`-S` reports commits where the *count* of the string changes, so the four lines
above read: added (`861a74549`) → lost (`30aeecd0d`) → re-added (`47905e3e8`) →
**lost again** (`ce093fa54`).

`git log -S"Issue #1740"` returns `ce093fa54`, `a2df484a8`, `2f108e321`: the
same mechanism destroyed **two** suites in three consecutive commits. Only the
newer one was ever restored.

## Root cause

`tmp/TLU_Test_Cases.md` is a single **shared append-target** for every concurrent
CI agent (rule 9 of `ai/AGENTS.md`) and it sits under a **git-ignored**
directory (`.gitignore:47` = `tmp/`). Two consequences:

1. `git add tmp/TLU_Test_Cases.md` refuses the path ("use `-f` if you really
   want to add it"), so the file is force-added by every agent — the ignore
   applies to *new* paths only;
2. **no CI job asserts anything about the file.** It *is* tracked
   (`git ls-files tmp/TLU_Test_Cases.md`), so a review diff does show the
   92-line deletion — the reason it shipped is the absence of a gate, not an
   invisible diff: the file is invisible to every automated check, and 3.6k
   lines of appended suites are not what a reviewer reads line by line.

The deletion itself: the agent working on issue #1740 built its copy of the suite
file by **re-serialising a stale read** (its base predated the #1701 block) and
then wrote the file wholesale instead of appending. Git had to realign the whole
neighbourhood of the inserted suite, and the #1701 block fell inside the removed
span — that single hunk (`@@ -1626,103 +1626,140 @@`, 129+/92-) *is* the
deletion. This is a shared-file clobber, not a careless edit: the same agent
pattern destroyed #1740 one commit later (`a2df484a8`).

## The fix — restore by append, and forbid the rewrite

**1. Append, never regenerate.** The #1701 block is the *last* section of
`47905e3e8` (lines 1631→EOF of a 1728-line file), so it is self-contained and
can be restored verbatim without touching a single existing line:

```bash
git show 47905e3e8:tmp/TLU_Test_Cases.md | sed -n '1631,1728p' >> tmp/TLU_Test_Cases.md
```

**2. Make the obligation explicit where the agents read it** — `ai/AGENTS.md`:

* **rule 9 (Write Test Cases)** is now `APPEND-ONLY`: the file is shared by all
  agents and lives under the git-ignored `tmp/`, so no CI gate can see a clobber;
  never regenerate it from your own copy, append with `>>`, and before committing
  verify `git diff --cached --numstat -- tmp/TLU_Test_Cases.md` shows **0
  deletions** (`grep -cE "Issue #<n>"` finds your suite, and
  `grep -cE "^## (Regression|Suite|Task|Modernize) "` did not drop). Both
  destroyed suites are cited by commit hash.
  The `--cached` is not cosmetic: `git diff --numstat` compares worktree to
  index, and rule 9 forces the agent to stage the file with `-f` — once staged
  that diff is empty, so the naive gate passes even on a clobber. The rule
  therefore names `git diff --cached --numstat` before the commit and
  `git diff --numstat HEAD~1 HEAD` after it.
* **rules 5 of `ai/FIX-ISSUE.md` and `ai/IMPLEMENT-TASK.md`** get the same
  gate — those are the two rulebooks whose runs actually produced `ce093fa54` and
  `a2df484a8`, so hardening only `ai/AGENTS.md` would have left the hole open
  where it was dug.
* **rule 7 (Update the GitHub Wiki)** gains the mirror-image rule for the second
  shared artifact: never regenerate a page from a stale read — `git pull
  --rebase` first, then edit only your own section.
* **rule 18 (Coordination etiquette)** names both shared artifacts explicitly.

### Verification of the restored state

```console
$ git diff --numstat origin/sebiboga...fix/issue-1793 -- tmp/TLU_Test_Cases.md
189	0	tmp/TLU_Test_Cases.md                    # additions only, nothing removed
$ git diff origin/sebiboga -- tmp/TLU_Test_Cases.md | grep -c '^-[^-]'
0                                               # zero removed lines, ever
$ grep -cE "^## Regression — Issue #1701:" tmp/TLU_Test_Cases.md
1
$ grep -cE "Issue #1701|Issue #1740" tmp/TLU_Test_Cases.md
2                                              # both suites coexist
$ tail -99 tmp/TLU_Test_Cases.md | sed '1d' | diff - <(git show 47905e3e8:tmp/TLU_Test_Cases.md | sed -n '1631,1728p')
                                               # byte-identical
$ diff <(git show origin/sebiboga:tmp/TLU_Test_Cases.md | grep '^## ') \
       <(grep '^## ' tmp/TLU_Test_Cases.md)
47a48,49
> ## Regression — Issue #1701: …               # only the two new suites
> ## Regression — Issue #1793: …
```

## Regression suite

`bash tmp/verify_1793.sh` (exit 0 = PASS) — see the suite
`## Regression — Issue #1793` in `tmp/TLU_Test_Cases.md`. It reads the **tracked**
suite file (`git show HEAD:tmp/TLU_Test_Cases.md`), not the working copy, and
asserts 8 invariants: the #1701 heading, its R1–R7 measured rows, its PASS
verdicts, its 1.9.20 target line, "no suite lost vs `47905e3e8`", balanced
markdown fences, a non-truncated tail, and — when run on the repo — that the last
commit touching the suite file deleted nothing.

```console
$ bash tmp/verify_1793.sh
  PASS  Issue #1701 heading present (= 1)
  PASS  Issue #1701 measured rows R1-R7 intact (= 7)
  PASS  Issue #1701 records a PASS verdict (= 7)
  PASS  Issue #1701 keeps its 1.9.20 target (= 1)
  PASS  no suite lost vs 47905e3e8 (= 0)
  PASS  markdown fences balanced (= 0)
  PASS  file does not end mid-block (last line non-blank)
  PASS  last commit on the suite file deletes nothing (= 0)
G1793 result: 8 PASS / 0 FAIL      # exit 0
```

**Negative controls** — two, because "the #1701 suite is gone" is only half the
risk:

| control | doctored copy | result |
|---|---|---|
| NC1 — the original bug, the whole `#1701` block removed | `sed '/^## Regression — Issue #1701:/,/^## Regression — Issue #1793:/d'` | **3 PASS / 6 FAIL, exit 1** |
| NC3 — a **newer** suite lost, only `#1740` removed | `sed '3438,3575d'` | **7 PASS / 2 FAIL, exit 1** |

NC3 exists because the first version of the cross-check took its baseline only
from `47905e3e8` and matched the issue number anywhere in the text: a *later*
suite could vanish and the harness still reported 8 PASS / 0 FAIL — exactly the
false negative a clobber would use. The cross-check now takes the union of
`47905e3e8` and the last commit that touched the file (45 suite references) and
matches the suite **heading**, not a prose mention.

## Alternatives rejected

* **Re-generate the whole file from a merged history** — this is the exact
  operation that caused the bug (twice).
* **Add a sixth CI workflow that fails when a commit touching
  `tmp/TLU_Test_Cases.md` contains deletions** — attractive, but the path is
  git-ignored, so a git-level gate is unreliable there; and rule 18 forbids
  fighting the five existing workflows. The obligation is agent-side instead.
* **Move the suite file out of `tmp/` into a tracked path** — this does remove
  the ignore problem, but it would break the reference contract of every
  `Refs #<n>` suite and every agent currently in flight. Recorded as the
  follow-up option, deliberately out of scope for a bug fix.

## Files changed

| File | Change |
|---|---|
| `tmp/TLU_Test_Cases.md` | append-only: the `## Regression — Issue #1701` suite restored verbatim (+99/-0) |
| `ai/AGENTS.md` | rules 7, 9, 18 hardened against the clobber (the 2 deletions are rule sentences being *extended*) |
| `ai/FIX-ISSUE.md`, `ai/IMPLEMENT-TASK.md` | §5 in both: the same append-only + 0-deletions gate — these are the rulebooks whose runs caused the clobber |
| `tmp/verify_1793.sh` | the regression gate for this bug (new; checks the tracked suite file, never deletes its input) |

No application code, no API endpoint, no locale bundle and no DB schema was
touched, so no Event Viewer / `events` entry can be produced by this change
(verified: `SELECT COUNT(*) FROM events;` → `0`).

**Mirror.** This page is mirrored verbatim in the GitHub Wiki
(`sebiboga/testlink-upgraded.wiki`, page
`Bugfix-Issue-1793-TLU-Test-Cases-1701-Suite-Clobber.md`, local clone
`tmp/wiki-repo/`); the wiki copy additionally carries any screenshots, this
defect being a repository-hygiene one with no UI surface.