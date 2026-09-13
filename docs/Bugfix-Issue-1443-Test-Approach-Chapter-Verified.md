# Issue #1443 — Test Strategy "Test Approach" chapter: verified complete (stale tracking issue)

## Problem / issue requirement

The tracking issue requested a dedicated chapter page for the **Test Approach**
section of the Test Strategy:

- Page: `gui/templates/strategy/approach.html`
- Icon: `fa-compass`
- Wired into:
  - Aside nav: sub-item under **Test Strategy** (`href_test_strategy_*` labels
    in en_GB/en_US/ro_RO strings.txt)
  - General Overview: `CHAPTERS` entry in `gui/templates/strategy/testStrategy.html`
    with an **Open chapter** button
- i18n keys: `ts.approach*` in en.json + ro.json (header, sub, card titles, bullets)
- Content — Strategy: risk-based prioritisation of what to test first;
  shift-left (static and early dynamic testing); combination of manual and
  automated execution. Techniques: specification-based techniques (EP, BVA,
  decision tables); experience-based and exploratory testing; regression and
  smoke suites on every build.

## Investigation result (measured)

Every requirement above is **already implemented and verified working** on the
default branch. No defect is reproducible on this issue's subject matter; the
issue was a tracking issue left open after the chapter landed (same resolution
pattern as sibling trackers #1440, #1441, #1442, #1455-1458).

Implementation provenance:

- `f934433d7` — `feat(strategy): dedicated pages + nav sub-items for all 18
  Test Strategy chapters (Refs #1426, #1440-#1458)` — created
  `approach.html`, the BFF chapter-map entry (`api/strategy/index.php:61`,
  num 4, `fa-compass`, `ts.chapterApproach` → `approach.html`) and the
  ASIDE sub-item (`gui/templates/dashio/aside.tpl:144`, label
  `href_test_strategy_approach`).
- `1ee2e0501` — `fix(strategy): load jQuery on all 20 Test Strategy chapter
  pages so TLi18n initializes (Refs #1457)` — chapter pages now include jQuery
  before `i18n.js`, so the locale switcher and translations work in the
  mainframe iframe.
- `945679c5b` — `fix(strategy): translate 179 chapter content keys in all 8
  non-en/ro bundles (Refs #1486)` (closed bug) — the `ts.approach*` content
  keys are translated in de/es/fr/it/ja/pt/ru/zh, exactly like every sibling.

## Verification evidence

- HTTP: `approach.html` → 200; `testStrategy.html` → 200.
- BFF (authenticated): `GET api/strategy/index.php?action=chapters` →
  `{status:"ok", chapters:[..., {num:4, icon:"fa-compass",
  key:"ts.chapterApproach", descKey:"ts.chapterApproachDesc",
  url:"/gui/templates/strategy/approach.html"}, ...]}`; footer keys
  (displayName/generated_on/right) present.
- Wiring: `aside.tpl:144` (`{$gui->uri->testStrategyApproach}` ←
  `lib/functions/common.php:2112`); label `href_test_strategy_approach` at
  `locale/en_US/strings.txt:2285`, `locale/en_GB/strings.txt:2316`,
  `locale/ro_RO/strings.txt:33`.
- i18n: all 10 bundles (`de en es fr it ja pt ro ru zh`) contain all 9
  `ts.approach*` keys + `ts.chapterApproach`/`ts.chapterApproachDesc` with
  native translations; every bundle passes `python3 -m json.tool`.
- Browser (headless Chrome, admin/admin): ASIDE → Test Strategy → **Approach**
  renders the chapter in the mainframe (header + subtitle, Strategy card with
  3 bullets, Techniques card with 3 bullets, Back-to-Strategy link, locale
  switcher); General Overview card #4 shows the `fa-compass` icon + **Open
  chapter** button and navigates to `approach.html`; locale switch → Română
  renders `Abordarea Testării` + translated bullets. Console: 0 messages.
- Event Viewer: `events` table contains only the login AUDIT info row — no new
  ERROR/WARNING entries.

## Resolution

No code change was needed (minimal-fix principle; nothing to fix). The normal
fix-bug close criteria apply because the issue's spec was verified error-free:
regression suite `Regression — Issue #1443` in `tmp/TLU_Test_Cases.md`
(8/8 PASS), CHANGELOG entry (Refs #1443), this docs mirror, and the GitHub
Wiki page (`Test Strategy Test Approach — Verification` family entry) with
screenshots; the issue was closed with the verification comment.

## Files Changed

- `tmp/TLU_Test_Cases.md` — regression suite `Regression — Issue #1443`
  (8 cases, 8/8 PASS).
- `docs/Bugfix-Issue-1443-Test-Approach-Chapter-Verified.md` — this write-up
  (mirror, without image lines).
- `CHANGELOG` — one line under the 2.0.1 KEY BUGFIX section (Refs #1443).
- `docs/screenshots/issue-1443-test-approach-en.png`,
  `docs/screenshots/issue-1443-test-approach-ro.png` — verification
  screenshots (mirrored into the wiki).

## Screenshots

- `issue-1443-test-approach-en.png` — `approach.html` English render
  (title/header/sub, Strategy + Techniques cards with all 6 bullets,
  Back-to-Strategy link and locale switcher).
- `issue-1443-test-approach-ro.png` — `approach.html` Română render
  (`Abordarea Testării`, translated header/sub/cards/bullets) proving
  client-side i18n.