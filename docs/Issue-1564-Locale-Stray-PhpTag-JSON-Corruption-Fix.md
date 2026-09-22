# Bugfix — Issue 1564 — locale/{es_AR,fi_FI,id_ID,ko_KR,pl_PL}: dead `$TLS_*` lines after a stray `?>` corrupt JSON in every API/BFF response for those locales

**Issue:** [#1564](https://github.com/sebiboga/testlink-upgraded/issues/1564)
**Branch:** `fix/issue-1564-locale-stray-php-tag`
**Status:** FIXED & VERIFIED (2026-09-22)

## Symptom

For the locales `es_AR`, `fi_FI`, `id_ID`, `ko_KR`, `pl_PL`, every API/BFF JSON
response was corrupted: the bundles carry dead PHP assignments AFTER a single
mid-file closing `?>` tag, so `lang_load()` → `require strings.txt` echoes them
as literal text into the response body. Client `JSON.parse()` throws at position 0.

Measured pre-fix (fresh DB, tproject id 1 recreated, admin/admin):

```
GET /api/cfields/index.php/assignment?tproject_id=1&locale=pl
→ body starts with: $TLS_href_nfr_performance = "NFR: Performance"; // ...
→ json.load → JSONDecodeError: Expecting value: line 1 column 1 (char 0)

php -r 'require "locale/pl_PL/strings.txt"; var_export(isset($TLS_href_nfr_performance))'
→ $TLS_href_nfr_performance = "NFR: Performance"; ...  (dead lines echoed)
→ false   (dead key)
```

Affected locales matrix (pre-fix, `locale=` on the assignment endpoint):

| locale | body starts with | JSON parse |
|---|---|---|
| pl | `$TLS_href_nfr_performance = ...` | FAIL (char 0) |
| es | `$TLS_req_title_length_exceeded = ...` | FAIL (char 0) |
| fi | `$TLS_href_nfr_performance = ...` | FAIL (char 0) |
| id | `$TLS_href_nfr_performance = ...` | FAIL (char 0) |
| ko | `$TLS_href_nfr_performance = ...` | FAIL (char 0) |
| en (control) | `{"status":"ok",...` | OK |

## Root cause chain

1. `locale/es_AR/strings.txt:3222`, `locale/fi_FI/strings.txt:2148`,
   `locale/id_ID/strings.txt:2886`, `locale/ko_KR/strings.txt:2442`,
   `locale/pl_PL/strings.txt:3842` — each file opens with `<?php` (line 1) and
   contains exactly ONE stray `?>` mid-file; the interpreter leaves PHP mode
   there and never re-enters it.
2. One or more `$TLS_*` assignments follow that `?>` in each file (es_AR: 2 keys;
   the other four: the 8 `$TLS_href_nfr_*` keys from #1470/#1462 + the 2
   `$TLS_req_*` keys). They are NOT parsed — they are literal text.
3. `lib/functions/lang_api.php:240` (`lang_load()`) `require`s the bundle;
   everything after `?>` is copied verbatim into the output buffer.
4. The BFF endpoints (e.g. `api/cfields/index.php/assignment`, `api/aside`,
   etc.) call `lang_get()` with the client-selected locale
   (`api/cfields/index.php:305-313` `assignLocale()`), which triggers
   `lang_load()` — the literal `$TLS_*` text lands before `json_encode()`, so the
   response body stops being JSON.
5. The keys themselves are never defined at runtime → `lang_get()` cannot resolve
   them (localization of those keys also broken).

Note: the `$TLS_btn_report_test_automation` / `$TLS_href_tc_create_from_issues`
lines mentioned during triage are at `pl_PL:3827-3828`, i.e. BEFORE the stray
`?>` (3842) — they are live and needed no change.

## Approach — remove the freezing `?>`, keep the existing translations

Chosen fix: delete the single stray `?>` line from each of the 5 bundles. All
content after it rejoins PHP mode → every existing `$TLS_*` assignment becomes a
live definition at runtime and no literal text is emitted. The translations were
already present — they were just dead code.

- Files keep `<?php` on line 1 and no closing `?>` at EOF (idiomatic for
  `require`d scripts; matches the other bundles). `php -l` stays clean.
- Rejected alternatives:
  - Moving the lines above the `?>` — more churn, leaves a useless frozen tag.
  - Editing `lang_load()` to strip output — wrong layer, larger blast radius,
    masks a frozen bundle instead of fixing it.
  - Deleting the dead lines — would drop translations that must be live.

## Files changed

| File | Change |
|---|---|
| `locale/es_AR/strings.txt` | remove stray `?>` (line 3222) — 1 line |
| `locale/fi_FI/strings.txt` | remove stray `?>` (line 2148) — 1 line |
| `locale/id_ID/strings.txt` | remove stray `?>` (line 2886) — 1 line |
| `locale/ko_KR/strings.txt` | remove stray `?>` (line 2442) — 1 line |
| `locale/pl_PL/strings.txt` | remove stray `?>` (line 3842) — 1 line |
| `CHANGELOG` | one KEY BUGFIX line |
| `tmp/TLU_Test_Cases.md` | Suite 1564 (6/6 PASS) |

Cause→fix commits: `97943a65e` (fix), `432a0b503` (regression suite),
docs commit (this file).

## Verification

- **Bundle gate:** `grep -c '?>'` = 0 in all 5 files; `php -l` "No syntax errors
  detected" ×5; line 1 still `<?php`.
- **Define gate:** `php -r 'require ...; var_export(isset($TLS_href_nfr_performance));
  var_export(isset($TLS_href_nfr_requirements));
  var_export(isset($TLS_req_title_length_exceeded));
  var_export(isset($TLS_req_docid_length_exceeded));'` → `truetruetruetrue` for all
  5 bundles, zero stray stdout (pre-fix: keys `false`, dead lines echoed).
- **Live API (primary symptom gone):**
  `GET /api/cfields/index.php/assignment?tproject_id=1&locale={pl,es,fi,id,ko}`
  → valid JSON, `status=ok` for every locale; `locale=en` unchanged.
- **Localization really works now:** `locale=pl` locations codes 5/7 →
  `Po tytule` / `Po warunkach wstępnych` — the #1563 keys render as real pl
  translations in a previously-corrupt locale (unblocks suite 1563's
  BLOCKED-BY-#1564 live checks).
- **Second endpoint sanity:** `GET /api/aside/index.php?locale=pl&format=json`
  → valid JSON.
- **Event Viewer hygiene:** no Error rows introduced by this fix. (Pre-existing
  out-of-scope: level-32 LOCALIZATION fallback warnings for keys genuinely absent
  from the pl_PL/fi_FI bundles — `before_summary`, `standard_location`, etc.)
- **Code-review sweep:** the only remaining `?>` tags across ALL locale bundles
  sit on the final non-empty line of `de_DE`, `es_ES`, `it_IT`, `ru_RU` —
  legitimate end-of-file terminators (followed by nothing), correctly left
  untouched.

Regression suite: `Suite 1564` in `tmp/TLU_Test_Cases.md` — 6/6 PASS.