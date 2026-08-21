# TODO — MD Test Case Import (#540) + follow-ups

Updated: 2026-08-21 ~13:10 UTC · Owner: opencode (sebi urmărește)

## DONE (do not redo)
- [x] Parser `lib/functions/markdown_tc_import.class.php` + reference fix (`93ffc9a6f`, Fixes #545)
      — real file: 122 cases / 24 suites / 0 errors
- [x] Endpoint v1 `POST api/testcases/index.php?action=import_md` (`a93e4051d`)
- [x] Event Viewer triage → #544 (execHistory), #541 does NOT cover it

## IMPORT RULES (legacy parity — lib/testcases/tcImport.php)
| Rule | Legacy | Ours now |
|---|---|---|
| permission | mgt_modify_tc | ✅ implemented |
| size limit | importLimitBytes | ❌ TODO step 2.2 |
| hitCriteria | name / internalID / externalID | ⚠️ title-in-suite only |
| actionOnHit | skip / update_last_version / generate_new / create_new_version | ⚠️ skip only |

---

## STEP 1 — Dedicated API file (atomic)
1.1. [ ] Create dir `api/testcasesimport/`
1.2. [ ] Copy `api/testcases/index.php` → `api/testcasesimport/index.php` as base
1.3. [ ] Strip from copy: action=context block, action=view block, helpers
       getParentChain/getProjectSuites stays (needed), view-only payload code
1.4. [ ] Keep ONLY: auth block (session+user), out(), getIntParam(),
       getProjectSuites(), action=import_md block, final Bad request fallback
1.5. [ ] Update file header docblock: URL `/api/testcasesimport/`, scope=import
1.6. [ ] Remove action=import_md block from `api/testcases/index.php` (restore
       read-only viewer BFF) + remove markdown require line if unused there
1.7. [ ] `php -l` BOTH files → zero syntax errors
1.8. [ ] Commit "refactor(api): dedicated testcasesimport BFF; viewer API back to read-only"

## STEP 2 — Legacy-parity options (atomic)
2.1. [ ] Read filesize of uploaded/markdown payload BEFORE parse
2.2. [ ] If > config_get('importLimitBytes') → HTTP 413 JSON error (mirror legacy msg key)
2.3. [ ] Add params: `hit_criteria` (only 'name' accepted for now; else 400),
       `action_on_hit` in {skip (default), create_new_version}
2.4. [ ] Implement create_new_version path: on duplicate title in target suite,
       fetch existing tcase id via nodes_hierarchy lookup by suite_id+name,
       call $tcaseMgr->create_new_version($tcid, ...) with new steps/preconditions
2.5. [ ] Report rows gain 'action' field: created|skipped|new_version
2.6. [ ] `php -l` + unit-ish CLI test of parser unchanged
2.7. [ ] Commit "feat(testcasesimport): legacy-parity duplicate handling + size limit"

## STEP 3 — Live end-to-end tests (atomic, curl)
3.1. [ ] Fresh session: POST login admin/admin via curl -c tmp/cookies.txt
       (fields: login=pwd form names from login.php snapshot)
3.2. [ ] Scratch project: check `api/projects/index.php` for POST/create action;
       IF exists → create "MD Import Fixture" (prefix MIF) and note its id;
       ELSE use browser (admin UI projectEdit) or fall back to project 2 with
       uniquely-named suite "MIF-Suite" and manual DB cleanup after
3.3. [ ] Test A (dry-run no-write): POST dry_run=1 synthetic MD (1 suite, 2 cases)
       → expect status ok, createdCount=2, suitesCreated=[name]; THEN verify via
       docker exec mariadb COUNT(nodes_hierarchy WHERE parent=<proj>) UNCHANGED
3.4. [ ] Test B (real write): same MD without dry_run → createdCount=2;
       verify DB: 1 testsuite node + 2 testcase nodes + tcversions + tcsteps rows
       exist; external IDs = prefix-1, prefix-2
3.5. [ ] Test C (idempotency): re-import same file → skippedCount=2, createdCount=0
3.6. [ ] Test D (create_new_version): re-import with action_on_hit=create_new_version
       → 2× new_version; tcversions count per case = 2
3.7. [ ] Test E (full real file): dry_run tmp/TLU_Test_Cases.md → 122 cases,
       24 suites recognized, mismatch warning vs scratch project name present
3.8. [ ] Test F (permissions): non-privileged user (noinv) → HTTP 403
3.9. [ ] Test G (size limit): payload > limit → HTTP 413

## STEP 4 — Event Viewer check (atomic)
4.1. [ ] SELECT events fired during tests window; expect ZERO new ERROR/WARNING
4.2. [ ] Any found → triage: fix inline if <8min else new GitHub issue w/ repro

## STEP 5 — Regression suite (atomic)
5.1. [ ] Append "## 26. MD Test Case Import API" to tmp/TLU_Test_Cases.md:
       one row per test A–G above with PASS/FAIL actual results
5.2. [ ] Commit docs

## STEP 6 — Docs mirror + Wiki (atomic)
6.1. [ ] New section in docs/WIKI-TEST-CASE-VIEWER.md OR new docs/WIKI-MD-IMPORT.md
       (endpoint URL, params table, rules table, example curl + response excerpt)
6.2. [ ] Same content into tmp/wiki-repo page (File-Import-Export.md likely target);
       include screenshot(s): terminal/browser showing dry_run report
6.3. [ ] cd tmp/wiki-repo && git pull --rebase origin master && commit && push
       (retry pull --rebase on rejection — shared clone)

## STEP 7 — Review & ship (atomic)
7.1. [ ] Code review subagent over full diff (security: escaping, auth, SQL build;
       correctness: version creation; consistency: other api/* patterns)
7.2. [ ] Apply BLOCKER/MAJOR findings; NITs optional
7.3. [ ] fetch+rebase sebiboga/sebiboga, push HEAD:sebiboga
7.4. [ ] Comment summary + evidence on #540 (keep OPEN — UI part pending)
7.5. [ ] Update this TODO: tick boxes, move leftovers to BACKLOG

## BACKLOG (tracked elsewhere)
- #542 Execution History modernization · #543 i18n bundles (cron) · #544 execHistory bugs
- UI screen "Import Test Cases" modernization consuming api/testcasesimport
- update_last_version / generate_new actions (legacy parity, lower priority)
