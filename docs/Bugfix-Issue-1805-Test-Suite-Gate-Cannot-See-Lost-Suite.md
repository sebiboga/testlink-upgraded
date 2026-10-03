# Bugfix — Issue #1805: the mandated suite-file gate could not detect a lost suite (3rd occurrence of the class tracked in #1793)

* **Issue**: [#1805](https://github.com/sebiboga/testlink-upgraded/issues/1805)
* **Branch**: `fix/issue-1805`
* **Commits**: `96632e116` (the gate), `583854d4b` (the three rulebooks),
  `e6eec7c37` (regression suite), plus the docs/wiki/CHANGELOG commit
* **Area**: test-suite maintenance / the `ai/*.md` rulebooks. **No application
  code, no API endpoint, no template, no i18n bundle, no DB row** was touched
* **Severity**: minor for the product, **major for the safety net** — the check
  every agent run executes to protect the shared QA evidence trail could not
  detect the loss it was written to prevent, and the loss had already happened
  three times.

## Symptom as reported, and what was actually still open

The report says the whole `## Task — Issue #1048: Implement SSO auto-login …
` suite is absent from the default branch, and that the mandated gate passes on
a tree where a suite is missing.

**Correction, measured.** The *data loss* was already repaired: `966a7997d`
(`feat(#1286)`, `141 0` on the suite file) carried the `#1048` block back to the
default branch. At `dea6f4bea` the file holds 55 suite headings and
`grep -c "Issue #1048"` returns `1`. **There was nothing left to restore.**

What remained open is the part the report itself calls *the silent part*: the
absence of any check that can **see** such a loss. That is what this fix adds.

## Measured evidence — the mandated gate passes while a suite is gone

Reproduction (`/tmp/repro1805`, a throwaway clone seeded with the tracked suite
file via `git show HEAD:tmp/TLU_Test_Cases.md`; 4 commands, no app or DB
involved — the defect is in the verification procedure, not in the product):

```console
BASE           suites=55  #1048=1
AFTER          suites=56  #1048=0          <-- a whole suite GONE

==== MANDATED GATE, rule 9 of ai/AGENTS.md ====
$ git diff --numstat HEAD~1 HEAD -- t.md
4	0	t.md
=> 0 deletions: GATE PASSES

==== what the gate is blind to ====
$ comm -23 <(grep -E "$H" base.md | sort) <(grep -E "$H" t.md | sort)
## Task — Issue #1048: Implement SSO auto-login (SSO_enabled) + ssodisable bypass in login.html (gap vs legacy)
$ git diff --numstat <base> HEAD -- t.md
8	23	t.md                      <-- 23 deleted lines, invisible to the gate
```

### Three blind gates, not two — the report's own remedy is also blind

| gate (all three were mandated) | why it cannot see the loss (measured) |
|---|---|
| `git diff --cached --numstat -- tmp/TLU_Test_Cases.md` | empty **by construction** once the file is staged with `-f`; the file is git-ignored (`.gitignore:47`), so `git add` refuses it without `-f` |
| `git diff --numstat HEAD~1 HEAD -- tmp/TLU_Test_Cases.md` | a **per-commit** property. The loss enters in an *earlier* commit of the branch (the rebase resolution), so every later commit is append-only against it: measured `4 0` on a file that had lost 23 lines |
| `grep -cE "^## (Regression\|Suite\|Task\|Modernize) "` must not drop | a **COUNT, not a set**. The same reproduction grows **55 → 56** suites while deleting one, so this passes too |

The third row matters beyond this issue: it is the check the report's *Expected*
section proposes to **add**, so the report's own remedy would not have caught its
own bug. Only a **set difference over suite headings** can.

## How the loss enters — git behaviour, not carelessness

Both sides of a rebase append at EOF of a 4.5k-line file, so git reports a
conflict whose hunk starts a few lines **after** the
`## Task — Issue #1048` heading. Everything outside the marked region is
auto-resolved — here git took **upstream** for the heading line — so the heading
survives while the suite body, absent from the agent's stale copy, is never
restored. The agent then resolves the *visible* markers; scanning only the
markers shows nothing wrong, the commit is append-only relative to its rebased
parent, and every per-commit gate reports 0 deletions.

The invariant that actually matters is a **merge-base** property — *"every suite
heading that existed before this run still exists"* — and nothing in the
repository computed it. `docs/Bugfix-Issue-1793-…` had already reached that
conclusion for its own deletion gate ("compares against the merge-base with the
default branch, not `HEAD~1`") but never turned it into a runnable check.

## The fix — `ai/verify_test_suites.sh`

A tracked, read-only gate next to the rulebooks that mandate it. It compares the
candidate suite file against the **merge-base with `origin/<default>`**
(`origin/HEAD` as a second try; the root commit is *not* used as a baseline —
see "an unrunnable check is a FAIL") and checks seven invariants:

| # | invariant | catches |
|---|---|---|
| 1 | candidate is readable and non-empty | an unreadable/truncated candidate must stop the run, not be scored on empty output |
| 2 | candidate still contains suite headings | truncated / empty file |
| 3 | markdown code fences balanced (count > 0 and even) | a block cut mid-block |
| 4 | no suite heading left **without a body** (body == 0) | the #1805 auto-resolution signature: heading kept, body never restored |
| 5 | **no suite lost vs the merge-base** — set difference over the `^## ` suite headings | the reported defect, and delete-one/append-two clobbers a count misses |
| 6 | no line removed vs the merge-base | a loss introduced in an *earlier* commit of the branch (invisible to `HEAD~1`) |
| 7 | the run's own suite **heading** is present (`TLU_REQUIRE_SUITE="Issue #<n>"`) | the agent forgetting its own suite |

Two pattern details that a first draft got wrong and code review caught:

* **`H='^## '`, not `H='^## (Regression|Suite|Task|Modernize) '`.** All 60 `## `
  headings in the tracked file are suites, but four do not use those prefixes
  (`## T1637 — Regression: …`, `## 1783 — Regression: …`,
  `## 1785 — Modernize …`, `## 1787 — Modernize …`), so the narrow pattern
  silently ignored them — four suites whose loss check 6 would still catch, but
  not check 5. Matching the heading level is the safer default here: the
  consequence of a false positive is a conservative FAIL, the consequence of a
  false negative is a lost suite.
* **`TLU_REQUIRE_SUITE` is matched against headings, case-insensitively.**
  A whole-file `grep -F` is a proven false negative — the issue number also occurs
  in prose, so the gate reported `own suite present` on a candidate from which
  that very suite had been deleted — and three real headings spell it lowercase
  (`## Suite 1644 — … (issue #1644)`), a proven false positive.

Usage — `bash ai/verify_test_suites.sh [--allow-skip] [<candidate-file>]`.

Two deliberate design points:

* **An unrunnable check is a FAIL, never a silent PASS.** Outside a clone, checks
  5–7 report FAIL and exit 1; only an explicit `--allow-skip` downgrades them to a
  visible `SKIP` with exit 0. The same applies to a *weak* baseline: when neither
  `origin/<default>` nor `origin/HEAD` is reachable, the root commit cannot
  support the claim "nothing that existed before this run is missing" (it only
  contains the suites from repository start), so it is reported as unverifiable
  rather than silently accepted — a first draft used it as a silent fallback and a
  review reproduced three healthy suites being declared LOST off it. For the same
  reason each command substitution is status-checked: an empty result from a
  *failed* `grep`/`diff`/`comm` is not evidence of zero.
* **It lives in `ai/`, not `tmp/`.** The 22 sibling harnesses
  (`tmp/verify_*.sh` — 28 of them) sit under the *git-ignored* `tmp/` and survive
  only because they were force-added — the very ignore rule that makes this bug
  possible would also make the fix vanish. `ai/verify_test_suites.sh` is tracked by an ordinary
  `git add` and cannot be lost that way.

The script never writes, moves or deletes the suite file or anything in the
repository; it only creates and removes scratch directories under `$TMPDIR`
(cleaned up by an `EXIT`/`INT`/`TERM` trap) while resolving the baseline.

### Rulebook changes (3 files, prose only)

`ai/AGENTS.md` rule 9, `ai/FIX-ISSUE.md` §5 and `ai/IMPLEMENT-TASK.md` §5 now
require `TLU_REQUIRE_SUITE="Issue #<n>" bash ai/verify_test_suites.sh`, keep the
two numstat checks as *extra evidence only*, and state — with the measured
numbers above — why the per-commit gate and the count cannot work. All three were
edited in one commit so they cannot drift. Hardening only `ai/AGENTS.md` would
have left the hole open in the two rulebooks whose runs actually produce the
clobber (the conclusion #1793 reached).

Review note: the first version of the `ai/FIX-ISSUE.md` edit *added* the new
bullet while leaving the old "verify `git diff --cached --numstat` shows 0
deletions" sentence in place immediately above it, so that rulebook alone still
mandated the blind gate and contradicted itself. The old sentence was replaced,
as in the other two files.

### Verification

```console
$ bash -n ai/verify_test_suites.sh                       # syntax OK, no output
$ TLU_REQUIRE_SUITE="Issue #1048" bash ai/verify_test_suites.sh
suite file: tmp/TLU_Test_Cases.md
  PASS  candidate is readable and non-empty
  PASS  candidate contains suite headings
  PASS  markdown code fences balanced (= 72)
  PASS  no suite heading left without a body (= 0)
baseline: dea6f4bea (59) -> candidate: 60 suites
  PASS  no suite lost vs merge-base with origin/sebiboga (= 0)
  PASS  no line removed from the suite file vs merge-base with origin/sebiboga (= 0)
  PASS  own suite heading present (Issue #1048)
G1805 result: 7 PASS / 0 FAIL / 0 SKIP          # exit 0
```

Negative controls — a gate that only ever passes proves nothing:

All commands below run `bash ai/verify_test_suites.sh [<candidate>]`; the counts
are the verbatim final lines of each run.

| control | doctored candidate | result |
|---|---|---|
| NC1 — the reported defect, the whole `#1048` block removed, `TLU_REQUIRE_SUITE="Issue #1048"` | `awk '/^## Task — Issue #1048: …/{s=1} s&&/^## /&&!/1048/{s=0} !s'` | **4 PASS / 3 FAIL, exit 1** — `LOST: ## Task — Issue #1048: …`, `no line removed … (= 23)`, `own suite heading present` FAIL, recovery hint |
| NC2 — **a different** suite removed while another is appended (with a body), heading count **60 → 60** | `#1286` block removed, `## Regression — Issue #8888` appended, `TLU_REQUIRE_SUITE="Issue #8888"` | **5 PASS / 2 FAIL, exit 1** — `LOST: ## Task — Issue #1286: …`, `no line removed … (= 113)`, own-suite PASS — the count gate reports "fine" on this file |
| NC3 — normal flow: append one well-formed suite, `TLU_REQUIRE_SUITE="Issue #8888"` | `60 suites` | **7 PASS / 0 FAIL, exit 0** |
| NC4 — run outside a git clone | `cd /tmp && bash …/verify_test_suites.sh t.md` | **4 PASS / 1 FAIL, exit 1**; with `--allow-skip` → 4 PASS / 0 FAIL / **1 SKIP**, exit 0 |
| NC5 — clone whose root commit holds the file but with no `origin/*` refs | `git init` + one commit | **4 PASS / 1 FAIL, exit 1** — `baseline fell back to the root commit … so 'no suite lost' cannot be verified` |
| NC6 — a suite heading appended with no body (the #1805 signature), `TLU_REQUIRE_SUITE="Issue #7777"` | `## Task — Issue #7777: header survived, body lost` | **6 PASS / 1 FAIL, exit 1** — `EMPTY: ## Task — Issue #7777: …` |
| NC7 — unreadable candidate (`chmod 000`) | — | **0 PASS / 1 FAIL, exit 1** — a `grep` that cannot read the file returns an empty count, which must not be scored as "zero problems" |
| NC8 — tracked file absent from the worktree (sparse checkout) | `git rm --cached` + delete | **4 PASS / 1 FAIL, exit 1** — the index fallback resolves, the weak baseline is reported; a first draft wrote the scratch copy into a non-existent directory and then "verified" a file it never wrote |
| NC9 — unknown option `--bogus` | — | `unknown option: --bogus`, exit 1 — a typo must not be read as a candidate path |
| NC10 — invoked from `ai/` instead of the repo root | — | **6 PASS / 0 FAIL / 1 SKIP, exit 0** — the suite path is resolved against the repository toplevel |

Event Viewer: `SELECT COUNT(*) FROM events;` → **0 rows**, unchanged. Nothing in
this change reaches PHP at all.

## Regression suite

`## Regression — Issue #1805: ai/verify_test_suites.sh sees the suite loss that the
mandated gates cannot` in `tmp/TLU_Test_Cases.md` — 10 steps covering the syntax
gate, the clean tree, the original loss, the count-is-not-a-set control, the
append-only false-positive control, the three unrunnable-gate controls, the
header-only signature, the post-append re-run and the `events` table, plus the
four controls that code review added (unreadable candidate, index fallback, unknown
option, invocation from `ai/`). **10/10 PASS.** Appended with `>>` — **97
insertions / 0 deletions** against `dea6f4bea`, verified with
`git diff --numstat dea6f4bea -- tmp/TLU_Test_Cases.md` and, after committing,
with `git diff --numstat HEAD~1 HEAD`.

## Alternatives rejected

* **Re-restore the `#1048` suite** — already restored by `966a7997d`; measured
  1/1 present at `dea6f4bea`. Nothing to restore.
* **Add the count check the report proposes** — measured blind (row 3 of the
  blind-gates table). Rejected on evidence, not on preference.
* **A sixth CI workflow running the gate** — rejected **on rule 18** (do not
  fight the five existing workflows), and noted as the natural follow-up: the
  suite file *is* tracked (`git ls-tree origin/sebiboga tmp/TLU_Test_Cases.md` →
  a blob), and `.gitignore` does not affect path filters on tracked paths, so an
  unconditional `on: push` step running `bash ai/verify_test_suites.sh` would work
  today and none of the five workflows does (they only mention the file in their
  agent prompts). A first draft of this page rejected the option on the claim
  that a git-ignored path cannot drive a `paths:` filter; that premise was wrong
  and is corrected here. Server-side enforcement is the real answer to a bug that
  has now recurred three times, but it is a workflow change, not a bug fix.
* **Move the suite file out of `tmp/` to a tracked path** — removes the root
  ignore problem, but breaks the reference contract of every existing
  `Refs #<n>` suite and every in-flight agent. Recorded as a follow-up (already
  noted in #1793); out of scope for a bug fix. The gate script is placed in `ai/`
  instead so at least the *check* is unconditionally tracked.
* **Prose-only guidance for rebase conflicts** — cannot help: the loss is produced
  by git's auto-resolution *outside* the conflict markers, i.e. invisible to any
  review that reads the markers.

## Files changed

| File | Change |
|---|---|
| `ai/verify_test_suites.sh` | **new**, executable — the merge-base gate (+281/-0 vs dea6f4bea, after the review fixes) |
| `ai/AGENTS.md` | rule 9: the ineffective checks replaced by the script, with the measured reason each is blind |
| `ai/FIX-ISSUE.md` | §5: same |
| `ai/IMPLEMENT-TASK.md` | §5: same |
| `tmp/TLU_Test_Cases.md` | append-only: the `## Regression — Issue #1805` suite (+97/-0 vs `dea6f4bea`) |
| `CHANGELOG` | one `[TESTING]` line under 2.0.1 |
| `docs/Bugfix-Issue-1805-…md` + the wiki page of the same name | this document |

No application code, API endpoint, template, locale bundle or DB schema was
touched, so no Event Viewer / `events` entry can be produced by this change
(verified: `SELECT COUNT(*) FROM events;` → `0`).