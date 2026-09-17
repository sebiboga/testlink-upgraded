# Attachment Upload / Download (attachmentUpload) — Modernized Screen

Modernization of the **Attachment Upload / Download** flow
(`lib/attachments/attachmentupload.php` + `attachmentdelete.php` +
`attachmentdownload.php`) — GitHub issue
[#1525](https://github.com/sebiboga/testlink-upgraded/issues/1525).

These were the **last standalone legacy screens still referenced from the modern
UI**. The Smarty popup + download endpoints are replaced by a standalone Dashio
page (`gui/templates/attachments/attachmentUpload.html`) backed by a plain-PHP
REST BFF (`api/attachments/index.php`). The legacy lib files are kept for
external/API callers but are no longer referenced by any modern screen.

**Path:** opened from the attachment link/button of any object (test case spec,
execution, requirement spec, …) that carries attachments
**URL:** `gui/templates/attachments/attachmentUpload.html?table=<fk_table>&id=<fk_id>&name=<object name>`
**BFF API:** `api/attachments/index.php` (actions `list` | `upload` | `delete` | `download`)
**Right:** legacy gate = attachment feature enabled (`config_get('attachments')->enabled`), mirrored by the BFF.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy defects fixed](#3-legacy-defects-fixed)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Object header | popup title + `fk_id` | Dashio title bar: object name in brackets + **Refresh** toolbar button |
| Max size | `TL_REPOSITORY_MAXFILESIZE` server-side only | shown live above the table (`Max file size: 1.0 MB`) and enforced by the BFF |
| List | Smarty table | DataTable with attachment name (download link), file name + human size, per-row **Delete** |
| Upload | `doAction=upload0` single file | multi-file picker + optional title, **Upload** submits multipart to the BFF |
| Download | `attachmentdownload.php?id=..` | BFF `action=download` streams the stored bytes (inline), honoring the legacy XSS-safe SVG handling |
| Delete | `attachmentdelete.php` + confirm | Bootstrap confirm modal (`Delete this attachment?`); BFF verifies the attachment belongs to the requested `fk_table`+`fk_id` |
| Empty state | — | localized `No attachments` notice |
| Locale switcher | none (legacy server locale) | client-side `TLi18n` switcher (all 10 bundles) |

Modern download links switched from `lib/attachments/attachmentdownload.php` to
`/api/attachments/index.php?action=download&id=` in:
`api/execute`, `api/execsetresults`, `api/reqspec`, `api/projectinfo`,
`gui/templates/testcases/testSpec.html` (`attachmentDownloadUrl()`) and
`gui/templates/execute/execTest.html`. `openFileUploadWindow()` in
`gui/javascript/testlink_library.js` now opens the modern screen.

## 2. REST API Reference

Base: `POST` / `GET` http://…/api/attachments/index.php, session auth, JSON I/O.

| Route | Params | Right / gate | Response |
|---|---|---|---|
| `action=list` | `table` (fk_table), `id` (fk_id) | session + `attachments->enabled` | `{status, attachments:[{id,title,fk_table,fk_id,path,file_name,file_type,file_size,max_size_text,download_url}], max_size}` |
| `action=upload` | multipart `uploadedFile[]`, `table`, `id`, optional `title` | session + CSRF proof + `attachments->enabled` | `{status, uploaded:n, errors:[]}` |
| `action=delete` | `table`, `id`, `file_id` | session + CSRF proof + `attachments->enabled` | `{status, message, deleted_id}` |
| `action=download` | `id` (attachment id) | session | streamed file bytes or error JSON |

Validation: `table` must be in the allowed fk_tables list (`executions`, `tcsteps`,
`tcversions`, `testcases`, `requirement_specs`, `req_specs`, `requirements`,
`testprojects`, `testsuites`, `nodes_hierarchy`, `testplans`); `id` positive int.
Errors: 401 anonymous, 403 no same-origin proof, 400 bad table/id, 404 attachment
not found for the object, 405 unknown action / bad HTTP method, 413 upload too
large, 500 internal.

## 3. Legacy defects fixed

- The legacy upload popup was the **last Smarty screen still reachable from the
  modern testSpec/execTest UIs** — replaced wholesale.
- Delete is now hardened: the legacy `attachmentdelete.php` only checked `id`;
  the BFF requires the attachment to belong to the requested `fk_table`/`fk_id`
  (forged delete → 404), matching the modern multi-object architecture.

## 4. i18n Keys

`att.*` (13 keys: att.title, att.titleChoose removed→`att.name`+`att.optional`,
att.maxFileSize, att.noAttachments, att.upload, att.uploading, att.uploaded,
att.file, att.size, att.delete, att.deleteConfirm, att.deleted, att.error,
att.invalidFile) in all 10 locale bundles (`en/ro/de/es/fr/it/ja/pt/ru/zh`).

## 5. Security

- Session auth required on every action (401 anonymous — verified raw-curl).
- CSRF same-origin proof (`X-Requested-With: XMLHttpRequest`) — 403 without it.
- fk_table whitelist prevents table-name injection; id validated positive int.
- Download replicates the legacy XSS-safe SVG handling (`text/plain` for `image/svg+xml`).
- Extension allow-list checked server-side before storing.

## 6. Testing

Browser suite 1525 **21/21 PASS** appended to `tmp/TLU_Test_Cases.md`:
BFF matrix (list/upload/download/delete happy path, forged delete 404, bogus table
400, id=0 400, `.php` extension rejected, CSRF 403, anonymous 401, binary PNG
round-trip `cmp`-identical + `Content-Length`), browser flows (upload + title,
download link, confirm-modal delete, empty state, `Max file size 1.0 MB`), `php -l`
clean on the BFF + 4 patched emitters, JSON-valid 10 bundles, no modern reference
to `lib/attachments/attachmentdownload.php`, clean console, Event Viewer clean
(only pre-existing AUDIT/INFO rows).