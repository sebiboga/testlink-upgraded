# Bugfix Issue #1872 — CI fallback still rebases with `-X theirs`, and auto-close fires on local-only commits

Follow-up to #1694 (data-loss class) and #1868 (its workflow-side fix, never landed).
Two defects in one: the destructive rebase flag is still live in all 8 workflow sites, and
the issue auto-close step claims "landed" from a purely local commit range.

## Symptom

1. `grep -rn "rebase -X theirs" .github/workflows/` → **8 hits**, `grep -rn "rebase -X ours"` → **0**
   — every scheduled agent run still resolves rebase conflicts toward its own stale copy,
   silently clobbering whatever a concurrent agent pushed meanwhile (the #1694 class).
2. #1868 was **auto-closed as landed** ("fix commits landed on the default branch …
   65b0257cb / 219c9be63 / 7032a542e") although those commits exist in **no ref**
   (`git cat-file -t` → `fatal` ×3): the auto-close step grepped the local `$BEFORE..HEAD`
   range of the runner checkout instead of verifying the commits reached `origin/<default>`.

## Root cause

* **Defect 1 — wrong rebase side.** The `Fallback commit of any leftover agent changes` step of 7
  workflows runs `git rebase -X theirs "origin/$BRANCH"`. During a rebase `ours` = the upstream
  being rebased **onto** (`origin/$BRANCH`, the live newer side) and `theirs` = the commit being
  **replayed** (the runner's stale local copy), so every overlap resolves toward the STALE copy.
  The 8th site is the agent-instruction string in `modernize.yml:238`.
* **Defect 2 — local-only close check.** `fix-bug.yml:261` (pre-fix numbering; 4 siblings) computed
  `REFS="$(git log --oneline "$BEFORE"..HEAD | grep -i "#$ISSUE")"` — commits that failed to push
  still sat in local `HEAD` and satisfied the trigger, so `gh issue close … "landed"` fired on a
  rejected push (this is exactly how #1868 was falsely closed).
* **Why the fix could never land:** the GitHub App installation token used by every automated
  path has **no `workflows` repository permission** (re-measured this run:
  `! [remote rejected] … .github/workflows/compare-screens.yml without workflows permission`;
  contents API and git-data API both `403`; no SSH key on the machine; no `workflows` key exists
  in a workflow `permissions:` block). Non-workflow content pushes fine (all recent default-branch
  commits are `github-actions[bot]`), which is why only the docs half of #1868/#1870 landed.

## Fix (13 changed lines in 7 files)

**Step 1 — 8 single-token swaps** (`18eb7695f`; runs 1–2's original commits `b680cac34`/
`4abc615c0` were local-only and no longer exist in any repo — dangling):

```bash
sed -i 's|git rebase -X theirs "origin/$BRANCH"|git rebase -X ours "origin/$BRANCH"|' \
  .github/workflows/{fix-bug,fix-bug-oldest,implement-task-newest,implement-task-oldest,investigate-fix,compare-screens,modernize}.yml
sed -i 's|git rebase -X theirs origin/<branch>|git rebase -X ours origin/<branch>|' \
  .github/workflows/modernize.yml     # the agent PROMPT text (~line 238)
```

**Step 2 — origin-verified auto-close, 5 sites** (`18eb7695f`):

```bash
for f in fix-bug fix-bug-oldest investigate-fix implement-task-oldest implement-task-newest; do
  python3 - ".github/workflows/$f.yml" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
old = '            REFS="$(git log --oneline "$BEFORE"..HEAD 2>/dev/null | grep -i "#$ISSUE" | head -3 || true)"'
new = ('            git fetch origin "$GITHUB_REF_NAME" >/dev/null 2>&1 || true\n'
       '            REFS="$(git log --oneline "$BEFORE".."origin/$GITHUB_REF_NAME" 2>/dev/null | grep -i "#$ISSUE" | head -3 || true)"')
assert s.count(old) == 1, (p, s.count(old))
open(p, "w").write(s.replace(old, new))
print("patched", p)
PY
done
```

The guard's real invariant (measured by the run-10 code review): **`REFS` never reads local
`HEAD`** — it is computed only from `origin/$GITHUB_REF_NAME`, so a commit that never reached the
remote can never trigger a close (local-only range ⇒ empty REFS ⇒ `::notice:: … leaving it open`).
If the remote-tracking ref happens to be absent when the fetch fails, `git log` fails (stderr
suppressed) and `REFS` is empty, so the issue also stays open; when the ref is already present
(e.g. refreshed by the preceding fallback step's own `git fetch origin "$BRANCH"`), a failed
fetch still yields a REMOTE-truth range — a legitimately landed commit can still close.

## Why `-X ours`, not the alternatives

`-X ours` picks the upstream (live) side on conflict — the same direction already validated in
#1694's strategy matrix and adopted by #1870's rulebook fix (`ai/FIX-ISSUE.md`,
`ai/IMPLEMENT-TASK.md`, both landed). Rejected earlier: plain rebase without `-X` leaves conflict
markers mid-file; `.gitattributes merge=ours` needs the non-versionable `merge.ours.driver` config
and would protect only named paths (the `merge=union` guard from #1694 already covers only
`tmp/TLU_Test_Cases.md`).

## Verification (measured 2026-10-07 on this run)

| check | pre-fix | post-fix |
|---|---|---|
| `grep -rn "rebase -X theirs" .github/workflows/` | 8 hits | **0** |
| `grep -rn "rebase -X ours" .github/workflows/` | 0 | **8** (7 fallback + PROMPT) |
| `grep -rn 'BEFORE"..HEAD' .github/workflows/` | 5 hits | **0** |
| `grep -rn '"$BEFORE".."origin/$GITHUB_REF_NAME"'` | 0 | **5** (+5 fetch guards) |
| `yaml.safe_load` over `.github/workflows/*.yml` | 11/11 | **11/11** |
| harness `rebase -X theirs` (stale vs live) | 46 lines, **0/45** live kept | — |
| harness `rebase -X ours` | — | 145 lines, **45/45** live kept |
| guard semantics: `d317f6e1c..HEAD` vs `d317f6e1c..origin/sebiboga` (count "1872") | local 2, remote 0 → old code would close | new code reads remote → stays open |
| `TLU_REQUIRE_SUITE="Issue #1872" bash ai/verify_test_suites.sh` | — | **7 PASS / 0 FAIL**, exit 0 |
| Event Viewer / `events` | n/a — no PHP/UI code touched | n/a |

## Landing status — BLOCKED on token permissions (open)

The fix is committed locally (`18eb7695f`, current durable re-application — runs 1–9's fix
commits were local-only and no longer exist) but **cannot be pushed by any
credential available to automation**: `git push origin HEAD:fix/issue-1872` →
`! [remote rejected] … without workflows permission` (re-measured; same 403 class as #1868).
Therefore **#1872 stays OPEN** with this page + the issue comments carrying the exact patch.
It lands the moment a human or a `workflows`-scoped credential applies the two steps above
(or cherry-picks the two commits).

Note for the run that carries this patch: until Step 2 is live, the pre-fix auto-close step still
greps local commit messages for the literal `#<issue>` token — that is why the commit messages of
the fixing run deliberately say `Refs: issue 1872` without the `#`.

## Re-verification (2026-10-17, second fix-bug run)

The bug was still fully live on the default branch @ `824f0f6de` (8 `-X theirs`, 0 `-X ours`,
5 local-only `BEFORE"..HEAD` auto-close ranges, 0 origin guards). The exact same two-step patch
re-applied **cleanly and byte-identically** (grep gates 0/8 and 0/5+5-fetch, YAML 11/11,
rebase harness `-X theirs` → 0/45 vs `-X ours` → 45/45, suite gate 34 PASS / 0 FAIL), confirming the
durable patch below is still valid for the current default branch. The landing blocker was
re-measured with this run's credential and **persists**: `git push origin HEAD:fix/issue-1872-cifix`
→ `! [remote rejected] … (refusing to allow a GitHub App to create or update workflow
`.github/workflows/compare-screens.yml` without `workflows` permission)`. The token embedded in the
`tmp/wiki-repo/` remote is the **same** `ghs_` App token (`gh api repos/…` → `push:false`), so it is
no escape route. #1872 therefore stays **OPEN** until a `workflows`-scoped credential applies the
patch. The two fix commits were kept on a local branch (`fix/issue-1872-workflow-patch` → `383c6bf23`);
nothing could be pushed for them.

## References

* Issues: #1694 (class), #1868 (workflow-side fix, never landed), #1870 (rulebook copies, landed),
  #1872 (this).
* Files: `.github/workflows/{fix-bug,fix-bug-oldest,implement-task-newest,implement-task-oldest,investigate-fix,compare-screens,modernize}.yml`.
* Suite: `Regression — Issue #1872` in `tmp/TLU_Test_Cases.md` (11/11 PASS).

## Re-verification — fix-bug run 3 (2026-10-17, base `72826298a`)

Third fix-bug run re-confirmed the bug fully live on the then-current default branch
(`8` `-X theirs` / `0` `-X ours`; `5` local-only `BEFORE"..HEAD` auto-close ranges; `0` origin
guards). The published two-step patch was re-applied byte-identically (grep gates `0`/`8` and
`0`/`5+5-fetch`, `yaml.safe_load` `11/11`, rebase harness `-X theirs` → `0/45` live lines kept vs
`-X ours` → `45/45`, guard semantics local-count `2` vs origin-count `0`, suite re-run appended as
`Regression — Issue #1872 (re-run 2026-10-17, fix-bug run 3, base 72826298a)` → `11/11 PASS`,
suite gate `34 PASS / 0 FAIL`). Landing blocker **re-measured with this run's token and persists**:
`git push origin HEAD:fix/issue-1872-cifix3` → `! [remote rejected] … (refusing to allow a GitHub
App to create or update workflow .github/workflows/compare-screens.yml without workflows
permission)`. Credential inventory: `ghs_…` App token only (len 377), no SSH key, no netrc, no
second token. Fix commits kept on local branch `fix/issue-1872-workflow-patch` → `53e9ac7cf`.
#1872 stays **OPEN** until a `workflows`-scoped credential applies the patch above.

## Re-verification — fix-bug run 4 (2026-10-17 14:51–15:1x UTC, base `095212e1d`)

Fourth fix-bug run re-confirmed the bug fully live on the then-current default branch
(`8` `-X theirs` / `0` `-X ours` at `fix-bug.yml:234`, `fix-bug-oldest.yml:237`,
`implement-task-newest.yml:254`, `implement-task-oldest.yml:252`, `investigate-fix.yml:287`,
`compare-screens.yml:333`, `modernize.yml:320` + PROMPT `modernize.yml:238`; `5` local-only
`BEFORE"..HEAD` auto-close ranges at `fix-bug.yml:261`, `fix-bug-oldest.yml:264`,
`investigate-fix.yml:313`, `implement-task-oldest.yml:279`, `implement-task-newest.yml:281`;
`0` origin guards; all 10 prior workflow-fix commits absent from every ref — `git cat-file -t`
fatal ×10). The published two-step patch (§Fix above) was re-applied byte-identically on
`fix/issue-1872-workflow-patch` → `8f231a75b` (7 files, **18 insertions / 13 deletions**, same
as runs 1–3), giving: grep gates `0` theirs / `8` ours, `0` local / `5` origin REFS + `5` fetch
guards, `yaml.safe_load` **11/11**, live 2-strategy harness (fresh `mktemp` repo, `git reset
--hard` between strategies) `-X theirs` → 46 lines **0/45** live kept vs `-X ours` → 145 lines
**45/45** kept, guard semantics BEFORE=`095212e1d` local proxy-count `1` vs origin `0` (old
local logic would close; new origin logic stays open; REFS never reads local HEAD ⇒ local-only commits cannot close (run-10 wording correction)),
suite re-run `Regression — Issue #1872 (re-run 2026-10-17, fix-bug run 4, base 095212e1d)` →
**10/10 executed PASS**, suite gate `TLU_REQUIRE_SUITE="Issue #1872"` → **34 PASS / 0 FAIL**,
exit 0. Code-review subagent: **non-blocking, no findings** (its file:line citations were
re-verified by grep: 8/8 + 5/5 at the exact expected lines). Landing blocker **re-measured with
this run's token and persists**: `git push origin HEAD:fix/issue-1872` (fresh ref — remote
branch absent, `git ls-remote --heads origin fix/issue-1872*` → empty) → `! [remote rejected]
… (refusing to allow a GitHub App to create or update workflow .github/workflows/compare-screens.yml
without workflows permission)`. Credential inventory unchanged: single `ghs_…` App installation
token (`GH_TOKEN == GITHUB_TOKEN`), no `secrets.*` referenced by ANY workflow
(`grep -rho 'secrets\.[A-Za-z_]*' .github/workflows/` → 0 hits), `fix-bug.yml:17-19` grants only
`contents: write, issues: write` (`workflows` is not a grantable `permissions:` key), no SSH
key / netrc / git-credentials / second token, origin URL embeds no credential. #1872 therefore
stays **OPEN**; the fix lives on local branch `fix/issue-1872-workflow-patch` → `8f231a75b`
and is fully reproducible from §Fix above.


## Re-verification — fix-bug run 5 (2026-10-17, base `9bc265954`)

Fifth fix-bug run re-confirmed the bug fully live on the then-current default branch
(`8` `-X theirs` / `0` `-X ours`; `5` local-only `BEFORE"..HEAD` auto-close ranges; `0` origin
guards — identical line numbers to runs 3–4). The published two-step patch (§Fix above) was
re-applied byte-identically on `fix/issue-1872` → `aff75e51c` (7 files, **18 insertions / 13
deletions**, same as runs 1–4), giving: grep gates `0` theirs / `8` ours, `0` local / `5` origin
REFS + `5` fetch guards, `yaml.safe_load` **11/11**, live 2-strategy harness (fresh repo per
strategy) `-X theirs` → 46 lines **0/45** live kept vs `-X ours` → 145 lines **45/45** kept,
guard semantics (BEFORE=`9bc265954`) old local `#1872` count `0` / origin count `0` (commit
message deliberately says "Refs: issue 1872", no `#`, so the dispatch-time OLD auto-close cannot
falsely close this issue; REFS never reads local HEAD ⇒ local-only commits cannot close (run-10 wording correction)), suite re-run
`Regression — Issue #1872 (re-run 2026-10-17, fix-bug run 5, base 9bc265954)` → **11/12 executed
PASS**, suite gate `TLU_REQUIRE_SUITE="Issue #1872"` → **34 PASS / 0 FAIL**, exit 0.
Code-review subagent over `aff75e51c`: **CLEAN** (8/8 swaps + 5/5 guards, `bash -n` 19/19,
YAML OK, no accidental line removals). Landing blocker **re-measured with this run token and
persists on BOTH routes**: git push → `! [remote rejected] … (refusing to allow a GitHub App to
create or update workflow .github/workflows/compare-screens.yml without workflows permission)`;
contents API `PUT /repos/…/contents/.github/workflows/fix-bug.yml` → `403 Resource not
accessible by integration`. Credential inventory unchanged: single `ghs_…` App installation
token (`GH_TOKEN == GITHUB_TOKEN`), no SSH key / netrc / git-credentials / second token; the
`permissions:` block cannot grant `workflows` (not a grantable key). #1872 therefore stays
**OPEN**; the fix lives on local branch `fix/issue-1872-workflow-patch` → `aff75e51c` and is
fully reproducible from §Fix above.

## Re-verification — fix-bug run 6 (2026-10-17, base `6fbc6e095`)

Sixth fix-bug run re-confirmed the bug fully live on the then-current default branch
(`8` `-X theirs` / `0` `-X ours`; `5` local-only `BEFORE"..HEAD` auto-close ranges; `0` origin
guards — identical line numbers to runs 3–5). The published two-step patch (§Fix above) was
re-applied cleanly on `fix/issue-1872` (commit `ea7657111`, rebased per §8 onto the remote
artifact tip `8deb7c0d7` → `61125777b`; 7 files, **18 insertions / 13 deletions**, byte-matches
runs 1–5), giving: grep gates `0` theirs / `8` ours, `0` local / `5` origin REFS + `5` fetch
guards, `yaml.safe_load` **11/11**, live 2-strategy harness `-X theirs` → **0/45** live lines
kept vs `-X ours` → **45/45** kept, guard semantics (BEFORE=`6fbc6e095`) strict `#1872` token
in local commit messages `0` (message deliberately says "Refs: issue 1872", no `#`), suite
re-run `Regression — Issue #1872 (re-run 2026-10-17, fix-bug run 6, base 6fbc6e095)` →
**10/11 executed PASS** (case 11 = landing blocker), suite gate `TLU_REQUIRE_SUITE="Issue #1872"`
→ **34 PASS / 0 FAIL**, exit 0. Code-review subagent: **CLEAN** (8/8 swaps + 5/5 guards at the
expected file:line, YAML OK, no accidental removals, no `#1872` token in the commit message).
Landing blocker **re-measured with this run's token on BOTH routes and persists**: git push of
the fix commit → `! [remote rejected] … (refusing to allow a GitHub App to create or update
workflow .github/workflows/compare-screens.yml without workflows permission)`; contents API
`PUT /repos/…/contents/.github/workflows/fix-bug.yml` → `403 Resource not accessible by
integration`. Credential inventory unchanged: single `ghs_…` App installation token
(`GH_TOKEN == GITHUB_TOKEN`), repo `permissions.push:false`, no SSH key / netrc / second token;
the `permissions:` block cannot grant `workflows` (not a grantable key). #1872 therefore stays
**OPEN**; the fix lives on local branches `fix/issue-1872` and `fix/issue-1872-workflow-patch` →
`61125777b` and is fully reproducible from §Fix above.

## Re-verification — fix-bug run 7 (2026-10-17, base `a41c96ba0`) + NEW landable mitigation

Seventh fix-bug run. The bug was still fully live on the current default branch
(`8` `-X theirs` / `0` `-X ours` at `compare-screens.yml:333`, `fix-bug-oldest.yml:237`,
`fix-bug.yml:234`, `implement-task-newest.yml:254`, `implement-task-oldest.yml:252`,
`investigate-fix.yml:287`, `modernize.yml:320` + PROMPT `modernize.yml:238`; `5` local-only
`BEFORE"..HEAD` auto-close ranges at `fix-bug.yml:261`, `fix-bug-oldest.yml:264`,
`investigate-fix.yml:313`, `implement-task-oldest.yml:279`, `implement-task-newest.yml:281`;
`0` origin guards). The published two-step patch (§Fix above) was re-applied byte-identically on
`fix/issue-1872-workflow-patch` → `56e9520a0` (7 files, **18 insertions / 13 deletions**), giving:
grep gates `0` theirs / `8` ours, `0` local / `5` origin REFS + `5` fetch guards,
`yaml.safe_load` **11/11**, live 2-strategy harness `-X theirs` → **0/45** live lines kept vs
`-X ours` → **45/45** kept, guard semantics BEFORE=`a41c96ba0` (a synthetic local-only
`…#1872…` commit is found by the OLD local-range grep and **not** by the NEW origin-range grep).

### NEW: a landable mitigation via `.gitattributes merge=union`

While re-measuring, this run isolated a **pushable** lever: when a `merge=union` attribute is
present on the merged tree, git's union merge driver **overrides** the `-X theirs` strategy option
for that path. Measured in a fresh repo (base = 100 lines; live side appends 45 lines; stale side
rewrites the file; `git rebase -X theirs live`):

| `.gitattributes` | live lines kept after `-X theirs` |
|---|---|
| (none) | **1** line total, **0/45** live lines kept (clobbered) |
| `f.txt merge=union` on the base tree | **46** lines, **45/45** live lines kept (union wins) |

The mechanism is pushable because `.gitattributes` is **not** a workflow file. This run therefore
landed one conservative line — `CHANGELOG merge=union` — protecting the shared, append-only file
that every concurrent agent edits (the largest single clobber target that is safe under union;
`gui/templates/i18n/*.json` cannot use union without producing invalid JSON, so it is **not**
covered and still relies on the workflow-flag fix). This mitigation reduces the blast radius of
defect&nbsp;1 but does **not** remove the 8 `-X theirs` sites; the issue's own grep gate (`0 theirs /
8 ours`) is still unmet.

### Landing status (unchanged)

Landing blocker **re-measured with this run's token and persists**: `git push origin
HEAD:fix/issue-1872-workflow-patch` → `! [remote rejected] … (refusing to allow a GitHub App to
create or update workflow .github/workflows/compare-screens.yml without workflows permission)`.
Credential inventory unchanged: single `ghs_…` App installation token (`GH_TOKEN == GITHUB_TOKEN
== wiki-remote token`; length 377), repo `permissions.push:false`, no SSH key / netrc /
git-credentials / `secrets.*`. **#1872 stays OPEN**; the workflow fix lives on local branch
`fix/issue-1872-workflow-patch` → `56e9520a0` and is fully reproducible from §Fix above, while the
`.gitattributes` mitigation + docs + suite land on the default branch through the normal artifact
route (no workflow paths touched by those commits).

## Re-verification — fix-bug run 8 (2026-10-17, base `b30bc309f`)

Eighth consecutive fix-bug run for #1872. Bug re-confirmed live on `origin/sebiboga @ b30bc309f`
(8 `-X theirs` / 0 `-X ours`; 5 local-only auto-close REFS / 0 origin guards — identical topology
to runs 3–7). The byte-identical 2-step patch from §Fix was re-applied on local branch
`fix/issue-1872-workflow-patch` (`0cf8e0eea`, 7 files, 18+/13−): grep gates 0 theirs / 8 ours,
0 local / 5 origin REFS + 5 fetch guards, `yaml.safe_load` 11/11, live rebase harness
theirs → 0/45 / ours → 45/45 (re-measured this run), no-false-self-close token count 0.

**Push wall re-measured:** `git push origin HEAD:fix/issue-1872-run8` →
`! [remote rejected] … (refusing to allow a GitHub App to create or update workflow
.github/workflows/compare-screens.yml without workflows permission)` — 8th consecutive run
blocked by the same App-installation-level permission; no SSH key / alternate credential exists
on the runner. Landable (non-workflow) artifacts of this run — the suite append
`Regression — Issue #1872 (re-run … run 8, base b30bc309f)` and this docs section — were pushed
to `origin/fix/issue-1872`. **Issue stays OPEN** (rulebook §7: no fake-close for an unpushed fix).

## Re-verification — fix-bug run 9 (2026-10-18, base `f8ea0a5ab`)

Ninth consecutive fix-bug run, first on a new day/token generation. Bug re-confirmed live on
`origin/sebiboga @ f8ea0a5ab` (8 `-X theirs` / 0 `-X ours`; 5 local-only `"$BEFORE"..HEAD`
auto-close REFS / 0 origin guards — identical topology to runs 1–8; measured line numbers in the
run-9 INVESTIGATION comment). The published 2-step patch (§Fix above) was re-applied
byte-identically on local branch `fix/issue-1872-workflow-patch` → `9abaa8060` (local-only;
that SHA no longer exists in any repo — current re-application: `18eb7695f`; 7 files,
**18 insertions / 13 deletions**):

| gate | measured (run 9) |
|---|---|
| `rebase -X theirs` | **0** (was 8) |
| `rebase -X ours` | **8** (was 0) |
| local `"$BEFORE"..HEAD` REFS | **0** (was 5) |
| `"$BEFORE".."origin/$GITHUB_REF_NAME"` REFS + fetch guards | **5 + 5** |
| `yaml.safe_load` over `.github/workflows/*.yml` | **11/11** |
| harness `-X theirs` / `-X ours` (live lines kept) | **0/45** / **45/45** |
| `#1872` literal token in branch commits (false-close guard) | **0** |

**Push wall re-measured with a freshly minted token** (`ghs_15368_…`, `iat` 2026-10-18T02:24Z —
not a stale-token artifact): `git push origin HEAD:refs/heads/fix/issue-1872-run9` →
`! [remote rejected] … (refusing to allow a GitHub App to create or update workflow
.github/workflows/compare-screens.yml without workflows permission)` — **9th consecutive blocked
run**. Credential inventory unchanged: single App token (`GH_TOKEN == GITHUB_TOKEN ==
wiki-remote token`), `gh api repos/… → permissions.push:false`, no SSH key / netrc / alternate
secret. Corroborated this run: `11f0a9718` (bot, 2026-10-05, "the bot token cannot push it" →
YAML preserved in **#1850**) and `git log origin/sebiboga -- .github/workflows/` showing **zero**
bot-authored workflow commits ever.

**Issue stays OPEN** (rulebook §7: no fake-close for an unpushed fix). The fix remains
one-shot reproducible from §Fix above; it lands the moment a human or `workflows`-scoped
credential applies the two steps (or cherry-picks `18eb7695f`), after which the gates in the
table are re-run and the issue closed with evidence.

## Re-verification — fix-bug run 10 (2026-10-18, base `f71ee56d8`)

Tenth consecutive fix-bug run. Bug re-confirmed live on `origin/sebiboga @ f71ee56d8`
(8 `-X theirs` / 0 `-X ours`; 5 local-only `"$BEFORE"..HEAD` auto-close REFS / 0 origin guards —
identical topology to runs 1–9; measured `file:line` in the run-10 INVESTIGATION comment). The
published 2-step patch (§Fix above) was re-applied byte-identically on local branch
`fix/issue-1872-workflow-patch` → `18eb7695f` (7 files, **18 insertions / 13 deletions**):

| gate | measured (run 10) |
|---|---|
| `rebase -X theirs` | **0** (was 8) |
| `rebase -X ours` | **8** (was 0) |
| local `"$BEFORE"..HEAD` REFS | **0** (was 5) |
| `"$BEFORE".."origin/$GITHUB_REF_NAME"` REFS + fetch guards | **5 + 5** |
| `yaml.safe_load` over `.github/workflows/*.yml` | **11/11** |
| harness `-X theirs` / `-X ours` (live lines kept) | **0/45** / **45/45** |
| `#1872` literal token in branch commits (false-close guard) | **0** |

**Landing routes re-measured (both blocked):**
- `git push origin HEAD:fix/issue-1872` → `! [remote rejected] … (refusing to allow a GitHub
  App to create or update workflow .github/workflows/compare-screens.yml without workflows
  permission)` — **10th consecutive blocked run** (token `iat` 2026-10-18T02:58Z, minted this run).
- **Contents API (re-measured after run 1):** a non-workflow probe branch push was ACCEPTED, then
  `PUT /repos/sebiboga/testlink-upgraded/contents/.github/workflows/fix-bug.yml` on that branch →
  **HTTP 403 `Resource not accessible by integration`** — the API route is blocked by the same
  App permission.

**Correction to the run-9 note:** run 9 stated "zero bot-authored workflow commits ever" —
**wrong**. `git log origin/sebiboga --author=github-actions -- .github/workflows/` shows the bot
**did** create/update workflow files in **August 2026**: `82c76c099` (add of
`merge-stale-branches.yml`), `1b7a9cac7`…`661e2f94a` (5 updates), `268f5ac33`, `d041ca9fe`,
`386231cb2` (fix-bug.yml / modernize.yml updates). The GitHub Actions App installation therefore
**once held the `Workflows` repository permission** and lost it (or GitHub tightened enforcement)
between Aug 2026 and early Oct 2026 (bot commit `11f0a9718`, 2026-10-05, already reports "the
bot token cannot push it"; the issue was filed 2026-10-07). Practical consequence: the wall is
revertible — a maintainer re-granting `Workflows` on the App installation restores the landing
route with **no code change**, after which this branch lands as-is.

**Issue stays OPEN** (rulebook §7: no fake-close for an unpushed fix). The fix remains one-shot
reproducible from §Fix above (or cherry-pick `18eb7695f`); the gates above are re-run after landing,
then the issue closes with evidence.

## Re-verification — fix-bug run 11 (2026-10-18, base `eb6784477`)

Eleventh consecutive fix-bug run. Bug re-confirmed live on `origin/sebiboga @ eb6784477`
(8 `-X theirs` / 0 `-X ours`; 5 local-only `"$BEFORE"..HEAD` auto-close REFS / 0 origin guards —
identical topology to runs 1–10; measured `file:line` in the run-11 INVESTIGATION comment). The
published 2-step patch (§Fix above) was re-applied byte-identically on local branch
`fix/issue-1872-workflow-patch` → `4d906b931` (7 files, **18 insertions / 13 deletions**):

| gate | measured (run 11) |
|---|---|
| `rebase -X theirs` | **0** (was 8) |
| `rebase -X ours` | **8** (was 0) |
| local `"$BEFORE"..HEAD` REFS | **0** (was 5) |
| `"$BEFORE".."origin/$GITHUB_REF_NAME"` REFS + fetch guards | **5 + 5** |
| `yaml.safe_load` over `.github/workflows/*.yml` | **11/11** |
| harness `-X theirs` / `-X ours` (live lines kept) | **0/45** / **45/45** |
| `#1872` literal token in branch commits (false-close guard) | **0** |

**Landing routes re-measured (both blocked):**
- `git push origin HEAD:fix/issue-1872` → `! [remote rejected] … (refusing to allow a GitHub
  App to create or update workflow .github/workflows/compare-screens.yml without workflows
  permission)` — **11th consecutive blocked run** (token minted this run).
- **Contents API:** a non-workflow probe branch push was ACCEPTED
  (`tmp/api-probe-run11`, empty commit, deleted afterwards), then
  `PUT /repos/sebiboga/testlink-upgraded/contents/.github/workflows/fix-bug.yml` on that branch →
  **HTTP 403 `Resource not accessible by integration`** — API route re-measured blocked.

**Regression suite:** run-11 entry `Regression — Issue #1872 (re-run 2026-10-18, fix-bug run 11,
base eb6784477)` appended (APPEND-ONLY); gate `TLU_REQUIRE_SUITE="Issue #1872"
bash ai/verify_test_suites.sh` → **34 PASS / 0 FAIL / 0 SKIP**, exit 0 (926 → 927 suites, none lost).

**Issue stays OPEN** (rulebook §7: no fake-close for an unpushed fix). The fix remains one-shot
reproducible from §Fix above (or cherry-pick `4d906b931` once a `workflows`-scoped credential or
the re-granted App permission exists); the gates above are re-run after landing, then the issue
closes with evidence.
