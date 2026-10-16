# Bugfix — Issue #1851: the largest suite-file clobber (595 → 1) was never detected and never repaired — restore + all-anchor gate

## Summary

`tmp/TLU_Test_Cases.md` is TestLink's only regression record (rule 9: append-only,
shared by every concurrent CI agent). Commit **`30aeecd0d`** (github-actions[bot],
2026-09-29, subject `feat(opencode): leftover changes from task-implementation run`) was
a whole-file **replacement**: `+32 / -26815`, **595 → 1** `^## ` suite headings — the
largest single loss ever measured on this file (1069+ commits touched it). Nothing ever
detected it and nothing ever repaired it:

- no gate existed on 2026-09-29;
- when `ai/verify_test_suites.sh` (issues #1805/#1849) gained **check 3**, that check
  anchored its baseline to the **newest** destructive commit only (a hard `break`), so
  `30aeecd0d` — older than `136fc4426` and `a2f83249f` — was structurally uncovered;
- measured on the pre-fix tip: the gate reported `7 PASS / 0 FAIL / EXIT=0` while
  **593** of the 595 headings were absent from the working tree (`comm -23` = 593).

## Root cause

1. **Write/write race (same class as #1793/#1805/#1836/#1849).** `tmp/` is git-ignored
   (`.gitignore:47`) and force-tracked, so an agent staged a stale whole working-tree copy
   with `git add -f` instead of appending with `>>`. The commit subject hides the damage.
2. **The gate's anchor was single-point by design.** `ai/verify_test_suites.sh` check 3
   looped over every destructive commit but `break`-ed at the first (newest) confirmed one
   (pre-fix lines 405-421), with the header documenting "Only the NEWEST confirmed
   destructive commit is enforced" (pre-fix lines 329-336). Any loss older than the newest
   clobber was therefore permanent *and silent*: checks 2a/2b compare against the
   merge-base, which already carries the damage.
3. **The stated justification was wrong.** The header claimed older anchors "belong to the
   retired `## N. Name (Suite ID: M)` heading scheme" and would be unsatisfiable. Measured
   over the whole pre-image: only **59 of 595** headings use that scheme; **493 of the 593
   lost headings are modern-scheme** (`## Regression — Issue #…`, `## Suite N — …`,
   `## Task — Issue #…`) — the exact format the gate protects today. The union-of-history
   fear (844/831 in the #1849 notes) does not apply to *per-anchor* enforcement: demanding
   each confirmed anchor's pre-image needed only **31 blocks beyond the 595 era**.

## Method chosen

**(a) Restore, then widen — no retirement.** The issue offered (a) re-append the era or
(b) formally retire it. (a) was chosen because rule 9 makes the file append-only, #1849's
precedent restored rather than retired (`0a39dbaf9`, `c399f198f`), and the measurements
above disprove the unsatisfiability premise.

1. **Restore, append-only** (`tmp/TLU_Test_Cases.md`, never a whole-file write):
   - 593 blocks whose `^## ` heading is absent, sourced from `30aeecd0d^` (the last state
     before the clobber);
   - 31 further blocks the widened gate proved lost elsewhere in history, sourced from the
     newest anchor parent that still holds each heading (`git show <par>:…`);
   - the 2 source-born body-less blocks (`Suite 427`, `Suite 1632`) get a
     one-line provenance body so the structural invariant "no heading without a body"
     holds — every other byte is verbatim;
   - net: `origin/sebiboga...HEAD = +28695 / -0` (zero lines removed, rule 9).
2. **Widen check 3** (`ai/verify_test_suites.sh`): drop the `break`; every confirmed
   destructive commit (numstat candidate `del ≥ 20 && add*2 < del`, confirmed by real
   heading counts `parent ≥ 20 && ha < hb`) is now anchored and enforced, newest-first,
   each with its own `clobber baseline:` line and its own `git show <sha>^:…` recovery
   hint. The delete-only commit `a572b8c75` (419 → 0) stays excluded by the existing
   `git cat-file -e <commit>:<path>` guard. Header docs + `--help` range (`2,66p` →
   `2,75p`) updated; `Refs` line now cites #1851. The `ha < hb` confirmation threshold is
   **kept** — reverting to `ha*2 < hb` would undo #1849's code-review fix for partial
   clobbers (a clobber keeping ≥ 50% of headings); instead the consequences (26 anchors,
   31 extra restorations) were measured and settled in content, not by weakening the gate.

### Alternatives rejected (measured)

- **(b) Formally retire the 595-era + widen with a retirement ledger** — rejected: it
  would codify the mis-characterization ("retired scheme") as a product decision, leave
  493 modern-scheme suites permanently lost, and duplicate #1849's restore precedent
  instead of following it.
- **Revert the anchor confirmation to `ha*2 < hb`** (3 anchors only) — rejected: it
  re-opens the partial-clobber hole #1849's review explicitly closed, just to avoid
  restoring 31 blocks that are, by the repo's own contract, recoverable evidence.
- **Enforce the union of every historical heading** — rejected: 741 of 1007 historical
  headings are absent today and most are not anchor-demanded losses; a permanently-red
  gate gets ignored (same argument as the #1849 notes).
- **Whole-file rewrite from the pre-image** — rejected: violates rule 9 and destroys the
  267 suites written after the clobber; only appends are allowed.

## Files changed

| file | change | purpose |
|---|---|---|
| `tmp/TLU_Test_Cases.md` | `+28695 / -0` | append-only restore of 593 + 31 lost suites, plus the 11-case #1851 regression suite |
| `ai/verify_test_suites.sh` | `+59 / -47` | check 3 enforces every destructive commit; header/`--help` document the new contract |
| `CHANGELOG` | `+18` | rule-22 ledger entry for #1851 |
| `docs/Bugfix-Issue-1851-…md` (this page) | new | documentation of the approach |

No PHP, template, API or locale-bundle file was touched; `config_db.inc.php` never staged.

## Verification (all measured on the final branch)

| test | result |
|---|---|
| repaired tree, mandatory gate | `TLU_REQUIRE_SUITE="Issue #1851"` → **33 PASS / 0 FAIL / 0 SKIP, EXIT=0**, 26 `clobber baseline:` anchors all PASS |
| hole closed: pre-repair tip as candidate | **EXIT=1, 24 FAIL**, incl. `pre-image of destructive commit 30aeecd0d (593 lost)` (old gate: `PASS / EXIT=0` on the same content) |
| isolation: copy minus one restored block | checks 1/2a/2b PASS, check 3 FAIL → detection is genuinely the new anchors |
| append-only | `git diff --numstat origin/sebiboga...HEAD -- tmp/TLU_Test_Cases.md` → `28695 0` |
| delete-commit guard | `a572b8c75` absent from all anchor lines |
| fail-closed paths | outside a git clone → `2 FAIL (unrunnable)`, EXIT=1; shallow clone unchanged |
| `--help` | 74 lines, full header incl. the corrected premise |
| Event Viewer (rule 12) | `SELECT COUNT(*) FROM events` → **0 rows** |
| suites lost vs `30aeecd0d^` | `comm -23 … \| wc -l` → **0** (was 593) |

## Recovery command printed by the gate

```
git show <sha>^:tmp/TLU_Test_Cases.md     # append only blocks whose ^## heading is absent
```

Refs #1851 (follow-up to #1793, #1805, #1849; merge-commit clobber gap remains #1923).
