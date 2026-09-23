# Bug fix — Issue #1571: datetime design CF value wiped on save + `E_WARNING "Undefined array key input"`

## Symptom
Design-time DATETIME (type 10) Custom Field values are silently wiped to empty
whenever the test case version is saved through the Test Case Editor (modern
`tcEdit.html` and the legacy editor alike), and a PHP `E_WARNING` is emitted on
every such save. Measured on the freshly-imported DB (fixture recreated per
FIX-ISSUE §2): after the save, `SELECT value FROM cfield_design_values WHERE
node_id=9 AND field_id=4` returned nothing (row DELETED) and the `events` table
gained `log_level=2 (WARNING) E_WARNING "Undefined array key \"input\"" ...
lib/functions/cfield_mgr.class.php - Line 1973`.

## Reproduction (this run, fresh DB)
1. Seeded fixture `/tmp/opencode/fixture1571.sql`: TCEDDemo (tproject 6),
   testsuite node 7, testcase `tcA` node 8, tcversion 9 (v2), design CFs
   f1 Severity (type 6/list), f2 Priority Flag (type 5/checkbox), f3 Notes
   (type 20/textarea), f4 DateTime (type 10/datetime); `cfield_design_values`
   node 9 = f4 `1767638400` plus f1/f2/f3 values.
2. `curl` login (admin/admin) + `POST /api/testcasesedit/?action=update` with
   the legacy hash shape produced by `collectCustomFields()`
   (`gui/templates/testcases/tcEdit.html:577`):
   `custom_field_10_4_input='2026-09-23 00:00:00'`,
   `custom_field_10_4_hour=12`, `custom_field_10_4_minute=30`,
   `custom_field_10_4_second=45`.
3. Pre-fix: HTTP 200, f4 row deleted, `E_WARNING` logged. Post-fix: HTTP 200,
   f4 row kept (`1868531445`), zero new `events` rows.

## Root cause chain (`lib/functions/cfield_mgr.class.php`)
- The BFF persists design CF values through
  `design_values_to_db()` → `_build_cfield()` (`:825`, `:834`).
- `_build_cfield` (`:1907-1937`) iterates the hash one key at a time. For a
  date/datetime field the hash carries one key per part
  (`_input/_hour/_minute/_second`). The pre-fix loop reset
  `$the_value = null` (`:1925`) and assigned a **fresh single-element array**
  on every suffix key: `$the_value[$dummy[$last_idx]] = $value;` (`:1929`).
  Iterating `input → hour → minute → second` therefore left
  `$the_value = ['second' => 45]` — the last suffix wins.
- The `datetime` branch (`:1981-1995`) then read `$value['input']` on that
  `['second'=>45]` map → PHP 8 `E_WARNING Undefined array key "input"` →
  `$cfield[$field_id]['cf_value']=''`.
- Back in `design_values_to_db` (`:875-878`): the row exists
  (`rowCount>0`) and the value is empty → the branch issues
  `DELETE FROM cfield_design_values`. Silent loss of the persisted value.
- Any page that posts only the bare `_input` key (no time selects) kept the
  value, which is why the failure only manifested on pages submitting the full
  date+time split.

### Second latent defect exposed by the fix
Once the merge fix lets the `datetime` branch run with a real map, `mktime()`
(`:1994`) received the raw string parts. Numeric strings coerce fine under
PHP 8.3, but the reported ISO-shaped input `2026-09-23 00:00:00` under the
`d/m/Y` locale parses to `year = "23 00:00:00"` (non-numeric) → `TypeError`
→ HTTP 500. This was previously unreachable (the `E_WARNING` short-circuited
to an empty `cf_value` before `mktime`). Fixed with `(int)`/`intval()` casts —
behavior-identical for legitimate values, immune to non-numeric remnants.

## Fix approach
1. **Merge date-part suffixes** (`cfield_mgr.class.php:1927-1945`): each date
   part now accumulates into a single per-field map. `cf_value` is pre-seeded
   from `$cf_map` (`:1884-1887`, `'cf_value'=>''`), so
   `is_array($cfield[$field_id]['cf_value'] ?? null)` distinguishes
   "first part of this field" (→ init the map with the part) from
   "subsequent parts" (→ merge into the map). Non-suffix keys keep the exact
   legacy `$the_value = $value` overwrite path (`#0008347` comment preserved).
2. **PHP 8 int hardening** of `date` (`:1970-1975`) and `datetime`
   (`:1981-1994`) branches: all `mktime()` arguments go through
   `(int)`/`intval()`. No change in behaviour for the numeric values the UI
   produces; no crash for anything else.

### Alternatives considered and rejected
- Clamping/validating `$value['input']` against the locale format and
  rejecting ISO-shaped input server-side — out of scope: the UI never sends it
  (the `showCal` shim writes the localized format), and rejecting input was
  not the reported defect.
- Rewriting `_build_cfield` around a real date parser — too large an
  architectural change for a late-phase PHP 8 compat repo; the merge + cast is
  the smallest change that preserves every legacy branch.
- The multi-owner CF keying (`custom_field_<type>_<id>_<owner>_<part>`) keeps
  its pre-existing `$cfid_pos` semantics (per-field, last owner wins) — verified
  unchanged via a CLI harness; out of scope.

## Verification (measured)
- `php -l lib/functions/cfield_mgr.class.php` → no syntax errors.
- Issue's exact repro POST → HTTP 200, `cfield_design_values` node 9 = f4
  `1868531445`, f1 `High`, f2 `Yes`, f3 `some notes`; `events` table empty.
- Empty datetime input → f4 row removed (intended clear semantics), others
  intact, no warning.
- Realistic localized round-trip in the modern editor (browser):
  `23/09/2026` + 12/30/45 rendered from the stored timestamp; native-picker
  overlay → `28/09/2026`; hour select → 14; Save → f4 `1790605845`
  == `mktime(14,30,45,9,28,2026)`; `events` only audit INFO
  (`audit_login_succeeded`).
- CLI harness for edge shapes: date single-`_input` →
  `mktime(0,0,0,9,22,2026)`; datetime single-`_input` → midnight timestamp, no
  warning; execution-page owner shape (`custom_field_10_5_234_*` with
  `cf_map=null`) → field 5 `mktime(8,15,0,9,22,2026)`, string field intact.
- Browser console on `tcEdit.html`: no JS errors from the fix; two pre-existing
  cosmetic 404s for the legacy `calendar.gif`/`trash.png` theme images in the
  injected datetime markup (present pre-fix, PHP-only change left them as-is).

## Files changed
- `lib/functions/cfield_mgr.class.php` — merge suffix keys + int-cast the
  `mktime()` args (23 insertions, 12 deletions).
- `tmp/TLU_Test_Cases.md` — `Regression — Issue #1571` suite (7/7 PASS).
- `CHANGELOG` — `[KEY BUGFIX] - #1571` entry.
- Wiki mirror page `Bugfix-Issue-1571-DatetimeCf-Wiped-On-Save.md` (with
  screenshots) + this docs mirror.

## Result
Issue #1571 fixed error-free and pushed (`fix/issue-1571`); regression suite
1571 7/7 PASS; Event Viewer clean.