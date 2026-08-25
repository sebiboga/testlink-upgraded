# Bugfix — Issue #578: Remove Dead `.circleci/` Config

## Problem

The `.circleci/config.yml` file was a leftover from the upstream `TestLinkOpenSourceTRMS/testlink-code` repository (commit `a7199ba47`). It contained a CircleCI 2.0 pipeline configuration that:

- Used PHP 7.1.5 (EOL since December 2019)
- Was never connected to CircleCI on the GitHub repo
- Had no test suite to run (no `phpunit.xml` in repo)
- Confused contributors into thinking CircleCI was active

## Root Cause

The file was added to the original upstream repo and never cleaned up when the project migrated to GitHub Actions. Zero subsequent commits in 5+ years.

## Fix

Deleted `.circleci/config.yml` (the only file in the directory). Verified:

- Zero references in any PHP/TPL/HTML/JS/JSON/MD/YAML/SH file (outside `tl200/` backup)
- 7 GitHub Actions workflows in `.github/workflows/` remain intact
- No CircleCI status checks existed on the repo
- App still serves HTTP 200 on `http://127.0.0.1:8082`

## Files Changed

| File | Change |
|---|---|
| `.circleci/config.yml` | Deleted (37 lines) |

## Commit

`5a8948abc` — `chore: remove dead .circleci config (Fixes #578)`
