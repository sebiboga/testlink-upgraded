# Bug fix — Issue #1585: read-only Requirement Manager list emitted a mismatched delete cell

**Issue:** [#1585](https://github.com/sebiboga/testlink-upgraded/issues/1585)
**Fix commit:** `fef528ac2` — `fix(reqmgrsystem): align read-only delete cell with its header (Refs #1585)`
**Status:** VERIFIED-FIXED (2026-09-25)

## Symptom

The legacy Dashio Requirement Management System list
(`lib/reqmgrsystems/reqMgrSystemView.php`) is reachable by a user holding only
`reqmgrsystem_view` — `checkRights()` at `reqMgrSystemView.php:68-70` admits
either right. For such a user the rendered table had **three** column headers
(`Req. Management System`, `Type`, `Environment`) but **four** cells in every
row: the delete header was suppressed while the delete body cell was still
emitted as an empty `<td class="clickable_icon">`.

Unlike the Code Tracker (#1582) and Issue Tracker (#1584) siblings, this
template has **no** DataTables initialization (`simple_tableruler` +
`enableTableSorting` only), so there is no `TypeError … reading 'mData'`. The
symptom is the broken column contract itself: a phantom trailing column that
mis-aligns the row, a permanently empty last cell, and a header/cell
disagreement that any future table consumer will trip over. The browser console
stays clean.

Entry point: `http://localhost:8082/lib/reqmgrsystems/reqMgrSystemView.php`.

## Environment and fixtures

The freshly imported database contained **0 test projects, 0 requirement
manager systems and 1 user** — the exact condition under which the original
reporter could not live-reproduce the defect. `tmp/fixtures_1585.php` recreates
it:

- Test project 1 `RM1585` (`reqmgr_integration_enabled = 1`) so the session
  carries a `testprojectID`.
- Requirement manager systems `1` `ReqMgr 1585 unlinked` and `2`
  `ReqMgr 1585 linked` (type 1 = contour).
- Project link `(testproject_id=1, reqmgrsystem_id=2)`. `link_count` is computed
  by `tlReqMgrSystem::getAll(['output' => 'add_link_count'])` as the number of
  `testproject_reqmgrsystem` rows for the system
  (`lib/functions/tlReqMgrSystem.class.php:509-517`) — so system 2 gets
  `link_count = 1` and system 1 `link_count = 0`, exercising the delete gate in
  both directions.
- Role 20 `reqmgr view only` holding **exactly** right 34
  (`reqmgrsystem_view`); right 33 (`reqmgrsystem_management`) deliberately
  absent. User `rmreadonly` / `rmreadonly`.
- Negative control: user `rmguest` / `rmguest` on role 3 `<no rights>`.
- `admin` / `admin` (role 8) holds both reqmgrsystem rights.

## Reproduction before the fix

1. `php tmp/fixtures_1585.php`.
2. Log in as `rmreadonly` / `rmreadonly` in a clean browser context.
3. Open the entry point.
4. Count `<th>` of the first table row and `<td>` of every body row.

Measured pre-fix, read-only user:

```
thCount   : 3  ["Req. Management System","Type","Environment"]
tdCounts  : [4,4]
row[0]    : ["ReqMgr 1585 linked","contour (Interface: soap)","",""]
createBtn : false
console   : no error / warn messages
```

Control — the same fixture as `admin`:

```
thCount   : 4  ["Req. Management System","Type","Environment","delete"]
tdCounts  : [4,4]
createBtn : true
```

Evidence: `docs/screenshots/issue-1585-before-readonly-3th-4td.png`. All
`reqMgrSystemView.php` requests returned `[200]`, so the fault is in the
emitted markup, not in request handling or templating.

## Root cause chain

1. `lib/reqmgrsystems/reqMgrSystemView.php:25` — `$gui->canManage` is
   `""` (falsy) for a user without `reqmgrsystem_management`.
2. `lib/reqmgrsystems/reqMgrSystemView.php:70` — `checkRights()` returns true
   for `reqmgrsystem_view` alone, so the view-only user legitimately reaches
   the list.
3. `gui/templates/dashio/reqmgrsystems/reqMgrSystemView.tpl:38-40` — the delete
   **`<th>` is inside** `{if $gui->canManage != ""}` → 3 headers.
4. `gui/templates/dashio/reqmgrsystems/reqMgrSystemView.tpl:71-75` — the delete
   **`<td>` is emitted unconditionally**; only the *icon* inside it is guarded:

   ```smarty
   <td class="clickable_icon">                                 <!-- 71 -->
   {if $gui->canManage != ""  && $item_def.link_count == 0}     <!-- 72 -->
     <span ... onclick="delete_confirmation(...)">…</span>      <!-- 73 -->
   {/if}                                                        <!-- 74 -->
   </td>                                                        <!-- 75 -->
   ```

The row contract therefore depends on a permission that only one of the two
emission sites consults. This is the inherited 1.9.20 shape: the same defect
was found and fixed in the Code Tracker sibling (#1582, `4f9d893e0`) and the
Issue Tracker sibling (#1584); #1582 deliberately did not touch this screen.

`link_count == 0` is **not** the defect — it is a data-level gate that must keep
suppressing the delete icon for a system linked to a test project. It only has
to live *inside* the permission guard.

### Blast radius

Every `gui/templates/dashio/**/*.tpl` referencing `canManage` was swept (15
files). This was the only remaining mismatch in the family:

| Template | delete `<th>` guarded | delete `<td>` guarded | Status |
| --- | --- | --- | --- |
| `codetrackers/codeTrackerView.tpl` | yes (:73) | yes (:76) | fixed by #1582 |
| `issuetrackers/issueTrackerView.tpl` | yes (:45) | yes (:78) | fixed by #1584 |
| `keywords/keywordsView.tpl` | yes (:62) | yes (:84) | already correct |
| `platforms/platformsView.tpl` | yes (:82) | yes (:134) | already correct |
| **`reqmgrsystems/reqMgrSystemView.tpl`** | **yes (:38)** | **NO (:71)** | **this bug** |

Server-side write rights are unaffected: `lib/reqmgrsystems/reqMgrSystemEdit.php:177-180`
still enforces `reqmgrsystem_management` on create/edit/delete.

## The fix

`gui/templates/dashio/reqmgrsystems/reqMgrSystemView.tpl` — one file,
+5 / -3:

```diff
-        <td class="clickable_icon">
-        {if $gui->canManage != ""  && $item_def.link_count == 0}
-            <span style="border:none;cursor: pointer;" title="{$labels.alt_delete}" onclick="delete_confirmation({$item_def.id}, '{$item_def.name|escape:'javascript'|escape}', '{$del_msgbox_title}','{$warning_msg}');">{$tlImages.delete}</span>
-        {/if}
-        </td>
+      {if $gui->canManage != ""}
+        <td class="clickable_icon">
+          {if $item_def.link_count == 0}
+            <span style="border:none;cursor: pointer;" title="{$labels.alt_delete}" onclick="delete_confirmation({$item_def.id}, '{$item_def.name|escape:'javascript'|escape}', '{$del_msgbox_title}','{$warning_msg}');">{$tlImages.delete}</span>
+          {/if}
+        </td>
+      {/if}
```

The method chosen is the **minimal structural hoist**: the existing
`$gui->canManage` predicate is lifted from the icon to the enclosing cell, so
header and cell are emitted from the same condition. This is structurally
identical to the shape already accepted for the two sibling templates in #1582
and #1584, which keeps the three list templates maintainable in parallel.

The `link_count == 0` test is deliberately kept as a **separate nested
condition** rather than folded into the outer one, so the manager list keeps
its documented behaviour: the delete column is present, and the icon is hidden
for any system linked to at least one test project.

### Alternatives considered and rejected

- *Hide the stray cell with CSS `display:none`* — hides the symptom and leaves
  a 4-cell row under 3 headers in the DOM for any future table consumer.
- *Always render the delete `<th>`* — advertises a control the user cannot use
  and diverges from the three sibling screens.
- *Suppress the extra cell in JavaScript* — same objection as the CSS option.
- *Touch the controller or the rights check* — access is already correct; the
  defect is purely markup.

No PHP, API, CSS or i18n change was required: `inc_del_onclick.tpl` still
supplies `delete_confirmation`, no JavaScript dereferences the cell, and every
label used was already requested by the template's `{lang_get}`.

## Verification after the fix

Full matrix, hard reloads with cache ignored, same fixture:

| # | Case | Expected | Measured | Verdict |
| --- | --- | --- | --- | --- |
| 1 | `rmreadonly` (view only) | 3 `<th>` / 3 `<td>`, no Create | `thCount:3`, `tdCounts:[3,3]`, `createBtn:false` | PASS |
| 2 | `admin` (manager) | 4 `<th>` / 4 `<td>`, Create present | `thCount:4`, `tds:4`, `createBtn:true` | PASS |
| 3 | `admin`, `link_count = 0` | delete icon rendered, clickable | `lastCellHasIcon:true`, `onclick="delete_confirmation…"` | PASS |
| 4 | `admin`, `link_count = 1` | cell present, icon absent | `lastCellHasIcon:false`, cell HTML `""` | PASS |
| 5 | Empty list (`DELETE FROM reqmgrsystems`) | no table, no error, Create kept | `hasTable:false`, `createBtn:true` | PASS |
| 6 | `rmguest` (no reqmgrsystem rights) | denied | redirected to `http://localhost:8082/`, no table, INFO audit `audit_security_user_right_missing` | PASS |

Screenshots: `docs/screenshots/issue-1585-before-readonly-3th-4td.png` (pre-fix)
and `docs/screenshots/issue-1585-after-readonly-3th-3td.png` (post-fix).

Static gates: Smarty brace balance `{if ` = 9 / `{/if}` = 9 (a missing `{/if}`
would throw at render), `git diff --check` clean, all five
`reqMgrSystemView.php` requests `[200]`, no PHP Warning/Error/Fatal added to
`tmp/php_server.log`, browser console clean on both sessions.

### Event Viewer

`events` gained **no** new Error/Warning row from this fix. The only
`log_level = 2` rows produced by this fixture are
`include_once(contoursoapInterface.class.php)` warnings, emitted by the
fixture's own `contour` system type through `checkEnv`; they are present
identically before and after the change and are tracked separately as **#1593**.
The remaining `log_level = 16` rows are the expected INFO audit entries
(`audit_login_succeeded`, `audit_security_user_right_missing`).

Regression suite: `tmp/TLU_Test_Cases.md`, entry `Regression — Issue #1585`,
**7/7 PASS**.

## Related, deliberately out of scope

- **#1592** — `reqMgrSystemView.php:58` reads `$_SESSION['tproject_id']`, which
  is never set, so an `E_WARNING Undefined array key "tproject_id"` is logged
  on every load of this controller. Not touched by this change.
- **#1593** — the missing `contoursoapInterface.class.php` include, triggered
  here by the fixture's `contour` system type.

## Files changed

| File | Purpose |
| --- | --- |
| `gui/templates/dashio/reqmgrsystems/reqMgrSystemView.tpl` | the fix (+5 / -3) |
| `tmp/TLU_Test_Cases.md` | `Regression — Issue #1585` suite, 7/7 PASS |
| `docs/screenshots/issue-1585-before-readonly-3th-4td.png` | pre-fix read-only evidence |
| `docs/screenshots/issue-1585-after-readonly-3th-3td.png` | post-fix read-only evidence |
| `docs/Bugfix-Issue-1585-ReqMgrSystem-Readonly-Cell-Contract.md` | this page (wiki mirror of record) |
| `CHANGELOG` | rule-22 one-line 2.0.1 entry |
