# Bug — Issue #1636: dead second import path on `api/platforms/index.php`

**Issue:** [#1636](https://github.com/sebiboga/testlink-upgraded/issues/1636) · **Fix commit:**
`7202a16f6` (branch `fix/issue-1636`) · **Screens affected:** Platform Management
(`gui/templates/platforms/platformsView.html`), Assign Platforms
(`gui/templates/platforms/platformsAssign.html`) — routes untouched; Import Platforms
(`gui/templates/platforms/platformsImport.html`) — the surviving canonical path.

## Symptom

After the Import Platforms modernization (#1632) moved the UI to
`gui/templates/platforms/platformsImport.html` + `api/platformsimport/index.php`, a **second**,
weaker import implementation stayed registered and kept answering: the `POST /import` branch of
`api/platforms/index.php`. Nothing in the repository called it any more, so it was dead weight and
a divergent code path for the same operation — and it was the one with the sharpest edges.

## Measured, pre-fix

| Call | Answer |
|---|---|
| `POST /api/platforms/index.php/import` + valid XML | `200 {"status":"ok","imported":1,"updated":0,"skipped":1}` — it really imported |
| `POST /api/platforms/index.php/import` + malformed XML | `200` + **raw HTML** `Please give this text to your TestLink Administrator<br> - Failed to load XML<br>…` |
| `POST /api/platformsimport/index.php?action=import` + valid XML | `200` full contract: `ok[]`, `ko[]`, `imported_total` |
| `POST /api/platformsimport/index.php?action=import` + malformed XML | `422 {"error_code":"WRONG_FORMAT","xml_errors":[…]}` |

## Root cause

1. #1632 removed the inline import modal from `platformsView.html` and pointed the Import button at
   `platformsImport.html` → `api/platformsimport/index.php`, but **left the old branch** at
   `api/platforms/index.php:384-472`.
2. The router is generic (`api/platforms/index.php:45-49` builds `$segments` from the path and the
   guard is only `$method === 'POST' && $segments[0] === 'import'`), so the branch was *reachable*.
   It was dead in the **product** sense only: **0 callers** repo-wide
   (`platformsView.html` calls `API + '/'` for list/flags/get/PUT/DELETE at lines 197/285/314/350/384;
   `platformsAssign.html:117` is assign-only; the legacy Smarty page posts to its own
   `$SCRIPT_NAME`).
3. The worst symptom: the branch called `@simplexml_load_file_wrapper()` →
   `lib/functions/xml.inc.php:33-41`, which on a parse failure **echoes a localized HTML sentence and
   `die()`s**. The caller's own `WRONG_FORMAT` / 422 answer at `api/platforms/index.php:428-432` is
   therefore unreachable, and the `Content-Type: application/json` header set at line 26 is a lie.
4. `api/platforms/index.php:462-463` discarded `create()`'s return status and unconditionally
   `$imported++`, so a platform that was never written was still reported as imported.

Also worth recording: the URL in the issue body (`?action=import`) never reached the branch at all —
it fell through to the `POST` create branch and answered `400 Invalid test project id`. The live
route was the path-segment form `POST /api/platforms/index.php/import`.

## Fix — delete, don't delegate

`api/platforms/index.php` lost lines 384-472 and the `require_once('xml.inc.php')` that only the
removed branch used (the export branch builds its XML through `ADODB_XML`, verified by R9). The file
header now states the scope split: view/CRUD/flags/assign/export only, import lives in
`api/platformsimport`. Diff: **9 insertions, 91 deletions**, one file.

Deleting was chosen over making the old route a thin delegation because a pass-through would keep a
second public entry point for the operation that exists to have exactly one hardened
implementation, cannot preserve the multipart field rename (`targetFilename` vs `uploadedFile` at
`api/platforms/index.php:400`) without inventing a mapping, and would keep 422-for-`TOO_LARGE` on
the public surface forever. With zero callers and no legacy deep link into the BFF, the removal's
blast radius is zero lines outside this file.

## Post-fix behaviour (measured, 13/13 regression cases PASS)

- `POST /api/platforms/index.php/import` → **`404 {"status":"error","message":"Not found"}`**, the
  JSON fallback the file already ended with (pre-existing, backup line 682 → now 600). No new 404
  branch was introduced, so there is no scope creep. Nothing is imported, and the raw-HTML-on-error
  failure mode is gone with the branch.
- `POST /api/platformsimport/index.php?action=import` → unchanged: 200 with `ok[]`/`ko[]`/`BAD_LINE`
  and `imported_total`; 422 `WRONG_FORMAT` + `xml_errors[]` on malformed XML; 403 `NO_RIGHTS` for a
  `platform_view`-only user (which also writes the legacy `audit_security_user_right_missing` event).
- All other routes of the file unchanged: list, create, update, `PUT /{id}/flags`, delete, `assign`,
  `export`, 401 without a session.
- Browser: Platform Management renders (0 console messages) and the Import screen imports
  `pimp_ui.xml` → `Imported: 1 / Updated: 0 / Skipped: 1 / Platforms now: 2`, with the nameless
  `<platform>` reported as `Skipped (line skipped: no name element)`.
- `events` table: 3 rows, all `log_level=16` (audit INFO) — no new Error/Warning.

## Regression suite

`tmp/TLU_Test_Cases.md` → **Suite 1636**, 12 matrix rows (R1–R12) plus the pre-fix reproduction.

## Screenshots

- `docs/screenshots/issue-1636-platforms-view.png` — Platform Management after the fix
- `docs/screenshots/issue-1636-import-result.png` — the surviving import path reporting
  Imported/Updated/Skipped + the per-line BAD_LINE message
