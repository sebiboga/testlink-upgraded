# Dashio Libraries — Version Alignment & Security Update

Tracking issue: [#893](https://github.com/sebiboga/testlink-upgraded/issues/893).

## Goal

TestLink's Dashio shell and the modernized standalone screens must run the
latest release of every library line they depend on, consistently, to avoid
running EOL/known-vulnerable assets. Where a line is abandoned (classic
Dashio jQuery template), "latest" means the newest drop-in release of that
line; a rewrite (e.g. the 2024 React Dashio) is NOT a drop-in and stays out
of scope.

## Starting state (mess)

| Library | Shell (main.tpl) | Modern screens (gu), templates) | Problem |
|---|---|---|---|
| jQuery | 2.2.4 (third_party, TL_JQUERY) | CDN 3.6.0 | 2.2.4 EOL; 3.6.0 has known fixes in 3.7.1 |
| Bootstrap | 3.3.7 (dashio-template/lib) | 3.3.7 (dashio/lib + themes/dashio/lib) | 3.3.7 EOL; 3.4.1 = final v3 with security fixes |
| Font Awesome | 6.2.0 (dashio-template/lib) | (some screens rely on shell FA6) | 6.2.0 outdated → 6.7.2 latest free |
| DataTables | n/a | CDN 1.10.25 + 1.13.6 mixed | EOL 1.10.25 |

Additionally `third_party/bootstrap/` held both stale 3.3.6 and current 3.4.1.

## What was done (commit `…` Refs #893)

1. **jQuery shell**: `third_party/jquery/jquery-2.2.4.min.js` → `jquery-3.7.1.min.js`;
   `TL_JQUERY` in `config.inc.php` updated. Old 2.2.4 file removed.
2. **jQuery modern screens**: all 96 standalone HTML screens: CDN
   `code.jquery.com/jquery-3.6.0.min.js` → `jquery-3.7.1.min.js`.
3. **Bootstrap 3.4.1** (final v3 — security patch release): replaced css/js/fonts in
   - `gui/templates/dashio/dashio-template/lib/bootstrap/` (shell)
   - `gui/templates/dashio/lib/bootstrap/` (modern screens)
   - `gui/themes/dashio/lib/bootstrap/` (theme copy)
   Kept `third_party/bootstrap/3.4.1` (used by `bootstrap.inc.tpl`) and **removed
   stale `third_party/bootstrap/3.3.6`**.
4. **Font Awesome 6.7.2-free-web**: replaced
   `gui/templates/dashio/dashio-template/lib/fontawesome-free-6.2.0-web/`
   (css+webfonts only) and updated `fontawesomeHomeURL` in
   `lib/functions/tlsmarty.inc.php`.
5. **DataTables unified to 1.13.7** across modern screens (31 HTML files, 93 refs
   replaced): `1.10.25` and `1.13.6` CDN paths → `1.13.7` (same asset file names,
   so drop-in; includes `jquery.dataTables.min.js`, `dataTables.bootstrap.min.js/css`).
   Row Group 1.4.1 unchanged.

## Verification (TLU TC-893, 9/9 PASS)

- Shell on jQuery 3.7.1 in all three frames (asidebar/mainframe/titlebar).
- Bootstrap 3.4.1 confirmed in dashio-template/lib + lib + themes copies.
- FA 6.7.2 `all.css` served; aside icons render (`"Font Awesome 6 Free"`).
- dcjqaccordion (jQuery-1-era plugin) still toggles submenus under jQuery 3.7.1.
- Modern screen curve-ball: `tplanWithCF` grouping (RowGroup) + DataTables 1.13.7 +
  jQuery 3.7.1 → collapse/expand/groups all work.
- Zero Error/Warning in Chrome console.

## Deferred / non-goals

- **Bootstrap 5 / jQuery 3 UI shell rewrite**: not a drop-in (legacy tpls use
  Bootstrap-3 classes: panels, col-md-*, label-*, hidden-*…). Would be a full
  shell rewrite; intentionally out of scope.
- **React Dashio** (`reactadmins/bootstrap-dashio`, 2024): different product,
  not compatible with static HTML screens / Smarty shell.
- **FA4→FA6 legacy pages**: untouched — shell already loads FA6 `all.css`; classic
  `fa fa-*` classes on legacy pages keep their theme fall-bak styling. If a screen
  needs modern FA icons, use `fa-solid`, `fa-regular` prefixed classes.

## What remains (next steps)

- [ ] Grep any leftover hardcoded version strings (`3.3.7`, `2.2.4`, `1.10.25`,
  `6.2.0`) in `gui/`, `lib/`, `third_party/` outside `tl200/` and report clean.
- [ ] Regression pass over the 10 report screens + execute screens (DataTables
  bump touches their DataTable init only via plugin — sanity click-through).
- [ ] Optionally verify the OTHER jQuery-1-era plugins used by legacy pages
  (nicescroll, backstretch, easy-pie-chart) after a legacy page visit
  (e.g. a non-modernized Smarty page).
- [ ] Mirror this doc to the GitHub Wiki (`tmp/wiki-repo/`).