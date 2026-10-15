# Issue #1820 — `ro_RO` logged the two `audit_cfield_{location,monitorable}` LOCALIZATION warnings on every Save (labels clobbered by the closure commit)

**Issue:** [#1820](https://github.com/sebiboga/testlink-upgraded/issues/1820)
**Branch:** `fix/issue-1820-cfield-audit-i18n`
**Status:** VERIFIED-FIXED — regression suite `Issue #1820` 6/6 PASS, suite gate 7/7

## Symptom

Every Save on the modernized **Custom Fields → Test Project** screen
(`gui/templates/cfields/cfieldsTprojectAssign.html`) that touched a *Location* or the
*Monitorable* box wrote two extra **Warning / LOCALIZATION** rows into the Event Viewer
(`events` table) next to the legitimate `SAVE` audit rows:

```
id=56  32  LOCALIZATION  string 'audit_cfield_location_changed' is not localized for locale 'ro_RO'  - using en_GB
id=55  32  LOCALIZATION  string 'audit_cfield_monitorable_on'  is not localized for locale 'ro_RO'  - using en_GB
```

The audit history itself stayed correct — only polluted with noise, so any reporter
filtering on `log_level` warnings got false positives. The issue was originally reported
under the default `en_GB` locale; `en_GB` was fixed by commit `36121574a`. This run
isolated the **live residual** to `ro_RO`.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo root),
PHP 8.3.35, DB `testlink` freshly imported (`SELECT COUNT(*) FROM events` = 0),
`admin`/`admin`. Fixture `php tmp/fixtures_1816.php` → testproject id **1** `CFP Main`,
custom field id **1** `CFP1812` (design-time testcase CF with a Location dropdown),
linked to project 1. The acting user locale was forced to `ro_RO`
(`UPDATE users SET locale='ro_RO' WHERE login='admin'`).

| probe | result |
|---|---|
| Save with Location `standard`→`Before steps` + tick MONITORIZABIL, then open the Event Viewer | `id=55 …audit_cfield_monitorable_on…`, `id=56 …audit_cfield_location_changed…` LOCALIZATION |
| `SELECT id,activity,description FROM events … LIMIT` at save time | audit rows stored as **serialized** `tlMetaStringHelper` objects (ids 51-53) — stringified only when the Event Viewer renders them |
| static matrix over all 19 `locale/*/strings.txt` | the three keys present in **18**, absent **only** in `ro_RO` |

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `lib/functions/cfield_mgr.class.php:2102` | `setDisplayLocation()` → `logAuditEvent(TLS("audit_cfield_location_changed", $tproject_info['name']), …)`. |
| 2 | `lib/functions/cfield_mgr.class.php:2990` | `setMonitorable()` builds the label dynamically: `$auditMsg = $val ? "audit_cfield_{$field}_on" : "audit_cfield_{$field}_off";` → `TLS($auditMsg, …)` at `:3003`. |
| 3 | `lib/functions/logging.inc.php:37,73` | `logAuditEvent()` → `tLog()` stores the `tlMetaString` **object**; the logger `serialize()`s it, so no stringification happens at write time. |
| 4 | `lib/functions/metastring.class.php:138` | rendering the event calls `__toString()` → `localize()` → `lang_get($label)` with the "fire warning" flag on. |
| 5 | `lib/functions/lang_api.php:57-135` | `lang_get()` does not find the key in `ro_RO`, falls back to `en_GB`, and calls `logL18NWarningEvent("string '…' is not localized for locale 'ro_RO' - using en_GB", "LOCALIZATION")` → the Warning row. |

