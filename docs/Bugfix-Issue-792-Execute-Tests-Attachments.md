# Bugfix: Issue #792 — Execute Tests screen missing attachment upload/list + "Copy attachments from latest execution" (regression)

## Summary

The modernized Execute Tests screen (`gui/templates/execute/execTest.html` +
`api/execute/index.php`) rendered the execution form (status buttons, notes,
steps, save) but had **no attachment functionality**: you could not attach a
file to a new execution, could not see the attachments of the latest
execution on a context, and there was no "Copy attachments from latest
execution" checkbox. The legacy screen (`inc_exec_img_controls.tpl`,
`inc_exec_show_tc_exec.tpl`) provides all three. This was a regression vs.
1.9.20.

## Root Cause

1. **Frontend had no attachment UI.** `renderForm()` in `execTest.html`
   stopped at notes/steps/save — the legacy upload control
   (`attachments.inc.tpl` at `inc_exec_img_controls.tpl:132`), the per-exec
   attachment list, and the copy checkbox (`inc_exec_img_controls.tpl:68-73`)
   were never ported.
2. **The BFF `save` dropped any uploaded file.** `api/execute/index.php`
   `?action=save` read only an `application/json` body
   (`json_decode(file_get_contents('php://input'))`), so a multipart file
   upload was never inspected. There was no execution-level
   `insertAttachment(..., DB_TABLE_PREFIX.'executions', ...)` call and no
   copy-from-latest logic.
3. **The copy-from-latest behavior did not exist in the BFF at all.** Legacy
   `execSetResults.php:168-205` copies the latest execution's attachments
   (execution level + per-step, keyed by `step_number`) onto the new run
   before/at save; the modernized screen had no equivalent.

## Fix

**Backend — `api/execute/index.php`:**
- New `execTestPayload()` helper reads the request body supporting both
  `application/json` and `multipart/form-data`; in multipart mode it decodes
  the JSON-encoded `steps_json` field back into `steps`.
- `?action=save`:
  - parses `copy_att_from_lexec` (accepts `1/true/on/yes`);
  - captures `testcase::getLatestExecIDInContext($tcversionId, $ctx)` with
    `ctx = {testplan_id, platform_id (0 when plan has none), build_id}`
    **BEFORE** `write_execution()` (after writing, the new run becomes the
    latest, so the source must be captured first);
  - after the write, when copy was requested, copies execution-level
    attachments via `tlAttachmentRepository::copyAttachments(...,
    'executions')` and per-step attachments matched by `step_number`
    (identical SQL to the legacy loop);
  - uploads execution-level attachments from the dedicated
    `$_FILES['exec_attachments']` field to
    `insertAttachment(..., DB_TABLE_PREFIX.'executions', ...)`; the response
    now carries `attachments_uploaded`.
- `?action=init` exposes `new_exec_latest` (from
  `$tlCfg->exec_cfg->exec_mode->new_exec == 'latest'`) so the frontend can
  gate the copy checkbox exactly like legacy.
- `?action=tcDetails` prior-execution object now includes an `attachments[]`
  list (`{id,title,file_name,file_size,download_url}`) so the form can show
  what a copy would bring over, with a root-absolute
  `/lib/attachments/attachmentdownload.php?id=N` download link.

**Frontend — `gui/templates/execute/execTest.html`:**
- New `renderAttachmentsBlock()` below the notes field: prior-execution
  attachment list (name + size + download link), the "Copy attachments from
  latest execution" checkbox (only when `new_exec_latest` AND a prior
  execution exists AND not read-only), and a multiple file-upload input.
  No UI change when the panel is in read-only mode (upload hidden).
- `saveExecution()` switched from a JSON body to `FormData`
  (`processData:false, contentType:false`), appending `steps_json`,
  `copy_att_from_lexec` (only when checked) and each selected file as
  `exec_attachments[]`.
- New i18n keys (`exe.attachments`, `exe.attachmentsUpload`,
  `exe.attachmentsTitle`, `exe.copyAttFromLatest`, `exe.noAttachments`,
  `exe.priorExecution`, `exe.download`) added to all 10 locale bundles.

## Why This Approach

- **Dedicated `exec_attachments[]` field, NOT `uploadedFile`.**
  `write_execution()` (exec.inc.php:206-216) treats `uploadedFile` as its
  own nested per-execution upload and `addAttachmentsToExec()` contains a
  `var_dump($_FILES['uploadedFile']['name'][0])` (exec.inc.php:1041-1043)
  that would leak `<pre>string(1) "t"</pre>` into the JSON save response
  (observed). The BFF owns the upload via the dedicated field, keeping the
  response clean and the sink identical to legacy.
- **`[]` suffix is required.** PHP builds `$_FILES['exec_attachments']['name']`
  as an array only when the multipart field is named `exec_attachments[]`;
  a flat field yields a string, so `is_array()` was false (first two attempts
  inserted nothing). The frontend therefore appends `exec_attachments[]`.
- **Capture latest-exec before writing.** Matches the legacy ordering
  (`execSetResults.php:1733` captures `$eid` before the copy at 168-205); the
  `latest_exec_by_context` view groups by
  `(tcversion_id, testplan_id, build_id, platform_id)` — all four supplied.
- **Copy checkbox gated on `new_exec=='latest'`** mirrors
  `inc_exec_img_controls.tpl:68-73`; under the default `'clean'` mode the
  checkbox is hidden but the direct upload and the prior-execution list still
  work.
- **Alternatives rejected:** reusing `name="uploadedFile"` (would hit the
  legacy var_dump / nested-array mismatch); naming the field
  `exec_attachments` without `[]` (breaks multi-file array).

## Files Changed

- `api/execute/index.php` — `execTestPayload()`, `save` (copy-from-latest +
  exec-level upload + `attachments_uploaded`), `init` (`new_exec_latest`),
  `tcDetails` (`prior_execution.attachments[]`).
- `gui/templates/execute/execTest.html` — `renderAttachmentsBlock()`,
  FormData `saveExecution()`, `newExecLatestFlag()`, `fmtBytes()`.
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — 7 new `exe.*`
  keys per bundle.

## Commit

- `621fb9d91` — `fix(execute): attachment upload/list + copy-from-latest on Execute Tests (Refs #792)`

## Regression Matrix

| # | Case | Result |
|---|------|--------|
| 1 | Direct upload on save creates an execution-level `attachments` row (`fk_table=executions`) and returns `attachments_uploaded:1` | PASS |
| 2 | Download link `/lib/attachments/attachmentdownload.php?id=N` returns HTTP 200 | PASS |
| 3 | With `new_exec='latest'`, copy checkbox visible; checking + save copies the latest execution's attachments (execution + step) onto the new run (`copy_att_from_lexec=1`) | PASS |
| 4 | Copy checkbox hidden under default `new_exec='clean'` (matches legacy) | PASS |
| 5 | Prior-execution attachment list renders (name + size + working download link) | PASS |
| 6 | No var_dump pollution in the save JSON response | PASS |
| 7 | Read-only mode hides upload + copy checkbox | PASS (code review guard) |
| 8 | All 10 i18n bundles valid JSON | PASS |
| 9 | Event Viewer `events` log_level 3/4 → 0 new rows | PASS |

Verified 2026-08-31, headless Chrome http://localhost:8082, admin/admin,
plan QA Test Plan (id 2) / project QA Project (id 1) / test case QA-1 Login
Test (tcversion 4) / build Build 1 (id 1); attachment fixture
`/tmp/opencode/test_attach.csv` (24 B, `.csv` allowed extension).
