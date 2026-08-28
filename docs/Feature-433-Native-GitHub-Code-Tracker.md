# Issue 433 — Native GitHub Code Tracker

**Issue:** [#433](https://github.com/sebiboga/testlink-upgraded/issues/433)
**Branch:** `sebiboga` · **Commits:** `90e724bb8` (backend), `781349135` (frontend + i18n)
**Status:** BACKEND VERIFIED · browser tests deferred (see Notes)

## What changed

Replaces the generic `stash`-style code tracker for GitHub with a **native GitHub REST
interface**, so GitHub repos get a dedicated form instead of raw XML config.

### New native interface — `lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php`

cURL wrapper over `https://api.github.com` providing:
- `connect()` / `isConnected()`
- `getBranches()` / `getTags()` / `getCommits()` / `getPullRequests()`
- `buildViewCodeURL()` → `github.com/{owner}/{repo}/blob|commit|pull`
- `buildViewCodeLink()` → `PR-N`, `#N`

### Registration — `lib/functions/tlCodeTracker.class.php`

- `$systems[200] = ['type' => 'github', 'api' => 'rest', 'enabled' => true, 'order' => 1]`
- `getImplementationForType()` maps type 200 / `github` → `githubrestCodeTrackerInterface`

> **Class-name collision (root cause):** the plain `githubrestInterface` name is already
> used by the **issue-tracker** integration (`lib/issuetrackerintegration/githubrestInterface.class.php`),
> and the include_path order (`cfg/const.inc.php`) resolves `issuetrackerintegration`
> before `codetrackerintegration` — so the code-tracker class silently failed to load.
> Fixed by naming the code-tracker class `githubrestCodeTrackerInterface` and mapping it
> explicitly. **Never reuse the plain `githubrestInterface` name for any code-tracker.**

### BFF — `api/codetracker/index.php`

- `POST /test_github` — live connectivity check (repo/token/branch) without saving
- `GET /{id}/branches` | `/tags` | `/commits?branch=` | `/pulls?state=` — JSON listings
- `trackerToJSON()` extracts `/github` fields and masks the token as `********`
- tightened `GET /{id}` / `PUT /{id}` routes to require a single segment

### Frontend — `gui/templates/codetracker/codetrackerView.html`

- Type-aware modal: when **Type = github** show Repository URL + Branch + Access Token +
  **Test Connection** button; generic Server URL / API Key / XML textarea are hidden (kept
  for `stash`/legacy type).
- `testGhConnection()` calls `POST /test_github` pre-save with green/red result banner.
- `buildGhCfg()` composes the XML config (`<repository>` / `<branch>` / `<token>`) from the
  form fields; storage stays XML (`codetrackers.cfg`), interface also accepts flat JSON.

### i18n — 9 new `ct.*` keys in all 10 bundles (en/de/es/fr/it/ja/pt/ro/ru/zh)

`ct.repository`, `ct.branch`, `ct.token`, `ct.tokenHelp`, `ct.testConnection`,
`ct.testing`, `ct.connOk`, `ct.connKo`, `ct.validation.repoRequired`. All bundles validate.

## Verification

Live (local, real GitHub API):

```
POST /api/codetracker/index.php/test_github
  { "repository":"https://github.com/sebiboga/testlink-upgraded", "token":"", "branch":"" }
→ { "status":"ok", "connected":true, "defaultBranch":"sebiboga",
    "branches":["fix/issue-616","fix/issue-765-get-last-child-info","sebiboga"],
    "branchCount":3 }
```

`GET /api/codetracker/index.php/meta/types` returns both `stash` (1) and `github` (200).

## Notes

- Local sandbox lacks the `simplexml` PHP extension, so saving via the XML path
  (`tlCodeTracker::create()` → `checkXMLCfg()`) can't be exercised locally. The CI test
  env (modernize.yml loads `xml`) covers the save path.
- Browser tests, docs screenshots and the full `tmp/TLU_Test_Cases.md` suite 64 are
  recorded; cases needing browser + `simplexml` are marked PENDING (deferred to CI env).
- The GitHub API integration itself (connect/branches/etc.) is verified live — the
  per-tracker listing endpoints share the exact same `ghGet`/`getBranches` code path.
