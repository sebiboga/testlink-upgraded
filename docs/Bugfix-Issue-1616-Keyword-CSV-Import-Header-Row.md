# Bugfix — Issue #1616: the keyword CSV import created a bogus `Keyword` entry from its own export header

**Issue:** [#1616](https://github.com/sebiboga/testlink-upgraded/issues/1616) — *Keyword CSV import creates a bogus 'Keyword' entry: importKeywordsFromCSV() does not skip the header row its own export writes*
**Branch:** `fix/issue-1616`
**Affected screen:** Keyword Export / Import (`gui/templates/keywords/keywordsExport.html`) + BFF `api/keywordsxml/index.php` (the legacy `lib/keywords/keywordsImport.php` path shares the same model function)
**Regression suite:** `tmp/TLU_Test_Cases.md` → **Suite 1616** (13/13 PASS)

---

## 1. Symptom

Exporting the keywords of a test project to **CSV** and importing that file back
created a junk keyword literally named `Keyword` (empty notes map to the first two
header cells) **on top of** the real ones. Measured on the fixture project
`KW1616Proj` (id 1, 2 keywords):

```
3 keywords expected ... 3 keywords, but the keywords table gained one more row:

1 | smoke_login        | smoke test of login
2 | regression_nightly | NULL
3 | Keyword            | Notes          <- bogus, created from the header row
```

The round trip corrupted the project data on **every** export → import cycle.

## 2. Reproduction (pre-fix, measured)

```bash
# 1. export CSV from the modern screen's BFF
curl -b cookies 'http://localhost:8082/api/keywordsxml/index.php?action=export&tproject_id=1&type=iSerializationToCSV&filename=keywords.csv'
#    body starts with:  Keyword;Notes;Number of Test Case Linked\r\n

# 2. import that exact file back
curl -b cookies -F tproject_id=1 -F type=iSerializationToCSV -F uploadedFile=@keywords.csv \
     'http://localhost:8082/api/keywordsxml/index.php?action=import'
# => {"status":"ok","keyword_count":3,"imported":1,"skipped":2,"rows":3}
#    ... and `SELECT ... FROM keywords` gained the row 3 | Keyword | Notes
```

## 3. Root cause

### Hop 1 — the exporter writes a header the importer never expects

`api/keywordsxml/index.php:478` `exportKeywordsToCSV()` is a faithful port of the
legacy `lib/keywords/keywordsExport.php` helper:

```php
function exportKeywordsToCSV($kwSet) {
    $keys = array("keyword", "notes", "tcv_qty");
    return exportDataToCSV($kwSet, $keys, $keys, array('addHeader' => 1));
}
```

`addHeader => 1` makes `exportDataToCSV()` (`lib/functions/csv.inc.php:17`) emit a
localized header line `Keyword;Notes;Number of Test Case Linked` (en_GB)
before the data rows.

### Hop 2 — `importKeywordsFromCSV()` treats every non-blank row as a keyword

`lib/functions/testproject.class.php:1409-1423` (pre-fix):

```php
while($data = fgetcsv($handle, TL_IMPORT_ROW_MAX, $delim))
{
    $rowNo++;
    $isBlank = (count($data) === 1) && (trim((string)$data[0]) === '');
    if ($isBlank) { continue; }
    ...
    $kw = new tlKeyword();
    $kw->initialize(null,$testproject_id,NULL,NULL);
    $rowCode = $kw->readFromCSV(implode($delim,$data), $delim);  // field[0]->name, field[1]->notes
    if ($rowCode >= tl::OK) { $rowCode = $kw->writeToDB($this->db); }
```

The only filter was the blank-line check, so the header row became a keyword named
`Keyword` with notes `Notes`. The `keywords` uniqueness key
(`keyword`,`testproject_id`) had no conflict for that name → `writeToDB()` wrote it.

### Why it breaks now

The 2.0.1 export screen (Refs #1615) faithfully recreated the legacy exporter
header; no model-side import skip has ever existed (1.9.20 had the same bug). The
modern screen simply made the round trip easy to perform.

### Blast radius

- `importKeywordsFromCSV()` callers: `api/keywordsxml/index.php:328` (the modern
  Exchange screen) and `api/keywords/index.php:419` (keywords edit import route);
  both pass the `$stats` out-param and neither iterates rows itself, so the fix is
  transparent to both.
- The XML round trip is unaffected: `importKeywordsFromSimpleXML()` iterates
  `$simpleXMLObj->keyword` child nodes — no header node exists.

## 4. The fix (approach)

Chosen method: skip the header **in the import model**, at the top of the parse
loop, when the *first* parsed row matches the exporter header. The screen keeps
legacy parity (header + delimiter + labels unchanged) exactly as the issue asked.

Why comparison in the model uses the **same localized labels the exporter writes**
(`lang_get('keyword')`, `lang_get('notes')`, `lang_get('tcv_qty')`) instead of the
issue's suggested hardcoded `$data[0] === 'Keyword'`: a German or Romanian export
uses localized labels (`Schlagwort` / `Notizen` / …), so an English-only match
would still corrupt those round trips. `lang_get()` resolves against the session
locale — the same call `exportDataToCSV()` makes — so a same-locale round trip
always matches.

Match rules (helper `keywordCsvHeaderMatch(array $data)`):

1. **Only the first DATA row** is ever tested (blank lines are skipped first).
2. All three cells must match their label, **case-insensitively**, **trimmed**.
3. No extra non-empty cell may follow the third column.
4. Robustness (from code review): a **UTF-8 BOM** tolerated on the first cell
   (spreadsheet re-saves), and the **en_GB labels accepted as a second
   candidate set** so a file exported under a non-English locale still skips
   its header when imported elsewhere.

These rules are what let a genuine keyword literally named `Keyword` keep
importing: the exporter writes an **numeric tcv_qty** in the third cell of a data
row (`Keyword;Notes;0`), which never equals the label
`Number of Test Case Linked`. A 2-column headerless row
(`Keyword;SomeNotes`) fails rule 2 (only 2 of 3 cells) and imports normally.

The header row is excluded from **both** `rows` and `skipped` in `$stats`: it is
not a data row and not a rejection, so a successful round trip reports the clean
`imported` count, and a header-only file still degrades to the callers' existing
`EMPTY_FILE` guard (`imported <= 0 && rows == 0`).

Rejected alternatives:

- Hardcoded `if ($rowNo === 1 && $data[0] === 'Keyword')` — English-only, breaks
  every non-English round trip.
- A separate import option "file has no header" — API/screen change, out of scope;
  the auto-detect is unambiguous because the header is a full 3-cell label line.
- Counting the header in `skipped` — would make every round trip show the partial
  import message (`imported N of M rows, 1 skipped`) for a fully successful import.

## 5. Files changed

| File | Change |
|---|---|
| `lib/functions/testproject.class.php` | header skip at the top of the `importKeywordsFromCSV()` parse loop + new private `keywordCsvHeaderMatch()` helper |

No i18n bundle, BFF, or screen change: the fix is invisible to the UI and the
import payloads are unchanged.

## 6. Verification (post-fix, measured)

| case | input | result |
|---|---|---|
| same-project round trip | export p1 → import back into p1 | 400 `NO_KEYWORDS_IMPORTED` (duplicates), **no** `Keyword` row |
| clean round trip | export p1 → import into empty p2 | 200, `imported=2, skipped=0, rows=2`, p2 holds exactly the exported keywords |
| hardest round trip | export p2 (holds a REAL keyword named `Keyword`) → import into empty p3 | 200, `imported=5, skipped=0, rows=5`, p3 is an exact mirror of p2 |
| headerless 2-col file | `a;note\r\nb;note\r\n` | 200, 2 imported |
| real keyword in row 1 | single row `Keyword;Notes;0` | 200, 1 imported → keyword `Keyword` preserved |
| header-only file | `Keyword;Notes;Number of Test Case Linked\r\n` | 400 `EMPTY_FILE`, nothing created |
| comma-delimited header | `Keyword,Notes,Number of Test Case Linked\r\n…` | comma header skipped too |
| BOM'd + header | `\uFEFFKeyword;Notes;…\r\nbom_kw;;0\r\n` | header skipped, `bom_kw` imported, **no** \uFEFF junk row |
| leading blank line + header | `\r\nKeyword;Notes;…\r\nblank_kw;;0\r\n` | header skipped, `blank_kw` imported |
| real keyword + round trip | export p2 (holds real `Keyword`) → import into fresh p4 | p4 mirrors p2 exactly; real `Keyword` preserved |

Plus: `php -l` clean, all 10 locale bundles untouched (still `json.tool`-valid),
Event Viewer shows only `log_level=16` CREATE audit rows — 0 new
ERROR/WARNING — clean browser console. Regression suite 1616 13/13 PASS.

## 7. Commit

See the issue trail for commit hashes and the verification evidence; the work is
pushed on `fix/issue-1616`.