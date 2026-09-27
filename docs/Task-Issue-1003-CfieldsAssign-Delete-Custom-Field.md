# Task 1003 — Delete a custom field (with in-use confirmation) from the cfieldsAssignView edit modal

**Issue:** [#1003](https://github.com/sebiboga/testlink-upgraded/issues/1003)
**Status:** IMPLEMENTED & VERIFIED (2026-09-27) — branch `task/issue-1003`
**Screens touched:** `gui/templates/cfields/cfieldsAssignView.html` (front-end only)
**BFF changes:** none — `DELETE /api/cfields/{id}` already existed and already ran the legacy cascade.

## The gap

In legacy 1.9.20 the **Assign Custom Fields** screen links *every* field name — assigned **and**
available — to `lib/cfields/cfieldsEdit.php?do_action=edit&cfield_id=N`. That single form offered
**three** actions, not two:

- `gui/templates/dashio/cfields/cfieldsEdit.tpl:186-214` — button group of the edit form (the
  edit-only branch itself is `:187-197`):
  ```smarty
  {if $user_action eq 'edit'  or $user_action eq 'do_update'}
      <input ... name="do_update" value="{$labels.btn_upd}" .../>

      {* Allow delete , just give warning *}
      <input class="{#BUTTON_CLASS#}" type="button" name="do_delete" id="do_delete"
             value="{$labels.btn_delete}"
             onclick="delete_confirmation({$gui->cfield.id},
                     '{$gui->cfield.name|escape:'javascript'|escape}',
                     '{$del_msgbox_title}','{$warning_msg}');"/>
  {/if}
  <input ... name="cancel" ... />
  ```
- `cfieldsEdit.tpl:29` → `{lang_get s='warning_delete_cf' var="warning_msg" }`, i.e.
  `locale/en_US/strings.txt:3048` →
  `$TLS_warning_delete_cf = "You are going to delete: %s <br /><br /> Are you sure?";`
  The confirm box therefore **names the field** and states the delete is irreversible.
- `gui/templates/dashio/include/inc_del_onclick.tpl:29` — `delete_confirmation(o_id,o_name,title,msg,pFunction)`
  posts `do_action=do_delete` back to the same controller.
- `lib/cfields/cfieldsEdit.php:61-67` dispatches `case 'do_delete'` → `doDelete()` at
  `lib/cfields/cfieldsEdit.php:395-413`: `get_by_id()` → `cfield_mgr->delete()` →
  `logAuditEvent(TLS("audit_cfield_deleted",$cf['name']),"DELETE",$id,"custom_fields")`.

The modern assign screen ported only the *update* half of that legacy entry point:

- `gui/templates/cfields/cfieldsAssignView.html:165-168` — the `#cfModal` footer held **only**
  `common.cancel` and `common.save` (`saveCf()`).
- `cfieldsAssignView.html:456` / `:490` — `renderAssigned()` / `renderAvailable()` render
  the name as `onclick="editCf(<id>)"`; that modal was the row's *only* interaction.
- Measured before the change: `grep -n "delete\|Delete" gui/templates/cfields/cfieldsAssignView.html`
  → **no match**. No button, no handler, no request. The sibling list screen
  `gui/templates/cfields/cfieldsView.html:409-425` had 4 matching lines / 7 occurrences of
  `delete`, so the capability was assumed to "live on the list screen" — which is exactly why a
  user reaching a field through *Assign* could not remove it.

## What `delete()` actually destroys (why the warning matters)

`lib/functions/cfield_mgr.class.php:1448-1473`:

```php
function delete($id)
{
    if( $this->is_used($id) )        { $this->remove_all_scopes_values($id); }   // 1451-1454
    $linked_tprojects = $this->get_linked_testprojects($id);
    ... foreach($target as $tproject_id) { $this->unlink_from_testproject($tproject_id,(array)$id); }  // 1455-1463
    $sql="DELETE FROM {$this->tables['cfield_node_types']} WHERE field_id={$id}";    // 1465
    ... $sql="DELETE FROM {$this->tables['custom_fields']} WHERE id={$id}";          // 1469
}
```

`remove_all_scopes_values()` (`:2592-2603`) blind-deletes the rows of
`cfield_design_values`, `cfield_build_design_values`, `cfield_execution_values` and
`cfield_testplan_design_values`. The unlink loop touches **every** linked test project, not just the
current one. Legacy's generic warning said none of this, so the modern flow states it explicitly —
the issue's "with in-use confirmation" requirement, and wording the test suite verified is literally
true.

## Implementation

### 1. Delete button in the edit modal — `cfieldsAssignView.html:166`

```html
<button type="button" id="btnDeleteCf" class="btn-red" onclick="deleteCfFromModal()"
        style="font-size:13px;float:left;" data-i18n="common.delete">Delete</button>
```

Placed in the `#cfModal` footer and floated left, so the order reads **Delete | Cancel | Save** —
the legacy group was **Update | Delete | Cancel** (`cfieldsEdit.tpl:186-214`). Measured: Delete at
`x=99`, Cancel at `x=530`, Save at `x=600`. It reuses the `.btn-red` Dashio class already declared
in the screen's own stylesheet (`:22`) and the shared `common.delete` label — the same convention
as the `.btn-red` Delete in `gui/templates/plans/planMilestones.html:190` — instead of a
per-screen duplicate key. `float:left` is correct here: the screen loads **Bootstrap 3.4.1**
(`:8`, `:178`), whose `.modal-footer` is `display:block`; it would be ignored on BS4/5 (flex).

### 2. Module state — `cfieldsAssignView.html:190-191`, set in `editCf()` at `:276-277`

```js
var editCfIsUsed = false;
var editCfName = '';
...
editCfIsUsed = !!cf.is_used;
editCfName = cf.name;
```

`is_used` was **already on the wire** (`api/cfields/index.php:105`, `cfToJSON()`), previously used
only to lock Type/Node Type on used fields. Reusing it means the confirmation needs **no extra
request**.

### 3. `deleteCfFromModal()` — `cfieldsAssignView.html:353-382`

* Builds the confirmation from `cfa.msg.confirmDelete`, which carries the whole sentence *and* a
  `{name}` placeholder (same shape as legacy `warning_delete_cf`). The name is interpolated through
  `TLi18n.t(key, {name: name})` — `i18n.js` replaces via a function, so a name containing `$&` or
  backticks is safe — instead of concatenating a raw name into an English `"…"?` tail, so the
  quotes and question mark stay inside each translation.
* Appends `cfa.msg.confirmDeleteInUse` when `is_used` is set — exactly the condition under which
  `delete()` wipes stored values, so the warning matches the real blast radius.
* On confirm: `DELETE /api/cfields/{id}` (`$.ajax`, existing endpoint). The **whole modal footer**
  is disabled while the request is in flight — not just Delete — because a Cancel click would
  dismiss the modal and park the error in a hidden `#modalError`, and a Save click would `PUT` a
  row that is being deleted. Then the modal is hidden, `cfa.msg.deleted` is toasted and the existing
  `load()` runs, which rebuilds **both** tables, the two counters and the footer info.
* On failure: the server message is shown in `#modalError` and the modal stays open, so nothing is
  swallowed. (`saveCf()` shows its errors the same single-place way.)

### 4. i18n — 4 new keys in **all 10** bundles

`cfa.msg.confirmDelete`, `cfa.msg.confirmDeleteInUse`, `cfa.msg.deleted` in
`en, ro, de, es, fr, it, pt, ru, ja, zh`. The button label reuses the pre-existing `common.delete`
(the repo convention for a `.btn-red` Delete in a modal footer, see `planMilestones.html:190`)
rather than adding a per-screen duplicate. They are inserted as **flat dotted keys** in their
alphabetical slot, matching the bundles' convention (the repo does not nest these groups), so each
file is `+3` lines with no reformat. Every file validated with `python3 -m json.tool`.

## Verification

Test suite: `tmp/TLU_Test_Cases.md` → *Task — Issue #1003* (14 cases, all PASS).

Measured end-to-end on a fixture where `deployment` was linked to **two** test projects and held a
design value + a test-plan-design value:

| Measurement | Before | After delete |
|---|---|---|
| `custom_fields` | 2 | **1** |
| `cfield_node_types` | 2 | **1** |
| `cfield_design_values` | 1 | **0** |
| `cfield_testplan_design_values` | 1 | **0** |
| `cfield_testprojects` (9001 + 9002) | 2 | **0** |
| `events` audit | — | `Custom field 'deployment' deleted` · `DELETE` · `custom_fields` |

UI after the delete, on the same live page without a reload: modal closed, toast
`Custom field deleted`, assigned table `[]` with its empty state shown, available table still holds
the surviving field, footer recomputed `1 / 2` → `0 / 1`, Delete button re-enabled.

Regression matrix (all executed, all PASS): in-use field, unused field, **declined** confirm (no
request, field survives), both tables reloaded, button re-enabled, survivor still editable,
i18n completeness ×10, `node --check` syntax gate, browser console clean, `events` table free of
new Error/Warning rows (every new row is `log_level = 16` = `tlLogger::AUDIT`,
`lib/functions/logger.class.php:54`; `ERROR = 1`, `WARNING = 2`).

## Notes for the next agent

- `cfield_mgr::get_all()` (`lib/functions/cfield_mgr.class.php:945`, INNER JOIN at `:961-968`) INNER-JOINs
  `cfield_node_types`, and `cfield_testprojects` is keyed on `field_id`. A `custom_fields` row
  without a matching `cfield_node_types` row is **invisible to the whole BFF** — a fixture that
  omits it produces empty tables and an `editCf()` 404, which looks like a product bug but is not.
- The freshly imported DB ships **0** custom fields and **0** test projects; the fixture SQL is in
  the test suite.
- The list screen `cfieldsView.html` keeps its own trash-icon delete; both now call the same BFF
  route, so behaviour is consistent and only the entry points differ (as in legacy).
