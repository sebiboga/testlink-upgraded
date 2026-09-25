# Bug fix — Issue #1584: read-only Issue Tracker table emitted a mismatched delete cell

## Symptom

The legacy Dashio Issue Tracker list returned HTTP 200 for a user holding only
`issuetracker_view`, but DataTables failed during initialization. The read-only
page had three visible column headers and four cells in every tracker row, so
the browser raised `TypeError: Cannot read properties of undefined (reading
'mData')`. The DataTables search box and the configured page-size control were
not rendered.

The management path still rendered, so the failure was limited to the read-only
column contract. This is the second of three sibling templates with the same
defect: the Code Tracker one was fixed in #1582, this one in #1584, and the
Requirement Manager one is tracked in #1585.

Entry point:
`http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1`.

## Environment and fixtures

- TestLink 2.0.1 with PHP 8.3 and MariaDB on the local CI instance, docroot =
  repository root, PHP built-in server on port 8082.
- The freshly imported database contained **0 test projects and 0 issue
  trackers** — exactly the condition under which the original reporter could not
  live-reproduce the defect. The fixture below was recreated for this run.
- Test project 1: `Issue1584 Fixture` (`nodes_hierarchy` id 1 → `testprojects`
  id 1, `issue_tracker_enabled = 1`).
- Issue trackers: `1` `IT1584 Redmine` (type 15), `2` `IT1584 Mantis` (type 3),
  `3` `IT1584 GitHub` (type 25).
- Project link: `(testproject_id=1, issue_tracker_id=2)`. Note that
  `testproject_issuetracker` has `PRIMARY KEY (testproject_id)` alone, so only
  **one** tracker can be linked per project. Result: `IT1584 Mantis` has
  `link_count = 1` and the other two have `link_count = 0`, which exercises the
  delete gate in both directions.
- `ro_tracker`: global role 10 `issue1584 read-only` holding **exactly** right 32
  (`issuetracker_view`) and **no** right 31 (`issuetracker_management`),
  assigned to project 1 through `user_testproject_roles`.
- `admin`: global role 8, holds both tracker rights.

## Reproduction before the fix

1. Recreate the fixture above.
2. Log in as `ro_tracker` / `admin`.
3. Open the entry point with a cache-bypassing navigation.
4. Inspect the table structure, DataTables controls and the browser console.

Measured pre-fix result:

- The document response was HTTP 200 and the PHP side was healthy (all three
  trackers rendered with name, type and environment).
- The table had 3 headers: `Issue Tracker`, `Type`, `Environment`.
- Every populated row had **4** cells (`rowCellCounts: [4,4,4]`).
- DataTables did not initialize (`dtWrapper: false`), the page-length menu was
  empty (`lengthSelect: []`) and no search box was rendered.
- Chrome reported one uncaught `TypeError: Cannot read properties of undefined
  (reading 'mData')` plus the associated jQuery Deferred warning, with the stack
  terminating in DataTables 1.12.1 and at the generated page line
  `issueTrackerView.php:275` (the `DataTables.inc.tpl` include).
- The Event Viewer gained no Error/Warning row from this defect: it is a
  client-side column-contract failure, not a PHP failure.

The pre-fix state is preserved in
`docs/screenshots/issue-1584-before.png`.

## Root cause chain

1. `lib/issuetrackers/issueTrackerView.php:23-24` loads tracker rows (including
   `link_count`) and sets `$gui->canManage` from `issuetracker_management`;
   `checkRights()` at `:64-65` admits the page to a user holding only
   `issuetracker_view`. The mismatch is therefore reachable by design.
2. `gui/templates/dashio/issuetrackers/issueTrackerView.tpl:45-47` renders the
   delete column **header** only when `$gui->canManage` is non-empty.
3. Before the fix, `issueTrackerView.tpl:78-85` rendered the delete body cell
   **unconditionally**:

   ```smarty
   <td class="clickable_icon">                                  <!-- 78 -->
     {if $gui->canManage != ""  && $item_def.link_count == 0}    <!-- 79 -->
       <i class="fas fa-minus-circle" ...></i>
     {/if}
   </td>                                                         <!-- 84 -->
   </td>                                                         <!-- 85 stray -->
   ```

   The permission check was written to guard the delete **icon**; it was never
   lifted to the enclosing `<td>`. Line 85 is a **second closing tag for the
   same cell**, so the HTML parser appends a fourth, empty cell rather than
   merely mis-aligning the row.
4. `issueTrackerView.tpl:26-29` includes `DataTables.inc.tpl` for `#item_view`
   whenever the list is populated. DataTables maps columns from the 3 `<th>`;
   the 4th `<td>` resolves to `undefined`, producing the `mData` TypeError and
   aborting initialization.

### Why it became visible now

The unconditional cell and the stray `</td>` are inherited 1.9.20 defects, not a
recent regression. They only became structurally visible when the **Environment**
column was inserted in front of them at `issueTrackerView.tpl:77`. Before that
column existed, both `clickable_icon` cells sat at the end of the row, so the
trailing empty cell was visually harmless. Adding a column ahead of them turned
the 3-vs-4 header/cell mismatch into a hard DataTables error — the same reason
the identically shaped Code Tracker template failed in #1582.

## The fix

`gui/templates/dashio/issuetrackers/issueTrackerView.tpl` — one file,
+9 / -8:

