# Task 1006 — Keyword Export must be gated on `mgt_view_key`, not `mgt_modify_key`

**Issue:** [#1006](https://github.com/sebiboga/testlink-upgraded/issues/1006)
**Status:** IMPLEMENTED & VERIFIED (2026-09-27) — branch `task/issue-1006`

## The gap

Legacy deliberately **delimited reading from writing** on keywords, and the Export
button sits on the *read* side:

| legacy | condition | meaning |
|---|---|---|
| `gui/templates/dashio/keywords/keywordsView.tpl:122-127` | `{if $gui->canManage != ""}` | Create Keyword |
| `gui/templates/dashio/keywords/keywordsView.tpl:135-140` | `{if $gui->canManage != ""}` | Import |
| `gui/templates/dashio/keywords/keywordsView.tpl:142-147` | `{if $gui->keywords != ''}` — **no rights check at all** | **Export** |
| `gui/templates/dashio/keywords/keywordsView.tpl:128-133` | `{if $gui->keywords != '' && $gui->canAssign!=''}` | Assign to Test Cases |

and the target screen `lib/keywords/keywordsExport.php` gated the flow on
`checkRights($db, $user, 'mgt_view_key', …)` — **view-only**.

The modern screen collapsed all four onto `rights.canManage` (`mgt_modify_key`)
and the list BFF never exposed an export right at all:

```js
// gui/templates/keywords/keywordsView.html:209 (before)
$('#btnExport').toggle(canManage && r.items.length > 0);
```

```php
// api/keywords/index.php:218-221 (before) — no canExport field
'rights' => [
    'canManage' => (bool)$user->hasRight($db, 'mgt_modify_key', $tproject_id),
    'canAssign' => (bool)$user->hasRight($db, 'keyword_assignment', $tproject_id),
],
```

So a user holding `mgt_view_key` without `mgt_modify_key` lost a capability the
backend was still serving: `api/keywordsxml/index.php:222` checks
`mgt_view_key` on `action=export` and answered **HTTP 200** with a full
`keywords.XML` — the working export was simply unreachable from the UI.

## Measured evidence gathered during INVESTIGATION (before the fix)

Fixture: project 1 with 3 keywords, role 90 holding **only** right 8
`mgt_view_key`, user `kwviewer`.

```
GET /api/keywords/?tproject_id=1      -> 200 rights:{canManage:false,canAssign:false}  items:3
GET /api/keywordsxml/?action=init     -> 200 rights:{export:true,import:false}  keyword_count:3
GET /api/keywordsxml/?action=export   -> 200 text/xml; name=keywords.XML
                                          <?xml …?><keywords><keyword name="regression_suite">…
toolbar                                  #btnCreate/#btnImport/#btnExport/#btnAssign all display:none
```

## The fix

**BFF** — `api/keywords/index.php:218-229` publishes the right the export route
actually enforces:

```php
'rights' => [
    'canManage' => (bool)$user->hasRight($db, 'mgt_modify_key', $tproject_id),
    'canAssign' => (bool)$user->hasRight($db, 'keyword_assignment', $tproject_id),
    // Refs #1006 …
    'canExport' => (bool)$user->hasRight($db, 'mgt_view_key', $tproject_id),
],
```

**Screen** — `gui/templates/keywords/keywordsView.html:139-146, 216-224` gates the
button on that right, a 1:1 port of the four legacy conditions:

```js
canExport = !!r.rights.canExport;
$('#btnExport').toggle(canExport && r.items.length > 0);
```

No i18n change: the button already reuses the existing `kw.export` label — only the
*gate* moved, so all 10 locale bundles stay untouched.

## After the fix

```
kwviewer @ tp1  rights:{canManage:false,canAssign:false,canExport:true} items:3
               toolbar -> "Export Keywords"  (only button; Create/Import/Assign stay hidden)
               export screen: Format XML|CSV, sample, File name keywords.xml
               click Export -> "Export started - check your browser downloads."
               Import tab   -> "Access denied"   (import is mgt_modify_key)
```

## Regression matrix

| user / role | project | list BFF | toolbar buttons | `?action=export` |
|---|---|---|---|---|
| `admin` | tp1 (3 kw) | 200 `canManage/canAssign/canExport` = true | Create Import Export Assign | 200 XML |
| `admin` | tp2 (0 kw) | 200 | Create Import | 200 |
| `admin` | tp3 (1 kw) | 200 | Create Import Export Assign | 200 |
| `kwviewer` — **only** `mgt_view_key` | tp1 | 200 `canExport:true`, others false | **Export** | 200 XML |
| `kwviewer` | tp2 | 200, `items:0` | *none* | – |
| `kwviewer` | tp3 | 200 | Export | – |
| `kwnorights` | tp1/2/3 | **403 "No permission"** | – (no screen) | 403 |
| `kwmanager` — view+modify+assign | tp1/2/3 | 200 | Create Import (+Export/Assign when keywords exist) | 200 XML |

`POST /api/keywordsxml/?action=import` still refuses a token-less POST with
`Forbidden: missing or mismatched same-origin proof (CSRF protection)` for every
role, including `admin` — **no write path is widened by this change**.

Suite `1006` in `tmp/TLU_Test_Cases.md`: 16 cases, 15 PASS + 1 pre-fix baseline
reproduction. Event Viewer after the suite: 5 rows, all `LOGIN` /
`audit_login_succeeded` / `log_level 16` — 0 error/warning rows. Browser console
clean.

## Screenshots

- `docs/screenshots/issue-1006-keywordsview-viewonly-export-button.png` — view-only
  user, `Export Keywords` the only toolbar button.
- `docs/screenshots/issue-1006-keywordsexport-viewonly.png` — the export screen
  reached from it (Format / sample / file name / Export).
