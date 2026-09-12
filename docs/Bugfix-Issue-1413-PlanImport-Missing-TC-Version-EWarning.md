# Issue 1413 — planImport (legacy): 2× E_WARNING `Undefined array key 1` on every import with a missing TC/version link

**Issue:** [#1413](https://github.com/sebiboga/testlink-upgraded/issues/1413)
**Branch:** `fix/issue-1413`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

Every legacy test-plan import whose result contains a "test case / version does
not exist" message logs **2× `E_WARNING "Undefined array key 1"`** to the Event
Viewer (`events` table, `log_level=2`, source `GUI - Test Project ID : 1`). The
message comes from the compiled template

```
E_WARNING Undefined array key 1 - in .../gui/templates_c/..._0.file.planImport.tpl.php - Line 105
```

On the rendered page the affected rows show an empty status: `... :` with
nothing after the colon.

Triggered by the mixed-import repro (1 valid link + 1 missing-version link plus a
missing-TC external id): each missing item adds one warning.

## Repro steps

1. Login `admin/admin` on `http://localhost:8082`.
2. Fixture `php tmp/fixtures_pimp.php`: tproject **1 PIMP**, tsuite 2, TCs
   `Login`(ext 1, v1 → tcversion 4)/`Logout`/`Settings`, plan **12 PIMP-Plan**,
   platform **1 PIMP-Android**.
3. `http://localhost:8082/lib/plan/planImport.php?tplan_id=12` → choose
   `tmp/pimp_xml/regr1390.xml` (4 links: valid Login v1; Login **v99**; external id
   **777**; no-platform link) → **Upload file**.
4. **Before fix:** the two "does not exist" rows render `" :"` (empty status);
   Event Viewer / `events` gains **2** `log_level=2` rows
   `E_WARNING Undefined array key 1 ... file.planImport.tpl.php - Line 105`.
   **After fix:** every row carries a status — `... version 99 does not exist ... :
   Not imported`, `... identified by 777 : Not imported` — and **zero** new events.

**Expected:** no warnings in Event Viewer.

## Root cause

`lib/plan/planImport.php` builds the result map for `{$gui->resultMap}`. Every
entry is expected to be a **2-element** array `array(message, status)` — the
Smarty template renders both unconditionally:

```smarty
{foreach item=result from=$gui->resultMap}
    <b>{$result[0]|escape}</b> : {$result[1]|escape}<br />
{/foreach}
```

(`planImport.tpl:58` → compiled `planImport.tpl.php:105`
`htmlspecialchars((string)$_smarty_tpl->tpl_vars['result']->value[1], ...)`).

Two appends produced **1-element** arrays:

```php
// :481 — "test case version does not exist"
$msg[] = array(sprintf($labels['tcversion_doesnot_exist'], $externalID, $version, $tprojectInfo['name']));

// :486 — "test case does not exist"
$msg[] = array(sprintf($labels['tcase_doesnot_exist'], $externalID, $tprojectInfo['name']));
```

Every other append in the file is 2-element (`:241`, `:267`, `:355`, `:403`,
`:422`, `:459`, `:475`, `:519`). On PHP 8, dereferencing `$result[1]` on such an
entry raises `E_WARNING Undefined array key 1`, which `testlinkInitPage`'s event
logger records in the `events` table.

Blast radius: the only producer of `resultMap` is `lib/plan/planImport.php`; the
only renderer is `planImport.tpl` (dashio + tl-classic copies, both fixed by the
producer change). 2 offender appends, 1 renderer.

## Fix (minimal)

Append the already-loaded `$labels['not_imported']` status to both appends,
matching the file's own convention for error/no-luck results:

```php
$msg[] = array(sprintf($labels['tcversion_doesnot_exist'], $externalID, $version, $tprojectInfo['name']),
               $labels['not_imported']);
...
$msg[] = array(sprintf($labels['tcase_doesnot_exist'], $externalID, $tprojectInfo['name']),
               $labels['not_imported']);
```

- `not_imported` is already declared/loaded (`:227`) — **no i18n bundle change**.
- The message text and every other result row are untouched.

Rejected alternative:

- **Null-guarding `{$result[1]}` in both templates** — fixes the symptom but
  leaves the malformed data model in place and silently hides future producer
  regressions; the producer-side fix removes the warning at the source.

## Verification (regression matrix, all PASS on localhost:8082)

- **primary repro** (mixed `regr1390.xml`): every result row renders a status
  (`Not imported` for the two missing items), no more empty `" :"`.
- **event hygiene:** baseline `MAX(id)=5` (the two pre-fix E_WARNING rows);
  post-fix `SELECT ... FROM events WHERE id > 5` → **0 rows**.
- **valid-link path intact:** re-import of the already-linked Login v1 renders
  `... was already linked to Test Plan, only execution order has been updated.: OK`
  (2-element, status shown) — no warning, no regression.
- `php -l lib/plan/planImport.php` clean; `git diff --stat` touches only
  `lib/plan/planImport.php` (+4/-2).
- Modern BFF comparator (static): the same 1-element defect exists at
  `api/planimport/index.php:280,283` and renders `undefined` in the modern table's
  status column (no PHP warning — JS reads `r[1]` off the decoded JSON). Filed
  separately: issue **#1461**.

## Evidence

Branch `fix/issue-1413` (pushed):
- `87a71cb0d` — fix (`lib/plan/planImport.php`, +4/-2)
- `78b660fb6` — regression suite (`tmp/TLU_Test_Cases.md`, suite "Regression —
  Issue #1413", 6/6 PASS)

Wiki page: `tmp/wiki-repo/Bugfix-Issue-1413-PlanImport-Missing-TC-Version-EWarning.md`
(post-fix screenshot: `issue-1413-planimport-not-imported-status.png`).
Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #1413".