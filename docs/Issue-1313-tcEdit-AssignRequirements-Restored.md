# Issue 1313 — tcEdit: "Assign Requirements" link restored

The modern Test Case Editor (`gui/templates/testcases/tcEdit.html`) regains the
legacy **Assign Requirements** affordance that was dropped during
modernization: nothing on the editor let a user link requirements to the test
case being edited.

## Legacy behaviour (1.9.20 — `gui/templates/dashio/testcases/tcEditViewer.tpl:88-93`)

```smarty
{if $gui->opt_requirements==TRUE && $gui->grants->req_tcase_link_management=='yes' && isset($gui->tc.testcase_id)}
  <br /><div><a href="javascript:openReqWindow({$gui->tc.testcase_id})">{$labels.assign_requirements}</a></div>
{/if}
```

- `opt_requirements` — project option `requirementsEnabled` (`tcEdit.php:406-412`);
- `req_tcase_link_management` — right 28 (`tcEdit.php:654-662`);
- the link opens a popup `lib/requirements/reqTcAssign.php?id=<tcase_id>&edit=testcase`
  (testcase mode) that lists Assigned + Free requirements per requirement spec and
  assigns/unassigns via POST (`assign_to_tcase`, `delReqVersionTCVersionLinkByID`);
  the whole popup feature is gated on right 28 (`reqTcAssign.php:402-407`).

## Modern re-implementation

- **BFF** `api/testcasesedit/index.php` `buildEditPayload()`:
  - reads `req_tcase_link_management` right via `$user->hasRight($db, 'req_tcase_link_management', $tprojId)`;
  - reads `requirementsEnabled` from the project `options` blob
    (`intval($projRow['opt']->requirementsEnabled ?? 0)`), same source as legacy;
  - emits `grants.req_tcase_link_management` and top-level `requirements_enabled`.
- **HTML** `gui/templates/testcases/tcEdit.html`:
  - toolbar button `#btnAssignReqs` (fa-bookmark) shown only when
    `ctx.info.requirements_enabled && ctx.info.grants.req_tcase_link_management`
    (exact legacy gate minus the always-true-in-editor `isset(testcase_id)` check);
  - `#assignReqsModal` — same Free/Assigned dual-select layout as the
    already-modernized `tcView.html:129-162` / `testSpec.html`;
  - `arqApiGet/arqApiPost/arqOptLabel/openAssignReqs/arqLoadReqs/arqDoAssign/
    arqDoUnassign` — same client logic as `tcView.html:547-646`, hitting the
    shared `/api/requirements` router: `GET /assign-reqspecs`,
    `GET /assign-reqs?req_spec_id=&tcase_id=`, `POST /assign-reqs {tcase_id, req_ids[]}`,
    `POST /unassign-reqs {link_ids[]}`. Those endpoints already enforce right 28
    (`arNeedManageRight`), so the client gate is a UX nicety and the security
    boundary is unchanged.
- **i18n** — `tcedit.assignRequirements` added to all 10 locale bundles (values
  mirroring the existing `tcview.assignRequirements` translations); the modal
  reuses `reqAssign.*` + `tspec.cancel` + `rpt.close` (already present).

## Verification (browser, fixture TCEDDemo)

- `requirements_enabled=1` + right present → button visible; flipping the project
  option to 0 hides it (`offsetParent === null`).
- Modal lists `[TCED-SPEC1] - TCED-SPEC1`, Free contains `TCED-REQ1 - Req A (v. 1)`.
- Assign → toast "1 requirement(s) assigned.", row moves to Assigned, `req_coverage`
  row created (req_id 3, req_version_id 4, testcase_id 5, tcversion_id 6,
  link_status 1, author 1), INFO audit event `audit_reqv_assigned_tcv`.
- Unassign → toast "1 link(s) removed.", row deleted, INFO audit event
  `audit_reqv_assignment_removed_tcv`.
- Save-flow regression green ("Test case saved.", summary persisted).
- Event Viewer: only log_level-16 audits, zero Error/Warning. Browser console clean.

Screenshot: `docs/screenshots/issue-1313-tcedit-assign-requirements-modal.png`.