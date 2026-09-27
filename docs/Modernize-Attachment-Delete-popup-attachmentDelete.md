# Modernize-Attachment-Delete-popup-attachmentDelete (#1638)

The standalone **Attachment Delete popup** — `lib/attachments/attachmentdelete.php`
(+ `gui/templates/dashio/attachments/attachmentdelete.tpl`) — is modernized as a
standalone Dashio screen backed by a REST BFF. It deletes a single file
attachment from the node it hangs on, and is opened from the Attachments
Upload/Download popup (and from every legacy attachment list) via
`deleteAttachment_onClick()`.

## Background

`docs/MODERNIZATION-STATUS.md` listed Attachments as partially modernized:
**Attachments Upload/Download** was done in #1525, but its **Delete** action
still pointed at the legacy page. That page was not a dialog at all — it
**deleted the attachment while rendering it**: a plain `GET .../attachmentdelete.php?id=N`
unlinked the file, deleted the row and wrote the audit event *before* the browser
ever saw any UI. Any link, bookmark or crawler hit destroyed data, and the
"do you really want to delete?" question only ever existed as a browser confirm
on the *previous* page. The modern screen turns the destructive action into an
explicit, session-guarded **POST**.

## Deliverables

- **Screen:** `gui/templates/attachments/attachmentDelete.html` — Dashio popup:
  teal header ("Attachment Delete") with a close button, a context card
  (object label + owner label + attachment file name / type / size / added
  date), a **Download** link to the stored file, the red
  "this action is irreversible" warning, a Refresh button, the TLi18n locale
  switcher and the Cancel / Delete button pair. Dedicated state boxes for
  *deleted* (success), *not found*, *not allowed*, *nothing selected*,
  *attachments disabled*, *CSRF blocked* and *session expired*.
- **BFF:** `api/attachmentsdelete/index.php` —
  `GET ?action=init&id=N[&table=T][&fk_id=M]` returns the attachment detail plus
  the owner label; `POST ?action=delete {id, table, fk_id}` deletes the row,
  unlinks the file and writes `audit_attachment_deleted` (type
  `attachments`, activity `DELETE`). Session auth via `bffEnforceSession`,
  POST-only for the destructive action, `bffSameOriginGuard` CSRF protection,
  the `cfg->attachments_enabled` gate, positive-integer id validation, owner
  proof from the `attach_tableName` / `attach_fk_id` pair (falling back to the
  legacy `$_SESSION['s_lastAttachmentInfos']` allow-list) → 401 anon / 403
  disabled-or-not-allowed / 400 bad id / 404 unknown / 405 wrong verb.
- **Link switch:** `deleteAttachment_onClick()` in
  `gui/javascript/testlink_library.js` now opens
  `gui/templates/attachments/attachmentDelete.html?id=&table=&fk_id=` (the legacy
  caller signature already passes the owning table and fk id, they were simply
  dropped before). The legacy `jsCallDeleteFile()` / `dlfile` delete-on-GET
  path is no longer reachable from the UI — the four legacy attachment lists
  (`inc_attachments.tpl` / `attachments.inc.tpl`, Dashio + tl-classic) all call
  the modern function.
- **Shim:** `lib/attachments/attachmentdelete.php` is kept as a
  **non-mutating** session-guarded 302 redirect onto the modern screen (anon →
  login) — a legacy deep link now *opens* the confirmation screen instead of
  silently deleting the file.
- **Legacy templates:** the owner context is now forwarded by every attachment
  list — `gui/templates/dashio/include/inc_attachments.tpl`,
  `gui/templates/tl-classic/inc_attachments.tpl` and the per-screen
  `attachments.inc.tpl` wrappers pass `attach_tableName` / `attach_fk_id`
  (for requirement versions `req_versions` + `version_id`), so the BFF can prove
  that the attachment really belongs to the object the user is looking at.
- **i18n:** `adel.*` (29 keys) + `footers.attachmentDelete` in all 10 bundles
  (en/ro/de/es/fr/it/ja/pt/ru/zh), validated with `python3 -m json.tool`.

## Verification

- Browser (admin session): open from the Attachments popup of a test case →
  context card + file detail render; Delete → POST → success state "The
  attachment was deleted."; `?id=999999` → not-found state; a Romanian session
  renders every label in Romanian; a user without the right gets the
  not-allowed state; no `id` → "nothing selected"; `cfg->attachments_enabled = 0`
  → disabled state; expired session → redirect to
  `login.php?note=expired`; the Attachments Upload/Download list itself is
  unchanged.
- BFF curl contract: 401 anonymous GET **and** POST, 405 GET on the delete
  action, 400 missing/zero/negative id, 404 unknown id, 403 CSRF (POST without
  `X-Requested-With`), 403 wrong owner, 200 init, 200 delete.
- Legacy shim: anonymous → login redirect; authenticated → 302 to the modern
  screen; **no row and no file are removed** (the destructive GET is gone).
- Event Viewer clean after the run (only audit/login rows, no Error/Warning);
  console clean; `php -l` clean.
