# Test Case Viewer (read-only) — Modernized Screen

**Path:** opened from the modernized *Search Test Cases* results
(click a test case row / edit icon)
**URL:** `gui/templates/testcases/tcView.html?tcase_id=<id>[&tcversion_id=<id>][&tproject_id=<id>]`
(also accepts the legacy `?id=` parameter)
**BFF API:** `api/testcases/index.php` (`action=context`, `action=view`;
session-based auth, JSON I/O)
**Prerequisite:** `mgt_view_tc` on the **owning** test project — a test case
can belong to a project different from the session one, exactly like legacy
`archiveData.php` resolved it.
**Replaces:** legacy read-only viewer
`lib/testcases/archiveData.php?edit=testcase&id=…` rendering
`gui/templates/dashio/testcases/tcView_viewer.tpl` (+ tcView.tpl,
inc_tcbody, steps, keywords/platforms/requirements/relations includes).


---

## What it does

Shows **one test case with ALL its versions**, one card per version
(latest first), mirroring what the 1.9.20 viewer displayed:

* identity header: external id (`DMP-1`), name, owning project, full path;
* per version:
  - badges: **Latest**, Active/Inactive, Open/Frozen, Executed,
    Importance (only when *test priority enabled*), Execution type,
    Status (when ≠ Draft);
  - meta grid: author, created on, last edited by/on, execution type,
    estimated exec. duration (min), parent test suite;
  - Summary and Preconditions as rich HTML blocks;
  - Steps table (# / Step actions / Expected results);
  - Keywords chips assigned to that version;
  - Custom fields with design-time values (per version);
  - Attachments list of that version;
  - Requirements coverage of that version (only when requirements are
    enabled AND the user holds `mgt_view_req`);
* relations with other test cases (bottom card, when any exist);
* warning/info banners: inactive version, frozen version,
  executed-version editing rules (`canEditExecuted` config).

## Actions

| Button | Behavior |
|--------|----------|
| Edit Version | opens legacy editor popup `tcEdit.php?doAction=edit&testcase_id=…&tcversion_id=…&tproject_id=…`; hidden when version is frozen, executed (and config forbids editing executed), or user lacks `mgt_modify_tc` |
| Execution History | legacy popup `/lib/execute/execHistory.php?tcase_id=…&tproject_id=…` |
| Add to Test Plan | legacy `tcAssign2Tplan.php?tcase_id=…&tcversion_id=…&tproject_id=…`; shown only when the project has test plans and user has `mgt_modify_tc` |
| Export | POST form to legacy `tcExport.php?tproject_id=…` with testcase_id/tcversion_id |
| Compare Versions | POST form to legacy `tcCompareVersions.php` (only when > 1 version) |
| Print | `window.print()`; print CSS hides chrome (header/toolbar/footer/actions) |

Destructive operations (delete TC/version, freeze/unfreeze, new version,
move/copy, step editing) intentionally remain in the Test Specification
editor — this screen is the read-only viewer reached from search.

## Errors & warnings


| Situation | Message |
|-----------|---------|
| No id in URL | "No test case id was provided." |
| Unknown test case | "Test case not found." (BFF 404) |
| No `mgt_view_tc` on owning project | "No permission" (BFF 403) |

## i18n

All labels, badges, banners and messages go through the client-side
`TLi18n` module under the `tcview.*` key space — present in ALL locale
bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`). The locale switcher in
the header re-renders the whole screen.


## Permissions

Rights are evaluated against the **owning** test project (not the session
one): `mgt_view_tc` gates the whole view; `mgt_modify_tc`,
`mgt_view_req`, `testcase_freeze`, `keyword_assignment`,
`req_tcase_link_management`, `testplan_planning` are returned by the BFF
and drive button/banner visibility.

## Bugs found & fixed while testing

* [#539](https://github.com/sebiboga/testlink-upgraded/issues/539) —
  `tree::get_path()` returned NULL under PHP 8 (string `'0'` root guard),
  crashing the legacy edit popup; fixed with an `intval()` guard.
* Export form must carry `tproject_id` on the query string (legacy
  requirement) — fixed in the same run.
* BFF: `hasTestPlans` exposed on `view`; latest version computed over ALL
  versions even when the payload is filtered to one requested version.

## Relations (task #1040)

Per-version relations (header + count, **New relation** form, `# / Type | Test Case |
Set by | icon` table, delete confirmation modal, delete/warning icons) — see
[Task-Issue-1040-TcView-Relations](Task-Issue-1040-TcView-Relations).


## Related

* Legacy popup warnings surfaced while testing (pre-existing):
  [#541](https://github.com/sebiboga/testlink-upgraded/issues/541).
* Test suite: `tmp/TLU_Test_Cases.md` § 24 (Suite ID 35).

## Direct link (issue [#1043](https://github.com/sebiboga/testlink-upgraded/issues/1043))

The viewer header now carries the legacy `toggle_direct_link` affordance: a **Direct link**
button (fa-link) next to the other toolbar actions opens a dark permalink bar below the toolbar
showing the permanent URL of the test case and a **Copy** button (toast
`Direct link copied to clipboard`). Clicking the button again hides the bar.

The link is the same one the 1.9.20 header exposed
(`$gui->direct_link`, `tcView.tpl:131,145`), but it now points at the 2.0.1 deep-link gateway
`gui/templates/links/directLink.html?tprojectPrefix=<PFX>&item=testcase&id=<PFX-N>` instead of the
legacy `linkto.php`, so opening it lands in the modern viewer. It is emitted by
`api/testcases/index.php` (`action=view` → `direct_link`). Anonymous visitors are sent to the
login, exactly like the legacy gateway.


## Operations panel (issue [#1044](https://github.com/sebiboga/testlink-upgraded/issues/1044))

The header now carries the legacy **cog** icon (`tcView.tpl:136-141`) again: an **Operations**
button toggles a strip below the toolbar that holds the two legacy `groupBtn` fieldsets of
`tcView_viewer.tpl:143-316` — *Test Case Operations* (New Sibling, Move / Copy, Delete Test Case)
and *Test Case Version Operations* (New Version, New Version From Latest, Freeze/Unfreeze
Version, Delete This Version). All buttons are gated by the server-computed `canDo` map
(`api/testcases/index.php`, a port of `testcase::getShowViewerActions()`), so Panel A disappears
in `editOnExec` mode exactly as in 1.9.20.

Freeze/Unfreeze, Delete This Version and Delete Test Case are new BFF actions
(`api/testcasesedit/index.php` → `set_is_open` / `delete`); the last one reproduces the legacy
`testcase::ALL_VERSIONS` semantics, the executed-test-case and frozen-version refusals, and the
audit events. New Sibling and Move / Copy reuse the modern twins of the legacy `tcEdit.php`
create form and of `tcMove.tpl`.

Details: [Task-Issue-1044-TcView-Operations-Panel](Task-Issue-1044-TcView-Operations-Panel).