**Why it breaks NOW (regression source):** commit `36121574a` (*fix #1820*, 2026-10-04 06:25)
added the three labels to all 19 locales, including `locale/ro_RO/strings.txt`. Thirty-four
minutes later commit `35e838f67` (*feat(closure) … Refs #1278*) was authored on a base
without that fix; its rewrite of the **tail** of `locale/ro_RO/strings.txt` replaced the
three `$TLS_audit_cfield_*` lines (and the trailing blank) with `$TLS_title_test_closure`.
`git show 35e838f67 -- locale/ro_RO/strings.txt` shows the exact removal. `ro_RO` was then
the only one of 19 locales missing the labels.

## Blast radius

* Static scan of all 19 `locale/*/strings.txt`: `audit_cfield_location_changed` present in
  18, absent only in `ro_RO`; `audit_cfield_monitorable_on` / `_off` identical (18 / 1).
* Runtime: any save on the Custom Fields → Test Project screen while the acting user locale
  is `ro_RO` and the Event Viewer is opened logs the Warning/LOCALIZATION rows.

## The fix (minimal, 1 file, +4/-0)

`locale/ro_RO/strings.txt` — restore the three labels with the same English
wording/placeholders used by the other 18 locales, tagged `// Refs #1820`:

```php
$TLS_audit_cfield_location_changed = "Custom field locations were saved on Test Project '{%1}'"; // Refs #1820
$TLS_audit_cfield_monitorable_on = "Custom field '{%1}' was set MONITORABLE on Test Project '{%2}'"; // Refs #1820
$TLS_audit_cfield_monitorable_off = "Custom field '{%1}' was set NOT MONITORABLE on Test Project '{%2}'"; // Refs #1820
```

### Why this method (and what was rejected)

* **Restore the labels (minimal).** `36121574a` intentionally defined these labels for every
  locale; the clobber removed them from `ro_RO` only. Putting them back is exactly the
  documented fix and keeps `ro_RO` consistent with the other 18 bundles.
* **Rejected — globally suppress the fallback warning in `lang_get()`.** It would hide
  genuine gaps in every partial locale, changes long-standing TestLink behaviour, and is far
  beyond the issue scope.
* **Rejected — translate the three labels into Romanian.** The three keys are authored in
  English across all 19 locales (consistent with the sibling `$TLS_audit_cfield_*` block); a
  lone Romanian translation would break that consistency.

## After the fix

With `ro_RO` active, a Save that changes Location and/or Monitorable writes the `SAVE` audit
rows but produces **no** LOCALIZATION warning for `audit_cfield_location_changed` /
`audit_cfield_monitorable_on` / `_off` when the Event Viewer renders them. The other 18
locales keep resolving the labels.

Remaining `LOCALIZATION` rows under `ro_RO` (`audit_cfield_display_order_changed`,
`audit_cfield_assigned`, `audit_login_succeeded`, `context_*`, `testsuite`, …) are the
normal English fallback for a curated **partial** locale (199 lines vs 4240 in `en_GB`) and
are outside #1820's named keys.

## Verification

Regression suite `Issue #1820`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1820" bash ai/verify_test_suites.sh` → 7/7 PASS.

| case | before | after |
|---|---|---|
| `grep -c audit_cfield_location_changed locale/ro_RO/strings.txt` | 0 | 3 (all 19 locales = 3) |
| `php -l locale/ro_RO/strings.txt` | — | No syntax errors detected |
| Save (Location + Monitorable) under a **fresh** `ro_RO` session, then Event Viewer | `id=55/56` LOCALIZATION for the two keys | `id=107/108` **SAVE**; **no** LOCALIZATION row for the fixed keys |
| Event Viewer, other keys | — | only `_display_order_changed` / `_assigned` (not #1820, never in partial `ro_RO`) |

Screenshot: `docs/screenshots/issue-1820-eventviewer-after.png` — Event Viewer after the
fresh-session Save: the three `SAVE` rows are present and none of them raises a
`LOCALIZATION` warning for the restored keys.

## How to re-test in one command

```bash
php tmp/fixtures_1816.php                        # tproject=1 (CFP Main), cfield 1 CFP1812
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "UPDATE users SET locale='ro_RO' WHERE login='admin'"
# login admin/admin in a FRESH browser session; open each URL:
#   http://localhost:8082/gui/templates/cfields/cfieldsTprojectAssign.html?tproject_id=1
#   change Location + tick MONITORIZABIL -> Salvează atributele de afișare
#   http://localhost:8082/gui/templates/eventviewer/eventviewer.html
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e \
  "SELECT id,description FROM events WHERE activity='LOCALIZATION' AND description LIKE '%audit_cfield_location_changed%';"  # expect: empty
```
