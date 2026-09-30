# Move/Copy Test Cases to another Test Suite — Modernized (TestLink 2.0.1)

**Tracking issue:** [#1724](https://github.com/sebiboga/testlink-upgraded/issues/1724) (enhancement)

| | |
|---|---|
| Legacy popup | `gui/templates/dashio/testcases/containerMoveTC.tpl` (served by `lib/testcases/containerEdit.php`) |
| Legacy actions | `move_testcases_viewer`, `do_move_tcase_set`, `do_copy_tcase_set`, `do_copy_tcase_set_ghost` |
| Modern screen | `gui/templates/testcases/containerMoveTC.html` |
| BFF | `api/tcmovecopy/index.php` (`init`, `move`, `copy`, `copy_ghost`) |
| Wiring | `$actions->containerMoveTC` (`lib/functions/common.php`) + a session-guarded 302 shim in `lib/testcases/containerEdit.php` |
| i18n | `tmvc.*` (50 keys) + `footers.containerMoveTC` in all 10 modern bundles |
| Suite | `tmp/TLU_Test_Cases.md` → **Suite 1724, 44/44 PASS** |
| Fixture | `tmp/fixtures_1724b.php` (1 project, 3 suites, 4 cases, 1 no-rights user) |

---

## 1. Why this screen

The `TODO` section of `docs/MODERNIZATION-STATUS.md` is **empty** — every ASIDE entry already maps to
a modern `.html` screen + BFF. The remaining legacy-only *features* are the popup actions that
TestLink launches from inside a test case/suite frame, and the smallest one left is
**Move/Copy Test Cases to another Test Suite** — four actions of a single controller, one template,
one BFF.

Reading the legacy code also turned up an authorization hole that no neighbouring migration would
ever have fixed, because nothing else touched these four actions.


## 2. What the legacy code did (and what it did not check)

`lib/testcases/containerEdit.php` served the popup for four actions:

| Action | Behaviour |
|---|---|
| `move_testcases_viewer` | renders `containerMoveTC.tpl`: the source suite's cases + `gen_combo_test_suites()` |
| `do_move_tcase_set` | `tree::change_parent($objectID, $containerID)` per case |
| `do_copy_tcase_set` | `testcase::copy_to($tcid, $containerID, $userId, $copyOpt)` |
| `do_copy_tcase_set_ghost` | same, with `stepAsGhost = true` |

`$copyOpt` carried `copyKeywords` and `copyRequirementAssignments`; a move ignored both.

**The hole:** all three write actions were plain **GET-submitted forms**. `containerEdit.php` never
called `hasRight()` for them and never proved that the submitted `objectID` / `objectIDs[]` belonged
to the suite being displayed — any authenticated session could POST a test case id from **any** test
project and relink it into an arbitrary suite. The modern BFF closes that: every write re-checks
`mgt_modify_tc` on the **owning** test project and proves each submitted id to be a test case of the
addressed source suite.

## 3. Security model of `api/tcmovecopy`

| Guard | Where |
|---|---|
| active session + inactivity check | `bffEnforceSession()` |
| same-origin proof on every write | `bffSameOriginGuard()` (Origin/Referer, XRW only as fallback) |
| `mgt_modify_tc` on the **owning** test project of the source suite | `tmvc_resolve_suite()` |
| source suite is a real `testsuite` node of that project | `tmvc_node_type()` / `tmvc_owning_project()` |
| destination is a `testsuite` of the **same** project | `tmvc_resolve_target()` |
| every submitted id is a test case **of the source suite** | `testsuite_id` of `testcase::get_by_id()` |
| destination ≠ source | `same_target` → 409 |
| method check | writes are POST-only → 405 |

Responses are JSON: `{status, context, targets, testcases, domains, options}` for `init`, and
`{status, action, moved|copied, created[], failed[]}` for the writes. Errors carry a stable `code`
(`missing_suite_id`, `unknown_suite`, `unknown_target_suite`, `foreign_testcase`, `no_selection`,
`same_target`, `access_denied`, `session_expired`, `method_not_allowed`, `move_failed`,
`copy_failed`, `unknown_action`) which the screen maps to its own i18n key — the server never sends
user-facing prose to translate.

## 4. The screen


- teal header + dark toolbar (project, source suite, locale switcher) — the Dashio pattern of the
  other modernized screens;
- **Destination Test Suite** card: the dotted suite list from `gen_combo_test_suites()`, with the
  source suite rendered as a disabled option so it cannot be picked by accident;
- **Test Cases** card: one row per case with External ID (`#PREFIX-N`), name, summary and the
  Status / Importance / Execution labels produced by `getConfigAndLabels()` — the same source the
  legacy screen used (`containerEdit.php:1030-1040`), so the wording is identical;
- `Select all` / `Clear selection` + a live selected counter; all three action buttons stay disabled
  until there is both a selection and a destination;
- `Copy Keywords` / `Copy Requirement Assignments` apply to **Copy** and **Copy as Ghost Steps** only
  (a Move relinks the originals) — stated in the hint under the pickers;
- every action goes through a **Bootstrap confirm modal** naming the count and the destination; the
  ghost variant adds the "no expected results" reminder;
- states: loading overlay, green result banner (survives the post-write reload), red error box,
  red **access denied** box, and an empty-suite box.


## 5. i18n

50 `tmvc.*` keys + `footers.containerMoveTC` were appended to **all ten** modern bundles (`en ro de
es fr it pt ru ja zh`), each validated with `python3 -m json.tool`. They are appended at the end of
the file — never re-sorted — because `json.dump(sort_keys=True)` rewrote ~2400 unrelated lines and
would collide with the concurrent CI agents editing the same files.


Labels that do not belong to the screen (Status / Importance / Execution) are localized **server
side** and shipped in `domains`, so the table is translated even for the 9 locales the legacy
`strings.txt` never covered.

## 6. Legacy routing

`lib/testcases/containerEdit.php` intercepts exactly four actions, after `init_args()` has resolved
`testsuiteID` / `tprojectID` and after `testlinkInitPage()` (so the session is authenticated), and
302-redirects to the modern screen:

```
lib/testcases/containerEdit.php?doAction=do_copy_tcase_set&testsuiteID=158
    &objectIDs[]=161&objectIDs[]=165&containerID=160&containerType=testsuite
  → /gui/templates/testcases/containerMoveTC.html?suite_id=158&tproject_id=157
    &tcase_ids[0]=161&tcase_ids[1]=165&target_suite_id=160&pending_action=copy
```

The old selection (`objectIDs[]` or a single `objectID`), the destination (`containerID`) and the
intent (`pending_action`) travel as query parameters; the screen shows an explicit
**"nothing has been changed yet"** notice and waits for a confirmed button press. Every other action
of the controller — test suite editing, test case editing, bulk set, deletes, uploads — is untouched.


Two traps cost real time here and are worth writing down:

1. `http_build_query()` encodes an array as `tcase_ids[0]`, `tcase_ids[1]` — **not** `tcase_ids[]`.
   The first `qsAll()` used the regex character class `'[?&]tcase_ids[]='`, which silently never
   matched and dropped the whole selection. Parsing is now done with `URLSearchParams`, with the
   `[n]` suffix normalized away.
2. The shim cannot live inside `init_args()`: it needs the ids that function resolves, but `$action`
   is only known after the submit-button loop back in main scope. It sits in main scope right after
   `$args->action = $action;`.

## 7. Traps in the 1.9.20 class API

Every one of these produced a **blank-bodied HTTP 500** first:

| Trap | Correct call |
|---|---|
| `getDBTables()` is **not** a global function | `tlObjectWithDB::getDBTables('nodes_hierarchy')` |
| `config_get('testproject_options')` returns the config **key name as a string** — property access on it fatals | `testproject::getOptions($tproject_id)` |
| `testcase::get_by_id($id, $version_id, $filters, $options)` takes **no `$db`** (the class already holds it) | `$tcaseMgr->get_by_id($tcid, testcase::LATEST_VERSION)` |
| `get_by_id()` rows use `id` (the tcversion id), `importance` (**not** `priority`), `testsuite_id` (**not** `parent_id`), `is_open` (**not** `open`) | the wrong key returns empty/0 **silently** instead of failing |
| `fetchFirstRowSingleColumn($sql, $column)` needs its column argument | `fetchFirstRowSingleColumn($sql, 'node_type_id')` |
| there is no `tlLog()` function in 1.9.20, and no locale key for "N test cases moved" | debug logging removed; the screen localizes the result itself (`tmvc.moveDone`) |
| the bundled `gui/templates/dashio/lib/bootstrap` is **Bootstrap 3.4.1**, not 5 | `$('#modal').modal('show')`, not `new bootstrap.Modal()` |

## 8. Access denied and error states


A user without `mgt_modify_tc` on the owning project gets the denied box, an empty table and a
fully locked screen — the BFF refuses before anything is read. Unknown suite, missing suite id,
foreign test case, cross-project destination and same-target all surface as localized errors, never
as a raw slug.

## 9. Tests

`tmp/TLU_Test_Cases.md` → **Suite 1724, 45/45 PASS** (BFF contract 17, screen 18, legacy routing 6,
final a11y pass 1, Event Viewer / syntax / i18n 3). Four defects were found by the mandatory browser
pass and fixed in `1ffd36bf6`: the Bootstrap 5 modal API on a Bootstrap 3 library, the success banner
being erased by its own reload, the legacy deep-link selection loss described above, and - in the
final a11y pass - the modal close logging `Blocked aria-hidden ... descendant retained focus`
(Bootstrap 3 sets `aria-hidden` while focus is still inside; now blurred in `hide.bs.modal` and
restored on `hidden.bs.modal`).

Event Viewer (rule 12): the only new `events` rows are `log_level=16` (AUDIT) LOGIN/CREATE/DELETE
written by the fixture and the logins. The sole `log_level=2` warnings name `Command line code` —
they come from the throwaway probe scripts used to dump the DB state, not from the screen or the BFF.
**0 new Error/Warning rows from the application.**

## 10. Commits

| Commit | Content |
|---|---|
| `0ae898f24` | `feat(tcmovecopy)` — the BFF |
| `b8067326d` | `feat(tcmovecopy)` — screen, 10 i18n bundles, `$actions`, legacy shim |
| `1ffd36bf6` | `fix(tcmovecopy)` — the three browser-test defects |

## 11. Not in scope

- `testcases_table_view` / `doBulkSet` return HTTP 500 on this box **before and after** this change
  (verified with `git stash`) — pre-existing, belongs to the bulk-update feature, not to this screen.
- No `events` audit row is written for a move/copy, matching `api/tcbulkop`, the comparable modern
  screen. Adding one would need new `$TLS_…` keys in all 19 server-side `strings.txt` files.
