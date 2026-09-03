# TODO — TestLink 2.0.1 Modernization

Updated: 2026-09-03 · Owner: opencode (sebi urmărește)

---

## IN PROGRESS (current)
None — last session finished Search Test Cases suite (823). Ready for next screen.

## NEXT (ASIDE menu order)
Per AGENTS.md rule 1: top to bottom, one screen at a time.
Check `tmp/ASIDE-MENU.md` for remaining un-modernized screens.

## SERVICES RUNNING
- MariaDB: container `testlink-mariadb`, port 3307 (`127.0.0.1:3307`)
- TestLink: `php -S 127.0.0.1:8082` from repo root (log: `tmp/php_server.log`)
- Login: admin/admin; no-perm user: noinv

---

## MODERNIZED SCREENS (done)

| Screen | Refs | Status |
|---|---|---|
| Dashboard (project issues widget) | #772 | DONE — suite 820, XSS fix, async enrichment |
| Execute Tests (linked-bugs + attachments) | #789, #772, #821 | DONE — suites 813/814/822; whitelist fix |
| Test Suite viewer popup | #818 | DONE — suite 819 (21/21) |
| Event Viewer (object-filter) | #757 | DONE — suites 816/821; redirect cleanup |
| Keywords Assign | #820 | DONE — i18n keys added to all 10 bundles |
| Search Test Cases (full screen) | #822 | DONE — suite 823 (18/18); 2 bugs found+fixed (#823/#824) |
| Quick Search | #662/#789 | DONE — suites 22/25/799 |
| Advanced Search | #662/#789 | DONE — suites 28/28b/34 |

## COMPLETED (do not redo)
- [x] Config whitelist: jpeg/webp/bmp/heic/heif/pdf/txt (`8098ef3f2`, Fixes #821)
- [x] Suite 822: Link bug to step + .jpeg upload (`aa543a3b9`, 9/9 PASS)
- [x] Suite 823: Search Test Cases full screen (`39d9c5b11`, 18/18 PASS)
- [x] Bug #823: DataTables fallback ported to searchView.html (CLOSED)
- [x] Bug #824: ReferenceError `p` hoisted to top-level (CLOSED)
- [x] Issue #822 tracking: closed with final checkpoint comment
- [x] Parser `lib/functions/markdown_tc_import.class.php` (`93ffc9a6f`, Fixes #545)
- [x] Endpoint v1 `POST api/testcases/index.php?action=import_md` (`a93e4051d`)
- [x] Dedicated API file `api/testcasesimport/index.php` (`a287fdca6`)
- [x] Legacy-parity options: size limit, hit_criteria, action_on_hit (`a287fdca6`)
- [x] Live tests A–G all PASS (scratch project MIF id 29)
- [x] Docs mirror `docs/WIKI-MD-IMPORT.md` + Wiki page pushed
- [x] Event Viewer: zero new Error/Warning during test window
- [x] Regression suites appended (MD import, dash, execute, event viewer, keywords, search)
- [x] Async linked-bugs enrichment + paperclip real-link fix (`faccc789f`)
- [x] Suite 819 (21/21 PASS) — Test Suite viewer popup regression

## PENDING (known gaps)
- [ ] MD import: code review subagent over parser+BFF diff (security/correctness)
- [ ] MD import: `update_last_version` / `generate_new` actions (legacy parity)
- [ ] MD import: `hitCriteria internalID / externalID` (legacy parity)
- [ ] Docs/Wiki update: async enrich/paperclip/dashboard changes (not yet mirrored)
- [ ] Junk data cleanup: execs 29/30, attachment id=1 repointed to exec 28

## BACKLOG (tracked elsewhere)
- #540 MD Test Case Import — UI screen pending (TBD: tcImport.html + BFF info endpoint)
- #542 Execution History modernization — DONE on remote (CI)
- #543 i18n bundles — DONE

## GIT STATE
- Branch: `sebiboga` (local == remote at `39d9c5b11`)
- CI: `fix-bug.yml` runs every 2h (newest open `bug`-labelled issue → fix/* branch → PR)
- `tmp/TLU_Test_Cases.md` tracked via `git add -f` (gitignored)

## ENV NOTES
- DB creds: `config_db.inc.php` → testlink/testlink@127.0.0.1:3307
- mysql CLI NOT installed — use BFF API or browser fetch for DB queries
- Chrome pages: 15 = Search Test Cases, 10 = Execute Tests, 13 = Dashboard
- curl jar in `tmp/mp.jar` may be stale — browser fetch with credentials is reliable
