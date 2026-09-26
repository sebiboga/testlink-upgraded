# Feature — Issue #1297: Requirement Viewer per-version attachments

## What was missing

The modern **Requirement Viewer** (`gui/templates/requirements/reqView.html`)
rendered Overview, Scope, Custom Fields, Linked Test Cases, Monitors and
Relations — but **no attachments at all**, although the legacy page had one
attachment section per requirement version. `grep -c attach reqView.html` → `0`,
and `GET /api/requirements/index.php/view` returned no `attachments` key at all.

## Legacy behavior captured

| Legacy place | Behavior |
|---|---|
| `lib/requirements/reqView.php:198-209` | one attachment set per version id (`$gui->attachments[$version_id]`) |
| `lib/functions/requirement_mgr.class.php:68` | attachments are bound to the requirement **VERSION** (`attachmentTableName = 'req_versions'`), not to the requirement doc |
| `lib/functions/requirement_mgr.class.php:3498-3525` | upload → `reqEdit.php?doAction=fileUpload&req_version_id=`, delete → `?doAction=deleteFile&file_id=` |
| `gui/templates/dashio/requirements/reqViewVersions.tpl:440-445` | *Attachment for LATEST Version* include |
| `gui/templates/dashio/requirements/reqViewVersions.tpl:509-513` | the same include inside the per-"Other Versions" loop, `attach_downloadOnly=($frozen_version == "yes")` |
| `gui/templates/dashio/requirements/reqViewVersions.tpl:314-317` | `$downloadOnly = true` when the user lacks `req_mgmt` **or** the version is frozen |
| `gui/templates/dashio/include/attachments.inc.tpl:78` | per-file download link → `lib/attachments/attachmentdownload.php?id=` |
| `.../attachments.inc.tpl:104-110` | per-file delete icon **only** when `!$attach_downloadOnly` |
| `.../attachments.inc.tpl:121-177` | multipart upload form (file + title, `checkFileSize()` against `TL_REPOSITORY_MAXFILESIZE`) only when the version is editable |
| `.../attachments.inc.tpl:52-56` | "attachment feature disabled" notice when the feature is off |

## What was implemented

### 1. BFF — `api/requirements/index.php` (`GET /view`)

New keys, computed for the **selected** version
(`$curVersionId`):

* `attachments` — file rows (`id`, `title`, `file_name`, `file_type`,
  `file_size`, `date_added`, `download_url`) read with
  `fk_table = 'req_versions' AND fk_id = <version id>`;
* `attachments_enabled` — `config_get('attachments')->enabled`;
* `attachments_max_size` — `TL_REPOSITORY_MAXFILESIZE`;
* `attachments_download_only` — `!mgt_modify_req || !is_open`, i.e. the exact
  legacy `$downloadOnly` expression, which covers both legacy include sites.

### 2. BFF — `api/attachments/index.php`

`req_versions` added to the `$KNOWN_TABLES` allowlist checked by `checkFk()`
(shared by `list` / `upload` / `delete`). Without it every per-version
upload/delete was rejected with `400 Invalid attachment table` (measured — see
the test suite). The existing per-file ownership hardening (an attachment must
belong to the given `fk_table`/`fk_id`) is untouched.

### 3. Front-end — `gui/templates/requirements/reqView.html`

New **Attachments** card (placed between Scope and Linked Test Cases) carrying a
`Version N` chip so the per-version nature stays visible. `renderAttachments()`
mirrors `attachments.inc.tpl`: one row per file (title / `file_name` · size ·
type · date), a **Download** link (`target=_blank`, `click_to_get_attachment`
tooltip), a **delete** button (only when `attachments_download_only === false`),
a read-only note in the same case, the upload row (file + title + Upload +
`Max file size`) for editable versions, the "no attachments" empty state and the
"feature disabled" notice. `uploadAttachment()` re-checks the client-side file
size like legacy `checkFileSize()`; `deleteAttachment()` confirms like legacy
`delete_confirmation()` and re-reads `/view`. Because the version `<select>`
re-reads `/view`, switching versions re-renders **that version's** attachments —
the modern equivalent of the legacy latest + per-other-version blocks.

### 4. i18n

16 new keys (`reqv.attachments`, `reqv.noAttachments`, `reqv.attFeatureDisabled`,
`reqv.attReadonly`, `reqv.attDelete`, `reqv.attDownload`, `reqv.attClickToGet`,
`reqv.attTitle`, `reqv.attUpload`, `reqv.attMaxSize`, `reqv.attNoFile`,
`reqv.attUploadOk`, `reqv.attUploadFail`, `reqv.attDeleteConfirm`,
`reqv.attDeleteOk`, `reqv.attDeleteFail`) added to **all 10** locale bundles
(`en, ro, de, es, fr, it, ja, pt, ru, zh`), each validated with
`python3 -m json.tool`.

## Verification

Test suite: `tmp/TLU_Test_Cases.md`, section *Task — Issue #1297* (15 cases).
All PASS, including: per-version file sets, download-only on the frozen version,
upload through the UI button, delete with confirm, forged `file_id` rejected
with 404, and the EN↔RO locale switch.

Fixture gotcha (documented in the suite): hand-inserted `attachments` rows need
`file_path` = full relative path **including** the file name and
`compression_type = 1`; otherwise `getAttachmentContentFromFS()`
(`tlAttachmentRepository.class.php:390-410`) returns null and the download
answers 404. Real uploads always write `compression_type = 1`.
