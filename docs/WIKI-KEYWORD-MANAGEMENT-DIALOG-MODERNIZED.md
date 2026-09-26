# Keyword Management Dialog (keywordsEdit) — Modernized Screen

Modernization of the legacy keyword popup — `lib/keywords/keywordsEdit.php` +
`gui/templates/dashio/keywords/keywordsEdit.tpl` — GitHub issue
[#1599](https://github.com/sebiboga/testlink-upgraded/issues/1599).

The legacy Smarty dialog (create / edit / delete / create-and-link in one
controller) is replaced by a standalone Dashio popup
(`gui/templates/keywords/keywordsEdit.html`) backed by a plain-PHP REST BFF
(`api/keywordsedit/index.php`).

**Path:** Test Case Design → Test Case Viewer → per-version **Create Keyword** /
**Create Keyword and Link**; also reachable from the Keyword Management screen
via `$actions->keywordsEdit` in `lib/functions/common.php`.
**URL:** `gui/templates/keywords/keywordsEdit.html?mode=create|edit|cfl&tproject_id=<id>[&id=<kwId>][&tcversion_id=<v>]`
**BFF API:** `api/keywordsedit/index.php`
**Rights:** `mgt_modify_key` **AND** `mgt_view_key` at test-project level
(legacy `initEnv()` AND-mode gate) — enforced server-side on every route and
mirrored client-side to hide the write controls.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)
7. [Screenshots](#7-screenshots)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Create keyword | `doAction=create` form → `do_create` → `testproject::addKeyword()` | `POST ?action=create` → same `addKeyword()` call, Dashio card with Name + Notes |
| Edit keyword | `doAction=edit&id=N` → `do_update` → `testproject::updateKeyword()` | `GET init&mode=edit&id=N` prefills the card; `POST ?action=update` |
| Create **and link** | `doAction=cfl&tcversion_id=V` → `do_cfl` = `addKeyword()` + `testcase::addKeywords()` | `GET init&mode=cfl&tcversion_id=V` shows the test-case context; `POST ?action=create_link` does the same two calls |
| Delete keyword | `do_delete` → `deleteKeyword(checkBeforeDelete)` — refuses when linked to executed/frozen TC versions | **Delete** button (edit mode) + confirm modal; `POST ?action=delete` keeps the executed/frozen refusal (422 `DELETE_BLOCKED`) |
| Name validation | client hint “quotes and commas are not allowed”, server `tlKeyword::checkKeywordName()` | identical client hint **and** identical server codes (`E_NAMENOTALLOWED/-1`, `E_NAMELENGTH/-2`, `E_NAMEALREADYEXISTS/-4`) surfaced with the legacy messages |
| Error feedback | `$gui->user_feedback` from `getKeywordErrorMessage()` | teal success box / red error box, fed by the same legacy lang keys |
| Keyword context | title only | context card: test project (and the test case name + id in `cfl` mode) |
| Refresh of the opener | the legacy form reloaded the opener frame | `window.opener` refresh when the popup was script-opened, `Open Keyword Management` link otherwise |
| ASIDE entry | `keywordsEdit` was a hidden dialog URL | `getActions()` now exposes `$actions->keywordsEdit` (modern URL with the `mode=` context) |

## 2. REST API Reference

Session-based auth; every route passes `bffSameOriginGuard()` (POST/PUT/DELETE
need the `X-Requested-With: XMLHttpRequest` header) and
`requireKeywordRights()` (`mgt_modify_key` AND `mgt_view_key` on `tproject_id`,
plus the legacy `tproject_id > 0` precondition).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=init&tproject_id=N&mode=create` | project context, empty keyword | 400 invalid project, 403 no right |
| `GET ?action=init&tproject_id=N&mode=edit&id=K` | keyword name/notes for the card | 400 invalid keyword id, 404 keyword not found **or owned by another project**, 403 |
| `GET ?action=init&tproject_id=N&mode=cfl&tcversion_id=V` | test case context (`tcase_id`, `tcase_name`) for the create-and-link card | 400/404 for the version, 403 |
| `POST ?action=create` `{tproject_id, keyword, notes}` | create | 400/403, 422 + `error_code` |
| `POST ?action=update` `{tproject_id, id, keyword, notes}` | rename / re-notes | 400/403, 404 foreign keyword, 422 + `error_code` |
| `POST ?action=create_link` `{tproject_id, keyword, notes, tcversion_id}` | create + link to the TC version | 400/403, 404, 422 + `error_code` |
| `POST ?action=delete` `{tproject_id, id}` | delete (refuses when linked to executed/frozen TC versions) | 400/403, 404 foreign keyword, 422 `DELETE_BLOCKED` |

The payload also returns `rights.mgt_view_events` so the card can offer the
event history link for the keyword, exactly like the legacy dialog did.

## 3. Legacy parity notes

- `testproject::getName()` is used for the context; in `cfl` mode the test case
  name is read from the `nodes_hierarchy` row of the version's parent id (the
  2.0.1 refactor has **no** static `testcase::getName()`, see issue #1600/#1601).
- The delete refusal uses the same `deleteKeyword(checkBeforeDelete:true)` call
  as 1.9.20, so `$cfg->keywords->onDeleteCheckExecutedTCVersions` and
  `onDeleteCheckFrozenTCVersions` still decide.
- `lib/keywords/keywordsEdit.php` is kept as a **session-guarded redirect shim**:
  anonymous users get the legacy `testlinkInitPage()` login bounce, the
  `create|edit|cfl` display requests are mapped onto the modern popup URL, and
  the legacy `do_create|do_update|do_delete|do_cfl` POSTs are still executed
  server-side (with the same AND-mode gate) before a redirect to the modern
  screen — so an old bookmarked form cannot silently lose data.
- The Test Case Viewer gained the two entry buttons inside each **version card**
  (`gui/templates/testcases/tcView.html`); the version id is passed explicitly
  so the keyword is linked to the version whose card was clicked, not to the
  “current” one.

## 4. i18n Keys

25 `kwedit.*` keys + `footers.keywordsEdit` were added to **all** locale bundles
(`en, ro, de, es, fr, it, pt, ru, ja, zh`):
`title, subtitle, context, testProject, testCase, name, notes, notesHint,
charHint, create, edit, cfl, save, saving, cancel, delete, close, loading,
loadError, noRight, msgCreated, msgUpdated, msgLinked, msgDeleted,
deleteTitle, deleteText, deleteConfirm, deleteCancel, openManager, required`.

## 5. Security

- **AND-mode rights** — legacy `initEnv()` refused a *view-only* keyword manager
  (the dialog can write, so `mgt_modify_key` alone was not enough). The same
  gate is applied here and in the shim; the view-only case is covered by
  regression test 16 of suite 1599 (issue #1008).
- **Project ownership of the keyword id** — rights are evaluated for the
  `tproject_id` sent by the caller, while the object is addressed by a bare
  `id`; neither `tlKeyword::writeToDB()` (`UPDATE … WHERE id = X`) nor
  `testproject::deleteKeyword()` (id only) re-check the owner. Every route that
  takes a keyword id now loads it and answers `404` when it belongs to another
  project, so a manager of project A can neither rename, re-own nor delete a
  keyword of project B (issue #1601, fixed in both keyword BFFs).
- **CSRF** — `bffSameOriginGuard()` on all writes; anonymous requests get 401.
- Nothing is interpolated into HTML unescaped (`esc()` in the front-end,
  prepared statements in the model layer).

## 6. Testing

Suite **1599** in `tmp/TLU_Test_Cases.md` (24 cases, all PASS): init for the
three modes, 400/404/403/401 paths, all four writes, the three name-validation
errors, the executed/frozen delete refusal, the view-only and no-rights users,
the browser flows (create, duplicate-name error box, delete modal, locale
switch RO), the legacy shim redirects and the Event Viewer check (no new
ERROR/WARNING row).

Bugs found and fixed while testing:

| Issue | Symptom | Fix |
|---|---|---|
| [#1008](https://github.com/sebiboga/testlink-upgraded/issues/1008) | view-only keyword manager could open the write dialog | AND-mode gate in `api/keywords/index.php` + this BFF |
| [#1600](https://github.com/sebiboga/testlink-upgraded/issues/1600) | every keyword create error was a fatal **HTTP 500** (`tlKeyword::getErrorMessage()` no longer exists) | new legacy-parity error mapping; `tlKeyword::getError()` `E_NAMELENGTH` case + `default` fixed |
| [#1601](https://github.com/sebiboga/testlink-upgraded/issues/1601) | cross-project keyword rename / re-own / delete | `requireKeywordOfProject()` in both keyword BFFs |
| [#1602](https://github.com/sebiboga/testlink-upgraded/issues/1602) | tcView buttons never rendered; wrong version targeted | grants added to the `view` action; `openKeywordPopup(mode, tcversion_id)` |

## 7. Screenshots

- Create-and-link mode after a successful link:
  `docs/screenshots/issue-1599-keywordsedit-cfl.png`
- Edit mode with the delete confirmation modal:
  `docs/screenshots/issue-1599-keywordsedit-delete-modal.png`
- Permission denied (user with `mgt_view_key` only):
  `docs/screenshots/issue-1599-keywordsedit-no-right.png`
- Test Case Viewer with the two keyword buttons:
  `docs/screenshots/issue-1599-tcview-keyword-buttons.png`
