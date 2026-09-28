# Modernize: Assign Requirements — single test case (`reqTcAssign`)

**Refs #1702** — the per-test-case "Assign Requirements" popup, now a Dashio
popup with a PHP BFF. This is the *other half* of the screen modernized in
#1595 (`reqTcBulkAssign`, the per-suite coverage grid).

## Context

The #1595 run reduced `lib/requirements/reqTcAssign.php` to a session-guarded
302 shim for the **bulk** mode. It left the **testcase** mode of the very same
controller unimplemented — and the shim did not just ignore it, it *misrouted*
it:

```php
// before
$mode = ($_REQUEST['edit'] === 'testcase') ? 'testcase' : 'bulk';
// ... but the redirect below only ever used $mode for a TODO branch, and
// unconditionally answered:
header('Location: .../reqTcBulkAssign.html?tsuite_id=' . $id . ...);
```

The live entry point is `openReqWindow()` in
`gui/javascript/testlink_library.js`, which the Test Specification tree calls
per test case. It builds:

```
lib/requirements/reqTcAssign.php?edit=testcase&showCloseButton=1&callback=<cb>&id=<tcase_id>
```

so a **test case** id landed in the **tsuite_id** slot of the **bulk** popup —
the wrong screen, against a suite that does not exist. The feature was broken
end-to-end, not merely unmodernized.

## What the modern screen does

`gui/templates/requirements/reqTcAssign.html` (BFF `api/reqtcassign/index.php`):

* teal header + dark toolbar (Refresh / Back to test case / Close), TLi18n
  locale switcher;
* context card: test project, test case name, **version number** (links always
  bind to the *latest* test case version, exactly like the legacy grid);
* requirement-spec selector — only the specs of the test project, and the
  selection is per project, kept across popup openings;
* **Assigned requirements** grid — Check / Doc ID / Requirement / Scope /
  Author / Timestamp / Actions, with a check-all + *Unassign* bulk action and a
  per-row unlink;
* **Free requirements** grid — same columns, Check / Doc ID / Requirement /
  Scope, with a check-all + *Assign* bulk action;
* Bootstrap confirm dialog for both destructive/creating operations, an
  ok/error feedback line, and dedicated states for: no specification
  (`rtca.noReqSpecs`), everything already assigned, no free requirements, no
  assignment right, linking disabled after execution, no context, forbidden,
  not found, generic error, and the executed read-only view.

## The BFF

| Route | Behaviour |
|---|---|
| `GET ?action=init&tproject_id=&tcase_id=[&idSRS=]` | context, both grids, `can_write`, `requirements_enabled`, `freeze_link_on_new_version`, `has_req_spec` |
| `POST ?action=assign` | `requirement_mgr::doAssignRequirements()` on the **latest** test case version + `audit_req_assigned_tc` |
| `POST ?action=unassign` | removes exactly the open `req_coverage` rows addressed by `link_id[]` + `audit_req_assignment_removed_tc` |

Guards: session auth + `bffEnforceSession()` on every route, `bffSameOriginGuard()`
on writes, the legacy right `req_tcase_link_management`, the project's
requirements-enabled flag, the `reqLinkingDisabledAfterExec` gate, and a
project-membership proof on **every** id (test case, spec, requirement, link) —
so a foreign project's requirement cannot be linked through this route. Codes:
`401` / `403` / `400` / `404` / `405` / `409`.

## The shim

`lib/requirements/reqTcAssign.php` is now a 302 that resolves the **node type**
and forwards the test project context; `?edit=` is deliberately *not* trusted, so
a stale bookmark that pairs `?edit=testcase` with a **suite** id is repaired
instead of 404-ing.

| Request | Answer |
|---|---|
| `?id=<test case>` | `reqTcAssign.html?tcase_id=…&tproject_id=…&tplan_id=…` |
| `?edit=testcase&id=<test case>` | same (the legacy live URL) |
| `?edit=testcase&id=<test SUITE>` | `reqTcBulkAssign.html?tsuite_id=…` (repaired) |
| `?tsuite_id=…[&idSRS=]` | `reqTcBulkAssign.html` (#1595 behaviour, `idSRS` kept) |
| anonymous | legacy `testlinkInitPage()` script → `login.php?note=expired` |

The node type is resolved with a narrow query **inlined on purpose**:
`lib/functions/tree.class.php` is not on this request path (`config.inc.php` /
`common.php` never require it), so `new tree_manager($db)` fatals with
*Class not found* and the shim answers **500** — that regression was caught by
the test suite and fixed. The shim performs **no rights check** on purpose: it
is a launcher, both popups enforce the rights, and keeping the check out avoids
turning the redirect into a node-type oracle.

## Code-review fixes applied (14)

The review and the Event Viewer between them found defects the first round of
tests missed. The most consequential:

* **Links closed by execution were invisible, un-unassignable *and*
  re-offered as free.** The legacy `requirement_spec_mgr` query omits
  `LINK_TC_REQ_CLOSED_BY_EXEC`, so a requirement linked on an older execution
  neither showed up nor could be removed; both grids now include that status
  and such a link is not removable (`can_be_removed=false`).
* **The executed gate read *any* version.** It now looks at the latest test case
  version only — an execution of an old version no longer locks the screen.
* **A project with no requirement specification claimed "every requirement is
  already assigned".** The legacy `warning_req_tc_assignment_impossible` state
  is now rendered (`rtca.noReqSpecs`).
* `requirementsEnabled` is enforced server-side, the audit translation is called
  with the right arguments, a free-grid `doc_id` no longer leaks a version
  suffix, `bffEnforceSession()` closes an anonymous-session hole on the write
  routes, a dead version query and a stray `ob_start()` are gone, and on the
  client the dead `FREEZE_LINK`, the unreachable `showWarn()` error branch, the
  unused `t()` parameter, the `innerHTML` modal labels (`text()` instead) and a
  check-all that ignored disabled boxes are all cleaned up.

## Testing

Suites **1702** and **1702b** in `tmp/TLU_Test_Cases.md`: 22 + 11 = **33/33
PASS**, plus 20 browser cases — grids, bulk assign/unassign with confirm *and*
cancel, per-row unlink, spec isolation, the executed read-only view, the guest
role without the right, EN↔RO, the six error codes, all seven shim paths and a
full assign → unassign → re-assign round trip that restores the fixture
baseline. Console clean on every state; the Event Viewer gained **0** ERROR /
WARNING rows.

Bug found while testing, filed as **#1705**:
`requirement_spec_mgr::getReqsOnSpecNotLinkedToLatestTCV()` returns the
inverted set, so a legacy caller would read linked requirements as free (with an
empty title). The BFF does not use it.

## Screenshots

![Assigned and free requirement grids](issue-1702-reqtcassign-grid.png)

![The assign confirmation dialog](issue-1702-reqtcassign-confirm.png)

![Executed test case: linking disabled, screen read-only](issue-1702-reqtcassign-executed.png)

i18n: `rtca.*` (43) + `footers.reqTcAssign` in all 10 locale bundles.
