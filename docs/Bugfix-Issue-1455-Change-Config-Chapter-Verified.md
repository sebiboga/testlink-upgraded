# Issue #1455 — Test Strategy "Change & Configuration Management" chapter: verified complete (stale tracking issue)

## Problem / issue requirement

The tracking issue requested a dedicated chapter page for the **Change &
Configuration Management** section of the Test Strategy:

- Page: `gui/templates/strategy/changeConfig.html`
- Icon: `fa-code-branch`
- Wired into:
  - Aside nav: sub-item under **Test Strategy** (`href_test_strategy_*` labels
    in en_GB/en_US/ro_RO strings.txt)
  - General Overview: `CHAPTERS` entry in `gui/templates/strategy/testStrategy.html`
    with an **Open chapter** button
- i18n keys: `ts.cfg*` in en.json + ro.json (header, sub, card titles, bullets)
- Content — Change control: formal change requests with impact analysis,
  traceability of requirement changes to tests, baselines frozen during test
  phases; Configuration: build and environment versioning, versioned test
  artifacts, release branches promoted to staging

## Investigation result (measured)

Every requirement above is **already implemented and verified working** on the
default branch. No defect is reproducible on this issue's subject matter; the
issue was a tracking issue left open after the chapter landed.

Implementation provenance:

- `f934433d7` — `feat(strategy): dedicated pages + nav sub-items for all 18
  Test Strategy chapters (Refs #1426, #1440-#1458)` — created
  `changeConfig.html`, the BFF chapter-map entry (`api/strategy/index.php:73`,
  num 16, `fa-code-branch`, `ts.chapterCfg` → `changeConfig.html`) and the
  ASIDE sub-item (`gui/templates/dashio/aside.tpl:156`, label
  `href_test_strategy_cfg`).
- `1ee2e0501` — `fix(strategy): load jQuery on all 20 Test Strategy chapter
  pages so TLi18n initializes (Refs #1457)` — chapter pages now include jQuery
  before `i18n.js`, so the locale switcher and translations work in the
  mainframe iframe.
- `945679c5b` — `fix(strategy): translate 179 chapter content keys in all 8
  non-en/ro bundles (Refs #1486)` (closed bug) — the `ts.cfg*` content keys
  are translated in de/es/fr/it/ja/pt/ru/zh, exactly like every sibling.

## Verification evidence

- HTTP: `changeConfig.html` → 200; `testStrategy.html` → 200.
- BFF (authenticated): `GET api/strategy/index.php?action=chapters` →
  `{status:"ok", chapters:[..., {num:16, icon:"fa-code-branch",
  key:"ts.chapterCfg", url:"/gui/templates/strategy/changeConfig.html"}, ...]}`;
  footer `displayName/generated_on/login/right` present.
- Wiring: `aside.tpl:156` (`{$gui->uri->testStrategyCfg}` ←
  `lib/functions/common.php:2124`); label `href_test_strategy_cfg` at
  `locale/en_US/strings.txt:2297`, `locale/en_GB/strings.txt:2328`,
  `locale/ro_RO/strings.txt:45`.
- i18n: all 10 bundles (`de en es fr it ja pt ro ru zh`) contain   all 10
  `ts.cfg*` keys + `ts.chapterCfg`/`ts.chapterCfgDesc` with native
  translations; every bundle passes `python3 -m json.tool`.
- Browser (headless Chrome, admin/admin): ASIDE → Test Strategy → **Change &
  Config** renders the chapter in the mainframe (header + subtitle, Change
  control card with 3 bullets, Configuration card with 3 bullets, Back-to-
  Strategy link, locale switcher); General Overview card #16 shows the
  `fa-code-branch` icon + **Open chapter** button and navigates to
  `changeConfig.html`; locale switch → Română renders `Gestionarea
  Modificărilor și a Configurațiilor` + translated bullets. Console: 0 messages.
- Event Viewer: `events` table contains only login AUDIT info rows — no new
  ERROR/WARNING entries.

## Resolution

No code change was needed (minimal-fix principle; nothing to fix). The normal
fix-bug close criteria apply because the issue's spec was verified error-free:
regression suite `Regression — Issue #1455` in `tmp/TLU_Test_Cases.md`
(8/8 PASS), CHANGELOG entry (Refs #1455), this docs mirror, and the GitHub
Wiki page (`Test Strategy Change & Configuration Management — Verification`
family entry) with screenshots; the issue was closed with the verification
comment.

## Files Changed

- `tmp/TLU_Test_Cases.md` — regression suite `Regression — Issue #1455`
  (8 cases, 8/8 PASS).
- `docs/Bugfix-Issue-1455-Change-Config-Chapter-Verified.md` — this write-up
  (mirror, without image lines).
- `CHANGELOG` — one line under KEY BUGFIX / COMPATIBILITY EFFORTS (Refs #1455).
- `docs/screenshots/issue-1455-change-config-en.png`,
  `docs/screenshots/issue-1455-change-config-ro.png` — verification
  screenshots (mirrored into the wiki).

## Screenshots

- `issue-1455-change-config-en.png` — `changeConfig.html` English render
  (title/header/sub, Change control + Configuration cards with all 6 bullets,
  Back-to-Strategy link and locale switcher).
- `issue-1455-change-config-ro.png` — `changeConfig.html` Română render
  (`Gestionarea Modificărilor și a Configurațiilor`, translated header/sub/
  cards/bullets) proving client-side i18n.