```diff
         <td>{$item_def.type_descr|escape}</td>
         <td class="clickable_icon">{$item_def.env_check_msg|escape}</td>
-          <td class="clickable_icon">
-            {if $gui->canManage != ""  && $item_def.link_count == 0}
-              <i class="fas fa-minus-circle" title="{$labels.testproject_alt_delete}" 
-                 onclick="delete_confirmation(...)"></i>
-            {/if}
-          </td>
-        </td>  
+          {if $gui->canManage != ""}
+            <td class="clickable_icon">
+              {if $item_def.link_count == 0}
+                <i class="fas fa-minus-circle" title="{$labels.testproject_alt_delete}" 
+                   onclick="delete_confirmation(...)"></i>
+              {/if}
+            </td>
+          {/if}
       </tr>
```

The method chosen is the **minimal structural hoist**: the existing
`$gui->canManage` condition is lifted from the icon to the cell, so header and
cell are emitted from the same predicate, and the stray tag is deleted. This is
byte-for-byte structurally identical to the fix accepted for the Code Tracker
sibling in `a548c855d` (#1582), which keeps the three list templates
maintainable in parallel.

The inner `link_count == 0` test is deliberately **kept as a separate nested
condition** rather than folded into the outer one, so the manager list keeps the
documented behaviour: the delete column is present, and the icon is hidden for
any tracker linked to at least one test project.

### Alternatives considered and rejected

- *Remove the delete column entirely* — breaks the management workflow, and the
  issue explicitly requires the manager list to keep it.
- *Add a fourth, empty header for read-only users* — ships a meaningless column
  and contradicts the expected contract (three headers, three cells).
- *Suppress the extra cell in JavaScript* (e.g. telling DataTables to ignore it)
  — leaves structurally invalid HTML (4 `<td>` under 3 `<th>`) in the DOM. The
  correct repair point is the template.
- *Hoist the condition into a `<tbody>`-level or per-`foreach` variable* — more
  churn for no behavioural gain.

No PHP, API or i18n changes were required: the defect was purely server-side
Smarty, `inc_del_onclick.tpl` still supplies `delete_confirmation`, and no
JavaScript dereferences the cell.

## Verification after the fix

Read-only user `ro_tracker` (`issuetracker_view` only):

| Metric | Pre-fix | Post-fix |
| --- | --- | --- |
| `<th>` count | 3 | 3 |
| `<td>` per row | 4, 4, 4 | **3, 3, 3** |
| `.dataTables_wrapper` | false | **true** |
| DataTables search box | absent | **present** |
| Console errors + warnings | 1 `TypeError` + 1 jQuery warning | **0** |
| Create button | hidden | hidden |
| Delete icons / edit links / wrench links | 0 / 0 / 0 | 0 / 0 / 0 |

Administrator:

| Metric | Result |
| --- | --- |
| `<th>` count | 4 (`Issue Tracker`, `Type`, `Environment`, `delete`) |
| `<td>` per row | 4, 4, 4 |
| DataTables wrapper + search box | present |
| Create button | present |
| `IT1584 Mantis` (`link_count = 1`) | delete **cell** present, delete **icon** absent |
| `IT1584 Redmine` / `IT1584 GitHub` (`link_count = 0`) | delete cell **and** icon present |
| Console errors + warnings | 0 |

The corrected read-only and management states are preserved in
`docs/screenshots/issue-1584-after-readonly.png` and
`docs/screenshots/issue-1584-after-admin.png`.

Additional regression cases exercised: the empty-list path (`$gui->items == ''`
skips the DataTables include — header-only table, no `<tbody>`, no console error,
no new Event Viewer row), and anonymous access in a fresh isolated browser
context, which is redirected to `login.php?note=expired&destination=…` without
ever rendering the list.

The generated-PHP lint of the recompiled template is clean, and the sibling
templates were confirmed untouched (`git diff --stat` shows only
`issuetrackers/issueTrackerView.tpl`).

Regression suite: `tmp/TLU_Test_Cases.md`, entry
`Regression — Issue #1584`, **8/8 PASS**.

## Related, deliberately out of scope

Two further pre-existing defects were found while verifying this fix and were
filed separately rather than silently folded in:

- **#1590** — `issueTrackerView.tpl:81` renders the delete icon tooltip with
  `{$labels.testproject_alt_delete}`, but the template's `{lang_get}` at
  `:12-16` loads `alt_delete`. Result: an empty tooltip plus two
  `E_WARNING Undefined array key` rows per manager page load. The sibling
  `codeTrackerView.tpl` uses the correct `{$labels.alt_delete}`. Introduced with
  the Dashio theme port, not by this change.
- **#1591** — `config.inc.php` never defines
  `$tlCfg->gui->{issueTrackerView,codeTrackerView,reqMgrSystemView}->pagination`,
  so `issueTrackerView.tpl:27` dereferences a missing object, logging three
  `E_WARNING`s per load and leaving the page-size `<select>` with zero options.
  This is why the page-length menu is still empty after this fix even though
  DataTables now initializes correctly.

`reqMgrSystemView.tpl:71-77` still carries the same cell/header mismatch and is
tracked by the still-open issue **#1585**.

## Files changed

| File | Purpose |
| --- | --- |
| `gui/templates/dashio/issuetrackers/issueTrackerView.tpl` | the fix (+9 / -8) |
| `CHANGELOG` | rule-22 one-line 2.0.1 entry |
| `tmp/TLU_Test_Cases.md` | `Regression — Issue #1584` suite, 8/8 PASS |
| `docs/screenshots/issue-1584-before.png` | pre-fix read-only evidence |
| `docs/screenshots/issue-1584-after-readonly.png` | post-fix read-only evidence |
| `docs/screenshots/issue-1584-after-admin.png` | post-fix management evidence |
| `docs/Bugfix-Issue-1584-IssueTracker-Readonly-Cell-Contract.md` | this page |
