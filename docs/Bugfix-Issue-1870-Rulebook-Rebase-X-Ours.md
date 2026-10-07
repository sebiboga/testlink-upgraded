# Bugfix Issue #1870 — agent rulebooks still advise `git rebase -X theirs` on push rejection

Companion to #1694 / #1868: the CI workflow fallback steps were diagnosed as a cross-run data-loss
class, but the **two agent-facing rulebook copies** of the same destructive advice were missed.

## Symptom

Every agent run that hits a push rejection is told to recover with:

```bash
git fetch && git rebase -X theirs origin/<your-branch>   # retry the push
```

* `ai/FIX-ISSUE.md:134` (Git & time discipline)
* `ai/IMPLEMENT-TASK.md:139` (byte-identical copy)

During a rebase **`ours` = the upstream being rebased ONTO** (`origin/<your-branch>`, the live and
newer side after `git fetch`) and **`theirs` = the commit being REPLAYED** (the agent's stale local
copy). `-X theirs` therefore resolves every overlap in favour of the STALE copy: a concurrent
agent's lines pushed meanwhile to a shared file are silently dropped, `rebase` exits 0, the retried
push succeeds and the loss looks like a clean fast-forward.

## Reproduction (2-strategy harness, measured this run)

Fixture: `base` = `shared.txt` with 100 lines. `agentB` (= live `origin/<your-branch>`) appends 45
distinguishable lines `101..145`. `staleA`/`staleB` (= the agent's local copy) rewrite the file to
46 stale lines. Rebase each stale branch onto `agentB`, then count surviving live lines:

| strategy | rebase exit | `shared.txt` lines | live lines kept |
|---|---|---|---|
| `rebase -X theirs` (rulebook as-is) | 0 | 46 | **0/45** — concurrent work destroyed |
| `rebase -X ours` (proposed) | 0 | 145 | **45/45** — newer content survives |

Both exit 0 → the retried push looks clean; the loss is **silent**. Same numbers as #1868's
harness (0/45 vs 45/45) and #1694's strategy matrix.

## Fix — why `-X ours`, why not the alternatives

Minimal single-token swap in the two rulebook lines, plus a 4-line rationale (2 sentences) so the
advice is not
"re-broken" by a future agent:

```bash
sed -i 's|git rebase -X theirs origin/<your-branch>|git rebase -X ours origin/<your-branch>|' \
  ai/FIX-ISSUE.md ai/IMPLEMENT-TASK.md
```

`-X ours` picks the upstream (live) side on conflict — upstream here IS
`origin/<your-branch>`, i.e. ground truth after `git fetch`. Rejected alternatives:

* **Drop the `-X` entirely** — a whole-file overlap then leaves raw conflict markers mid-file; the
  push fails loudly but the agent may `--abort` and force-push instead, a worse failure mode.
* **`merge=union` for these files** — right for the append-only ledger (`tmp/TLU_Test_Cases.md`,
  guarded in `.gitattributes` since #1694) but wrong for JSON/i18n bundles and any non-append file;
  cannot be expressed in rulebook prose per-path.

Files changed: `ai/FIX-ISSUE.md` and `ai/IMPLEMENT-TASK.md`, 5 insertions / 1 deletion each
(`git show --numstat a4baa939d`), the advice around line 134 / line 139 respectively. After the swap: `grep -rn "rebase -X theirs" ai/` → **0 hits**;
`grep -rn "rebase -X ours" ai/` → **2 hits**.

## Blast radius

Advice applies to 100% of agent-side conflict resolutions (fix-bug / implement-task / modernize /
investigate runs). At risk: every shared file the `.gitattributes merge=union` guard does NOT
protect — `tmp/wiki-repo/*`, `docs/*`, `gui/templates/i18n/*.json` (10 bundles), any source file.
Only `tmp/TLU_Test_Cases.md` survives by construction.

## Related — #1868 never landed (discovered while fixing this)

`grep -rn "rebase -X theirs" .github/workflows/` still shows **8 hits (0 `-X ours`)** and none of
#1868's commits (`7032a542e`, `219c9be63`, `65b0257cb`) exist in any ref, although #1868 was
auto-closed as "fix commits landed on the default branch". The workflow-file push was rejected
(missing `workflows` scope) while the docs/test commits were landable — a partial landing triggered
the auto-close. Filed separately as **#1872** (`bug`); out of scope for #1870.

## Verification

* grep gate: 0 `theirs` / 2 `ours` in `ai/` (run after the fix, commit `a4baa939d`).
* 2-strategy harness: `-X theirs` → 0/45 live lines (clobber reproduced pre-fix);
  `-X ours` → 45/45 (advice post-fix).
* Regression suite: `Regression — Issue #1870` appended to `tmp/TLU_Test_Cases.md`;
  `TLU_REQUIRE_SUITE="Issue #1870" bash ai/verify_test_suites.sh` → PASS.
* Event Viewer: not applicable (no TestLink PHP / BFF code touched — rulebook + docs only).
* No i18n bundles touched → no `verify_i18n_coverage.sh` run required.
