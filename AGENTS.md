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

4. **Investigate the legacy screen first.** Before writing any code, read the
   legacy PHP controller (`lib/...`) and Smarty template
   (`gui/templates/dashio/.../*.tpl`) to fully understand the 1.9.20 behavior:
   rights checks, data flow, edge cases, dialogs.

5. **Rewrite for 2.0.1 with Dashio integration.** Reimplement ALL legacy
   functionality (nothing dropped) using the Dashio look & feel: shell layout,
   DataTables, Bootstrap modals, teal/dark/red palette, same visual patterns as
   already-modernized screens (e.g. `cfieldsAssignView.html`).

6. **Update the project docs/Wiki mirror.** Document each modernized screen in
   `docs/` (mirror of the wiki page, without image lines).

7. **Update the GitHub Wiki.** Push the same documentation to
   `sebiboga/testlink-upgraded.wiki` (local clone: `tmp/wiki-repo/`).

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
    issues only when the solution is confirmed error-free.

14. **Regression testing per ASIDE section.** After finishing a whole parent
    section of the ASIDE menu (a chapter of items, not each single item), run a
    regression pass over that section plus previously modernized screens.
