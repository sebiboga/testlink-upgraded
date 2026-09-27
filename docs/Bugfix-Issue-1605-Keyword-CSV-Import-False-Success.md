# Bugfix — Issue #1605: the keyword CSV import reported success while importing nothing

**Issue:** [#1605](https://github.com/sebiboga/testlink-upgraded/issues/1605) — *api/keywords POST /import: returns 200 `{"status":"ok"}` when nothing is imported (wrong delimiter, duplicates or bad rows are all silently ignored)*
**Commit:** `1691c92b0` on `fix/issue-1605`
**Affected screens:** Keyword Export / Import (`gui/templates/keywords/keywordsExport.html`) and the `api/keywords` BFF
**Regression suite:** `tmp/TLU_Test_Cases.md` → *Regression — Issue #1605* (30 cases, 26 PASS + 4 pre-fix baseline reproductions)

---

## 1. Symptom

`POST /api/keywords/index.php/import` answered `200 {"status":"ok"}` even when
**no keyword was created**. A user uploading a normal comma-delimited CSV, a
file of duplicates, or a file of empty names was told the import succeeded.

The failure left no trace whatsoever: the `events` table gained **zero** rows, so
neither the audit trail nor the Event Viewer showed that anything had gone wrong.

## 2. Reproduction (pre-fix)

```bash
printf 'imported-1605-a,note a\n' > /tmp/k.csv
curl -b cookies -H 'X-Requested-With: XMLHttpRequest' \
     -F tproject_id=1 -F type=csv -F uploadedFile=@/tmp/k.csv \
     http://localhost:8082/api/keywords/index.php/import
# => 200 {"status":"ok"}      ... and the keywords table is unchanged
```

The same file with `;` instead of `,` imported fine, because `;` is the legacy
default delimiter.

## 3. Root cause

### Hop 1 — the importer threw every per-row verdict away

`lib/functions/testproject.class.php:1362` (pre-fix):

```php
function importKeywordsFromCSV($testproject_id,$fileName,$delim = ';')
{
  $handle = fopen($fileName,"r");
  if ($handle) {
    while($data = fgetcsv($handle, TL_IMPORT_ROW_MAX, $delim)) {   // :1367
      $kw = new tlKeyword();
      $kw->initialize(null,$testproject_id,NULL,NULL);
      if ($kw->readFromCSV(implode($delim,$data)) >= tl::OK) {      // :1371
        if ($kw->writeToDB($this->db) >= tl::OK) {                   // :1373
          logAuditEvent(TLS("audit_keyword_created",$kw->name), "CREATE", $kw->dbID, "keywords");
        }                                                           // no else, no counter
      }
    }
    fclose($handle);
    return tl::OK;                                                  // :1380 UNCONDITIONAL
  }
  return ERROR;                                                     // :1384 only fopen() failure
}
```

`tlKeyword::checkKeywordName()` (`lib/functions/tlKeyword.class.php:389-401`)
rejects a comma in a keyword name (`preg_match("/(\"|,)/")` →
`E_NAMENOTALLOWED`), and `tlKeyword::doesKeywordExist()` (`:348`) returns
`E_NAMEALREADYEXISTS` for a duplicate. Those negative codes died inside the `if`
at line 1373.

The function therefore returned `tl::OK` as long as `fopen()` worked, i.e.
regardless of whether 0, 1 or 500 keywords landed.

> **Note the inverted constants.** `tl::OK === 1` and `tl::ERROR === 0`
> (`lib/functions/object.class.php:27,34`), so `>= tl::OK` really does mean
> ">= 1", and `!= tl::OK` is a strict "did fopen() work?" test.

### Hop 2 — the BFF route had no zero-rows guard

`api/keywords/index.php:412-420` (pre-fix):

```php
$pfn    = $type === 'csv' ? 'importKeywordsFromCSV' : 'importKeywordsFromXMLFile';
$result = $tproject_mgr->$pfn($tproject_id, $dest);
@unlink($dest);
if ($result != tl::OK) {                 // only true when fopen() failed
  http_response_code(422);
  out(['status'=>'error','message'=>'Wrong keywords file','error_code'=>'WRONG_FORMAT']);
}
out(['status' => 'ok']);                 // no imported/skipped counters
```

### Why a comma file silently did nothing

With `$delim=';'` the line `imported-1605-a,note a` is a *single* `fgetcsv`
column. `readFromCSV()` at line 1371 was handed the whole line, so
`$data[0] = 'imported-1605-a,note a'` became the keyword **name** — and a name
containing a comma is always rejected by `checkKeywordName()`. Skipped, with no
counter, no event and no error.

### Blast radius

| call site | guarded before the fix? |
|---|---|
| `api/keywords/index.php:413` (`POST /import`) | **NO — this bug** |
| `api/keywordsxml/index.php:325` (the real Import dialog) | yes — before/after count guard from #1615 |
| `lib/keywords/keywordsImport.php` | 2.0.1 shim → 302 to the modern dialog |

So the two 2.0.1 import entry points disagreed: one reported failure, the other
reported success, for the identical file.

## 4. The fix

### 4.1 The importer reports what it did

`testproject::importKeywordsFromCSV()` gained an **optional by-ref** `$stats`
argument:

```
rows     => data rows found in the file
imported => keywords actually written
skipped  => rows that were rejected (rows - imported, always exact)
errors   => [{row, code, name}] per rejected row, `code` = the tlKeyword::E_* value
```

* `$stats` is optional, so **both existing callers and the legacy `tl-classic` /
  `dashio` screens are untouched**; the `tl::OK` / `tl::ERROR` return contract
  is unchanged.
* `errors[]` is capped at `IMPORT_KEYWORD_ERRORS_MAX` (200) so a 10 MB
  all-duplicate file cannot produce ~200 000 rendered nodes; `skipped` stays
  exact.
* Blank lines (`fgetcsv` yields `array(null)`) are no longer counted as data rows.

### 4.2 The delimiter is decided once per file, and is quote-aware

New `testproject::keywordImportDelimiter()` sniffs the **raw bytes** of the first
`IMPORT_KEYWORD_SNIFF_LINES` (50) non-blank lines with `str_getcsv()`:

* if **any** line really splits on `;` → `;` — the legacy default and what
  `exportKeywordsToCSV()` writes;
* only when **no** line splits on `;` **and** at least one splits on `,` → `,`.

So the natural comma file is accepted, and a `;` file can never be reinterpreted.

> **Why quote-aware, and why the first attempt was rejected by code review.**
> The initial implementation chose the delimiter **per row** and re-split an
> already-unquoted `fgetcsv()` field. A code review blocked it with a concrete
> counter-example: the line `"a,b"` (one *quoted* field) came back from `fgetcsv`
> as `['a,b']` and was then invented into name `a` + notes `b` and **written** —
> where 1.9.20 rejected the name. Deciding per file with a quote-aware sniff keeps
> `"a,b"` as ONE rejected name. The per-row choice had a second flaw the review
> also caught: a file mixing `;` and `,` rows was imported half-wrong. Both are
> covered by regression cases 1605-14 and 1605-15.

### 4.3 Both BFFs publish the real outcome

| situation | `api/keywords/index.php` | `api/keywordsxml/index.php` |
|---|---|---|
| at least one keyword landed | `200` + `imported`/`skipped`/`rows`/`errors[]`/`keyword_count` | `200` + the same |
| file had data rows, none accepted | `422` `NO_KEYWORDS_IMPORTED` + per-row `errors[]` | `400` `NO_KEYWORDS_IMPORTED` |
| file had no data rows at all | `422` `EMPTY_FILE` | `400` `EMPTY_FILE` |
| file unreadable (`fopen` failed) | `422` `WRONG_FORMAT` (unchanged) | `400` `wrong_keywords_file` (unchanged) |
| XML arm | as before | as before (see #1666) |

A raw `tlKeyword::E_*` code is mapped to a stable short code
(`CHAR_NOT_ALLOWED` / `EMPTY_NAME` / `ALREADY_EXISTS` / `DB_ERROR` /
`WRONG_FORMAT` / `REJECTED`) that the client and the i18n layer can translate.

In `api/keywordsxml` the new codes are gated on a `$fileWasReadable` flag
captured **before** the zero-rows guard overwrites `$result`, so an unreadable
file still honestly reports "the format could not be read". The **XML** arm keeps
the legacy code on purpose — its behaviour is deliberately unchanged (see §6).

### 4.4 The dialog tells the user what happened

`gui/templates/keywords/keywordsExport.html`:

* a per-row error card listing every rejected row **and the offending keyword**;
* a partial import no longer reads as plain success —
  *"Imported 2 of 5 rows; 3 row(s) were rejected - see the details below."*;
* an all-rejected import shows a red banner and **no** green success box;
* the hint now states the delimiter:
  *"…Use semicolon ";" as the field separator (a comma separated file is accepted too)."*;
* the report is gated on `MODE === 'import'` so it never leaks onto the Export tab.

The per-row **reasons** deliberately reuse the keyword popup's existing
`kwedit.err*` wording, so the same reason reads identically in both screens.

### 4.5 i18n

Four new keys plus a reworded hint, in **all 10** locale bundles
(`en ro de es fr it ja pt ru zh`):

`kwxml.errNoneImported`, `kwxml.errEmptyFile`, `kwxml.rowError` (`{row}` `{name}`
`{reason}`), `kwxml.partialImportMsg`, `kwxml.importHint`.

All bundles validated with `python3 -m json.tool`.

## 5. Verification

| # | check | result |
|---|---|---|
| 1605-01 | the pre-fix repro, comma file | was `200 ok` + empty table → now `422 NO_KEYWORDS_IMPORTED` with 2 named rows |
| 1605-06 | `;` import unchanged | `200 {"imported":2,"skipped":0,"rows":2}` |
| 1605-07 | comma file now imports | `200 {"imported":2,...}`, `cc1`/`cc2` in the table |
| 1605-08/09 | mixed file | `200 {"imported":1,"skipped":3,"rows":4}` with 3 named failures |
| 1605-10/11 | empty / blank-lines-only file | `422 EMPTY_FILE`, `rows:0` |
| 1605-13 | single-column line | `200`, 1 keyword, empty notes (1.9.20 parity) |
| 1605-14 | **review blocker** `"a,b"` | 1 rejected row, name stays `a,b`, nothing written |
| 1605-15 | **review S2** mixed `;`+`,` file | one delimiter for the file, `imported:0, skipped:2` |
| 1605-17 | XML arm unchanged | measured identical pre-fix and post-fix |
| 1605-20/21/22 | dialog copy | per-row lines with names, partial summary, red-only on total failure |
| 1605-25/26 | i18n | 4 keys × 10 bundles valid; live `ro` renders translated, no raw keys |
| 1605-27 | legacy callers | 2-argument call unaffected; constants unchanged |
| 1605-28 | Event Viewer | `log_level` 16 only — **0** new ERROR/WARNING rows |
| 1605-29 | console | only the expected `400` XHR log, no JS errors |

Gates: `php -l` on 4 files, `node --check` on the dialog's inline JS, and
`python3 -m json.tool` on all 10 bundles — all clean.

## 6. Known separate defect — #1666 (not fixed here)

An XML **round-trip** import of already-existing keywords is wrongly rejected
`400 wrong_keywords_file`. The zero-rows guard in `api/keywordsxml` uses the
keyword **count** as a proxy for "nothing happened", but a valid re-import only
UPDATEs, so the count does not grow.

Verified **pre-existing**, by restoring the pre-fix `api/keywordsxml/index.php`
from HEAD and re-running the same request: byte-identical `400` before and after
this fix. Filed as [#1666](https://github.com/sebiboga/testlink-upgraded/issues/1666).

## 7. Files changed

| file | purpose |
|---|---|
| `lib/functions/testproject.class.php` | `importKeywordsFromCSV()` gains the optional `$stats` report; new `keywordImportDelimiter()` whole-file quote-aware sniff |
| `config.inc.php` | `IMPORT_KEYWORD_ERRORS_MAX` (200), `IMPORT_KEYWORD_SNIFF_LINES` (50) |
| `api/keywords/index.php` | `/import` publishes the real outcome; `keywordCountFor()`, `importErrorRows()` |
| `api/keywordsxml/index.php` | same report on `?action=import`; `$fileWasReadable` gate; `importErrorRows()` |
| `gui/templates/keywords/keywordsExport.html` | per-row error list, partial summary, new `serverMessage()` codes, Export-tab gate |
| `gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json` | 4 new keys + reworded `kwxml.importHint` |
| `docs/screenshots/issue-1605-*.png` | partial-import and nothing-imported states |
| `CHANGELOG` | one line under the 2.0.1 `[KEY BUGFIX]` section |
