# Bugfix — Issue #1694: the CI fallback-push step silently deleted a concurrent agent's test suite

## Symptom

The `fix-bug.yml` follow-up commit `d73f8c012` — *"fix(opencode): leftover changes from
bug-fix run"* — removed 44 lines belonging to a **different** agent's regression suite
(`Suite 1681`, the Requirement Specification Tree move/re-order suite) from the shared
`tmp/TLU_Test_Cases.md`, with no conflict, no revert commit and no mention in the commit
message. The same commit also destroyed the suite's `### Bugs found and fixed while
executing this suite` heading and a **10-row bug table** listing issues #1682–#1691 with
their fixing commit hashes. The data was later restored in `906892501`, so nothing is
permanently lost — only the workflow defect remained, ready to strike the next run.

## Why the file is a silent time bomb

`tmp/` is gitignored (`.gitignore:47`) yet `tmp/TLU_Test_Cases.md` is force-tracked, so it
is an ordinary tracked file that every concurrent agent rewrites. `tmp/TLU_Test_Cases.md` is
an append-only ledger: rule 9 of `ai/AGENTS.md` mandates one numbered suite per screen and
the suites are only ever appended.

## Root cause

The `Fallback commit of any leftover agent changes` step of every agent workflow ends with a
rebase onto the shared default branch, using a strategy that resolves conflicts towards the
**replayed** commit:

```
git rebase -X theirs "origin/$BRANCH"     # fix-bug.yml:234
```

In a rebase, `theirs` is the commit being replayed — i.e. this run's **stale** local copy —
while `ours` is the upstream, i.e. the **newer** `origin/$BRANCH`. So the timeline

1. the run checks out its branch from the default branch's state at *run start*,
2. another agent appends its suite to `tmp/TLU_Test_Cases.md` on the default branch,
3. this run appends **its own** suite to its now-stale copy and leaves it uncommitted
   (rule 9 / `ai/FIX-ISSUE.md` §5 mandates a regression suite per fix),
4. the fallback step commits the whole worktree (`git add -A`) and rebases it onto the newer
   default branch with `-X theirs`,

ends with the newer branch content (Suite 1681 + the #1682–#1691 bug table) **discarded**,
and the result pushed as a clean fast-forward. No conflict marker, no warning — the loss is
indistinguishable from an intentional edit.

## The method used to isolate it

A reproduction harness replays the exact timeline in throwaway git repositories, running the
workflow step extracted **verbatim** from the YAML (`yaml.safe_load`) and giving every
scenario its own pristine `origin.git`. Two measurements mattered:

**1. The issue's proposed fix does not work.** Implementing the suggested
staging allow-list / staged-deletion guard first, the guard **never fired**: the worktree
file is `stale(3 lines) + own 20 lines` while `HEAD` is `3 lines`, so the staged diff is
**100% additions — zero deletion lines**. At commit time the staged diff is harmless; there
is nothing for a deletion check to see.

**2. The loss happens at the rebase, not at the commit.** Strategy matrix on the same
harness, one pristine origin per row:

| `git rebase` strategy | surviving Suite-1681 lines |
|---|---|
| `-X theirs` (the workflow) | **0 — destroyed** |
| *(default / `ort`)* | 46 — safe |
| `-X ours` (the fix) | 46 — safe |
| `-X merge` | 46 — safe |

## Fix

`git rebase -X theirs "origin/$BRANCH"` → `git rebase -X ours "origin/$BRANCH"` in all
**7** sites, each with a comment naming the hazard:

* `fix-bug.yml`, `fix-bug-oldest.yml`, `implement-task-oldest.yml`, `implement-task-newest.yml`,
  `investigate-fix.yml`, `compare-screens.yml`, `modernize.yml` — the six fallback-push steps
  that target the shared default branch;
* `modernize.yml`'s agent `PROMPT` text, which was *instructing* agents by hand to use the
  destructive strategy when a push is rejected.

`-X ours` was chosen over dropping the flag because plain `ort` leaves conflict markers
inside a 26k-line append-only file — exactly the "content conflict in the middle of its own
suite" collateral the issue describes for the next agent. It was chosen over a
`git checkout origin/$BRANCH -- <file>` step because that is larger, stateful, and would not
protect any *other* shared file added later; fixing the conflict strategy protects the whole
class. `git add -A` was deliberately left alone (measured non-defective), and
`compare-screens.yml` already shows the repo's own precedent of an explicit path allow-list
where one is genuinely needed.

`-X ours` here does not endanger an agent's actual bug fix: that fix is pushed to the agent's
own `fix/issue-<n>` branch and landed by `merge-prs.yml`. This step only commits *leftover
bookkeeping*, and where leftover and branch conflict, the newer branch content is the correct
winner.

## Verification

The step extracted verbatim from the patched `fix-bug.yml`:

| # | scenario | result |
|---|---|---|
| R1 | concurrent agent appended a suite during the run (**the bug**) | **PASS — 46 lines preserved** |
| R1b | same harness, pre-fix step | reproduces the bug — 0 lines |
| R2 | no concurrent change, only the run's own leftover | PASS — leftover still committed and pushed |
| R3 | leftover in a file nobody else touched | PASS |
| R4 | rebase needed, single attempt | PASS |
| R5 | push rejected → `fetch` + rebase retry loop | **NOT VERIFIED** — harness reused a mutated origin; result discarded, not claimed |
| R6 | `--force-with-lease` / `--force` escalation | unchanged code path, not touched |
| R7 | `yaml.safe_load` on all 11 workflows + `bash -n` on every `run:` block | PASS (11/11, all blocks parse) |
| R8 | `grep -rn "rebase -X theirs" .github/workflows/` | PASS — 0 hits, 7 `-X ours` sites |

The full suite is in `tmp/TLU_Test_Cases.md` ("Regression — Issue #1694").

## Residual risk

* **R5 is unverified.** It needs a hermetic origin; the retry loop itself is untouched by the
  edit and executes the same `-X ours` line on every attempt.
* If an agent **commits** its own stale replacement of a shared file itself (rather than
  leaving it uncommitted), the rebase replays that commit — `-X ours` now resolves it towards
  the branch, so the shared file is protected, but the agent's own append is dropped. A
  future hardening could re-apply the agent's own suite append on top of the branch version
  (`git checkout origin/$BRANCH -- <file>` then re-append), as the issue suggests.
* Each of these steps is paired with `git push --force-with-lease` → `git push --force`
  (`fix-bug.yml:242-243`), so a rejected normal push escalates to a force push. That path is
  unchanged and is the reason the fix had to be made where the conflict is decided.
