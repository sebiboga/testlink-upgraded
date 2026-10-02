# Bugfix — Issue #1793: the `Issue #1701` regression suite was destroyed by a concurrent-agent full-file rewrite of `tmp/TLU_Test_Cases.md`

* **Issue**: [#1793](https://github.com/sebiboga/testlink-upgraded/issues/1793)
* **Branch**: `fix/issue-1793` (fix + prevention, one commit)
* **Area**: test-suite maintenance / `ai/AGENTS.md` rulebook (no application code touched)
* **Severity**: minor for the product, **major for the safety net** — a
  verification suite that guarded the 1.9.20-parity of the issue-tracker
  connection-check diagnostics was no longer re-runnable from the repository, so
  a regression on that path would have passed CI silently.

## Symptom

`git grep -c "Issue #1701" origin/sebiboga -- tmp/TLU_Test_Cases.md` returns `0`:
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
   want to add it"), so the file is force-added by every agent;
2. because it is ignored, **no CI gate and no review diff covers it** — a
   92-line deletion inside it is invisible.

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
  verify `git diff --numstat -- tmp/TLU_Test_Cases.md` shows **0 deletions**,
  `grep -cE "Issue #<n>"` finds your suite, and `grep -c "^## Regression"`
  did not drop. Both destroyed suites are cited by commit hash.
* **rule 7 (Update the GitHub Wiki)** gains the mirror-image rule for the second
  shared artifact: never regenerate a page from a stale read — `git pull
  --rebase` first, then edit only your own section.
* **rule 18 (Coordination etiquette)** names both shared artifacts explicitly.

### Verification of the restored state

```console
$ git diff --numstat -- tmp/TLU_Test_Cases.md
99	0	tmp/TLU_Test_Cases.md                    # additions only
$ grep -c "Issue #1701" tmp/TLU_Test_Cases.md
1
$ grep -cE "Issue #1701|Issue #1740" tmp/TLU_Test_Cases.md
2                                              # both suites coexist
$ tail -99 tmp/TLU_Test_Cases.md | sed '1d' | diff - <(git show 47905e3e8:tmp/TLU_Test_Cases.md | sed -n '1631,1728p')
                                               # byte-identical
$ diff <(git show HEAD~1:tmp/TLU_Test_Cases.md | grep '^## Regression') \
       <(grep '^## Regression' tmp/TLU_Test_Cases.md)
17a18
> ## Regression — Issue #1701: …               # only the restoration changed
```

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
| `ai/AGENTS.md` | rules 7, 9, 18 hardened against the clobber (15 insertions / 2 deletions — the two deletions are rule sentences being *extended*) |

No application code, no API endpoint, no locale bundle and no DB schema was
touched, so no Event Viewer / `events` entry can be produced by this change.