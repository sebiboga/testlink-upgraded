# Task 913 — Attachment upload/delete in Test Specification editor

**Issue:** [#913](https://github.com/sebiboga/testlink-upgraded/issues/913)
**Status:** IMPLEMENTED (2026-09-15)

## The gap

The legacy TC editor (`lib/testcases/tcEdit.php`) provides full attachment
management: upload via `fileUploadManagement($db, $tcversion_id, $title, 'tcversions')`
and delete via `deleteAttachment($db, $file_id, false)`, gated by the
`mgt_modify_tc` permission.

The modern `testSpec.html` editor had no upload or delete controls. The
`api/testcases/index.php` BFF returned no attachment data. The only place
attachments could be viewed was the legacy `tcView.html` (read-only) and
`attachmentdownload.php`.

## Legacy source of truth

- `lib/testcases/tcEdit.php:122-133` — `doAction=fileUpload` calls
  `fileUploadManagement($db, $tcversion_id, $fileTitle, $tcaseMgr->getAttachmentTableName())`;
  `doAction=deleteFile` calls `deleteAttachment($db, $file_id, false)`.
- `lib/functions/attachments.inc.php:132-157` — `fileUploadManagement()`:
  reads `$_FILES['uploadedFile']`, passes to
  `tlAttachmentRepository::insertAttachment($fkid, $table, $title, $fInfo)`.
- `lib/functions/tlAttachmentRepository.class.php:111-190` —
  `insertAttachment()`: validates filename against `allowed_filenames_regexp`
  (`/^[a-zA-Z0-9_-]{1,20}\.[a-zA-Z0-9]{1,10}$/`), checks extension against
  `allowed_files` (doc,xls,gif,png,...), stores file in `upload_area` (FS
  repository), writes `attachments` table row with `fk_table='tcversions'`.
- `lib/functions/attachments.inc.php:159-178` — `deleteAttachment()`:
  reads file info, removes from repository and `attachments` table.
- `lib/functions/testcase.class.php:123` — testcase constructor sets
  `$attachmentTableName = 'tcversions'`.
- `lib/attachments/attachmentdownload.php` — serves file content by
  `attachments.id` (GET param); GUI mode requires session.

## Modern implementation (port)

**Backend `api/testcases/index.php`**

- `get` action (view/edit): returns `attachments[]` array — each entry contains
  `id`, `title`, `file_name`, `file_size`, `file_type`, `date_added`. Fetched
  via `getAttachmentInfosFrom($tcaseMgr, $tcversionId, false)` for the latest
  tcversion.
- `upload_attachment` POST action: multipart/form-data (not JSON). Reads
  `$_POST['tcase_id']`, `$_POST['tcversion_id']`, `$_POST['fileTitle']`,
  `$_FILES['uploadedFile']`. Gate: `mgt_modify_tc` via `$checkWrite()`.
  BFF hardening: owning project resolved from `tcase_id`; attachment stored via
  `fileUploadManagement($db, $tcversionId, $title, 'tcversions')`. Returns
  refreshed `attachments[]` array on success.
- `delete_attachment` POST action: multipart/form-data. Reads `$_POST['file_id']`,
  `$_POST['tcase_id']`, `$_POST['tcversion_id']`. Gate: `mgt_modify_tc` via
  `$checkWrite()`. BFF hardening: `attachments` row must have `fk_id = tcversion_id`
  AND `fk_table = 'tcversions'` (forged `file_id` guard); returns 404 otherwise.
  Calls `deleteAttachment($db, $fileId, false)`. Returns refreshed `attachments[]`.
- Upload/delete handlers placed before `$body = getJsonBody()` since they use
  `$_FILES`/`$_POST` (multipart), not `php://input`.
- `$checkWrite` closure moved to top of POST block (before attachment handlers)
  so it is available when upload/delete actions run.
- Header comment updated: no longer claims "read-only".

**Frontend `gui/templates/testcases/testSpec.html`**

- `attachmentsEditorHtml(data)` — block shown below custom fields in the edit
  form, containing:
  - `#attRows` div populated by `attachmentsListHtml(data.attachments, canDelete)`
  - Upload controls (file input, title input, Upload button) gated by
    `data.tcversion_id > 0 && grants['mgt_modify_tc']` — upload controls
    hidden in create mode (no version yet).
- `attachmentRowHtml(att, canDelete)` — single row: download link
  (`/lib/attachments/attachmentdownload.php?id=N`), file name + size, and
  optional trash button.
- `uploadAttachment()` — builds `FormData` (tcase_id, tcversion_id, fileTitle,
  uploadedFile), calls `apiFormPost('upload_attachment', fd)`. On success:
  refreshes `#attRows`, clears file input + title.
- `deleteAttachment(fileId)` — confirm modal, builds `FormData`, calls
  `apiFormPost('delete_attachment', fd)`. On success: refreshes `#attRows`.
- `refreshAttachmentRows(attachments)` — re-renders `#attRows` via
  `attachmentsListHtml()`.
- `apiFormPost(action, formData)` — new helper: `$.ajax` with
  `processData: false, contentType: false` (multipart). jQuery sends
  `X-Requested-With: XMLHttpRequest` automatically (passes same-origin guard).
- `openEditTc()` now stores `editMode.tcversion_id` and passes
  `attachments` + `tcversion_id` to `formHtml()`.
- `openCreateTc()` passes `tcversion_id: 0, attachments: []` → upload
  controls hidden.
- `renderTcView()` — read-only `ATTACHMENTS` block with download links (no
  delete buttons; matching legacy tcView behavior). Uses `attachmentsListHtml(attList, false)`.
- `formatFileSize(bytes)` — human-readable size (B / KB / MB).
- CSS additions: `.att-block`, `.att-block-title`, `.att-row`, `.att-link`,
  `.att-meta`, `.att-upload`.

**i18n**

- New keys in all 10 locale bundles:
  `tspec.attachments`, `tspec.noAttachments`, `tspec.attachmentTitle`,
  `tspec.uploadAttachment`, `tspec.deleteAttachment`,
  `tspec.confirmDeleteAttachment`, `tspec.errNoFile`, `tspec.errNoVersion`,
  `tspec.errUpload`, `tspec.errDelete`, `tspec.attachUploaded`,
  `tspec.attDeleted`.

## Security guards

- Same-origin CSRF: `bffSameOriginGuard()` in `api/_guard.php` requires
  `X-Requested-With: XMLHttpRequest` (or matching Origin/Referer) for POST;
  jQuery `$.ajax` sends it automatically.
- Session auth: `/api/` requires valid PHP session (401 if missing).
- Permission gate: `$checkWrite()` requires `mgt_modify_tc` on the owning
  project (403 if missing).
- BFF hardening: `attachments.fk_id = tcversion_id AND fk_table = 'tcversions'`
  checked before every delete (404 if mismatch). Prevents forged `file_id`.
- Upload validation: `insertAttachment()` validates filename vs
  `allowed_filenames_regexp` and file extension vs `allowed_files`.

## Verification

### Backend API (curl)

1. `get` action → returns `attachments: []` for TC with no attachments.
2. `upload_attachment` with `$_FILES[uploadedFile]` → `attachments` row
   persisted in DB (`fk_table = 'tcversions'`, `fk_id = 9219`); file stored
   in `upload_area/tcversions/9219/`.
3. Download via `attachmentdownload.php?id=N` → returns file content.
4. `delete_attachment` → `attachments` row deleted; file removed from FS.
5. Forged `file_id` with wrong `tcversion_id` → 404.
6. Forged `file_id` with nonexistent id → 404.
7. Missing `X-Requested-With` header → 403.
8. No session → 401.

### Frontend browser

1. **Edit mode (tcversion_id present):**
   - ATTACHMENTS block shows "No attachments" with upload controls.
   - Upload `ui_att.txt` with title "UI spec notes" → row appears with
     download link (`attachmentdownload.php?id=N`), file name, size, and
     trash button. Toast "Attachment uploaded" shown.
   - Click trash → confirm modal "Delete this attachment?" → confirm → row
     disappears; "No attachments" shown. Toast "Attachment deleted".
2. **Create mode (no tcversion):**
   - ATTACHMENTS block shows "No attachments" without upload controls
     (upload requires saved version).
3. **Read-only view (`renderTcView`):**
   - After uploading in edit mode and canceling, re-select TC → ATTACHMENTS
     block shows with download link, file name and size. No delete button
     (read-only).
4. **Console:** Zero errors; only pre-existing a11y hint on step textareas.

## Files changed

- `api/testcases/index.php`
- `gui/templates/testcases/testSpec.html`
- `gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`
- `CHANGELOG`
- `docs/Task-Issue-913-Attachment-Upload-Delete.md`

## Merge note (2026-09-15)

Local commit `492b8280c` was merged with `origin/sebiboga` (`8038f9670`).
Remote work integrated alongside: #1507 BFF API/template restores, #1380
reqEdit unsaved-changes guard, #1382/#1379 reqEdit helpers, #908 status
field, #911 estimated duration, #912 assign-requirements.

Conflict resolution (all merged without regression):
- `CHANGELOG`: kept our #906/#907/#913 Test Spec sub-bullets.
- 10 i18n bundles: union merge (remote keys + our 12 `tspec.att*` keys).
- `testSpec.html`: our version (already a superset: step editor + exec
  types, CF design-time editing, attachments) + ported the remote's
  **assign-requirements** feature (arq modal, arq* API helpers, CSS,
  renderTcView button gated on `reqEnabled` + `req_tcase_link_management`).
- `tmp/TLU_Test_Cases.md`: kept our #913 section + remote's 68 sections.

Runtime re-verified after merge: spec editor loads with Attachments upload
controls + CF typed inputs + step editor intact, assign-requirements modal
present in DOM, no JS errors.
