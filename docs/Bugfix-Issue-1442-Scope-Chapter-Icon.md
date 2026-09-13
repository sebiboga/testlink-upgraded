# Bugfix — Issue #1442: Test Strategy "Scope" chapter verified; header icon corrected

## Context

#1442 is the tracking issue for the *Test Strategy chapter: Scope*
(page `gui/templates/strategy/scope.html`, icon `fa-expand-arrows-alt`,
ASIDE sub-item **Scope**, General Overview card **3**, `ts.scope*` i18n keys
in en.json + ro.json, In-scope / Out-of-scope content bullets). The
implementation lives on the default branch (`08c334b4a` added the page and the
first nav sub-items, `f934433d7` added the full chapter grid + nav sub-items,
`1ee2e0501` fixed the missing jQuery on the chapter pages, `945679c5b`
translated the content keys in the 8 non-en/ro bundles). This run re-verified
the chapter end-to-end and found one real, user-visible defect: the **Scope
page header rendered the wrong FontAwesome icon**.

## The defect

- `gui/templates/strategy/scope.html:31` rendered `<i class="fas fa-bullseye">`
  in the chapter header — the icon of the **Objectives** chapter.
- The canonical Scope icon is `fa-expand-arrows-alt`: it is what this issue
  specifies (`Icon: fa-expand-arrows-alt`), what the ASIDE sub-menu uses
  (`gui/templates/dashio/aside.tpl:143`) and what the BFF chapter map serves
  for chapter 3 (`api/strategy/index.php:60`).
- Verify per chapter page (`grep -o 'fa-[a-z0-9-]*' gui/templates/strategy/*.html`):
  **every other chapter page's header icon matches its canonical BFF chapter
  icon** (intro `fa-book`, objectives `fa-bullseye`, approach `fa-compass`,
  exitCriteria `fa-flag-checkered`, training `fa-graduation-cap`, …). `scope.html`
  was the **only** mismatch — it duplicated the Objectives icon.

## Root cause

The page was created in `08c334b4a` (together with `exitCriteria.html`) as the
first pair of chapter sub-pages; at that time `fa-bullseye` was the initial
icon used for the Scope entry both in `scope.html` and in the ASIDE sub-menu
(`aside.tpl:141`). When `f934433d7` canonized the Scope chapter icon as
`fa-expand-arrows-alt` (ASIDE sub-menu + BFF chapter map) and added the
remaining chapter pages, the header icon in `scope.html` — the source copy
created at the start — was never aligned to the canonical icon.

## Fix

Branch `fix/issue-1442-scope-icon`:

- `gui/templates/strategy/scope.html:31`: `fas fa-bullseye` →
  `fas fa-expand-arrows-alt` (one line; no JS, CSS, BFF API or i18n keys
  touched).

## Why this approach

The change is minimal (1 line), has zero behavior impact, and aligns the page's
header icon with the icon this issue itself defines and that every other layer
(nav + BFF + overview card) already uses. Fixing it in the ASIDE or BFF would
have been wrong — those were already correct. Any further icon change would
have been speculative churn.

> Note (observation, not fixed): the overview card description
> `ts.chapterScopeDesc` reads "The first chapter of a Test Strategy…" while the
> Scope card renders as **#3** in the 28-card grid. The wording is uniform and
> deliberately translated in all 10 bundles, so it was left untouched to keep
> this fix minimal and unambiguous.

## Verification

- Regression suite `Regression — Issue #1442` in `tmp/TLU_Test_Cases.md`:
  **9/9 PASS** — chapter page EN/RO (content + back link + locale switch, no
  literal keys), header icon = `fa-expand-arrows-alt` after fix (measured via
  JS), ASIDE wiring, overview card #3 "Scope" + Open chapter, all 28 chapter
  header icons match their canonical BFF icons, BFF 28 entries + `ts.scope*`
  (9 keys) in all 10 bundles (`python3 -m json.tool` valid), `events` table
  clean (no Error/Warning), browser console clean.
- Screenshots: `docs/screenshots/issue-1442-scope-en-fixed.png`,
  `docs/screenshots/issue-1442-scope-ro-fixed.png`.