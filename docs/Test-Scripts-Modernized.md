# Test Scripts (Code Tracker Script Links) — Modernized Screen

Modernization of the **Test Scripts** screen (`lib/testcases/scriptAdd.php`,
`lib/testcases/scriptDelete.php`, `gui/templates/dashio/include/showScriptsTable.inc.tpl`),
which links source files from the test project's Code Tracker to a test case
version — GitHub issue
[#1543](https://github.com/sebiboga/testlink-upgraded/issues/1543).

TestLink's "Test Scripts" story lets a test case version reference the code
that automates it. The 1.9.20 implementation was a narrow legacy form that
worked reliably only for the **Stash** code-tracker interface; the native
GitHub interface shipped with it had no repository-content browsing at all.
This milestone re-implements the screen with full **GitHub REST** support (the
screens' modern twin, Code Tracker Management, configures the tracker; any
existing data is preserved).

**URL:** `gui/templates/testcases/tcScripts.html?tproject_id=<id>&tcversion_id=<id>`
**BFF API:** `api/tcscripts/index.php` (`GET tracker|list|commits|files`, `POST link|unlink`)
**Rights:** authenticated session required (401 anonymous); `mgt_modify_tc` on the owning project for link/unlink (403 otherwise)
**Entry points:** "Test Scripts" action in the modern Test Case Viewer toolbar (`tcView.html`); direct URL.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | `fc=scriptAdd` action inside the legacy TC viewer frameset (shown only when `code_tracker_enabled`) | toolbar "Test Scripts" action in `tcView.html`, opens `tcScripts.html?tproject_id=&tcversion_id=` in its own Dashio window |
| Linked list | `showScriptsTable.inc.tpl` — table of code path / project / repository / branch / commit with delete checkbox | DataTable: Code Path (external GitHub view link), Project (owner), Repository, Branch, Commit, per-row delete |
| View link | Stash interface-only `buildViewCodeURL` | same interface call; GitHub fallback `viewBase/owner/repo/blob/branch/code_path` |
| Tracker info | session-bound project code tracker (`tlCodeTracker::getLinkedTo` + `getInterfaceObject`) | BFF re-reads the linked tracker row + cfg; meta card shows tracker name/type and TC version |
| Link script form | Stash-only file browser (`getRepoContentForHTMLSelect`) + Repository/Code Path fields | modal with repository, branch (live GitHub branch list), optional commit (live GitHub commit list) and a **file tree** browser against the GitHub contents API (drill-down + `..` parent) |
| Code path entry | text field (path or full URL) | text field + click-a-file; pasted full GitHub URLs (`.../blob/<ref>/<path>`, `.../tree/<ref>/<path>` or Stash `?at=` URLs) are normalised server-side |
| Validation | `code does not exist on CTS` on Stash | GitHub contents check for files AND directories (`ghPathExists`), same user-facing message |
| Persistence | rows in `testcase_script_links`; session keys `testscript_projectKey` / `testscript_repositoryName` | same table, same session keys |
| CF "Test Script" sync | `write_cfield_testscript` (only when CF linked to testcase at design, active+open+no baseline+no reviewer, `testproject_edit_executed_testcases` right when executions exist) | parity preserved |
| Unlink | `scriptDelete.php` DELETE by `project&&repository&&code_path` | same id format; confirm modal; audit `audit_testcasescript_deleted` |
| Audit | `audit_testcasescript_added` (direct link) / `audit_testcasescript_deleted` (script id) | identical events written to `events` (verified in Event Viewer) |

## 2. REST API Reference

All routes need a session (401 anonymous) and pass the `bffSameOriginGuard`
(for POST: `X-Requested-With: XMLHttpRequest` or same-origin Referer). Reads
may pass `tproject_id` explicitly or only `tcversion_id` (the owning project is
resolved through the nodes tree).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=tracker&tcversion_id=` | linked code tracker + branches | 400 missing id / unresolvable project; tracker-less project → `tracker:null` + message (screen shows the "no tracker" banner) |
| `GET ?action=list&tcversion_id=&tproject_id=` | linked scripts + `can_modify` + resident view URLs | 400 missing id |
| `GET ?action=commits&tcversion_id=&branch=` | recent commits (githubrest `getCommits()` mapped to arrays) | 502 when the interface has no `getCommits` / GitHub unreachable |
| `GET ?action=files&tcversion_id=&branch=&path=` | repo directory listing (GitHub contents API; Stash fallback via `getRepoContentForHTMLSelect`) | 502 fetch failure (renders an in-tree error row) |
| `POST ?action=link` `{tproject_id,tcversion_id,project_key,repository_name,code_path,branch_name,commit_id}` | create script link + CF sync + audit; duplicates are no-ops | 400 missing fields, 403 no `mgt_modify_tc`, 400 path missing on CTS, 500 DB error |
| `POST ?action=unlink` `{tproject_id,tcversion_id,script_id}` | delete a script link by legacy id + audit | 400 malformed id, 403 no `mgt_modify_tc`, 404 unknown link |

`GET list` `200` payload (truncated):
```json
{ "status":"ok", "scripts":[{
    "script_id":"sebiboga&&testlink-upgraded&&docs/CI-FACTORY.md",
    "project_key":"sebiboga","repository_name":"testlink-upgraded",
    "code_path":"docs/CI-FACTORY.md","branch_name":"sebiboga",
    "commit_id":"","view_url":"https://github.com/sebiboga/testlink-upgraded/blob/sebiboga/docs/CI-FACTORY.md"
}], "can_modify":"yes","tproject_id":12 }
```

`POST link` `200`:
```json
{ "status":"ok","message":"Test Script link added","link":{
    "script_id":"sebiboga&&testlink-upgraded&&docs/CI-FACTORY.md",
    "project_key":"sebiboga","repository_name":"testlink-upgraded",
    "code_path":"docs/CI-FACTORY.md","branch_name":"sebiboga","commit_id":null,
    "view_url":"https://github.com/sebiboga/testlink-upgraded/blob/sebiboga/docs/CI-FACTORY.md"} }
```

## 3. Legacy parity notes

- **Row identity** is the 1.9.20 composite: `project_key && repository_name && code_path`
  (three PK columns of `testcase_script_links`) — unlink works with a
  `script_id` copied out of that table (legacy `scriptDelete.php` behaviour).
- **Normalisation pipeline** (scriptAdd parity): strip the view base
  (`uribase`/`viewBase`) from a pasted URL, then (GitHub) the
  `owner/repo/(blob|tree)/<ref>/` prefix, then any `?at=` branch/commit
  reference; commit refs may come as `refs/heads/x` / `refs/tags/x` / raw
  branch / `[0-9a-f]{7,40}` hash.
- **Interface gap filled**: `githubrestCodeTrackerInterface` has no
  repo-content browsing (only Stash did). The BFF speaks the GitHub contents
  API itself (`ghContents`, `ghGet` with optional bearer token + proxy) so the
  file browser and the CTS validation work for GitHub trackers.
- **Commits** come from the githubrest interface and are PHP arrays (the
  route maps them onto `{sha, full, message, author, date}`).
- **No code tracker / disabled project**: the screen shows a localized banner
  and hides the Link action; list still renders (empty).
- The screen requires a `tcversion_id`; the project id can be implicit.

## 4. i18n Keys

`tscs.*` (29 keys, all 10 bundles) plus `footers.tcScripts` and the entry-point
key `tcview.testScripts`. All `common.*` keys used (`refresh`, `cancel`,
`save`, `actions`) already existed. Romanian is a full translation; the other
8 bundles carry the English value.

| Key | Value (en) |
|---|---|
| `tscs.title` / `tscs.titleSub` | Test Scripts / Code Tracker script links for a test case version |
| `tscs.linkScript` / `tscs.backToTc` | Link Script / Back to Test Case |
| `tscs.linkedScripts` | Linked Scripts |
| `tscs.codePath` / `tscs.projectKey` / `tscs.repository` / `tscs.branch` / `tscs.commit` | Code Path / Project / Repository / Branch / Commit |
| `tscs.codePathHint` | Pick from the file browser below, or paste a full repo URL… |
| `tscs.fileBrowser` / `tscs.loadingBrowser` | File Browser / Select branch to load repository contents... |
| `tscs.selectBranchFirst` / `tscs.folderEmpty` | Select a branch first / (empty folder) |
| `tscs.availableBranches` / `tscs.codeTracker` / `tscs.testCaseVersion` | Available branches: / Code Tracker / Test Case Version |
| `tscs.count` | `{n} linked script(s)` |
| `tscs.noTracker` | No code tracker is linked to this test project… |
| `tscs.loadError` / `tscs.saveError` / `tscs.deleteError` | Error loading/linking/deleting |
| `tscs.empty` / `tscs.required` / `tscs.noModifyRight` | no links / validation / mgt_modify_tc message |
| `tscs.delete` / `tscs.deleteTitle` / `tscs.deleteQuestion` | Delete / Delete Script Link / Delete this script link? |
| `footers.tcScripts` / `tcview.testScripts` | TestLink 2.0.1 - Test Scripts / Test Scripts |

## 5. Security

- Session auth + 401 on anonymous (verified with `curl`, no cookie).
- `bffSameOriginGuard` CSRF proof required on every POST.
- `mgt_modify_tc` gate on link/unlink → 403 (reads stay open to project viewers).
- Prepared statements via `$db->prepare_string()` on every user string.
- Output escaping in JS (`esc()`/`escJs()`) for paths/labels injected into the
  GUI (no printf-paste of untracked content); the GitHub view link is built
  server-side and rendered as a normal `<a>`.
- No secrets in the repo: the tracker token lives in the code-tracker cfg
  (server-side), never sent to the client; the fixture reads a token from the
  environment only.

## 6. Testing

Executed in the browser (admin/admin, `http://localhost:8082`, fixture project
`TS1543` = tproject 12, `TC-TS1543` version 1 = tcversion 15, GitHub tracker
`GH-TS1543`):

- List renders (Code Path / Project / Repository / Branch / Commit / Actions).
- Link Script modal: repository prefilled, branch prefilled + hint of all
  GitHub branches, commit selector, file tree root → drill down `docs/` →
  click `docs/CHANGELOG-2.0.1.md` fills Code Path.
- Save→alert "Test Script link added", table refreshes (3 rows incl.
  `docs/CI-FACTORY.md`), repo name stored not the full URL.
- Duplicate re-link is a no-op (no new row, idempotent).
- Delete → confirm modal shows path → Confirm → alert
  "The test script link was successfully deleted!", row gone.
- Backend paths via `curl` + session cookie: tracker/branches, commits
  (message+author+date), files tree, magic-path normalisation for
  `https://github.com/<o>/<r>/blob/<ref>/<path>`, and `?at=` (Stash) refs.
- Right path: POST link/unlink without `mgt_modify_tc` → 403.
- Audit rows land in `events` with correct labels (`CREATE`/`DELETE`,
  `testcase_script_links`); Event Viewer shows no new ERROR/WARN.
- Locale switcher present; all 10 `*.json` bundles pass `json.tool`.

See `tmp/TLU_Test_Cases.md` → **Suite 1543** for the recorded run.