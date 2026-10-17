# Bugfix — Issue #1923: check 3 could not see a clobber performed in a MERGE commit

## Summary

`tmp/TLU_Test_Cases.md` is TestLink's only regression record (rule 9: append-only,
shared by every concurrent CI agent). `ai/verify_test_suites.sh` guards it with three
checks: the structural invariants (check 1), the merge-base set-difference (check 2),
and the history-wide clobber detection (check 3, added by #1849, widened by #1851).

Check 3 anchors its baseline to every commit that **replaced** the file, located with

```bash
git log --format='C %H' --numstat <range> -- tmp/TLU_Test_Cases.md
```

`git log` prints **no stat block for a merge commit** unless asked (`--diff-merges=off`
is the default with `--numstat`). So a whole-file overwrite of the suite file performed
in a merge commit's own diff emitted only the `C <sha>` marker line — no numstat block —
and could never become a candidate. Check 3 then reported
`PASS  no destructive commit in the suite-file history (nothing to re-append)` and the
gate exited **0**. Check 2 cannot compensate: when the candidate forks at or after that
merge, `merge-base(HEAD, origin/<default>)` **is** the merge, so check 2's baseline already
carries the clobber. The two checks together fail open — a permanent, silent blind spot.

The check-3 header had recorded this as a `KNOWN LIMITATION`; this fix closes it.

## Root cause

1. **The extractor suppressed merge diffs** — `ai/verify_test_suites.sh:383` (pre-fix):

   ```bash
   hist_raw="$hist_raw$(git log --format='C %H' --numstat "$r" -- ":/$SUITE_RELPATH" 2>/dev/null)
   ```

   Measured on a scratch reproduction (merge `21baf3b`, first parent 61 headings, result
   3 headings):

   ```console
   $ git log --format='C %H' --numstat 21baf3b -- tmp/TLU_Test_Cases.md
   C 21baf3b844d1d9a3d4c2ba0fc8e821ffaa14f063     <-- marker emitted, ZERO numstat lines
   C 90ca7b0...
   4	0	tmp/TLU_Test_Cases.md
   ```

2. **The candidate extractor** (`ai/verify_test_suites.sh:394-410`) infers a destructive
   commit from those numstat lines (`del >= TLU_HIST_MIN_HEADINGS && add*2 < del`). With no
   block for the merge, it can never be a candidate.

3. **The fall-through** (`ai/verify_test_suites.sh:463-464`) reports `ok "no destructive
   commit …"`. `hist_nostat > 0` (plain commits still have blocks), so the *unrunnable*
   guard does not fire either — a clean, wrong PASS.

## Method chosen

Add **`-m`** to the extractor so each merge commit is diffed once per parent:

```bash
hist_raw="$hist_raw$(git log -m --format='C %H' --numstat "$r" -- ":/$SUITE_RELPATH" 2>/dev/null)
```

A destructive first-parent diff (the clobber) now appears as an ordinary numstat block and
the merge becomes a candidate. Measured on the same merge:

```console
$ git log -m --format='C %H' --numstat 21baf3b -- tmp/TLU_Test_Cases.md
C 21baf3b844d1d9a3d4c2ba0fc8e821ffaa14f063
2	234	tmp/TLU_Test_Cases.md
C 21baf3b844d1d9a3d4c2ba0fc8e821ffaa14f063
2	234	tmp/TLU_Test_Cases.md
```

The existing **real-heading-count confirmation** (`ai/verify_test_suites.sh:424-428`) is
kept unchanged: a candidate is anchored only when its **first parent** (`c^`) has
`>= TLU_HIST_MIN_HEADINGS` headings and the result has **fewer**. A normal merge whose
result equals its first parent therefore cannot produce a false anchor, even though `-m`
also exposes the second-parent diff.

### Alternatives rejected

- **`--first-parent -m`** — cleaner for merge diffs, but `--first-parent` also changes which
  commits are *traversed*: it stops following merged-branch history, so a plain destructive
  commit that lives on a merged branch would stop being scanned — a regression of the
  #1849/#1851 coverage. Rejected; plain `-m` keeps the full traversal and the existing
  first-parent confirmation already rejects the merges that must not be anchored.
- **Diff each merge against its first parent explicitly** (a separate `git diff` pass) —
   equivalent detection, but more code and a second history walk for no benefit. Rejected.

No other check was relaxed. `--allow-skip` semantics are unchanged; a shallow clone is still
reported unrunnable rather than PASS.

## Files changed

- `ai/verify_test_suites.sh` — `-m` in the check-3 extractor (`line 395`); check-3 header
  and `KNOWN LIMITATION` notes rewritten to document merge handling and why `--first-parent`
  is deliberately not used. Commit `3cf174afb`.
- `tmp/TLU_Test_Cases.md` — appended `Regression — Issue #1923` (append-only, committed with
  `-f`). Commit `aef1a550c`.

## Verification

Scratch reproduction (`/tmp/repro-m`): root commit with a 60-heading suite file (pure add),
two non-destructive appends on divergent branches, then a **merge** `21baf3b` whose resolution
replaces the file with 3 headings; `origin/sebiboga` is moved to the merge so check 2's
merge-base is the merge itself.

| | pre-fix | post-fix |
|---|---|---|
| gate result on the 3-heading candidate | `7 PASS / 0 FAIL / 1 SKIP`, `EXIT=0` | `clobber baseline: 90ca7b0^ (61 headings) -> 21baf3b (3 headings)`; `FAIL (58 lost)`; `EXIT=1` |

False-positive guard: a **normal** merge whose result equals its first parent (61 headings)
while the second parent had 70 headings — its second-parent diff reads as `1 add / 75 del` —
is **not** anchored (`ha >= hb`), gate `7 PASS / 0 FAIL`, `EXIT=0`.

Live default branch (`sebiboga` @ `5f4e00c15`): `32 PASS / 0 FAIL` before → `34 PASS / 0 FAIL`
with the regression suite (`TLU_REQUIRE_SUITE="Issue #1923"`), `EXIT=0`. Exactly one new
anchor appears — a real merge that had been invisible:

```console
clobber baseline: e7b7c8f21^ (152 headings) -> 31162f6cc (150 headings)
  PASS  no suite lost vs the pre-image of destructive commit 31162f6cc (= 0)
```

`31162f6cc` = `Merge branch 'sebiboga' of https://github.com/sebiboga/testlink-upgraded into
sebiboga`, first parent 152 headings → merge 150; the current file still holds those
headings, so there is no loss to re-append.

Regression suite `Regression — Issue #1923` in `tmp/TLU_Test_Cases.md`.
