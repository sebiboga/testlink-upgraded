# Requirement Viewer (reqView) — Edit, Delete, Copy (Refs #1300)

The modern Requirement Viewer at
`gui/templates/requirements/reqView.html` now restores the legacy
requirement-management actions that were missing from its toolbar.

## What the screen does

| Action | Modern implementation | Legacy parity |
|---|---|---|
| Edit | Opens `reqEdit.html?id=<id>&tproject_id=<project>&version_id=<version>` for the displayed open version. | Preserves the selected version id; an explicitly selected historical open version is editable. |
| Delete | Confirmation modal followed by `DELETE /api/requirements/index.php/{requirementId}`. | Deletes the complete requirement and all versions, distinct from Delete this version. |
| Copy | Opens `reqCopy.html` with `req_id` and the source specification preselected. | Uses the single-requirement destination-selection flow; the Direct link clipboard Copy action is separate. |

Edit, Delete and Copy are shown only to callers with `req_mgmt`. Full Delete
and Copy are available only for the current version. Edit is hidden for frozen
versions; Delete and Copy are hidden for explicitly selected historical
versions.

## BFF API

- `POST /api/requirements/index.php/copy`
  - Body: `{req_id, container_id, copy_testcase_assignment}`.
  - Validates the authenticated caller, source requirement, target specification
    and project management right.
  - Calls `requirement_mgr::copy_to()` with the legacy test-case-assignment
    option and records the COPY audit event.
- `DELETE /api/requirements/index.php/{requirementId}`
  - Validates the authenticated caller, owning project and `req_mgmt`.
  - Calls the full deletion path with `ALL_VERSIONS`, notifies monitors and
    records the DELETE audit event.
  - This route is separate from `DELETE /api/requirements/index.php/versions/{versionId}`.

Unknown IDs return structured JSON 404 responses. The existing specification
copy route remains available for multi-requirement copies.

## i18n

The following `reqv.*` keys are present in all 10 locale bundles (`en`, `de`,
`es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`, `zh`):
`reqv.deleteConfirm` and `reqv.requirementDeleted`. The existing
`common.edit`, `common.delete` and `rco.copy` keys are reused for action
labels.

## Verification

Suite 1300 in `tmp/TLU_Test_Cases.md` passed 7/7 cases. It covers exact-version
Edit and save, new-version state reset, frozen/historical action gates, copy to
a destination specification, full deletion, unauthenticated and invalid-id
paths, syntax/i18n validation, and Event Viewer cleanliness (`items:[]`,
`total:0` for Error/Warning levels).

The browser test fixture is test project `1` (`I1300`), specification `2`
(`SPEC-1300`), requirement `4` (`REQ-1300`), using the application shell with
`admin/admin` credentials. Direct standalone tabs without that shell session
return 401 as expected.
