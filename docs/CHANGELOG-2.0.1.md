# CHANGELOG 2.0.1 — What was implemented in TestLink 2.0.1 (Refs #1424)

> Task issue #1424 ("CHANGELOG update") asked to document everything implemented
> in 2.0.1 in the project `CHANGELOG` — previously the file contained only the
> 1.9.20 history (2020) and nothing about 2.0.1.
>
> This page is the wiki/docs mirror of the CHANGELOG 2.0.1 section. The full
> section lives in `CHANGELOG` (repo root); per-issue details stay in `docs/`
> and the GitHub Wiki.

## Problem

The `CHANGELOG` file was 85 lines of 1.9.20 content only. The entire 2.0.1 effort
(UI modernization, BFF layer, PHP 8.x compatibility, hundreds of bugfixes) was
undocumented in the CHANGELOG, even though `cfg/const.inc.php:22` declares
`TL_VERSION_NUMBER = '2.0.1'` and the README says "TestLink Upgraded 2.0.1".

## Solution — CHANGELOG 2.0.1 section

A new `TestLink - 2.0.1 (2026 Q3)` section was added at the top of `CHANGELOG`,
with these blocks:

- **MAJOR AREAS** — UI modernization (Dashio Bootstrap), BFF API layer (60 plain
  PHP REST endpoints under `api/<area>/index.php`), PHP 8.x compatibility
  (43+ warning classes resolved, PHP 8.4 support), 112 modernized HTML screens
  (87+ feature-parity checked), i18n with 10 locale JSON bundles, security
  hardening, the 5-workflow   CI factory, 181 regression test suites, and the
  361-page GitHub Wiki + `docs/` mirror.
- **MODERNIZED SCREENS** — grouped by ASIDE section (System, Product, Dashboard,
  Requirements, Test Spec, Plans, Execution, Reports, Auth & Documentation,
  Test Strategy) with each screen's HTML file, BFF API and issue references.
- **NEW FEATURES** — Markdown test-case import/export, Quality Objectives &
  Risk Traceability Matrix, Test Strategy section, native GitHub code tracker,
  apikey/public-link anonymous report access, Reset Password / Generate API Key
  user actions, create-project-from-existing, report send-by-email, multi-column
  drag-to-toolbar sort in the TPlan-with-CF report, Windows/Docker dev setup.
- **KEY BUGFIX / COMPATIBILITY EFFORTS** — PHP 8.0–8.4 fixes, installer/Database
  compatibility, security (stored XSS, SQL injection, CSRF hardening, apikey
  isolation), and legacy-behavior restorations.
- **PROCESS / KEEPING THIS CHANGELOG UPDATED** — a rule describing that every run
  landing feature work must add one line to the matching CHANGELOG section, the
  rule is mirrored in `ai/AGENTS.md` rule 22 and enforced by the code review step.

## Keeping the CHANGELOG updated

A new **rule 22** was appended to `ai/AGENTS.md`:

> **CHANGELOG is mandatory in every run.** The `CHANGELOG` file documents what
> has been implemented in 2.0.1; it must be updated in EVERY run that lands
> feature work on the default branch (modernized screen, bugfix, new feature,
> new BFF endpoint). Add one line under the matching 2.0.1 CHANGELOG section
> (screens / key bugfix / new features) with the issue reference. Per-issue
> details still live in `docs/` and the GitHub Wiki — the CHANGELOG is the
> one-line summary. Code review (rule 16) checks that the CHANGELOG was
> updated for the screen/issue being committed.

This guarantees continuous updates across all five CI workflows, since every
agent (human or Actions) is bound by `ai/AGENTS.md` and the code-review step
checks the CHANGELOG before each commit.

## Verification

- `CHANGELOG` — 2.0.1 section present (lines 9–246), all 5 headers present.
- `git log` shows the commit adding the section with `Refs #1424`.
- Regression suite numbers (181 suites, 3658 PASS) cross-checked against
  `tmp/TLU_Test_Cases.md`.
- No functional code touched — documentation-only task. Event Viewer unaffected
  (no runtime code changed).