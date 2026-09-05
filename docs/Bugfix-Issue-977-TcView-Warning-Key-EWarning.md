# Bugfix — Issue #977: tcView.tpl `E_WARNING Undefined array key "warning"` on every Test Case view

## Problem

Rendering the legacy Test Case View (`lib/testcases/archiveData.php?edit=testcase&id=N`)
always logged an `E_WARNING` into the Event Viewer / `events` table:

```
E_WARNING Undefined array key "warning"
  - in gui/templates_c/*_0.file.tcView.tpl.php - Line 69
```

The warning is cosmetic (the screen renders fine) but pollutes the Event Viewer
on **every** Test Case view.

## Root Cause

`gui/templates/dashio/testcases/tcView.tpl` builds its label map with a Smarty
`lang_get` call:

```smarty
{lang_get var='labels'
          s='no_records_found,other_versions,show_hide_reorder,version,title_test_case,match_count,actions,
             file_upload_ko,warning_estimated_execution_duration_format '}
```

`lang_get_smarty` (`lib/functions/lang_api.php:194-212`) splits the `s` list on
commas and sets **one array key per listed string**. The template then reads
`{$labels.warning|escape:'javascript'}` at `tcView.tpl:39` (compiled to
`$labels->value['warning']` at `file.tcView.tpl.php:69`) — but `warning` was
*not* in the list, so the key was never set → `E_WARNING Undefined array key`.

Note: the issue's original hypothesis that `$TLS_warning` is missing from the
locale was **incorrect** — the translation string exists
(`locale/en_US/strings.txt:217` → `$TLS_warning = "Warning";`). The defect was
only the omitted key in this template's list.

The directly-analogous screen `gui/templates/dashio/testcases/tcNew.tpl:10`
(uses the same `labels.warning` + `labels.warning_estimated_execution_duration_format`
combo) does include `warning` in its list — confirming `tcView.tpl` simply
omitted it.

## Fix

**File:** `gui/templates/dashio/testcases/tcView.tpl` — **one line** added.
Prepend `warning,` to the `lang_get` `s` list:

```smarty
{lang_get var='labels'
          s='warning,no_records_found,other_versions,show_hide_reorder,version,title_test_case,match_count,actions,
             file_upload_ko,warning_estimated_execution_duration_format '}
```

No PHP changed, no i18n bundle changed (the string already exists in the locale
files). Smarty recompiles the template on the next render.

## Files Changed

| File | Change |
|---|---|
| `gui/templates/dashio/testcases/tcView.tpl` | add `warning,` to `lang_get` labels `s` list (1 line) |
| `docs/screenshots/issue-977-tcview-fixed.png` | post-fix Test Case view render (new) |

## Testing / Evidence

Commit `6633cb6db` on branch `fix/issue-977`.

- **Before:** rendering `archiveData.php?edit=testcase&id=3` once added
  `E_WARNING Undefined array key "warning" ... file.tcView.tpl.php:69` to
  `events` (event id 3 in the fresh repro; measured `events` count 2 → 6 from a
  single load).
- **After:** same URL re-rendered → **no new `warning`-key event**. Compiled
  template regenerated with `s=>'warning,no_records_found,...,warning_estimated_execution_duration_format'`
  and the JS `alert_box_title` variable is populated (`"Warning!!"`, the locale
  value) instead of the PHP warning.
- **Blast radius:** `{$labels.warning}` is used across ~80 templates, each of
  which must declare `warning` in its own `lang_get s` list. This fix covers the
  `tcView.tpl` screen (issue scope). Three *other* warnings observed on the same
  render (`inc_relations`, `value on null`, `click_to_copy_ghost_to_clipboard`)
  are distinct root causes tracked separately in issue **#978** — not touched here.
- **Event Viewer:** no new Error/Warning caused by this fix.

## Regression Test Case

Suite `Regression — Issue #977` (4/4 PASS) appended to `tmp/TLU_Test_Cases.md`:
tcView render produces no `warning`-key event, compiled labels list contains
`warning`, scoped Event Viewer query returns zero rows, and the screen still
renders fully.
