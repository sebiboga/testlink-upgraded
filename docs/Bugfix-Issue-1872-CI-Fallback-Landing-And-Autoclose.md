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
* **Defect 2 — local-only close check.** `fix-bug.yml:261` (and 4 siblings) computed
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

**Step 1 — 8 single-token swaps** (`b680cac34`):

```bash
sed -i 's|git rebase -X theirs "origin/$BRANCH"|git rebase -X ours "origin/$BRANCH"|' \
  .github/workflows/{fix-bug,fix-bug-oldest,implement-task-newest,implement-task-oldest,investigate-fix,compare-screens,modernize}.yml
sed -i 's|git rebase -X theirs origin/<branch>|git rebase -X ours origin/<branch>|' \
  .github/workflows/modernize.yml     # the agent PROMPT text (~line 238)
```

**Step 2 — origin-verified auto-close, 5 sites** (`4abc615c0`):

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

The guard is failure-safe: if the fetch fails, `REFS` stays empty and the issue is left open
(`::notice:: … leaving it open`) instead of being falsely closed.

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

The fix is committed locally (`b680cac34` + `4abc615c0`) but **cannot be pushed by any
credential available to automation**: `git push origin HEAD:fix/issue-1872` →
`! [remote rejected] … without workflows permission` (re-measured; same 403 class as #1868).
Therefore **#1872 stays OPEN** with this page + the issue comments carrying the exact patch.
It lands the moment a human or a `workflows`-scoped credential applies the two steps above
(or cherry-picks the two commits).

Note for the run that carries this patch: until Step 2 is live, the pre-fix auto-close step still
greps local commit messages for the literal `#<issue>` token — that is why the commit messages of
the fixing run deliberately say `Refs: issue 1872` without the `#`.

## References

* Issues: #1694 (class), #1868 (workflow-side fix, never landed), #1870 (rulebook copies, landed),
  #1872 (this).
* Files: `.github/workflows/{fix-bug,fix-bug-oldest,implement-task-newest,implement-task-oldest,investigate-fix,compare-screens,modernize}.yml`.
* Suite: `Regression — Issue #1872` in `tmp/TLU_Test_Cases.md` (11/11 PASS).
