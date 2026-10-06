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
| `-X ours` (the proposed class fix, NOT landed — see below) | 46 — safe |
| `-X merge` | 46 — safe |

## Fix — what actually landed (`15ae8c1bc`)

A single versioned line, new file `.gitattributes`:

```gitattributes
tmp/TLU_Test_Cases.md merge=union
```

Why this method:

* **Attributes are read from the working tree during a rebase** — i.e. from the *upstream*
  checkout (`origin/$BRANCH`) — so once this line sits on the default branch, every future
  fallback rebase merges the ledger with the builtin `union` driver, whatever `-X` flag the
  workflow passes. Measured: a running merge driver beats the strategy flag for that path.
* `union` is a **builtin** driver — it needs no `merge.<name>.driver` config, and git config
  is the one thing a repo cannot version, which is exactly why `merge=ours` was rejected:
  measured, the bare attribute is a no-op without `merge.ours.driver` (clobber still happens),
  and with the config it works but cannot ship (`.git/config` is local to each runner clone).
* Union semantics fit an append-only ledger: the upstream side is kept verbatim and the
  replayed side's lines are appended with lines identical to existing content collapsed into
  their first occurrence — **no unique content from either side is ever lost**. The reported
  failure mode (an entire concurrent suite silently deleted) becomes impossible; the cosmetic
  residue of a simultaneous append (shared boilerplate such as blank lines / `### Repro steps`
  appears once with both table rows) is visible and repairable, not silent.

`git check-attr merge -- tmp/TLU_Test_Cases.md` → `merge: union`; any other path → `unspecified`
(the rule is scoped to the one file).

## Class fix — documented, BLOCKED on token permissions

The root-cause fix at the right layer remains `git rebase -X theirs` → `git rebase -X ours` in
all **7** sites (`fix-bug`, `fix-bug-oldest`, `implement-task-oldest`, `implement-task-newest`,
`investigate-fix`, `compare-screens`, `modernize` — the last one also in the agent `PROMPT`
text). It was prepared, grepped clean (0 `theirs`, 8 `ours`) and YAML-validated (11/11), but
**cannot be pushed by the CI token** — measured rejection, three runs in a row:

```console
 ! [remote rejected] HEAD -> fix/issue-1694 (refusing to allow a GitHub App to create or
   update workflow `.github/workflows/compare-screens.yml` without `workflows` permission)
```

Exact patch (needs a credential with the `workflows` scope):

```bash
sed -i 's|git rebase -X theirs "origin/\$BRANCH"|git rebase -X ours "origin/$BRANCH"|' \
  .github/workflows/{fix-bug,fix-bug-oldest,implement-task-newest,implement-task-oldest,investigate-fix,compare-screens,modernize}.yml
sed -i 's|git rebase -X theirs origin/<branch>|git rebase -X ours origin/<branch>|' \
  .github/workflows/modernize.yml     # the agent PROMPT text (~line 238)
```

Verified on a copy of all 11 workflows: `grep -rn "rebase -X theirs"` → 0 hits,
`grep -rn "rebase -X ours"` → 8 hits, `yaml.safe_load` → 11/11 OK.

`-X ours` (in a rebase: `ours` = upstream = the newer default branch) was chosen over
dropping the flag because plain `ort` leaves conflict markers inside a 26k-line append-only
file, and over a `git checkout origin/$BRANCH -- <file>` step because that is larger, stateful,
and would not protect any *other* shared file. `git add -A` was deliberately left alone
(measured non-defective: the staged diff is 100% additions, so the issue's proposed deletion
guard never fires).

## Verification (measured)

Harness: pristine `origin.git` per scenario, base = the real 1526-line ledger; timeline =
concurrent agent appends its suite to the default branch while a stale agent leaves its own
suite as the "leftover" commit, then the fallback runs `git rebase -X theirs origin/main`.

| # | scenario | result |
|---|---|---|
| R1 | reported bug, **without** the guard (true control) | **bug reproduced** — `1681-surviving: 0` |
| R1b | reported bug, **with** the guard (synthetic) | **PASS — 45/45 + 20/20 preserved** |
| R1c | reported bug, **with** the guard (realistic, shared boilerplate) | **PASS — `Suite 1681`=1, `Suite 1608`=1, both table rows, 1526→1543 lines** |
| R2 | only this run's own leftover (rebase skipped — origin is ancestor — or guarded rebase) | PASS — append lands intact, unique content 1/1 |
| R3 | other files / other paths | PASS — `git check-attr` resolves `union` for the ledger only |
| R4–R6 | rebase retry / force-push escalation paths | unchanged (no workflow code landed) |
| R7 | `.gitattributes` syntax + scope | PASS (`git check-attr` output above) |
| suite gate | `TLU_REQUIRE_SUITE="Issue #1694" bash ai/verify_test_suites.sh` | PASS — 7/0/0, 24 suites, none lost |

The full suite is in `tmp/TLU_Test_Cases.md` ("Regression — Issue #1694").

## Residual risk

* The **7 `rebase -X theirs` workflow sites remain live** for every *other* shared file —
  the class fix is blocked on a `workflows`-scoped credential (tracked as issue **#1868**).
* Union's dedup collapses lines of the replayed side that are *identical* to existing content
  (blank lines, repeated boilerplate) into their existing occurrence — unique content always
  survives, but two suites appended in the same instant share one copy of their boilerplate.
* The fallback step is still paired with `git push --force-with-lease` → `git push --force`
  (`fix-bug.yml:242-243`); that escalation path is untouched and is why the class fix must
  eventually be made where the conflict is decided.
