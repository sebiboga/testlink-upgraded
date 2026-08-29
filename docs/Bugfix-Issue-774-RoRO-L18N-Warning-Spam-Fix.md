# Bugfix — Issue #774: ro_RO bundle missing server-side keys → L18N warning spam

**Issue:** [#774](https://github.com/sebiboga/testlink-upgraded/issues/774)
**Branch:** `fix/issue-774-ro-l18n` · **Commits:** `a190b641f` (fix), `f731bbbcb` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-29)

## Symptom

Every session whose locale is `ro_RO` and that touches the Execute flow (Execute Tests
screen / execution history) wrote three level-32 LOCALIZATION warning events into `events`:

```
string 'the_format_tc_xml_import' is not localized for locale 'ro_RO' - using en_GB
string 'manual' is not localized for locale 'ro_RO' - using en_GB
string 'automated' is not localized for locale 'ro_RO' - using en_GB
```

Reproduced against a fresh database (empty Event Viewer): `UPDATE users SET locale='ro_RO'
WHERE id=1`, login as admin, `GET /api/execute/?action=history&tcase_id=2` produced
events #2/#3/#4 (the exact three lines above) — logged once per user session each.

## Root cause chain

1. `lib/functions/lang_api.php:41` (`lang_get`): a key absent from the active bundle falls
   back to `en_GB` and, via `:110-139`, logs a level-32 LOCALIZATION event. The per-session
   dedupe guard (`:133-136`) skips repeats of the *same* key only.
2. Three server-side keys are requested while handling Execute flows, all from
   `lib/functions/testcase.class.php`:
   - `:22` `$g_tcFormatStrings = ["XML" => lang_get('the_format_tc_xml_import')]` — module
     level, fires when the file is included (Execute BFF `api/execute/index.php` requires it);
   - `:139-143` `getExecutionTypes()` → `lang_get('manual')` / `lang_get('automated')`,
     invoked by the constructor at `:102` whenever a `testcase` object is built.
3. `locale/ro_RO/strings.txt` is a *partial* translation (its own header: "lang_get() falls
   back to en_GB for every key not defined here") and never received these three keys.
   Audit: all other 17 locale bundles define all three; **ro_RO was the only one missing them**.

This is a data gap, not a code bug — the en_GB fallback yields correct values, and only the
log spam is the problem (matches the issue's "minor" priority).

## Approach — add the missing keys to the ro_RO bundle

Chosen fix: append the three keys to `locale/ro_RO/strings.txt` with a issue-labelled comment:

```php
$TLS_manual = "Manual";
$TLS_automated = "Automat";
$TLS_the_format_tc_xml_import = "";
```

- `manual` → "Manual" (the correct Romanian execution-type term; identical word).
- `automated` → "Automat" (Romanian for "Automated").
- `the_format_tc_xml_import` → `""`, matching the legacy empty placeholder already present in
  en_GB and every other bundle.

Rejected alternatives:
- Suppressing the `lang_api.php` warning path: hides every future localization gap globally
  and diverges from upstream behavior.
- Fixing `testcase.class.php` to lazily resolve the keys: unnecessary, the keys are meant to
  be translatable server-side; adding a partial-translation bundle key is the established
  pattern (see issue #644 / #653 for the same approach).

## Files changed

| File | Change |
|---|---|
| `locale/ro_RO/strings.txt` | +3 keys (6 lines incl. header comment) |
| `tmp/TLU_Test_Cases.md` | +Suite 774 (5 test cases, 5/5 PASS) |

## Verification

- `php -l locale/ro_RO/strings.txt` → "No syntax errors detected".
- Post-fix fresh session (new cookie jar, `locale='ro_RO'`, `TRUNCATE TABLE events`):
  same two API requests → `SELECT COUNT(*) FROM events WHERE description LIKE '%not localized%'`
  → **0** (was 3). Only `audit_login_succeeded` (INFO) in the window.
- Event Viewer: no new Error/Warning entries.
- Coverage gate: 19/19 locale bundles now define all three keys (confirmed via `grep`).

## Regression suite

Suite 774 in `tmp/TLU_Test_Cases.md` — 5/5 PASS (pre-fix repro, post-fix clean events,
bundle validity, other-locale regression, Event Viewer scan).