# TODO — MD Test Case Import (#540) + follow-ups

Updated: 2026-08-22 · Owner: opencode (sebi urmărește)

## DONE (do not redo)
- [x] Parser `lib/functions/markdown_tc_import.class.php` + reference fix (`93ffc9a6f`, Fixes #545)
      — real file: 122 cases / 24 suites / 0 errors
- [x] Endpoint v1 `POST api/testcases/index.php?action=import_md` (`a93e4051d`)
- [x] STEP 1 — Dedicated API file `api/testcasesimport/index.php`; viewer BFF back
      to read-only (`a287fdca6`)
- [x] STEP 2 — Legacy-parity options: size limit (HTTP 413), `hit_criteria=name`,
      `action_on_hit=skip|create_new_version` (`a287fdca6`)
- [x] STEP 3 — Live tests A–G all PASS (scratch project MIF id 29):
      dry_run no-write / real write / idempotency skip / create_new_version /
      full real file (120 would-create, mismatch reported) / noinv 403 / oversize 413
- [x] STEP 4 — Event Viewer: zero new Error/Warning during test window
- [x] STEP 5 — Regression suite appended as "## 27. MD Test Case Import API"
      (`84366c6e2`; commit message says "suite 26" — off-by-one, content correct)
- [x] STEP 6 — Docs mirror `docs/WIKI-MD-IMPORT.md` (`ec6be1828`) + Wiki page
      `MD-Test-Case-Import.md` pushed (`fd5d5af` in tmp/wiki-repo)

## IMPORT RULES (legacy parity — lib/testcases/tcImport.php)
| Rule | Legacy | Ours now |
|---|---|---|
| permission | mgt_modify_tc | ✅ implemented |
| size limit | importLimitBytes | ✅ implemented (413) |
| hitCriteria | name / internalID / externalID | ⚠️ name only (400 otherwise) |
| actionOnHit | skip / update_last_version / generate_new / create_new_version | ⚠️ skip, create_new_version |

---

## REMAINING #540
7.1. [ ] Code review subagent over parser+BFF diff (security/correctness) — apply BLOCKER/MAJOR
7.3. [x] push HEAD (ec6be1828 on sebiboga/sebiboga)
7.4. [ ] Comment summary + evidence on #540 (keep OPEN until UI ships)
7.5. [x] Update this TODO

## UI SCREEN (next atomic chunk — unblocks closing #540)
U1. [ ] Add `GET api/testcasesimport/?action=info` (tproject/container names,
       maxUploadBytes, grant mgt_modify_tc)
U2. [ ] New screen `gui/templates/testcases/tcImport.html` (Dashio pattern ca tcView.html):
       file type XML|Markdown, file picker, duplicate criteria/action selects,
       dry-run checkbox, max-size note, upload/cancel
       - MD  → AJAX multipart POST către BFF, raport secționat
         (mismatch banner, suites created/matched, created/skipped/new versions/
         failed/parser errors)
       - XML → form POST clasic către lib/testcases/tcImport.php (comportament
         legacy păstrat, nimic scăpat)
U3. [ ] Wire entry points: containerView.tpl (project-root import btn) +
       containerViewTestSuiteTextButtons.inc.tpl (suite import btn) → noul HTML
U4. [ ] i18n `tci.*` ×10 bundles + lint_i18n + json.tool validation
U5. [ ] Browser tests: admin happy path (dry+real), XML passthrough, no-perm user,
       oversize, mismatch banner, cancel; Event Viewer clean după
U6. [ ] Regression suite nouă în tmp/TLU_Test_Cases.md + docs/WIKI-MD-IMPORT.md
       update + screenshot-uri în wiki page
U7. [ ] Review, commit+push, comment pe #540 cu evidence, close issue dacă totul OK

## BACKLOG (tracked elsewhere)
- #542 Execution History modernization — DONE pe remote (CI); #543 i18n bundles — DONE
- update_last_version / generate_new actions (legacy parity, lower priority)
- hitCriteria internalID / externalID pentru MD import (lower priority)

## NOTE ENV (repro testing setup)
- App: `php -S 127.0.0.1:8082` din rădăcina repo (log: tmp/php_server.log)
- DB: container docker `testlink-mariadb` publicat pe `127.0.0.1:3307`
  (config_db.inc.php: DB_HOST=127.0.0.1:3307, db=testlink, user=testlink/testlink)
- Login test: admin/admin; user fără drepturi: noinv
