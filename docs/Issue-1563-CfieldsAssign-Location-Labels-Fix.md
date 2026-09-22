# Bugfix — Issue 1563 — cfieldsAssignView Location dropdown `LOCALIZE:` placeholders + level-32 warnings

**Issue:** [#1563](https://github.com/sebiboga/testlink-upgraded/issues/1563)
**Branch:** `fix/issue-1563` · **Cause→fix commits:** `bdea95f48` (fix), `6d052880c` (regression suite), docs commit (below)
**Status:** FIXED & VERIFIED (2026-09-22)

## Symptom

On the modern Assign Custom Fields screen (`cfieldsAssignView.html`) the Location
dropdown of a testcase custom field rendered the placeholder `LOCALIZE: after_title`
(option 5) and `LOCALIZE: after_preconditions` (option 7), and the Event Viewer
gained two `log_level=32` LOCALIZATION rows every time the screen loaded with the
`en`/`en_GB` locale:

```
string 'after_title' is not localized for locale 'en_GB'
string 'after_preconditions' is not localized for locale 'en_GB'
```

Measured pre-fix (fresh DB, fixture `tmp/fixtures_1563.php`, admin/admin):

```
GET /api/cfields/index.php/assignment?tproject_id=1&locale=en
→ locations: [...,(4,'Before Preconditions'),(5,'LOCALIZE: after_title'),
              (6,'After Summary'),(7,'LOCALIZE: after_preconditions'),...]
events rows 4/5: log_level=32, unix 1790090606 (the two strings above)
```

## Root cause chain

1. `lib/functions/cfield_mgr.class.php:135-146` defines the testcase location map with
   **key** `after_title` (code 5) and `after_preconditions` (code 7).
2. `api/cfields/index.php:352-356` (GET /assignment, added with #950) resolves **all 8**
   location labels via `lang_get($labelKey, $lang)` on every screen load — that is the
   trigger path of the modern screen.
3. `lib/functions/lang_api.php:78-89,110-139`: a key absent from the requested bundle AND
   from the `en_GB` fallback bundle returns `TL_LOCALIZE_TAG . $key` (the `LOCALIZE:`
   placeholder) and fires a level-32 LOCALIZATION event (deduped per user session via
   `$_SESSION['missingL18N']`).
4. Regression source: `en_GB`/`en_US` never defined the two keys
   (`grep -n after_title` → no match pre-fix). Only `fr_FR` (~line 4085) and
   `pt_BR`/`pt_PT` (~line 4183) carried them — so en_GB users and every locale that
   falls back to en_GB got the placeholder, i.e. **16 of 19 bundles** were affected.

## Approach — restore keys in all bundles that miss them (policy of issue #644)

Chosen fix: add `$TLS_after_title` + `$TLS_after_preconditions` to the 16 bundles that
did not have them.

- `en_GB`/`en_US` got the canonical `After Title` / `After Preconditions` (matches the
  camel-case style of the sibling `Before Preconditions` / `After Summary`).
- The other 14 bundles got real translations (de `Nach dem Titel` / `Nach den
  Vorbedingungen`, es `Después del título` / `Después de las precondiciones`, fi
  `Otsikon jälkeen` / `Esiehtojen jälkeen`, id `Setelah judul` / `Setelah prasyarat`,
  it `Dopo il titolo` / `Dopo le precondizioni`, ja `タイトルの後` / `前提条件の後`, ko
  `제목 뒤` / `전제 조건 뒤`, nl `Na de titel` / `Na de voorwaarden`, pl
  `Po tytule` / `Po warunkach wstępnych`, ro `După titlu` / `După precondiții`, ru
  `После заголовка` / `После предварительных условий`, zh `标题之后` / `前提条件之后`).
- `cs_CZ` got an ASCII-only English fallback because of its legacy mixed encoding —
  the same policy issue #644 applied.
- Placement follows #644: files ending with the closing PHP tag (`de_DE`,`es_ES`,
  `it_IT`,`ru_RU`) got the block inserted **before** `?>`, the rest at EOF; every file
  keeps its LF EOL.
- **Code-review correction:** the first pass appended the block at EOF for
  `es_AR`/`fi_FI`/`id_ID`/`ko_KR`/`pl_PL`, but those 5 bundles contain a single
  mid-file `?>` — keys after it are dead PHP. The block was relocated **before** that
  `?>` in each; a `php -r require + isset()` gate proves all 16 bundles now define the
  keys (16/16 DEFINED).

Rejected alternatives:
- Fixing only en_GB/en_US → would leave the placeholder (and the bug class) in 14 other
  locales; the issue's expected behaviour is that every option shows a real label.
- Suppressing `lang_api.php`'s warning path → hides future localization gaps and diverges
  from upstream (same rationale as #644).

## Files changed

| File | Change |
|---|---|
| `locale/{cs_CZ,de_DE,en_GB,en_US,es_AR,es_ES,fi_FI,id_ID,it_IT,ja_JP,ko_KR,nl_NL,pl_PL,ro_RO,ru_RU,zh_CN}/strings.txt` | +`$TLS_after_title` + `$TLS_after_preconditions` each, with an `issue #1563` comment header |
| `CHANGELOG` | one KEY BUGFIX line |
| `tmp/fixtures_1563.php` | test fixture (tproject Demo Project + testcase CF `assigned_cf` location 5) |
| `tmp/TLU_Test_Cases.md` | Suite 1563 (6/6 PASS) |

## Verification

- `php -l` PASS on all 16 edited bundles; coverage gate 16 × 2 = 32/32 key occurrences,
  exactly once each.
- **Live en_GB (primary symptom gone):** fresh session,
  `GET /assignment?tproject_id=1&locale=en` → `(5,'After Title'),(7,'After
  Preconditions')`, **0 new level-32 events**.
- **Live de_DE/ro_RO/ja_JP/zh_CN (non-en locales):** `GET /assignment?tproject_id=1&locale=<loc>` →
  real translations for codes 5/7 (`Nach dem Titel`/`Nach den Vorbedingungen`,
  `După titlu`/`După precondiții`, `タイトルの後`/`前提条件の後`, `标题之后`/`前提条件之后`),
  no `LOCALIZE:` and **no events for the two fixed keys**.
- **Browser end-to-end**: cfieldsAssignView.html dropdown shows the selected
  `After Title` and option `After Preconditions` in English; switching the locale
  combobox to German shows `Nach dem Titel` selected plus `Nach den Vorbedingungen`.
  Console clean (only pre-existing a11y hints). Screenshots below.
- **Event Viewer hygiene:** no new Error/Warning for the fixed keys. (Pre-existing gap
  observed, out of scope: 4 *other* location keys — `before_summary`,
  `before_preconditions`, `after_summary`, `hide_because_is_used_as_variable` — still
  fall back to en_GB gracefully in non-en bundles and emit one "using en_GB" level-32
  row per session; they never produce `LOCALIZE:`.)
- **Pre-existing bug found while re-verifying (filed as #1564):** the 5 bundles above
  (`es_AR`,`fi_FI`,`id_ID`,`ko_KR`,`pl_PL`) carry dead `$TLS_*` assignments after a
  mid-file `?>`, which are echoed verbatim into every BFF JSON response for those
  locales → the modern UI cannot parse the payload for them. The #1563 keys are
  correctly installed (define-gate passes) but a full live check for those 5 locales is
  blocked by #1564, a separate, pre-existing root cause.

Regression suite: `Suite 1563` in `tmp/TLU_Test_Cases.md` — 7/7 PASS + 1 BLOCKED-BY-#1564.