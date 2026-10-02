# Task — Issue #1045: Requirements section of the Test Case Viewer (rights check + version links)

## What was missing

TestLink 1.9.20's Test Case Viewer rendered its **Requirements** block per version panel
(`gui/templates/dashio/testcases/tcView_viewer.tpl:513-556`) when

```smarty
{if $gui->requirementsEnabled == TRUE &&
  ($gui->view_req_rights == "yes" || $gui->req_tcase_link_management) }
```

`$gui->view_req_rights` **is** the `mgt_view_req` right
(`lib/functions/testcase.class.php:7387`), so the gate is an **OR**: a user whose role is
"may link requirements to test cases" (`req_tcase_link_management`) sees the list of linked
requirements too, even without `mgt_view_req`.

Inside the block legacy rendered

* a bold **"Requirements"** label linking to `$hrefReqSpecMgmt`
  (`lib/general/frmWorkArea.php?feature=reqSpecMgmt`, tpl:31-32),
* a **link/unlock icon** on the label calling `openReqWindow(tcase_id,'a')`, gated on the
  per-version `$reqLinkingEnabled` (tpl:516-524) **and** `isTheLatest` (tpl:530),
* one line per linked requirement, `spec_title : doc_id (Version N) : title`, each preceded
  by a clickable icon calling
  `openLinkedReqVersionWindow(req_id, req_version_id, tproject_id)` (tpl:544-547) →
  `lib/requirements/reqView.php?showReqSpecTitle=1&requirement_id=…&req_version_id=…`.

The modern viewer had three defects:

| # | defect | consequence |
|---|---|---|
| 1 | `api/testcases/index.php` ANDed `mgt_view_req` only | a linker-only role received `requirements: {}` → **the whole section vanished** |
| 2 | the coverage projection had no `RC.req_version_id` | the per-row "open the linked **version**" affordance could not exist |
| 3 | no `reqLinkingEnabled` in the payload | no server-side mirror of the link/unlock icon gate; the toolbar button re-derived a weaker one from the bare right |

## What changed

### BFF — `api/testcases/index.php`

* the coverage gate is now the legacy **OR**
  (`requirementsEnabled && (mgt_view_req || req_tcase_link_management)`);
* `RC.req_version_id` is projected next to the version number and shipped per row;
* new `canLinkReqs()` — port of `$reqLinkingEnabled` (tpl:516-524):
  `req_tcase_link_management` **and** `mgt_modify_tc` (legacy `$edit_enabled` needs
  `$args_can_do->edit == "yes"`, which `getShowViewerActions()` only answers when
  `mgt_modify_tc` is granted), **and** a non-frozen version, **and** on an executed version
  `testcase_cfg->can_edit_executed`;
* new response keys: per-version `reqLinkingEnabled` and `reqSpecMgmtUrl`
  (the modern twin of `$hrefReqSpecMgmt`).

### Screen — `gui/templates/testcases/tcView.html`

* `canSeeRequirements()` — the legacy OR (`tcView.html:1230-1240`);
* `reqSpecMgmtLabelHtml()` — the label anchor to `reqSpecMgmt.html` with tooltip
  "Open Requirement Specification Management";
* `reqOpenIconHtml(rq)` — the per-row pencil to
  `reqView.html?showReqSpecTitle=1&id=<req>&req_version_id=<ver>&tproject_id=<prj>`, the twin
  of `openLinkedReqVersionWindow()`; omitted when the version id is missing (otherwise
  reqView would silently open the *latest* version);
* the **Link / Unlink Requirements** button on the latest version when
  `reqLinkingEnabled`, opening the assign dialog (the twin of `openReqWindow()`);
* the toolbar "Assign Requirements" button now uses the same per-version flag instead of
  `grants.req_tcase_link_management` alone (it previously ignored frozen versions, executed
  versions and `reqLinkingDisabledAfterExec`).

### i18n

`tcview.linkUnlinkReqs`, `tcview.openLinkedReqTitle`, `tcview.reqSpecMgmtTitle` translated in
**all 10** locale bundles (`en, de, fr, es, it, pt, ro, ru, ja, zh`).

## Verification

* `tmp/fixtures_1045.php` — three private projects with identical content, differing only in
  the user's project role: linker (`req_tcase_link_management`, no `mgt_view_req`),
  req-viewer (`mgt_view_req`), negative control (no requirement right at all).
* `tmp/verify_1045.php` — **19/19 PASS** (API matrix, incl. no new `events` Error/Warning row).
* Browser (chrome-devtools MCP) — 12/12 PASS: the linker role now sees the section, the
  label link, the per-row version links and the link/unlock button; the req-viewer sees the
  section but no link/unlock button; the negative control sees no section at all; admin
  unaffected; console clean.
* Test suite: section *Task — Issue #1045* in `tmp/TLU_Test_Cases.md`.

## Note — the per-row link for a linker-only user

Legacy `lib/requirements/reqView.php:261-266` self-checks
`$context->rightsAnd = ["mgt_view_req"]`, so a user without that right gets a refused
requirement window **in legacy too** (modern: "No permission"). The icon is rendered exactly
where legacy rendered it — the target keeps its own rights check.