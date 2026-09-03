# Bugfix Issue #821 — Attachment whitelist missing jpeg/webp/bmp/heic/heif/pdf/txt

## Problem
`config.inc.php` `$tlCfg->attachments->allowed_files` only listed
`doc,xls,gif,png,jpg,xlsx,csv`. Uploading `.jpeg`, `.png`, `.pdf`, `.txt`,
or any other common format besides the six whitelisted extensions silently
failed — the attachment was rejected without an error message.

## Root cause
The whitelist was hardcoded and too restrictive. Files like `.jpeg` (the most
common camera format) were not accepted even though the application could
serve them correctly.

## Fix
Extended the whitelist in `config.inc.php` (line ~1558) to:
```
doc,xls,gif,png,jpg,jpeg,webp,bmp,heic,heif,xlsx,csv,pdf,txt
```
Commit: `8098ef3f2`. **Note:** `.svg` is intentionally excluded (active-content
XSS vector).

## Verification
- Upload `.jpeg` → `attachments_uploaded:1, attachments_rejected:false`
- Download → HTTP 200, `content-type: image/jpeg`, 1,799,772 bytes
- Rejected format still shows toast (no regression)
