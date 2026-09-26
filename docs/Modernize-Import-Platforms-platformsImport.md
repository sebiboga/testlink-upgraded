# Modernize-Import-Platforms-platformsImport (#1632)

The legacy **Import Platforms** page was the last fully legacy standalone screen
in the Platforms area. It is now a Dashio HTML/JavaScript screen backed by a
session-authenticated PHP BFF.


## Why

`gui/templates/platforms/platformsView.html` opened the import as an inline modal
wired to `POST /import` on `api/platforms/index.php`, and that route answers
**counts only**. Meanwhile `lib/platforms/platformsImport.php` — the real
standalone page, still reachable by deep link and still a live Smarty render —
printed one line per imported/updated platform and one skipped line per
`<platform>` node without a `<name>`. That per-platform reporting contract was
therefore unreachable from the modern UI, and `lib/functions/common.php` had no
`$actions->platformsImport` (only `platformsExport` got a link switch in #1583).

## Deliverables

- `gui/templates/platforms/platformsImport.html` — project/test-plan/platform
  context, import criteria (XML only), documentation link, size hint, locale
  switcher, Refresh / Back / Cancel actions, per-entry result card, and explicit
  loading, empty, session-expired, permission and project error states.
- `api/platformsimport/index.php` — `GET ?action=init&tproject_id=N[&tplan_id=M]`
  and `POST ?action=import` (multipart). Session auth, JSON in/out, project
  existence check, rights checks, upload limits, XML parsing, create-or-update
  by name, per-entry results.
- `gui/templates/platforms/platformsView.html` — the Import button now launches
  the standalone screen; the inline modal and its `doImport()` are gone.
- `lib/functions/common.php` — registers the `platformsImport` action.
- `lib/platforms/platformsImport.php` — kept as a session-guarded **302 shim**,
  so every old bookmark and `doAction` deep link still lands on the new screen.
- All `pimp.*` labels plus `footers.platformsImport` exist in all 10 client-side
  i18n bundles (49 keys referenced by the screen, verified present everywhere).

## API

| Route | Method | Answers |
|---|---|---|
| `?action=init` | GET | 200 project/plan/platform count, `import_types`, `import_limit_bytes`, `file_formats_doc`, `grants.platform_management` |
| `?action=import` | POST | 200 `{imported, updated, skipped, node_count, imported_total, ok:[{code,name}], ko:[{code,name}]}` |

Error codes: `INVALID_TPROJECT_ID` (400), `TEST_PROJECT_NOT_FOUND` (404),
`NO_RIGHTS` (403), `METHOD_NOT_ALLOWED` (405), `NO_FILE` / `WRONG_FORMAT` (422,
with `xml_errors[{line,column,message}]`), `TOO_LARGE` and every `UPLOAD_ERR_*`
(413/422), `UNKNOWN_ACTION` (400), `DATABASE_UNAVAILABLE` / `SERVER_ERROR` (500).

## Verification

| Scenario | Result |
|---|---|
| Valid file, 3 entries | 1 `IMPORTED` + 1 `UPDATED` + 1 `BAD_LINE`; both rows asserted in the database, including the three flags |
| Same platform name twice in one file | 1 import + 1 update, **one** row, second node's values win |
| Not well-formed XML | 422 `WRONG_FORMAT` with libxml line/column; form stays usable |
| Plain text / zero-byte file | 422 `WRONG_FORMAT` |
| Valid but empty `<platforms/>` | 200, `node_count` 0, "no platform entries" empty state |
| 3 MB file | 413, size-limit message |
| Unknown / missing / non-numeric project | 404 / 400 / 400 |
| `GET` on import, `POST` on init | 405 `METHOD_NOT_ALLOWED` |
| Anonymous request | 401, session-expired state |
| Viewer (`platform_view` only) | init 200 read-only screen, Upload and file picker disabled, import 403 |
| No rights at all | init 403, no project name or platform count in the body |
| Denied attempts | `audit_security_user_right_missing` written to the Event Viewer |
| Legacy deep link | 302 to the standalone screen, `testproject_id`/`testplan_id` aliases honoured |
| Romanian locale | every label, button, hint, chip and the footer translated |
| No JavaScript errors | console clean in all states |

Browser verification used the disposable Test Project `PIMP` (id 16) and plan
`PIMP Plan` (id 17) created by `tmp/fixtures_1632.php`.


A mixed-outcome run in the browser — the result card lists every entry with an Imported / Updated /
Skipped tag, and the toolbar platform count stays truthful:

| Imported | Updated | Skipped | Platforms now |
|---|---|---|---|
| 2 | 0 | 1 | 5 |



A `platform_view` holder reaches the screen in a disabled state. Legacy could not
even open the page for them, because `checkRights()` required
`platform_management` for every request.


## Legacy parity notes

- `doImport()` walked the file with `foreach($xml as $platform)`, i.e. the
  **root's direct children**. A whole-project TestLink export (root `<TestLink>`)
  therefore produces one nameless child and one "bad line" — the BFF reproduces
  this exactly, so fixtures must use `<platforms>` as the root.
- Legacy wrote the upload to `TL_TEMP_PATH . session_id() . "-import_platforms.tmp"`
  and never deleted it; the BFF unlinks it in a `finally` block.
- The rights gate is the one deliberate superset: legacy required
  `platform_management` for the page view as well as the upload. The modern split
  (init needs `platform_view`, import needs `platform_management`) keeps the view
  right as the guard on init rather than dropping it.

## Bugs found and fixed while testing

1. **Upload button permanently disabled** for `platform_management` holders —
   `load()` called `busy(false)` before assigning `canManage`, and `busy(false)`
   re-enables Upload only when the grant is already known.
2. **Documentation link 404** — the BFF returned the raw
   `PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT` (`docs/tl-file-formats.pdf`), which the
   browser resolves against the current screen directory. Legacy prefixed it with
   `$basehref`; the BFF now returns it root-relative, like `api/platformsexport`.
3. **Repeated platform name lost data** — the in-memory name map was seeded with
   `id => 0` after `create()`, so the next node with that name called
   `update(0, …)` (a no-op) while still reporting "updated". The real id from
   `create()` is now stored, and `create()`'s failure status is honoured instead of
   counting a platform that was never written as imported.
4. **405 leaked an untranslated server string** — now `error_code`
   `METHOD_NOT_ALLOWED` with its own i18n key.

Separately filed: **#1634** — the class autoloader in `lib/functions/common.php`
wraps `include_once` in `catch (Exception)`, but since PHP 7 a failed include
raises `E_WARNING`, so the catch can never run and every missing class adds two
Error/Warning rows to the Event Viewer.

## Test suite

Suite **1632 — Import Platforms standalone screen and BFF** was added to
`tmp/TLU_Test_Cases.md`: **74/74 PASS** in `tmp/verify_1632.sh` (verified
discriminating: 67 PASS / 7 FAIL against the pre-fix code), plus 12
browser-only checks.

## Files

- `api/platformsimport/index.php` — import BFF (init + import)
- `gui/templates/platforms/platformsImport.html` — standalone Dashio screen
- `gui/templates/platforms/platformsView.html` — live launcher wiring
- `lib/platforms/platformsImport.php` — session-guarded 302 shim
- `lib/functions/common.php` — action registration
- `gui/templates/i18n/*.json` — localized `pimp.*` keys (10 bundles)
- `docs/screenshots/issue-1632-platformsimport-*.png` — browser evidence

Refs #1632.
