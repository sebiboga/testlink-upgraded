# Bugfix — Issue #1666: an XML round-trip keyword import of already-existing keywords was rejected with 400 `wrong_keywords_file`

**Issue:** [#1666](https://github.com/sebiboga/testlink-upgraded/issues/1666) — *api/keywordsxml: an XML round-trip import of already-existing keywords is wrongly rejected with 400 wrong_keywords_file*
**Branch:** `fix/issue-1666`
**Affected screen:** Keyword Export / Import (`gui/templates/keywords/keywordsExport.html`) + BFF `api/keywordsxml/index.php`; model `lib/functions/testproject.class.php`
**Regression suite:** `tmp/TLU_Test_Cases.md` → **Suite 1666** — pre-fix 28/41, post-fix **41/41 PASS** (`php tmp/suite_1666.php`, fixture `php tmp/fixtures_1666.php`)

---

## 1. Symptom

Export a test project's keywords to **XML**, then re-import the file you just exported —
the normal way to merge a keyword update, and exactly what the dialog hint promises
("Existing keywords with the same name are updated; new ones are created"). The Import
dialog answers **400 `wrong_keywords_file`** — *"Wrong keywords file - the format could
not be read"* — although the file is perfectly valid.

Measured on fixture project `1666` (keywords `alpha`, `beta`):

```
POST /api/keywordsxml/index.php/?action=import     (type=iSerializationToXML, own export)
=> 400 {"status":"error","message":"wrong_keywords_file","code":"wrong_keywords_file",
        "result":0,"imported":0,"skipped":0,"rows":0,"errors":[]}
```

`rows:0` and `errors:[]` are the giveaway: the BFF claims it read **nothing**, while the
file was parsed fine.

## 2. Reproduction (measured control matrix, HEAD `f0058496b`)

| # | File | Pre-fix answer | Consequence |
|---|---|---|---|
| A | the project's **own export** (`alpha`,`beta`) | 400 `wrong_keywords_file`, rows=0 | merge workflow impossible |
| B | all-**new** (`gamma`) | 200 imported=1 | passes **only because the count grows** |
| C | update-only (`beta` → new notes) | 400 `wrong_keywords_file` | **and the notes are not written** |
| D | **CSV** round-trip | 400 `NO_KEYWORDS_IMPORTED`, 2 × `ALREADY_EXISTS` | same no-op, but the message names the real reason |

No Event Viewer row in any case (2 rows before, 3 after — all `log_level=16` audit
lines). It is a wrong boolean, not an exception.

## 3. Root cause

### Hop 1 — the XML importer returns a single verdict for the whole file

`lib/functions/testproject.class.php::importKeywordsFromSimpleXML()` walks the
`<keyword>` children but keeps **no per-row outcome**; it returns one
`tl::OK` / `tlKeyword::E_*` scalar. The CSV sibling `importKeywordsFromCSV()` has taken
an optional row report (`$stats`) since #1605 — the XML arm never got one.

### Hop 2 — the BFF substituted the keyword COUNT for "rows read"

`api/keywordsxml/index.php` (pre-fix `:335-338`):

```php
if ($type === 'iSerializationToXML') {
    $stats['rows']     = max(0, $after - $before);   // keyword COUNT DELTA
    $stats['imported'] = $stats['rows'];
}
```

### Hop 3 — the guard then reads "no growth" as "nothing happened"

`api/keywordsxml/index.php` (pre-fix `:345-350`):

```php
if ($result == tl::OK && $stats['imported'] <= 0 && $after === $before) {
    $result = tl::ERROR;      // => 400 wrong_keywords_file
}
```

A merge that only touches keywords which **already exist** is an *update-in-place*
workload: the count stays flat, so `$after === $before` and a valid file is refused.
The inversion also made the opposite case pass only by luck (B).

### Why it is not a regression of #1605

The count proxy predates #1605 — restoring the pre-#1605 `api/keywordsxml/index.php`
from HEAD reproduces a byte-identical 400 (as the issue itself measured). What #1605
changed is only the *accuracy of the message*, which is what made this pre-existing
rejection visible.

## 4. The fix (approach)

**Chosen method: make the XML importer report what it actually did, and gate the BFF on
that report** — the CSV arm's contract, applied to XML.

1. `importKeywordsFromSimpleXML()` gains the **optional** by-reference `&$stats`
   out-param (`rows` / `imported` / `skipped` / `errors[{row,code,name}]`, capped at
   `IMPORT_KEYWORD_ERRORS_MAX` — the same cap the CSV arm uses). The counts come from
   the real per-row results: `rows++` per `<keyword>` element, `imported++` only when
   `writeToDB() >= tl::OK`, otherwise `skipped++` plus the row's error code.
   `importKeywordsFromXMLFile()` forwards `$stats`.
2. `api/keywordsxml/index.php` drops the count-delta proxy and gates **only the XML arm**
   on the reported rows:

```php
if ($result == tl::OK && $stats['imported'] <= 0
    && ($type === 'iSerializationToXML' ? $stats['rows'] <= 0 : $after === $before)) {
```

   A `<keywords/>` document without a single `<keyword>` child still yields
   `rows === 0` and is still refused with the legacy `wrong_keywords_file` code.

**Why this method and not the alternatives**

* *Keep the count guard but reject only on `$result != tl::OK`* — removes the safety net
  for a syntactically valid but empty document, which would then be reported as a
  successful import.
* *Count `audit_keyword_created` event rows* — events are written on the success path
  only, so an all-skipped file is indistinguishable from an empty one. The row-level
  report is strictly more precise.
* *Fix `tlKeyword::writeToDB()` to UPDATE a duplicate name* — that is a different defect
  (see §6, filed as **#1783**): it changes behaviour for `keywordsedit`, `tcImport` and
  `tcCreateFromIssue`, and it needs a product decision.

**Blast radius** — the XML arm of **both** keyword-import entry points. The issue
report claimed `api/keywords/index.php` was unaffected ("no guard at all until #1605");
a code review of this fix measured otherwise and the claim was corrected: that route
carried the **same** count-delta proxy (old `api/keywords/index.php:421-424`) and
answered `422 EMPTY_FILE` — *"The keywords file has no data rows"* — for a 4-row round
trip, i.e. the same defect in a worse disguise. Both are fixed by the same two-line
change (`importKeywordsFromXMLFile()` now hands back `$stats`). No screen posts to
`api/keywords/index.php/import` today (`keywordsView.html` opens the
`keywordsExport.html` popup), so this was a latent, not a visible, regression.

The CSV arms of both routes keep their own signals and their `NO_KEYWORDS_IMPORTED` /
`EMPTY_FILE` codes, byte for byte. The legacy return value of
`importKeywordsFromSimpleXML()` is unchanged, so its other callers
(`importKeywordsFromXML()`, `lib/testcases/tcImport.php`,
`lib/testcases/tcCreateFromIssue.php`) behave exactly as in 1.9.20.

**No client change and no i18n change were needed**: `keywordsExport.html:360-364`
already renders `skipped > 0` as `kwxml.partialImportMsg` plus the per-row error list —
the same path the CSV partial import has used since #1605 — and all 10 bundles already
carry the keys.

## 5. Result (measured, before / after)

| Case | Before | After |
|---|---|---|
| re-import own export | 400 `wrong_keywords_file` rows=0 | **200** rows=2 skipped=2, `ALREADY_EXISTS` per row |
| all-new XML | 200 imported=1 | unchanged |
| update-only XML | 400, no data written | 200 rows=1 skipped=1 (no-op honestly reported — see #1783) |
| `<keywords></keywords>` | 400 `wrong_keywords_file` | **unchanged** |
| non-XML garbage / wrong root | 400 `wrong_keywords_file` | **unchanged** |
| `<keyword>` without `name` | 400, no detail | 400 **+ row 1 `WRONG_FORMAT`** |
| CSV arm (all cases) | see matrix | **unchanged** |
| unknown project / id=0 / GET | 404 / 400 / 405 | **unchanged** |

Screen after the fix (re-import of a 5-keyword export):

```
Imported 0 of 5 rows; 5 row(s) were rejected - see the details below.
Row 1 (alpha): A keyword with this name already exists.
…
```

Console: 0 errors, 0 warnings. Event Viewer: 0 new Error/Warning rows.

## 6. Related defects found while testing — filed, not fixed here

* **#1783** — a keyword import never *updates* an existing keyword, XML **and** CSV.
  `tlKeyword::writeToDB()` (`lib/functions/tlKeyword.class.php:190-213`) returns
  `E_NAMEALREADYEXISTS` and skips its own `UPDATE` branch (`:192`), even though
  `checkKeyword()` has already resolved `$this->dbID` to the existing row (`:230`).
  The dialog hint promises an update that no code performs. Needs a product decision.
* **#1784** — a malformed **last** `<keyword>` element turns a successful XML import into
  `400 wrong_keywords_file` although the earlier rows were already written: the legacy
  `$status` in `importKeywordsFromSimpleXML()` is reassigned per row, so the last row
  decides the whole file's verdict.

## 7. Files changed

| File | Purpose |
|---|---|
| `lib/functions/testproject.class.php` | `importKeywordsFromSimpleXML()` reports its per-row outcome; `importKeywordsFromXMLFile()` forwards `$stats` |
| `api/keywordsxml/index.php` | count-delta proxy deleted; XML arm gated on the reported rows |
| `api/keywords/index.php` | same count-delta proxy removed from the second import route (found by code review) |

Commit: `fix(keywordsxml): report the XML import's real row outcome instead of the keyword count`