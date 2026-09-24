# Execution Script Edit Popup — Modernized Screen

Modernization of the **Test Scripts** lightbox popup (`lib/testcases/scriptAdd.php`,
`lib/testcases/scriptDelete.php`, the 1.9.20 `dashio` popup templates
`scriptAdd.tpl` / `scriptDelete.tpl`), the small window that links/unlinks a
code-tracker source file to a test case version right from the Test Case
content frame — GitHub issue
[#1574](https://github.com/sebiboga/testlink-upgraded/issues/1574).

It is the popup twin of the full-screen [Test Scripts](./Test-Scripts-Modernized.md)
view (#1543): both read/write the shared legacy table `testcase_script_links`
with the composite key `project_key && repository_name && code_path`, both speak
the GitHub contents API through the BFF, and both produce the legacy audit
events `audit_testcasescript_added` / `audit_testcasescript_deleted`.

**URL:** `gui/templates/testcases/scriptEdit.html?user_action=create|edit|delete&tcversion_id=<id>&tproject_id=<id>&tplan_id=<id>[&script_id=<key>][&locale=<locale>]`
**BFF API:** `api/scriptedit/index.php` (`GET init|meta|files`, `POST save|delete`)
**Rights:** authenticated session required (401 anonymous); `mgt_modify_tc` on the owning project (403 otherwise)
**Entry points:** legacy `open_script_add_window()` and `deleteScript` in `gui/javascript/testlink_library.js`
(now re-routed to the modern popup); legacy `scriptAdd.php` / `scriptDelete.php` kept as session-guarded
sign-in shims that 302 to the modern popup with the ids preserved.

---

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | `fc=scriptAdd` action in the legacy TC content frame (frameset popup window, opened only when `code_tracker_enabled`) | `open_script_add_window()`/`deleteScript` in `testlink_library.js` open `scriptEdit.html`; `user_action=delete` auto-opens the delete confirmation |
| Popup layout | 1.9.20 `dashio` frameset lightbox (header + form, Close X) | standalone Dashio popup: teal header, meta card (Test case / Version / Test project / Test plan / Code tracker), "Add script link" card, "Linked scripts" list, locale switcher, footer |
| Context | session- and request-bound; popup title showed only the tc external id | BFF resolves the tc version through the nodes tree: external id, version, project + plan names, linked code tracker (name/type/owner/repo/branch) |
| Script path | free text, Stash-only validation | free text + repository file-tree browser (GitHub contents API; Stash fallback via `getRepoContentForHTMLSelect`) + click-a-file fills the path |
| Branch / commit | Stash-only | live GitHub branch list + optional commit picker (metadata endpoint); legacy session keys `testscript_projectKey` / `testscript_repositoryName` still honoured |
| Validation | `error_code_does_not_exist_on_cts` on CTS | GitHub contents check, same user-facing message (`400`) |
| Save | `write_testcase_script()` — insert, duplicate returns true | BFF `POST save` mirrors it: duplicate (same triple) is dedupe-first, still `status:ok`; audit `audit_testcasescript_added` |
| Unlink | `scriptDelete.php?script_id=project&&repo&&path` confirm popup | row trash icon → Bootstrap confirm modal ("Unlink this script from the test case version?") → delete; audit `audit_testcasescript_deleted`; deep link `user_action=delete&script_id=` auto-opens the modal |
| View link | `buildViewCodeURL` (Stash) | GitHub fallback `viewBase/owner/repo/blob/branch/code_path` on every row |
| i18n | hardcoded strings / Smarty | `sced.*` keys (34) in all 10 bundles + `footers.scriptEdit`; header locale switcher re-renders the whole popup (`&locale=ro` full translation) |

## 2. REST API Reference

All routes need a session (401 anonymous) and pass the `bffSameOriginGuard`
(POST: `X-Requested-With: XMLHttpRequest` or same-origin Referer). Missing
action → `405`.

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=init&tcversion_id=&tproject_id=&user_action=` | everything the popup needs in one call: `context` (tproject/plan/tcversion names + external id + version), `can_modify` (from `mgt_modify_tc`), linked `code_tracker`, `metadata` (projects/repos/branches/commits for the branch) and the current `scripts` list | 400 missing/invalid tcversion; 403 `mgt_modify_tc right required`; tracker-less project → `code_tracker:null` + `tracker_message` (screen shows the "no tracker" banner) |
| `GET ?action=meta&tcversion_id=&tproject_id=` | repo branches + commits for a branch (githubrest mapped to `{sha,full,message,author,date}`) | 400 missing id; 502 Github unreachable |
| `GET ?action=files&tcversion_id=&tproject_id=&branch=&path=` | directory listing for the file browser (GitHub contents API; Stash fallback) | 400 missing id; 502 fetch failure (renders an in-tree error row) |
| `POST ?action=save` `{tproject_id,tcversion_id,project_key,repository_name,code_path,branch_name,commit_id}` | create script link; duplicate triple is a no-op that returns ok; audit `audit_testcasescript_added` | 400 missing fields, 403 no `mgt_modify_tc`, 400 `Script Link '<path>' does not exist on CTS!` |
| `POST ?action=delete` `{tproject_id,tcversion_id,script_id}` | delete by legacy composite id `project&&repo&&code_path`; audit `audit_testcasescript_deleted` | 400 malformed id, 403 no `mgt_modify_tc`, 404 unknown link |

`GET init` `200` payload (abridged):
```json
{ "status":"ok",
  "context": { "tproject_id":1,"tplan_id":2,"tcversion_id":5,"user_action":"edit",
               "tprojectName":"ScriptEdit Demo","tplanName":"Plan","tcversionName":"SED1574 Scripted TC",
               "tc_external_id":"1","version":"1" },
  "can_modify":"yes",
  "code_tracker": { "name":"ScriptEdit-GH-170909","verboseType":"github (Interface: rest)",
                    "repository":"sebiboga/testlink-upgraded","owner":"sebiboga","repo":"testlink-upgraded","branch":"sebiboga" },
  "metadata": { "project_key":"sebiboga","repository_name":"testlink-upgraded",
                "branches":{ "sebiboga":"sebiboga","task/issue-884": "task/issue-884" }, "commits":[] },
  "scripts": [ { "script_id":"sebiboga&&testlink-upgraded&&README.md","project_key":"sebiboga",
                 "repository_name":"testlink-upgraded","code_path":"README.md","branch_name":"sebiboga",
                 "commit_id":"","link_label":"README.md",
                 "view_url":"https://github.com/sebiboga/testlink-upgraded/blob/sebiboga/README.md" } ] }
```

## 3. Legacy parity notes

- **Row identity / delete id** is exactly the 1.9.20 composite
  `project_key && repository_name && code_path` (the PK columns of
  `testcase_script_links`) — `script_id=x` copied from that table still works,
  as in legacy `scriptDelete.php`.
- **Save is dedupe-first**: legacy `write_testcase_script()` returns `true`
  when the row already exists without inserting again; `POST save` reproduces
  that (idempotent link/save, as confirmed in the recorded suite).
- **CTS validation** keeps the legacy user-facing message
  `error_code_does_not_exist_on_cts` (`Script Link '<path>' does not exist on CTS!`).
- The **"Create code on the code tracker"** action still sends the user to the
  tracker's `createCodeURL`.
- **Audit parity**: `audit_testcasescript_added` / `audit_testcasescript_deleted`
  land in the `events` table with the same labels as #1543.
- The legacy `scriptAdd.php` / `scriptDelete.php` URLs are preserved as
  session-guarded shims: anonymous → legacy `top.location` login redirect;
  authenticated → `302` to the modern popup with `user_action`, `tcversion_id`,
  `tproject_id`, `script_id` preserved.
- The screen requires a valid `tcversion_id` (else the "Access denied / A test
  case version is required" state); a missing `mgt_modify_tc` shows the
  dedicated "You do not have permission to modify test cases." card.

## 4. i18n Keys

`sced.*` (34 keys, all 10 bundles) plus `footers.scriptEdit`. Romanian is a
full translation; the other 8 bundles carry the English value. All bundles
pass `json.tool`.

| Key | Value (en) |
|---|---|
| `sced.title` / `sced.addScript` / `sced.createOnCts` | Test Script / Add script link / Create code on the code tracker |
| `sced.testCase` / `sced.version` / `sced.project` / `sced.plan` / `sced.codeTracker` | Test case / Version / Test project / Test plan / Code tracker |
| `sced.projectKey` / `sced.repository` / `sced.branchName` / `sced.commitId` / `sced.scriptPath` | Project / Repository / Branch / Commit id / Script path (code path) |
| `sced.fileBrowser` / `sced.selectRepoFirst` / `sced.selectBranchFirst` / `sced.loadingBrowser` / `sced.folderEmpty` | Repository browser / not-yet-chosen repo / branch / loading / empty folder |
| `sced.linkedScripts` / `sced.noScripts` / `sced.view` / `sced.delete` | Linked scripts / No linked scripts / View / Delete |
| `sced.deleteTitle` / `sced.deleteMsg` / `sced.deleteError` | Delete script link / Unlink this script from the test case version? / delete error |
| `sced.saved` / `sced.saveError` / `sced.loadError` / `sced.required` / `sced.noModifyRight` | Script linked / save error / load error / validation / mgt_modify_tc message |
| `sced.accessDenied` / `sced.accessDeniedMsg` / `sced.noTracker` | Access denied / tcversion-required message / no code tracker banner |
| `footers.scriptEdit` | TestLink 2.0.1 · Script Edit |

## 5. Security

- Session auth + 401 on anonymous (verified via `curl`, no cookie).
- `bffSameOriginGuard` CSRF proof on every POST.
- `mgt_modify_tc` gate: init returns `can_modify`; save/delete hard-403 when the
  right is missing (browser shows the localized "no permission" card).
- Prepared statements via `$db->prepare_string()` on every user string.
- Output escaping in JS (`esc()`/`escJs()`); the GitHub view link is built
  server-side and rendered as a plain `<a>`.
- No secrets in the repo: the tracker token lives server-side in the
  code-tracker cfg and is never sent to the client; the fixture uses a public
  repo (`sebiboga/testlink-upgraded`, branch `sebiboga`) so no token is needed.

## 6. Testing

Executed in the browser (admin/admin, `http://localhost:8082`) against the
fixture `tmp/fixtures_1574.php` (tproject 1 `ScriptEdit Demo` with
`code_tracker_enabled=1`, GitHub tracker on the public repo, tcversion 5 with a
pre-seeded `README.md` link, tcversion 8 empty, restricted user `norights`):

- Init edit context + existing scripts; init create on the empty tcversion
  returns `scripts:[]`.
- Save `LICENSE` via the UI → toast "Test Script link added" + DB row; save of
  an already-linked path is deduped (idempotent); save of a non-existent path →
  400 `does not exist on CTS!`.
- Row delete → confirm modal → delete → toast + row (and DB row) removed;
  deep-link `user_action=delete&script_id=…&&README.md` auto-opens the modal and
  Cancel keeps the row.
- Repository file browser drills into folders (`install/` path crumbs render).
- Locale switcher EN → RO re-renders the whole UI (meta, form, buttons, footer).
- Rights matrix: anon → 401; `norights` user → 403 + "Access denied / You do
  not have permission to modify test cases."; missing action → 405.
- Legacy shims: anon → legacy login redirect; admin → 302 to the modern popup
  preserving ids.
- Event hygiene: `events` holds only `log_level=16` AUDIT rows
  (`audit_testcasescript_added` / `audit_testcasescript_deleted`); popup console
  clean; `php -l` clean on the BFF and both shims.
- All 10 bundles validated (`json.tool`), screenshots captured for the normal,
  delete-modal, RO-locale and 403 states.

See `tmp/TLU_Test_Cases.md` → **Suite 1574 (15/15 PASS)** for the recorded run.
