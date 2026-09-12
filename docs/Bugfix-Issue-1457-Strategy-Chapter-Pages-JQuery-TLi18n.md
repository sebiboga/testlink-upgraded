# Bugfix — Issue #1457: Test Strategy chapter pages never apply i18n (missing jQuery → `$ is not defined`)

## Problem

The 20 Test Strategy chapter pages introduced by commit `f934433d7`
(`feat(strategy): dedicated pages + nav sub-items for all 18 Test Strategy
chapters (Refs #1426, #1440-#1458)`) are functionally broken: their in-page
initialization never runs because the pages reference jQuery (`i18n.js` and
the inline `$(function(){...})`) but **never load jQuery**.

Impact (reproduced on all 20 pages):

- Mainframe console: `Uncaught ReferenceError: $ is not defined` on every page load.
- Only the hard-coded English fallback text is visible — the active locale's
  translation is never applied.
- The top-right **locale switcher** never renders.
- `#footerInfo` stays empty.

Repro (browser):
1. `http://localhost:8082/login.php`, sign in `admin/admin`.
2. Aside → **Test Strategy** → **Release** (or hub `General Overview` →
   chapter 18 → **Open chapter**).
3. Watch the mainframe iframe; open DevTools console.

## Root Cause

`gui/templates/strategy/release.html:61` loads only
`/gui/templates/i18n/i18n.js`, and `:63-68` runs
`$(function(){ TLi18n.load(...) })`. The page lives in the **mainframe
iframe**; the Dashio shell's jQuery resides in the parent window and never
leaks into the frame. `TLi18n` is entirely jQuery-bound
(`gui/templates/i18n/i18n.js:89` `$.getJSON`, `:149-151` `$(root||document)`,
`:206` `$(document).on(...)`), so with no `$` the first jQuery call throws and
`TLi18n.load()` never executes.

The sibling hub page `gui/templates/strategy/testStrategy.html:72` **does**
load jQuery (`https://code.jquery.com/jquery-3.7.1.min.js`), which is why the
hub works and its chapter links deliver users into broken pages. The omission
is a generation defect in `f934433d7`, not a design decision.

**Blast radius:** all 20 `gui/templates/strategy/*.html` chapter pages:
`intro`, `objectives`, `scope`, `approach`, `testLevels`, `testTypes`,
`exitCriteria`, `environments`, `roles`, `tools`, `communication`,
`deliverables`, `metrics`, `risks`, `defectManagement`, `changeConfig`,
`training`, `release`, `bugStructure`, `bugLifecycle` (verified static:
0 jQuery references in each; only the hub has one).

## Fix

Branch `fix/issue-1457`, commit `1ee2e0501` (`fix(strategy): load jQuery on
all 20 Test Strategy chapter pages so TLi18n initializes (Refs #1457)`) —
**20 files, +1 line each**.

Inserted immediately **before** `<script src="/gui/templates/i18n/i18n.js"></script>`:

```html
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
```

**Why this method:** the identical URL already loads on the hub page
(`testStrategy.html:72`) and on ~100 modernized mainframe screens (the repo
convention for standalone HTML pages); reusing it keeps the fix minimal and
consistent. The local copy
(`/gui/templates/dashio/lib/jquery/jquery.min.js`) is only used by the auth
screens, so mixing sources here would diverge from the sibling pages.

**Rejected alternatives:** (a) moving the shell's jQuery into the iframe —
would require re-architecting the frame bootstrap; (b) rewriting the chapter
pages to be jQuery-free — a large refactor for a one-line dependency; (c)
fixing only `release.html` — would leave 19 identical broken pages.

## Files Changed

- 20× `gui/templates/strategy/*.html` (`intro`, `objectives`, `scope`,
  `approach`, `testLevels`, `testTypes`, `exitCriteria`, `environments`,
  `roles`, `tools`, `communication`, `deliverables`, `metrics`, `risks`,
  `defectManagement`, `changeConfig`, `training`, `release`, `bugStructure`,
  `bugLifecycle`) — +1 line each: jQuery include before `i18n.js`.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1457".
- `docs/Bugfix-Issue-1457-Strategy-Chapter-Pages-JQuery-TLi18n.md` — this file.
- `tmp/wiki-repo/Bugfix-Issue-1457-Strategy-Chapter-Pages-JQuery-TLi18n.md` —
  wiki mirror (with screenshots).
- `docs/screenshots/issue-1457-release-i18n-en.png`,
  `docs/screenshots/issue-1457-release-i18n-ro.png`,
  `tmp/wiki-repo/issue-1457-release-i18n-en.png`,
  `tmp/wiki-repo/issue-1457-release-i18n-ro.png` — English and Romanian
  chapter-page renders after the fix.
- `CHANGELOG` — one-line entry under 2.0.1 KEY BUGFIX section.

## Verification

All checks on branch `fix/issue-1457`, PHP built-in server, fresh-import
MySQL schema, admin/admin.

- Pre-fix control: mainframe console on `release.html`
  `Uncaught ReferenceError: $ is not defined`; no locale switcher; English
  fallback text only.
- Post-fix R1 (`?locale=en`): `release.html` renders, **zero** console
  errors, top-right locale switcher present.
- Post-fix R2 (`?locale=ro`): title **"Informații de Livrare"**, all body
  strings and "Înapoi la Strategia de Testare" translated; switcher shows
  Română selected.
- Post-fix R3 (in-shell end-to-end): Aside → Test Strategy → Release loads
  in mainframe with switcher; switching the dropdown to **Română** reloads
  `?locale=ro` and the mainframe translates — full round-trip verified.
- Post-fix R4 (siblings): `intro.html`, `bugLifecycle.html` load with zero
  console errors.
- Static gate: `grep -c code.jquery.com gui/templates/strategy/*.html` → 21
  (all pages incl. hub).
- Event Viewer / `events` table: only the login row exists; **zero** new
  ERROR/WARNING rows after the fix.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1457".

Address: `Fixes #1457`.