# Bugfix — Issue #1924: CHANGELOG lost 1171 lines / 53 issue refs to a `# Conflicts: CHANGELOG` resolution, and had no gate

## Summary

`CHANGELOG` is TestLink's rule-22 evidence ledger — one line per modernized
screen / bugfix / feature that lands on the default branch. Commit
`d6b3b860a` (`docs(#1689) … # Conflicts: CHANGELOG`) resolved a rebase conflict
by taking the incoming side **wholesale**, deleting **10 hunks / 1171 lines**
and dropping **53 unique issue refs** (`#1065`–`#1885`) from the ledger.

| metric | value |
|---|---|
| `git show d6b3b860a --numstat -- CHANGELOG` | `4 + / 1171 −` |
| hunks (`grep -cE '^@@'`) | `10` (largest `@@ -8481,374 +7734,6`) |
| unique refs lost (pre-image vs today) | **53** |
| `grep -ic 1883 CHANGELOG` | `0` (entry existed in pre-image) |
| `2026-… (issue #N)` date rows | `1` pre-fix (only `#1102`, later restored by `a9dcee2bf`) |

No product code is affected — this is a documentation-ledger integrity bug. But
rule 22's trail was unverifiable for 53 issues.

## Root cause

1. `d6b3b860a`'s message ends with `# Conflicts: CHANGELOG` — a cherry-pick /
   rebase hit a conflict on the append-only `CHANGELOG` and resolved it by
   discarding the existing body in 10 hunks, keeping only the commit's 4 own
   lines. This is the `git rebase -X theirs` **clobber class** already flagged
   in #1793 / #1805 / #1849 / #1918, whose rulebook still told agents to use
   that strategy at the time.
2. `d6b3b860a` is an **ancestor of HEAD** and `HEAD == origin/sebiboga`, so the
   damage is live on the default branch.
3. `CHANGELOG` had **no gate**: `ai/verify_test_suites.sh` protects only
   `tmp/TLU_Test_Cases.md`. Nothing compared `CHANGELOG`'s ref-set across a
   conflict resolution, so the loss shipped silently.

**Blast radius:** 53 issues (`#1065`–`#1885`) with no ledger line; every future
rebase conflict on `CHANGELOG` repeats the loss.

## Fix

### 1. Splice the 10 dropped regions back — a 3-way merge, direction matters

The clobber mixed a *legitimate* addition (the `#1689` line) with the bad
deletions, so **neither pure direction is correct** on its own:

- `pre + (current − clobbered)` starts from the pre-image → loses every
  post-`d6b3b860a` entry the bad commit still carried forward.
- `current + (pre − clobbered)` re-applies the clobber's own deletions → can
  drop current content.

Chosen: `git merge-file` with

- `base`   = `d6b3b860a` (the corrupt commit),
- `ours`   = `HEAD` — **keeps every post-`d6b3b860a` entry** (matches the issue's
  "do NOT touch post-`d6b3b860a` entries"),
- `theirs` = `d6b3b860a^` — the 10681-line pre-image holding the dropped regions.

6 conflicts were resolved as a **union** (keep ours, add theirs only where
absent). The merge re-materialised some blocks twice (content the clobber
deleted and a later commit re-added), so each non-blank line's multiplicity is
capped at `max(count(pre), count(base))`.

**Rejected alternatives:** (a) hand-re-inserting the 10 hunks by pre-image
offsets — fragile against the post-clobber additions interleaved in those
regions; (b) `theirs`-direction merge — provably loses post-clobber entries;
(c) taking the union of the two ref-sets as a set — destroys ordering and the
short structural lines a ledger legitimately repeats.

### 2. Gate — `ai/verify_changelog_coverage.sh`

Three merge-base-scoped invariants (mirrors `verify_test_suites.sh`):

| check | invariant | purpose |
|---|---|---|
| 1 | every `#NNNN` ref at the merge-base still present | catches a clobber on this run's branch |
| 2 | every `Refs/Fixes/Closes #N` of `git log <merge-base>..HEAD` present | enforces rule 22 for this run |
| 3 | for the **newest ancestor that dropped refs** (`refs(parent) ⊄ refs(commit)`), candidate ⊇ `refs(parent)` | **movable-baseline guard** — a merge-base already carries any loss an ancestor made (#1849 class) |

Check 3 is what makes the gate work: on the *broken* baseline itself, check 1 is
blind (merge-base == candidate), but check 3 anchors to `d6b3b860a^` and reports
the exact 53 dropped refs.

**CI wiring is intentionally NOT included**: `.github/workflows/*` changes are
push-rejected for this token (`without workflows permission`, #1868/#1872). The
gate ships as an `ai/` script run like `verify_test_suites.sh`; wiring it into a
workflow needs a `workflows`-scoped credential (remaining item).

## Verification (measured)

| # | check | pre-fix | post-fix |
|---|---|---|---|
| 1 | lost refs still missing | **53** | **0** |
| 2 | unique ref total | `735` | **`790`** (+53, +`#1924`) |
| 3 | merge-introduced duplicates | — | **0** |
| 4 | baseline lines dropped by fix | — | **3** (clobber's own partial re-wraps; full entries survive from pre-image) |
| 5 | `2026-… (issue #N)` date rows | `1` | **`5`** |
| 6 | conflict markers | — | **0** |
| 7 | `117 standalone HTML screens` (deleted *post*-clobber) | absent | **stays absent** (not resurrected) |
| 8 | gate on fixed ledger | — | **4 PASS / 0 FAIL** (exit 0) |
| 9 | gate on broken baseline (self-test) | — | **2 FAIL** (exit 1) — check 3 lists `#1065 … #1885` |
| 10 | suite gate `TLU_REQUIRE_SUITE="Issue #1924"` | — | **8 PASS / 0 FAIL** |

Regression suite `Issue #1924` (9 cases) appended to `tmp/TLU_Test_Cases.md`,
executed **9/9 PASS**.

## Files changed

- `CHANGELOG` — `+993 / −4`: the 53 refs (1171 lines) restored, plus the
  mandatory rule-22 line for `#1924` itself. The 4 removed lines are the 3
  partial re-wraps `d6b3b860a` itself inserted (their full entries survive from
  the pre-image) plus one re-aligned line whose count is preserved.
- `ai/verify_changelog_coverage.sh` — new blocking gate (self-tested: passes
  fixed, fails broken).
- `tmp/TLU_Test_Cases.md` — `## Regression — Issue #1924` (9 cases).

## RESUME — how to re-test in one command

```bash
# gate: PASS on fixed ledger, FAIL (exit 1) on the pre-fix baseline
TLU_CHANGELOG_REQUIRE_REF="#1924" bash ai/verify_changelog_coverage.sh            # -> 0 FAIL
bash ai/verify_changelog_coverage.sh <(git show 9a9ca6b51:CHANGELOG)              # -> 2 FAIL (53 refs)

# the 53 refs are back
comm -23 <(git show d6b3b860a^:CHANGELOG|grep -oE '#[0-9]{3,4}'|sort -u) \
         <(grep -oE '#[0-9]{3,4}' CHANGELOG|sort -u) | wc -l                       # -> 0
```

Refs #1924 (same shared-file clobber family as #1793, #1805, #1849, #1918).