- Harness `tmp/verify_1638.sh` (108 assertions, all green) also asserts
  statically: the delete action is POST-only, the BFF fails closed with
  `NOT_AUTHENTICATED` before any query, `bffEnforceSession` is present, the
  legacy shim contains **no** `deleteAttachment` / `attachmentRepository` call,
  the library forwards `&table=` and `&fk_id=`, all four legacy templates forward
  the owner context, both `reqViewVersions.tpl` variants pass
  `attach_tableName="req_versions"`, both session lists append instead of
  clobbering, `attach_id` is escaped in all four link templates, the
  `attachments->attachmentDelete` action is registered, all 29 `adel.*` keys are
  identical in the 10 bundles and every key the screen uses is defined, and no
  new `log_level IN (1,2)` rows appeared in the Event Viewer.

## Bugs found and fixed

1. **#1639 — the BFF had no session-auth check.** A crafted anonymous
   `POST /api/attachmentsdelete/?action=delete&id=N` deleted an attachment.
   Fixed with `bffEnforceSession` (401 `NOT_AUTHENTICATED`) before any query.
2. **#1640 — the owner context was lost.** `attachments.inc.tpl` built the link
   without `attach_tableName` / `attach_fk_id`, and the per-version loops in
   `lib/functions/testcase.class.php` and `lib/requirements/reqView.php`
   *overwrote* `$_SESSION['s_lastAttachmentInfos']` on every iteration, so the
   session allow-list only ever described the last row. Fixed in the templates
   (`reqViewVersions.tpl` now passes `attach_tableName="req_versions"` +
   the version id) and by appending (`getAttachmentInfosFrom(..., true, 1)`)
   instead of clobbering.
3. **Flat-vs-list result mismatch** — `getAttachmentInfo()` returns a single flat
   row, the code dereferenced `$info[0]`, so *every* delete was refused with
   "not allowed".
4. **Slim-schema crash** — the owner-label enrichment assumed columns
   (`tc_external`, `builds` name) that a reduced TestLink database does not
   have; the column probe now degrades gracefully.
5. **Untranslated button** — the Delete button read the raw key
   `adel.deleteBtn` (no such key existed).
6. **Wrong object type** — node type `4` is a *test version*, but was labelled
   "Build".
7. **Spurious 500** — when the file was already missing on disk the row was
   deleted correctly but the unlink failure was treated as a hard error; a
   missing file is now tolerated (the row is the source of truth).

Fixes landed in `e5c402ad7`, `9dcf71d9c`, `c6bb0d8f9` and the mandatory code
review commit `665128bf2`.

## Open follow-up

**#1647 — object-level authorization.** The delete is protected by
authentication, CSRF, the feature gate and the owner-context proof, but the
BFF does not yet check `mgt_modify_key` on the *owning* test project. A logged-in
user who guesses a valid `(id, table, fk_id)` triple can still delete an
attachment from a project they have no rights on. Filed with repro steps and a
suggested fix; it is deliberately tracked separately from this screen.

## Test cases

Suite **1638 (Screen — Attachment Delete popup)** in `tmp/TLU_Test_Cases.md` —
108/108 PASS, plus 10 browser cases executed manually (list, delete, not found,
Romanian, not allowed, no id, disabled, session expired, legacy shim, upload
list regression).

Screenshots: `docs/screenshots/issue-1638-attachmentDelete-init.png`,
`docs/screenshots/issue-1638-attachmentDelete-deleted.png`,
`docs/screenshots/issue-1638-attachmentDelete-notfound-ro.png`,
`docs/screenshots/issue-1638-attachmentDelete-disabled.png`.

Fixture: `tmp/fixtures_1638.php` (a test project with a test case, a
requirement spec, a test plan and an execution, each with an uploaded
attachment; ids are dynamic and never hardcoded).

## Files

- `api/attachmentsdelete/index.php` — delete BFF (init + delete)
- `gui/templates/attachments/attachmentDelete.html` — standalone Dashio popup
- `lib/attachments/attachmentdelete.php` — session-guarded, non-mutating 302 shim
- `gui/javascript/testlink_library.js` — `deleteAttachment_onClick()`
- `gui/templates/dashio/include/inc_attachments.tpl`,
  `gui/templates/tl-classic/inc_attachments.tpl` — owner-context wrappers
- `gui/templates/dashio/include/attachments.inc.tpl`,
  `gui/templates/tl-classic/attachments.inc.tpl` — per-screen owner context
- `gui/templates/dashio/requirements/reqViewVersions.tpl`,
  `gui/templates/tl-classic/requirements/reqViewVersions.tpl` — `req_versions`
  owner context
- `lib/functions/testcase.class.php`, `lib/requirements/reqView.php` — session
  allow-list is appended, not clobbered
- `gui/templates/i18n/*.json` — localized `adel.*` keys (10 bundles)
- `docs/screenshots/issue-1638-attachmentDelete-*.png` — browser evidence

Refs #1638.
