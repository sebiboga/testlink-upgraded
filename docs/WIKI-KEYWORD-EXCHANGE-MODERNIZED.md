# Keyword XML/CSV Export + Import — modernized (`keywordsExport` / `keywordsImport`)

> Refs **#1615** (enhancement). Modern screen `gui/templates/keywords/keywordsExport.html` +
> BFF `api/keywordsxml/index.php`. Supersedes the legacy popup pair
> `lib/keywords/keywordsExport.php` + `lib/keywords/keywordsImport.php`
> (`gui/templates/dashio/keywords/keywordsExport.tpl` / `keywordsImport.tpl`), which are now
> session-guarded 302 shims.

![Export mode](../screenshots/issue-1615-kwxml-export.png)

![Import mode](../screenshots/issue-1615-kwxml-import.png)

## 1. Why a standalone screen

The **TODO** section of `docs/MODERNIZATION-STATUS.md` is empty — every ASIDE entry already
maps to a modern screen. The keyword exchange was the remaining gap: the modern Keyword
Manager (`keywordsView.html`) inlined the export and import **modals** in its own toolbar, so
there was no deep-linkable screen and no dedicated BFF for the flow. The legacy pair was
therefore lifted into one standalone Dashio popup, exactly like the other action popups
(`bugAdd` #1560, `bugDelete` #1559, `eventinfo` #1556).

## 2. The screen

| Element | Behaviour |
|---|---|
| Header | teal bar, subtitle *move your keywords between test projects*, TLi18n locale switcher |
| Tools | Refresh, Back to Keyword Management, Close |
| Tabs | **Export** ⇄ **Import**, driven by `?mode=export\|import` |
| Context card | Test project + live keyword count |
| Format select | XML / CSV, populated from `getSupportedSerializationInterfaces()` |
| Format sample | the real snippet for the selected format, from `getSupportedSerializationFormatDescriptions()` |
| Export panel | editable filename (`maxlength=255`, default `keywords.<ext>`) + Download |
| Import panel | file picker, `Maximum file size: N KB` hint, Upload, "existing keywords with the same name are updated" note |
| States | success box, error box, per-right **Access denied** cards, unknown project, empty project |

Everything is localized: `kwxml.*` (33 keys incl. the two server-error strings) +
`footers.keywordsExport` in all 10 bundles (`de, en, es, fr, it, ja, pt, ro, ru, zh`),
validated with `python3 -m json.tool` and checked for key parity.

## 3. BFF contract — `api/keywordsxml/index.php`

```
GET  ?action=init&tproject_id=N
       -> 200 {tproject_id, tproject_name, keyword_count,
                exportTypes[], importTypes[], formatDescriptions{},
                rights{export,import}, limits{import_file_max_size_bytes},
                default_filename}
       -> 403 Access denied          (caller holds neither right on the project)
       -> 404 Unknown test project
       -> 405 non-GET

GET  ?action=export&tproject_id=N&type=iSerializationToXML|iSerializationToCSV&filename=F
       -> 200 attachment (Content-Type + Content-Disposition, name sanitized + 255-char cap)
       -> 400 no_keywords_to_export  (empty project, refused BEFORE dispatch)
       -> 403 Access denied {right}

POST ?action=import      multipart (uploadedFile) or JSON {content_base64}
       -> 200 {status, tproject_id, keyword_count}
       -> 400 please_choose_keywords_file | wrong_keywords_file | upload_error
       -> 403 Access denied {right: mgt_modify_key} | CSRF
       -> 413 File too large
```

Session auth + `bffSameOriginGuard` (the CSRF guard runs **before** the session check, so an
anonymous POST answers 403, never 401). Temp file is always `TL_TEMP_PATH` + `session_id()`
+ server-derived extension — the client filename never influences the path — and is unlinked
after the import.

## 4. Legacy parity notes (things that look like bugs but are not)

* **Import does not use the serialization ids.** `testproject::importKeywordsFromXML/CSV()`
  takes `TL_KEYWORDS_IMPORT_XML = 1` / `TL_KEYWORDS_IMPORT_CSV = 2`; the *export* side uses
  `iSerializationToXML` / `iSerializationToCSV`. The BFF normalizes `1|xml` and `2|csv` into
  the interface ids so the screen has a single vocabulary.
* **Format descriptions are keyed by the extension name** (`XML`, `CSV` — upper case), not by
  the interface id, and `iSerializationToCSV` is spelled `CSV` in caps while the client
  lower-cases it elsewhere. Both panels therefore map id → ext → sample.
* **CSV is `;`-delimited with a header row** (`Keyword;Notes;Number of Test Case Linked`) and
  the export is therefore *not* a valid CSV import — see **#1616**.
* `mgt_view_key` gates the export and `mgt_modify_key` the import (legacy `checkRights()`
  per action), checked on the **owning** project with `getAccess = true`, so a global-only
  right no longer reaches a private project.
* Deviation (improvement): an empty filename falls back to `keywords.<ext>` instead of the
  legacy alert, and the "view file format doc" link became the inline format sample.

## 5. `tlUser::hasRight()` traps (recorded in the fixture)

`lib/functions/tlUser.class.php:846-880` — three behaviours that make rights fixtures
mislead:

1. a **project role replaces** the global right set for that project, so a view-only user
   needs a view-only role at *project* level too;
2. a project role holding **exactly one right is always denied** — the "Special situation =>
   just one right" branch returns `false` unless the single right carries a `dbID`. A
   1-right project role therefore produces a *false* 403 on export;
3. no project role + a private project ⇒ denied (`getAccess`).

The in-session `tlUser` is cached, so a rights change applied straight in the DB only shows
up after a fresh login.

## 6. Defects found and fixed while testing

| # | Symptom | Fix |
|---|---|---|
| 1 | Locale bundles used printf `%s` while `TLi18n` interpolates `{name}` → the file-size hint and the import confirmation printed the raw `%s` | `kwxml.maxFileSize` / `kwxml.importedMsg` use `{kb}` / `{count}` |
| 2 | jQuery was never loaded | added |
| 3 | `TLi18n` was initialised before its bundle promise resolved | `TLi18n.load(cb)` → `apply()` → `initLocaleSwitcher()` → `load()` |
| 4 | Format sample looked up with the wrong key **and** rendered before its `<select>` existed | map id → ext → sample, render after the select |
| 5 | Import `.fail()` dropped `xhr.responseText`, so **every** 4xx showed a bare `Error` box | parse the JSON body, render the localized reason |
| 6 | **Security:** `init` leaked the project name + count to users with no rights, and 404-vs-200 enumerated project ids | 403 when neither right is held |
| 7 | **500 on any project without keywords** — `sizeof(null)` in `exportKeywordsToXML`, plus an `E_WARNING` in the CSV branch | refuse empty projects up front (400) |
| 8 | Oversized uploads (empty `tmp_name`) were reported as "please choose a file" | read `$_FILES['error']` first; clamp the advertised cap to the PHP runtime |
| 9 | The export was a plain download navigation → 4xx/5xx JSON saved as a file while the UI claimed success | fetch, check `res.ok`, download the blob only on success |
| 10 | Typed filename wiped by the re-render, disable guard a no-op, download name unbounded | `CTX.filename`, `CTX.busy`, 255-char cap server-side |
| 11 | CSV import of a zero-row body reported success | compare the keyword count before/after → `wrong_keywords_file` |
| 12 | 10 `kw.*` keys orphaned by the modal removal | removed from all 10 bundles |

## 7. Bug filed

**#1616** — `testproject::importKeywordsFromCSV()` (`lib/functions/testproject.class.php:1362`)
never skips the header row that `exportKeywordsToCSV()` itself writes
(`exportDataToCSV(..., ['addHeader' => 1])`, `lib/functions/csv.inc.php:17`), so importing a
TestLink-exported CSV inserts a junk keyword literally named `Keyword`. Every export → import
round trip therefore corrupts the project. The modern screen keeps legacy parity on purpose —
the fix belongs in the import model.

## 8. Wiring

* `$actions->keywordsExport` in `lib/functions/common.php`.
* `gui/templates/keywords/keywordsView.html` — the Import/Export toolbar buttons open
  `keywordsExport.html?mode=…&tproject_id=…`; the inline modals are gone.
* `lib/keywords/keywordsExport.php` / `keywordsImport.php` — session-guarded **302 shims**
  (anon → login) that forward `?doAction=`, `?exportType=` and `testproject_id`.

## 9. Regression suite

`tmp/TLU_Test_Cases.md` → **Suite 1615, 23/23 PASS**: export XML/CSV (CDATA, RFC4180 quoting,
`content-disposition`), import XML/CSV counts, wrong-format XML, no file, 3 MB upload,
5000-char filename, empty project, view-only (export ok / import 403), no rights (init + export
403, no metadata leak), 400/404/405 contract, anonymous 401/403, EN↔RO locale, i18n parity,
Event Viewer clean (only INFO/LOGIN/AUDIT rows).

Fixture: `tmp/fixtures_1615.php` (project `KWXML1615` with 3 keywords — one with quotes and
commas in the notes, one with NULL notes — plus `kwview1615` and `kwnorights1615`).

Commits: `3f6a52e47` (BFF) → `1af75f0a1` (screen + i18n + link switch) → `e0c1553f0`
(test-found fixes) → `6556dc91e` (code-review fixes).
