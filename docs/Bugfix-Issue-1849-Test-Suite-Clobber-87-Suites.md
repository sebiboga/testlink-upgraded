# Bugfix — Issue #1849: a whole-file overwrite destroyed 87 regression suites and the gate certified it as a PASS

* **Issue**: [#1849](https://github.com/sebiboga/testlink-upgraded/issues/1849)
* **Branch**: `fix/issue-1849`
* **Commits**: `4da3ff178` (restore the 87 lost suites), `fb4701018` (the gate's
  new check 3), plus the regression-suite and docs/wiki/CHANGELOG commits
* **Area**: test-suite maintenance / the `ai/*.md` rulebooks. **No application
  code, no API endpoint, no template, no i18n bundle, no DB row** was touched
* **Follow-up**: [#1851](https://github.com/sebiboga/testlink-upgraded/issues/1851)
  — an *earlier, larger* clobber (`30aeecd0d`, 595 → 1 suite heading) that the new
  check deliberately does not cover
* **Severity**: minor for the product, **blocker for the safety net** — 88 issues
  lost their only regression record, and the check every agent run executes to
  protect that record reported `6 PASS / 0 FAIL / EXIT=0` on the damaged file

## Symptom

Commit `136fc4426` — titled `testcases: append suite for #1089
(reqMonitorOverview grouping+toolbar)` — was not an append:

```console
$ git show --numstat --format="%H%n%s%n%ad" 136fc4426 -- tmp/TLU_Test_Cases.md
136fc44262a07b0f35792a934db458408115750d
testcases: append suite for #1089 (reqMonitorOverview grouping+toolbar)
Mon Oct 5 00:11:04 2026 +0000
12	6332	tmp/TLU_Test_Cases.md
```

`tmp/TLU_Test_Cases.md` is rule 9's single shared regression record. It went from
**88 suite headings / 6338 lines** to **1 heading / 12 lines**.

## Root cause

### 1. The replace, not the append

`.gitignore:47` ignores `tmp/`, so the file is only force-tracked
(`git add -f tmp/TLU_Test_Cases.md`). Five CI workflows plus every local run
append to it concurrently (AGENTS.md rule 18), and git cannot merge *uncommitted*
appends. An agent that staged a stale working-tree copy — `git add -f <file>` is a
whole-file commit — replaces the file instead of adding to it. Nothing in the
path detects that: there is no lock, no per-issue split, and the commit message
actively misdescribes the operation.

### 2. Why the mandated gate could not see it

`ai/verify_test_suites.sh` compares the candidate against
`git merge-base HEAD origin/$default_branch`
(`ai/verify_test_suites.sh:190-268`) — a baseline that *moves with the default
branch*. Once a clobbering commit is an ancestor, the merge-base **carries the
damage**, so baseline and candidate agree and nothing is reported missing. This is
the same blind spot recorded in [#1805](https://github.com/sebiboga/testlink-upgraded/issues/1805),
one order of magnitude larger. Measured on the clobbered file, unmodified gate:

```console
$ bash ai/verify_test_suites.sh
suite file: tmp/TLU_Test_Cases.md
  PASS  candidate is readable and non-empty
  PASS  candidate contains suite headings
  PASS  markdown code fences balanced (= 28)
  PASS  no suite heading left without a body (= 0)
baseline: 2db9148b4 (13) -> candidate: 13 suites
  PASS  no suite lost vs merge-base with origin/sebiboga (= 0)
  PASS  no line removed from the suite file vs merge-base with origin/sebiboga (= 0)
  SKIP  own suite not checked (set TLU_REQUIRE_SUITE="Issue #<n>" to check it)
G1805 result: 6 PASS / 0 FAIL / 1 SKIP
EXIT=0
```

87 suites gone, gate green, exit 0.

### 3. The proposed remedy that is wrong (measured, rejected)

The report suggests gating against the union of every historical blob. Measured:

```console
$ git rev-list origin/sebiboga -- tmp/TLU_Test_Cases.md | wc -l      # 1069 blobs
$ union of every historical heading | sort -u | wc -l                 # 844
$ comm -23 union.set candidate.set | wc -l                            # 831 MISSING
```

The file went through a **retired heading scheme**
(`## 41. Modernization — Metrics Dashboard screen (Suite ID: 49)`) before the
current one. A history-union gate would demand 831 headings that are not losses at
all: permanently red, and therefore ignored. Rejected.

### 4. The signature that does work

A destructive commit keeps the parent's suite set nowhere in the file. Scanning
all 1069 commits for that (`tmp/1849/scan.py`: parent ≥ 20 `^## ` headings AND the
commit kept < 50% of them):

```console
commits touching file: 1069
DESTRUCTIVE commits:
   ('136fc4426', 88, 1, 'testcases: append suite for #1089 (reqMonitorOverview ...)')
   ('30aeecd0d', 595, 1, 'feat(opencode): leftover changes from task-implementation run')
```

**2 hits in 1069 commits** — and it surfaced a second, larger, previously
untracked clobber, filed as [#1851](https://github.com/sebiboga/testlink-upgraded/issues/1851).

## Fix

### Part 1 — restore the evidence (`4da3ff178`)

The content still exists in git. Every block of `136fc4426^` whose `^## ` heading is
absent from the current file is re-appended, in chronological order, deduped on the
heading:

```console
$ python3 tmp/1849/restore.py
current blocks: 13  pre blocks: 88  lost: 87
$ grep -cE '^## ' tmp/TLU_Test_Cases.md    # 13 -> 100
$ comm -23 pre.set after.set | wc -l      # 0  (nothing from the lost era still missing)
```

**The net loss was 87, not 88**: `## Regression — Issue #1779` survived the clobber
and was re-appended later, which is why the raw `88 → 1` count overstates it. The
restore is a *union*, not a replacement, so the **12 suites written after the
clobber** (#1089, #1092, #1268, #1275, #1682, #1696, #1703, #1780, #1839, #1840,
#1844, #1845) survive it as well.

### Part 2 — check 3 of the gate (`fb4701018`)

> `no suite lost vs the pre-image of the newest destructive commit in history`

Instead of anchoring to a baseline that moves, the new check anchors to **the
newest commit that REPLACED the suite file** and requires the candidate to still
contain that commit's *parent's* suite headings. A replacement has an unmistakable
`--numstat` signature (`del >= 20 && add*2 < del`), so it is located in a single
`git log --format='C %H' --numstat` pass and then **confirmed** by real heading
counts (`ai/verify_test_suites.sh:281-395`).

Only the **newest** destructive commit is enforced — older ones belong to the
retired heading scheme (1849-24 in the suite rejects that alternative as a false
positive generator).

```console
# the same input the old gate accepted, now rejected
$ bash ai/verify_test_suites.sh <clobbered-file>
clobber baseline: a381901e3^ (88 headings) -> 136fc4426 (1 headings)
  FAIL  no suite lost vs the pre-image of the newest destructive commit 136fc4426 (87 lost)
          LOST: ## Regression — Issue #1688: reqTreeReorder.html - toolbar stayed live on the 403/404 error page
          ...
          that commit replaced the file (88 -> 1 headings); APPEND the blocks, never rewrite the file:
            git show 136fc4426^:tmp/TLU_Test_Cases.md >> tmp/TLU_Test_Cases.md
G1805 result: 6 PASS / 1 FAIL / 1 SKIP
EXIT=1
```

## Two discoveries worth recording

1. **The first version of the extractor was silently broken and produced a false
   PASS** (suite case 1849-22). `git log --format='C %H'` separates the sha with a
   **space**, numstat lines with **tabs**, so one `awk -F'\t'` saw `$2` empty on
   every marker line and matched nothing — the gate cheerfully printed
   `PASS no destructive commit in the suite-file history` while `136fc4426` sat in
   its own input. Fixed by parsing with `read` plus explicit digit guards, and —
   the part that matters — by adding a sanity assertion: when **no** numstat block
   can be parsed, the check reports *unrunnable*, never PASS. A gate that fails open
   is worse than no gate.
2. **Only the newest destructive commit is enforced, by design.** `30aeecd0d`
   (595 → 1) stays uncovered; a suite from that era can still be destroyed silently.
   Tracked in [#1851](https://github.com/sebiboga/testlink-upgraded/issues/1851) with
   the product decision it needs.

## Verification

Regression suite `Regression — Issue #1849` (26 cases) in `tmp/TLU_Test_Cases.md`.
Primary symptom gone, gate green on the restored file:

```console
$ TLU_REQUIRE_SUITE="Issue #1849" bash ai/verify_test_suites.sh
baseline: 2db9148b4 (13) -> candidate: 101 suites
  PASS  no suite lost vs merge-base with origin/sebiboga (= 0)
  PASS  no line removed from the suite file vs merge-base with origin/sebiboga (= 0)
  PASS  own suite heading present (Issue #1849)
clobber baseline: a381901e3^ (88 headings) -> 136fc4426 (1 headings)
  PASS  no suite lost vs the pre-image of the newest destructive commit 136fc4426 (= 0)
G1805 result: 8 PASS / 0 FAIL / 0 SKIP
EXIT=0            real 0m5.6s
```

Cost of the new check over a 1069-commit history: 5.6 s for the whole gate.
