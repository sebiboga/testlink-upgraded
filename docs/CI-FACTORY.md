# CI Factory — Autonomous Workflows

Three GitHub Actions workflows run this repository with minimal human input.
They coordinate through awareness (each agent can inspect the others' state)
rather than hard locks. This document mirrors the wiki page; the authoritative
rulebooks are `AGENTS.md` and `FIX-ISSUE.md`.

## The three workflows

| Workflow | Trigger | What it does |
|---|---|---|
| `fix-bug.yml` | schedule every 2h + manual | Picks the NEWEST open issue, fixes it end-to-end on its own `fix/issue-<n>-<slug>` branch, opens a PR containing `Fixes #<n>`, tries to self-merge (squash). Rulebook: `FIX-ISSUE.md`. |
| `merge-prs.yml` | hourly | Safety net: squash-merges open `fix/*` PRs authored by the bot, after attempting a base update when the default branch moved. |
| `modernize.yml` | manual, one screen per run | Modernizes one legacy screen (BFF API + HTML screen + i18n), pushes intermediate commits directly to the default branch. |

## Coordination model

- **fix-bug never writes the default branch directly** — all its work lands via
  PRs, so issue closure (`Fixes #<n>`) always coincides with the fix being in
  the default branch.
- **modernize pushes intermediates to the default branch**, but its prompt
  includes an AWARENESS block: before each push it checks for in-progress
  fix-bug runs and open agent PRs, then decides its own strategy (rebase first,
  push now, or defer to the next phase commit).
- **The wiki clone is shared**: both agents push to
  `sebiboga/testlink-upgraded.wiki`; on rejection they `git pull --rebase` and
  retry.
- **No deterministic waiting is imposed on any agent** — they are AIs and pick
  their own strategy from live repository state.

## Safety rails

- fix-bug has a cheap `triage` job (~10s): if there are no open issues, the
  expensive environment (MariaDB, PHP, Chrome, opencode) never starts.
- Issues whose latest comment is a bot "could not fix" note are skipped by the
  next run — no run burns twice on the same stuck issue.
- Fallback steps guarantee that whatever an agent produced gets pushed even if
  the run times out — but only to the agent's own branch, never the default
  branch.
- Runtime artifacts (`.ci_deadline_epoch`, `.ci_branch`, `config_db.inc.php`,
  `tmp/php_server.log`) are gitignored.

## Human etiquette

- The default branch can move at any time from CI merges: `git fetch && git
  rebase` before starting local work and before every push.
- Never force-push or manually merge/delete `fix/*` branches or agent PRs,
  unless cleaning up after a confirmed failure.
- Do not close issues that have an open agent PR.
