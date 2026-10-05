# IMPLEMENT-TASK.md — Rules for Autonomous Feature Implementation (opencode)

Mission: implement exactly ONE feature gap per run — the OLDEST open GitHub task
issue — end to end, then stop. This file is the authoritative rulebook for that
run. Also follow ALL rules in ai/AGENTS.md (they apply to every run).

These are feature implementations from scratch, not bug fixes. Each task issue
describes a legacy capability that was not ported to the modern screen during
modernization. Your job is to build it — BFF API, HTML front-end, i18n —
following the Dashio patterns of already-modernized screens.

## 1. Pick the issue yourself

- You receive NO input. Find the oldest open TASK issue. TRIAGE IS MANDATORY:
  SKIP any issue whose title starts with "Delete legacy" — those are cleanup
  tasks blocked by gap issues that must be implemented first.
  IMPORTANT: use `sort:created-asc` (NOT gh's default order) so you truly get
  the OLDEST task — the default sort silently skips older issues beyond window:

      gh issue list --state open --label task --search "sort:created-asc" \
        --limit 25 --json number,title \
        --jq 'map(select(.title | startswith("Delete legacy") | not))
              | .[0].number // empty'

- Read the FULL body (and all comments) with `gh issue view <number> --comments`.
- If there are NO open task issues after triage: report that and stop cleanly.
  Do not invent work.
- If the oldest open issue's latest comment is from `github-actions[bot]` saying
  it could not be implemented / was left open, SKIP it and take the next oldest
  — never burn a run re-attempting the same stuck issue.

## 2. Understand the gap before you code

- The issue body contains a detailed gap analysis: what legacy does, what modern
  drops, exact repro steps, and a suggested fix. READ IT CAREFULLY.
- Reproduce the gap in the browser first (chrome-devtools MCP):
  navigate to the modern screen, verify the missing feature is indeed absent.
- Read the legacy code referenced in the issue to understand the exact behavior.
- Read the modern code (HTML + BFF) to understand what's already there.
- The database is freshly imported on every run: recreate whatever data you need.

## 3. Implement the full feature

- This is NOT a minimal bug fix — you must PORT THE ENTIRE LEGACY FEATURE
  into the modern screen. Follow the issue's suggested fix and the patterns
  of already-modernized screens.
- Typical changes span:
  - **BFF API** (`api/reports/index.php` or other): expose missing data
    (e.g. `addOpAccess`, `cf_columns`, default sort order, apikey handling)
  - **HTML screen** (`gui/templates/**/*.html`): render the missing UI
    (e.g. column filters, group-by headers, toolbar buttons, icon links,
    checkboxes, select dropdowns, info notes, footers)
  - **i18n bundles** (`gui/templates/i18n/*.json`): add keys in ALL locales
    for any new labels, messages, or tooltips
- If the fix touches user-facing strings/labels/messages: i18n is mandatory —
  add keys to ALL locale bundles, no hardcoded text.
- Follow the Dashio patterns of previously modernized screens.
- Validate every touched i18n JSON bundle before committing:
  `python3 -m json.tool <file> > /dev/null` **and** `bash ai/verify_i18n_coverage.sh`
  — json.tool proves well-formedness only; the coverage gate proves the key SET is
  present in all 10 bundles (a bundle that misses a key passes json.tool and renders
  the raw key on screen). See #1844.

## 4. Verify the feature works

- Re-test in the browser: the legacy feature must now work in the modern screen.
- Quick regression pass over the affected area/screen.
- Check the Event Viewer screen / `events` table: your implementation must not
  introduce new Error/Warning entries.
- If while testing you discover NEW bugs: log each one as a new GitHub issue
  with `--label bug` — never fix them silently, never expand this run's scope.

## 5. Test case (mandatory)

- Append a numbered suite entry to `tmp/TLU_Test_Cases.md`, headed
  `Task — Issue #<n>: <short title>`, containing:
  - Precondition (what data/setup is needed)
  - Steps to exercise the new feature
  - Expected behavior (what the feature should do)
  - Actual result you observed

  **APPEND-ONLY** — the file is shared by all concurrent agents and is
  git-ignored (`tmp/`), so `git add` needs `-f` and no CI gate can see a
  clobber: append with `>>`, never regenerate it from your own copy, and before
  committing run the gate:

  ```bash
  TLU_REQUIRE_SUITE="Issue #<n>" bash ai/verify_test_suites.sh
  ```

  It compares the suite file against the **merge-base with `origin/<default>`**
  and fails if any suite heading present in the base is **missing** (a set
  difference over the `^## ` suite headings), if a line was removed, if a suite
  heading was left without a body, or if your own suite heading is absent. The three checks that used to stand here are structurally
  blind to the loss and must not be used as the gate: `git diff --cached
  --numstat` is empty by construction once the file is staged with `-f`;
  `git diff --numstat HEAD~1 HEAD` sees only the previous commit, so a suite lost
  in an EARLIER commit of the branch (which is how the loss enters — a rebase
  resolution) is invisible; and `grep -cE "^## (Regression|Suite|Task|Modernize) "`
  is a COUNT, not a set — a clobber that deletes one suite while the run adds two
  of its own passes it (measured: 55 → 56 suites with one suite gone). Keep the
  numstat checks as extra evidence, never as the gate. A full-file rewrite has
  destroyed three suites this way (#1701 lost in `ce093fa54`, #1740 lost in
  `a2df484a8`, `Issue #1048` lost in `966a7997d`) — see issues #1793 and #1805.
- Execute it and record PASS/FAIL honestly.

## 6. Document the implementation — the HOW, not just the WHAT

- Post a comment on the issue with:
  - **IMPLEMENTATION** section: what you changed and why, file:line references
  - **VERIFICATION** section: commit hashes, diff summary, test results
- Update the GitHub Wiki page for the affected screen at `tmp/wiki-repo/`
  (origin is authenticated). If the feature is visual, attach a screenshot
  taken via chrome-devtools MCP.
- Mirror the same documentation into `docs/` (without image lines).

## 7. Land the implementation (NO pull requests)

- Work on your own branch `task/issue-<n>-<slug>` and push it:
  `git push origin HEAD:task/issue-<n>-<slug>`.
- NEVER create a pull request — the bot token cannot open PRs, so any PR
  plan dead-ends. You do NOT merge anything yourself either.
- The CI workflow lands your branch onto the default branch automatically
  after you finish (rebase + push with escalation). Your job ends at a
  clean, pushed, verified branch.
- CLOSE THE ISSUE YOURSELF once the feature is verified AND pushed:
  `gh issue close <n> --comment "<verification evidence>"`.
  A fixed-and-pushed issue left open is a FAILURE of your mission.
- Could not implement / needs product decision / too large → do NOT close.
  Post a detailed comment on the issue: what you attempted, what blocks it,
  suggested approach. Leave it open.

## 8. Git & time discipline

- Work on YOUR OWN branch only: create `task/issue-<n>-<short-slug>` at the
  start. Push it as `task/issue-<n>-<short-slug>`; never push to the default
  branch yourself — the CI workflow does that landing for you.
- NEVER `git stash`, NEVER force-push to the default branch. If a push of YOUR
  branch is rejected: `git fetch && git rebase -X theirs origin/<your-branch>`
  then retry — force-with-lease is allowed only on your own agent branch.
- AWARENESS: other CI agents may run concurrently. You are an AI — decide
  your own strategy. Useful check before finishing:
  `gh api repos/$GITHUB_REPOSITORY/actions/workflows/modernize.yml/runs?status=in_progress`
  Fetch + rebase onto the default branch whenever it moved.
- `config_db.inc.php` is gitignored — never stage or commit it.
- The GitHub Wiki clone (`tmp/wiki-repo/`) is SHARED — another agent may push
  to it while you work. If its push is rejected:
  `cd tmp/wiki-repo && git pull --rebase origin master && git push` and retry.
- The unix epoch of your hard deadline is in `.ci_deadline_epoch`. When fewer
  than 10 minutes remain: stop starting new work, commit+push everything done,
  and write a final summary stating whether the feature was implemented or
  left open.
- STOP after this one task. Do not pick another issue. Do not start other work.
