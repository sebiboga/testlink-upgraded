# AGENTS.md — TestLink 2.0.1 Modernization Rules

Project: `sebiboga/testlink-upgraded` — modernizing TestLink 1.9.20 screens into a
modern UI (Dashio Bootstrap admin template) with a PHP REST BFF layer.

## Workflow Rules

1. **One screen at a time.** Modernize each screen individually, taking the ASIDE
   menu top to bottom. Never start a new screen before closing the current one.

2. **Modernization stack.** Every screen is rewritten as a standalone
   HTML + JS + CSS page (`gui/templates/<area>/<screen>.html`) backed by a REST
   BFF API in plain PHP (`api/<area>/index.php`, session-based auth, JSON I/O).
   No Smarty for modernized screens.

3. **i18n is mandatory.** Every screen uses the client-side `TLi18n` module
   (`gui/templates/i18n/i18n.js`). All labels, titles, placeholders and messages
   get keys in ALL locale bundles (`en.json`, `ro.json`, ...). No hardcoded strings.
   Validate every touched bundle before committing (`python3 -m json.tool <file>`
   ) — parallel CI agents append keys to the same files.

4. **Investigate the legacy screen first.** Before writing any code, read the
   legacy PHP controller (`lib/...`) and Smarty template
   (`gui/templates/dashio/.../*.tpl`) to fully understand the 1.9.20 behavior:
   rights checks, data flow, edge cases, dialogs.

5. **Rewrite for 2.0.1 with Dashio integration.** Reimplement ALL legacy
   functionality (nothing dropped) using the Dashio look & feel: shell layout,
   DataTables, Bootstrap modals, teal/dark/red palette, same visual patterns as
   already-modernized screens (e.g. `cfieldsAssignView.html`). Before starting a
   new screen, always check how the previously modernized screens were
   implemented and reuse their patterns/conventions.

6. **Update the project docs/Wiki mirror.** Document each modernized screen in
   `docs/` (mirror of the wiki page, without image lines).

7. **Update the GitHub Wiki.** Push the same documentation to
   `sebiboga/testlink-upgraded.wiki` (local clone: `tmp/wiki-repo/`). The wiki
   clone is SHARED with concurrent CI agents — if a push is rejected:
   `git pull --rebase origin master` and retry.

8. **Test the new screen.** Exercise every button, form, modal, permission path
   and error state in the browser before considering the screen done.

9. **Write Test Cases.** Mandatory per modernized screen — appended to
   `tmp/TLU_Test_Cases.md` (local md file for now), one numbered suite per screen,
   then executed and results recorded.

10. **Screenshots in the GitHub Wiki.** Every wiki page update includes current
    screenshots of the modernized screen (normal states + key interactions).

11. **Bugs go to GitHub Issues.** Any bug found while testing (legacy or new)
    is logged as an issue on `sebiboga/testlink-upgraded` with repro steps.

12. **Check the Event Viewer.** After testing, verify no new Error/Warning entries
    were generated (`Event Viewer` screen / `events` table); handle anything found.

13. **Keep GitHub Issues clean.** Verify each issue's fix actually works; close
    issues only when the solution is confirmed error-free. In the CI flow the
    fix PR contains `Fixes #<n>`, so the issue closes automatically at merge —
    only put that line in a PR whose fix you have verified.

14. **Regression testing per ASIDE section.** After finishing a whole parent
    section of the ASIDE menu (a chapter of items, not each single item), run a
    regression pass over that section plus previously modernized screens.

15. **Fix all open GitHub Issues before moving on.** Fix, repair and implement
    everything mentioned in GitHub Issues, verify each fix works error-free,
    then continue with the next screen.

16. **Code review before every commit.** Before committing, run a code review
    with a subagent over the screen's changes (HTML/JS/CSS + BFF API); apply
    any required fixes, then commit and push — locally and to the repository
    (`sebiboga/testlink-upgraded`).

17. **Time-box bug fixing to ~8 minutes.** If fixing an issue (or a batch of
    issues) is estimated to take longer than 8 minutes: fix at least 1 bug,
    file new GitHub Issues for everything that remains — each with proper
    documentation (symptom, repro steps, root cause, suggested fix) — then
    move on with modernization. New screens will surface further fixes anyway;
    do not stall progress on long debugging sessions.

18. **The CI factory (GitHub Actions).** Three autonomous workflows run on this
    repo — be aware of them and never fight them:
    - `fix-bug.yml` (every 2h): picks the NEWEST open issue, fixes it on its own
      `fix/*` branch, opens a PR with `Fixes #<n>`, tries to self-merge.
      Rulebook: `FIX-ISSUE.md`.
    - `merge-prs.yml` (hourly): safety net that squash-merges open `fix/*` PRs.
    - `modernize.yml` (manual): one screen per run, pushes intermediate commits
      directly to the default branch.
    Coordination etiquette: the default branch can move at any time from CI
    merges — `git fetch && git rebase` before starting local work and before
    every push; NEVER force-push or manually merge/delete `fix/*` branches or
    agent PRs unless cleaning up after a confirmed failure; do not close issues
    that have an open agent PR.

19. **One tracking issue per screen — created FIRST.** The VERY FIRST action of
    any modernization session (before exploring the codebase or writing a line)
    is: check whether the screen has an open tracking issue on
    `sebiboga/testlink-upgraded`; if NOT, create it INSTANTLY
    (`gh issue create --label enhancement --title "Modernize: <screen>"`) and
    post your plan as its first comment. We must know EXACTLY what every agent
    works on at all times. All commits, pushes, docs and test suites reference
    that number (`Refs #<n>`). Enhancements are NEVER picked by `fix-bug.yml`.

20. **Progress checkpoints (~20 min) in the ticket.** While working a screen,
    post a comment on its tracking issue at least every ~20 minutes AND at
    every push, always with the same sections:
    - **DONE** — what landed since last update (commit hashes + files)
    - **DISCOVERIES** — root causes found, gotchas, decisions taken
    - **PLAN** — ordered next steps (the implementation strategy)
    - **RESUME** — exact state needed to continue: env/commands/credentials,
      what is verified vs pending
    - **FILES** — mapping file → purpose within this screen's modernization
    Goal: any agent (human or Actions) resumes from the ticket without
    re-exploring the codebase from zero. On session start, READ the issue
    comments + `tmp/TODO.md` FIRST and continue the existing plan — do not
    reinvent the strategy.

21. **Issue triage: bugs only for fix-bug.** `fix-bug.yml` picks the newest
    open issue that does NOT carry a work-type label (`enhancement`, `task`,
    `investigation`, `documentation`). Screens/features/enhancements belong to
    `modernize.yml`. When creating issues, ALWAYS label them correctly so the
    triage works; bug reports get `bug` where possible.
