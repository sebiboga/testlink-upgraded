# FIX-ISSUE.md — Rules for Autonomous Bug Fixing (opencode)

Mission: fix exactly ONE bug per run — the NEWEST open GitHub bug issue — end
to end, then stop. This file is the authoritative rulebook for that run. Also
follow ALL rules in ai/AGENTS.md (they apply to every run).

## 1. Pick the issue yourself

- You receive NO input. Find the newest open BUG issue. TRIAGE IS MANDATORY:
  SKIP any issue labeled `enhancement`, `task`, `investigation` or
  `documentation` — those belong to the modernize workflow, not to you:

      gh issue list --state open --limit 200 --json number,title,labels,createdAt \
        --jq 'map(select([.labels[].name] | any(. == "enhancement" or . == "task" or . == "investigation" or . == "documentation") | not)) | first'

- Read the FULL body (and all comments) with `gh issue view <number> --comments`.
- If there are NO open bug issues after triage: report that and stop cleanly.
  Do not invent work. Do not "fix" enhancements.
- Skip pull requests automatically (`gh issue list` already excludes them).
- If the newest open issue's latest comment is from `github-actions[bot]` saying
  it could not be fixed / was left open, SKIP it and take the next newest —
  never burn a run re-attempting the same stuck issue.

## 2. Reproduce before you fix

- Reproduce the bug locally first (browser via chrome-devtools MCP, curl against
  http://localhost:8082, or direct DB inspection). A fix without a reproduced
  symptom is a guess — do not guess.
- The database is freshly imported on every run: previous fixtures are gone,
  recreate whatever data you need to reproduce.

## 3. Root cause, then minimal fix

- Investigate the legacy code path fully before editing anything.
- Apply the MINIMAL correct fix. No refactoring, no drive-by changes in
  unrelated files, no style churn.
- If the real fix turns out to be large (architecture change), do NOT attempt
  it: implement nothing or the smallest safe mitigation, document findings, and
  leave the issue open (see §7).
- If your fix touches user-facing strings/labels/messages: i18n is mandatory —
  add keys to ALL locale bundles (`gui/templates/i18n/*.json`), no hardcoded text.
- If the fix touches a modernized screen (gui/templates/**/*.html or api/**):
  follow the existing Dashio patterns of the previously modernized screens.

## 4. Verify error-free

- Re-run your reproduction: the original symptom must be gone.
- Quick regression pass over the affected area/screen (the flows that share the
  code you touched). If `tmp/TLU_Test_Cases.md` already has suites covering that
  area, re-run their steps too.
- Check the Event Viewer screen / `events` table: your fix must not introduce
  new Error/Warning entries.
- If you touched any i18n JSON bundle (`gui/templates/i18n/*.json`), validate
  every touched file before committing:
  `python3 -m json.tool <file> > /dev/null` — invalid JSON must never reach a
  commit or a PR (parallel agents append keys to the same bundles).
- If while testing you discover NEW bugs: log each one as a new GitHub issue
  with symptom, repro steps and root-cause hypothesis — never fix them silently,
  never expand this run's scope. ALWAYS create them with the `bug` label
  (`gh issue create --label bug ...`) — unlabeled bugs break the CI triage.

## 5. Regression test case (mandatory)

- Append a numbered suite entry to `tmp/TLU_Test_Cases.md`:
  `Regression — Issue #<n>: <short title>`, containing precondition, repro steps
  (pre-fix), expected post-fix behavior, and the actual result you observed.
- Execute it and record PASS/FAIL honestly.

## 6. Document the fix — the HOW, not just the WHAT

- The documentation must explain the APPROACH: root cause, the method you chose
  to fix it, why that method (and what alternatives you rejected), and which
  files changed.
- Update the relevant page in the GitHub Wiki clone at `tmp/wiki-repo/`
  (origin is authenticated). If the bug touched UI, attach a fresh screenshot
  taken via chrome-devtools MCP saved into `tmp/wiki-repo/`.
- Mirror the same documentation into `docs/` (without image lines).
- Run a CODE REVIEW subagent over your full diff before pushing; apply its
  findings, then push.
- Commit+push after EACH phase, small conventional messages, immediately pushed:
  (1) the fix itself, (2) the regression test suite, (3) docs+wiki.

## 7. Land the fix (NO pull requests)

- You work on your own branch `fix/issue-<n>-<slug>` (see §8) and push it:
  `git push origin HEAD:fix/issue-<n>-<slug>`.
- NEVER create a pull request — the bot token cannot open PRs, so any PR
  plan dead-ends. You do NOT merge anything yourself either.
- The CI workflow lands your branch onto the default branch automatically
  after you finish (rebase + push with escalation). Your job ends at a
  clean, pushed, verified branch.
- CLOSE THE ISSUE YOURSELF once the fix is verified error-free AND pushed:
  `gh issue close <n> --comment "<verification evidence>"`.
  A fixed-and-pushed issue left open is a FAILURE of your mission.
- Could not reproduce / needs product decision / too large → do NOT close.
  Post a detailed comment on the issue: symptom, root-cause analysis so far,
  suggested fix, what blocks it. Leave it open.

## 8. Git & time discipline

- Work on YOUR OWN branch only: create `fix/issue-<n>-<short-slug>` at the
  start. Push it as `fix/issue-<n>-<short-slug>`; never push to the default
  branch yourself — the CI workflow does that landing for you.
- NEVER `git stash`, NEVER force-push to the default branch. If a push of YOUR
  branch is rejected: `git fetch && git rebase -X theirs origin/<your-branch>`
  then retry — force-with-lease is allowed only on your own agent branch.
- AWARENESS: other CI agents may run concurrently (the modernize workflow
  pushes intermediate commits to the default branch). You are an AI — decide
  your own strategy. Useful check before finishing:
  `gh api repos/$GITHUB_REPOSITORY/actions/workflows/modernize.yml/runs?status=in_progress`
  Fetch + rebase onto the default branch whenever it moved.
- `config_db.inc.php` is gitignored — never stage or commit it.
- The GitHub Wiki clone (`tmp/wiki-repo/`) is SHARED — another agent may push
  to it while you work. If its push is rejected:
  `cd tmp/wiki-repo && git pull --rebase origin master && git push` and retry.
- The unix epoch of your hard deadline is in `.ci_deadline_epoch`. When fewer
  than 10 minutes remain: stop starting new work, commit+push everything done,
  and write a final summary stating whether the issue was fixed or left open.
- STOP after this one bug. Do not pick another issue. Do not start other work.
