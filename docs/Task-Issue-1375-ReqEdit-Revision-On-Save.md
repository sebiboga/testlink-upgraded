# Task 1375 — reqEdit: revision-creation + log-message prompt on save (gap vs legacy)

**Issue:** [#1375](https://github.com/sebiboga/testlink-upgraded/issues/1375) (Refs #798)
**Status:** IMPLEMENTED (2026-09-16) — branch `task/issue-1375`

## The gap

The modern Requirement Editor (`gui/templates/requirements/reqEdit.html`, BFF
`api/reqedit/index.php`) dropped the legacy revision-on-save flow:

- **Legacy** (`lib/requirements/reqCommands.class.php` `doUpdate()`): after
  saving a version, it runs `simpleCompare()` against the previous row. A change
  of status/type/expected_coverage/req_doc_id/title → `diff['force']`, a
  scope-only change → `diff['suggest']`. The TPL (`reqEdit.tpl`) then shows the
  `prompt4revision` / `prompt4log` dialogs asking for a log message; on confirm
  a **new revision** is created for the version via
  `requirement_mgr::create_new_revision()` with that log message
  (`req_versions.revision++`, snapshot row in `req_revisions`).
  An attribute-only change always forced the revision prompt; a scope-only
  change merely suggested it (Cancel = save without revision).
- **Modern** (pre-fix): the save handler called
  `requirement_mgr::update()` with **no** `create_revision`/`log_message`
  parameters, no revision was ever created, no `log_message` recorded and no
  dialog existed.

## Legacy source of truth

- `lib/requirements/reqCommands.class.php:301-377` — `doUpdate()`:
  `simpleCompare()` (force/suggest/nochange), `prompt_for_log`,
  `suggest_revision`, second-pass `update(..., $createRev, $log_message)`.
- `lib/requirements/reqCommands.class.php:804-854` — `simpleCompare()` field
  arrays: `force_revision = {status:reqStatus, type:reqType,
  expected_coverage:expected_coverage, req_doc_id:reqDocId, title:title}`;
  `suggest_revision = {scope:scope}`; precedence force > suggest, then
  `nochange`.
- `lib/functions/requirement_mgr.class.php:430-517` — `update()` params 13/14 =
  `$create_revision`, `$log_msg`; when true it calls `create_new_revision()`.
- `lib/functions/requirement_mgr.class.php:2954-2998` —
  `create_new_revision()`: `req_revisions` snapshot (copy_version_as_revision),
  `req_versions.revision++`, `log_message` stored, `creation_ts=now`,
  `modifier_id=NULL`.
- `gui/templates/dashio/requirements/reqEdit.tpl:115-151` — the
  `prompt4log` (mandatory 'Revision Log' / 'Please add a log message') and
  `prompt4revision` ('Attention!! - Do you want to create a new revision?' /
  'You changed ONLY the scope…') dialogs.
- `locale/en_US/strings.txt:1000-1003` — `revision_log_title`,
  `please_add_revision_log`, `suggest_create_revision_html`,
  `warning_suggest_create_revision` strings.

## Modern implementation (port)

**Backend `api/reqedit/index.php`**
- New `simpleReqCompare($old, $posted)` porting legacy `simpleCompare()`:
  returns `'force'` (status/type/expected_coverage/req_doc_id/title changed),
  `'suggest'` (only scope changed), or `null` (nochange). The legacy
  custom-field comparison is skipped — the modern screen has no CF section.
- The `POST ?action=save` edit-mode branch:
  - loads the pre-save row via `requirement_mgr::get_by_id($reqId, $versionId)`,
  - computes `revision_prompt`,
  - still persists the data on the first round-trip (legacy first-submit
    parity) and returns `revision_prompt` + `revision_created` in the JSON,
  - honours body `create_revision` + `log_message` by forwarding them into
    `update(..., null, null, 0, (bool)$createRevision, $logMessage)` → on
    confirm the manager runs `create_new_revision()` with the log.
  - When the body explicitly carries `create_revision`, `revision_prompt` is
    forced to `null` so the second round-trip never re-prompts (no loop).

**Frontend `gui/templates/requirements/reqEdit.html`**
- Save split into `doSave(payload, onOk)` (the single AJAX call, reused by
  both round-trips) and `showSaved(r)` (info bar: `reqe.revisionCreated` when
  `revision_created=1`, else `reqe.saved`).
- `save()` inspects `r.revision_prompt`:
  - `'suggest'` → `window.prompt(reqe.revScopePrompt)` — OK = second round-trip
    with `create_revision:true, log_message`; **Cancel** = `showSaved` without a
    revision (legacy `save_rev=0` parity: the scope change is still saved).
  - `'force'` → `window.prompt(reqe.revLogPrompt)` — OK = revision + log;
    Cancel = save only (legacy prompt4log dismissal parity).
  - `null` → plain save (create mode, or nothing changed).

**i18n** — new keys in all 10 locale bundles (en/ro/de/es/fr/it/ja/pt/ru/zh):
`reqe.revisionCreated`, `reqe.revScopePrompt`, `reqe.revLogPrompt`. Strings
mirror `revision_log_title` / `please_add_revision_log` /
`warning_suggest_create_revision` / `suggest_create_revision_html`.

## Verification (browser, headless Chrome — admin/admin, fresh DB)

Fixture: test project #1 `Revisions demo` (requirements ON), spec REV-SPEC-1,
requirement RE-1 (id=4, version_id=5, initial revision=1).

| Case | Result |
|---|---|
| Scope-only change → Save ⇒ "Attention!! - Do you want to create a new revision?" prompt | PASS |
| Prompt OK + log → second POST `create_revision:true`; `revision_created:1`; DB `revision=2`, `log_message` stored, 1 row in `req_revisions` | PASS |
| Scope change → prompt → Cancel ⇒ saved, **no** new revision (`revision` unchanged, `req_revisions` count unchanged) | PASS |
| Title (attribute) change → Save ⇒ "Revision Log / Please add a log message" (force) prompt | PASS |
| Force prompt OK + log ⇒ `revision=3`, second snapshot, log recorded | PASS |
| Force prompt Cancel ⇒ title saved, no revision | PASS |
| No-change Save ⇒ no prompt, data re-saved idempotently, no revision | PASS |
| Create mode (`?spec_id=`) ⇒ no prompt, plain create | PASS |
| First POST response carries `revision_prompt`; second carries `revision_prompt:null` (no loop) | PASS |
| Console: 0 errors; Event Viewer/events: only INFO entries, 0 new Error/Warning | PASS |

Screenshots: `docs/screenshots/issue-1375-reqedit-editor-form.png` (editor form
after a confirmed revision), prompt dialogs are native `window.prompt` (not
capturable in headless screenshots).

## Files

- `api/reqedit/index.php` — `simpleReqCompare()` + save-handler revision logic
- `gui/templates/requirements/reqEdit.html` — `doSave`/`showSaved`/`save()`
  prompt flow
- `gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json` — 3 new `reqe.*` keys