# Issue 1461 — planImport (modern): results table renders blank/`undefined` status for missing TC/version links

**Issue:** [#1461](https://github.com/sebiboga/testlink-upgraded/issues/1461)
**Branch:** `fix/issue-1461`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

The modern import screen (`gui/templates/plans/planImport.html`, BFF
`api/planimport/index.php`) renders an **empty/`undefined` status** in the report
table's status column for imports that contain a Test Case / Test Case Version
that does not exist. Unlike the legacy twin (#1413) there is **no PHP warning** —
the defect is consumed in JS: the renderer dereferences `r[1]` on decoded JSON
rows that are 1-element arrays, and `esc(undefined)` renders an empty string.

Triggered by the mixed-import repro (`tmp/pimp_xml/regr1390.xml`: 1 valid link +
1 missing-version link + missing-TC external id + 1 no-platform link): the two
"does not exist" rows render a blank status cell.

## Repro steps

1. Login `admin/admin` on `http://localhost:8082`.
2. Fixture `php tmp/fixtures_pimp.php`: tproject **1 PIMP**, tsuite 2, TCs
   `Login`(ext 1, v1 → tcversion 4)/`Logout`/`Settings`, plan **12 PIMP-Plan**,
   platform **1 PIMP-Android**.
3. `http://localhost:8082/gui/templates/plans/planImport.html?tproject_id=1&tplan_id=12`
   → choose `tmp/pimp_xml/regr1390.xml` → **Upload file**.
4. **Before fix:** rows for "external id 1 version 99 does not exist" and "Can not
   find test case identified by 777" render an **empty** status `<td>` (API JSON
   `result_map[2]` / `result_map[3]` are 1-element arrays). **After fix:** both
   rows render **`Not imported`**, matching every other no-luck row.

**Expected:** every row shows a status ("Not imported" for missing TC/version),
never a blank/`undefined` value.

## Root cause

`api/planimport/index.php` `processTestcaseExeBff()` builds the `result_map` that
the modern screen consumes. Every entry is expected to be a **2-element** array
`array(message, status)`; the JS renderer reads both elements unconditionally:

```js
// planImport.html:248-250
var statusCls = (r[1] === 'OK' || r[1] === TLi18n.t('common.ok'))
  ? 'ok' : 'not-imported';
h += '<tr><td>' + esc(r[0]) + '</td><td class="' + statusCls + '">' + esc(r[1]) + '</td></tr>';
```

Two appends produced **1-element** arrays:

```php
// :280 — "test case version does not exist"
$msg[] = array(sprintf($labels['tcversion_doesnot_exist'], $externalID, $version, $tprojectInfo['name']));

// :283 — "test case does not exist"
$msg[] = array(sprintf($labels['tcase_doesnot_exist'], $externalID, $tprojectInfo['name']));
```

Every other append in the file is 2-element (`:109`, `:135`, `:198`, `:226-227`,
`:251-252`, `:264`, `:276`, `:294`, `processPlatformsBff()` `:318`). With
`r[1]` undefined, `esc(s)` (`planImport.html:130-134`, `s == null ? '' : s`)
returns an empty string → blank status cell (visual "undefined" status).

Blast radius: the only producer of the modern import `result_map` is
`api/planimport/index.php`; the only renderer is `planImport.html`. The legacy
producer (`lib/plan/planImport.php:481,486`) was fixed separately in #1413.
2 offender appends, 1 JS renderer.

## Fix (minimal)

Append the already-loaded `$labels['not_imported']` status to both appends,
matching the file's own convention for error/no-luck results and the #1413 fix:

```php
// :280
$msg[] = array(sprintf($labels['tcversion_doesnot_exist'], $externalID, $version, $tprojectInfo['name']),
              $labels['not_imported']);

// :283
$msg[] = array(sprintf($labels['tcase_doesnot_exist'], $externalID, $tprojectInfo['name']),
              $labels['not_imported']);
```

- `not_imported` is already loaded (`:102`) and used at `:109/:135/:294` —
  **no i18n bundle change**.
- The message text and every other result row are untouched.

Rejected alternative:

- **Null-guarding `r[1]` in the JS renderer** (e.g. `esc(r[1] || 'Not imported')`)
  — fixes the symptom but leaves the malformed 1-element data model in place and
  silently hides future producer regressions; the producer-side fix repairs the
  payload so any consumer (current or future) sees well-formed rows.

## Verification (regression matrix, all PASS on localhost:8082)

- **primary repro** (mixed `regr1390.xml`): the two missing-TC/version rows
  render **`Not imported`**; API JSON now emits 2-element rows
  `["...version 99 does not exist...","Not imported"]` and
  `["...identified by 777","Not imported"]`.
- **valid-link path intact:** already-linked/OK rows still render `OK`.
- **no-platform link row unaffected:** `"Test case link #4 has no platform element"` → `Not imported`.
- **i18n hygiene:** zero bundle files touched (reuses `not_imported`).
- **Event Viewer / console clean:** `SELECT COUNT(*) FROM events WHERE log_level <= 3`
  → **0** (only `log_level=16` audits); browser console has no errors.
- `php -l api/planimport/index.php` clean.

## Evidence

Branch `fix/issue-1461` (pushed):
- `b0857ec47` — fix (`api/planimport/index.php`, +4/-2)
- `684428652` — regression suite (`tmp/TLU_Test_Cases.md`, suite "Regression —
  Issue #1461", 6/6 PASS)

Wiki page: `tmp/wiki-repo/Bugfix-Issue-1461-PlanImport-Modern-undefined-Status.md`
(post-fix screenshot: `issue-1461-planimport-not-imported-status.png`).
Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #1461".