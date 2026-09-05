# Bugfix — Issue #978: tcView viewer E_WARNINGs `click_to_copy_ghost_to_clipboard` + `inc_relations` on every Test Case view

## Problem

Rendering the legacy Test Case View (`lib/testcases/archiveData.php?edit=testcase&id=N`)
logged **three E_WARNING events** to the Event Viewer on every load:

```
E_WARNING  Undefined array key "inc_relations"                 — gui/templates_c/*_0.file.tctitle.inc.tpl.php:39
E_WARNING  Attempt to read property "value" on null           — gui/templates_c/*_0.file.tctitle.inc.tpl.php:39
E_WARNING  Undefined array key "click_to_copy_ghost_to_clipboard" — gui/templates_c/*_0.file.tcbody.inc.tpl.php:105
```

The screen otherwise worked — pure log noise (`events.log_level=2`, i.e. E_WARNING), minor priority.

## Root Cause

Both warnings came from the **Dashio port** of the Test Case viewer (source commit
`4fe583e2d` "TN2023 fixes + Dashio theme from tl20"):

1. **`click_to_copy_ghost_to_clipboard` (and `tc_has_relations`) label keys never loaded.**
   `gui/templates/dashio/testcases/include/tcbody.inc.tpl:77` renders
   `{$inc_tcbody_labels.click_to_copy_ghost_to_clipboard}` and
   `gui/templates/dashio/testcases/include/tctitle.inc.tpl:21` renders
   `{$inc_tcbody_labels.tc_has_relations}`, but the label list refactored into
   `gui/templates/dashio/testcases/labels/tcViewViewer.labels.tpl` did **not** include those
   two keys. Upstream `gui/templates/tl-classic/testcases/tcView_viewer.tpl:25` fetches ALL
   of: `show_ghost_string,display_author_updater,onchange_save,...tc_has_relations,
   click_to_copy_ghost_to_clipboard,do_not_execute`. Only the Dashio label file dropped two.

2. **`inc_relations` parameter never passed to the viewer include.**
   The `tcbody.inc` include in `tcViewViewer.inc.tpl:424` (the template actually rendered via
   `tcView.tpl:147`) did not forward `args_relations` as `inc_relations`. Upstream
   `tl-classic/testcases/tcView_viewer.tpl:473` passes `inc_relations = $args_relations`.
   Without it, `tctitle.inc.tpl:19`'s `{if $inc_relations != ''}` read an unassigned Smarty
   variable → "Undefined array key" + "Attempt to read property value on null".

Two more latent defects surfaced once the guard was fixed (the branch through which
`tc_has_relations` renders now actually executes):

3. **`$TLS_tc_has_relations` / `$TLS_click_to_copy_ghost_to_clipboard` missing from the
   English locale.** Only `pt_PT`/`pt_BR` had the strings. `lang_get()` therefore emitted
   log_level 32 L18N notices and showed the raw key as the tooltip in `en_GB`.
4. **`tlImages.relations` icon key missing.** `getImageSet()` in
   `lib/functions/tlsmarty.inc.php` dropped the `relations` key during the FontAwesome
   migration (commit `0bda70cdd`); the old mapping was `relations => asterisk_yellow.png`.

### Blast radius

- The 3 E_WARNINGs fired on **every** Test Case view in the `archiveData.php?edit=testcase`
  flow (current + other versions), i.e. all test specification browsing.
- `tcStepEdit.tpl` (step-edit screen) includes the same `tcbody.inc.tpl`/`tctitle.inc.tpl`
  and would fire the `inc_relations` + label warnings as well — but that screen has
  **additional pre-existing, unrelated** warnings (`inc_tcbody_cf=null`, `$stepInfo` null when
  invoked with a malformed URL), so it is documented, not part of this fix.
- The `relations` icon key was referenced by exactly one template: `tctitle.inc.tpl:22`.

## Fix

| File | Change |
|---|---|
| `gui/templates/dashio/testcases/labels/tcViewViewer.labels.tpl` | added `click_to_copy_ghost_to_clipboard` + `tc_has_relations` to the `{lang_get s=...}` list |
| `gui/templates/dashio/testcases/include/tcViewViewer.inc.tpl` | added `inc_relations=$args_relations` to the `tcbody.inc` include |
| `gui/templates/dashio/testcases/tcView_viewer.tpl` | same `inc_relations=$args_relations` added (upstream-twin consistency) |
| `locale/en_GB/strings.txt` | added the two English strings |
| `lib/functions/tlsmarty.inc.php` | reinstated `relations` icon (`fa-sitemap`) in `getImageSet()`/`getFontAwesomeIcon()` |

**Why this method:** it is the minimal, faithful restoration of the upstream tl-classic
behaviour that was lost in the Dashio port: pass the relations data through and load the
labels that the include templates reference. No PHP logic change was needed — the fix is
entirely in template/label wiring plus one icon + two strings.

**Alternatives rejected:** (a) guarding `{if isset($inc_relations)}` in `tctitle.inc.tpl`
would hide the bug and suppress the relations jump icon permanently — worse UX than
upstream; (b) changing `tcbody.inc.tpl` to use `copy_ghost_string` instead of
`click_to_copy_ghost_to_clipboard` would diverge from upstream/l10n keys and leave the
`tc_has_relations` label broken; (c) a PHP-side `$tlImages` fallback would mask the missing
icon instead of restoring it.

## Testing / Evidence

- **Primary symptom (before):** `archiveData.php?edit=testcase&id=3&tproject_id=1` →
  events ids 7-9 = the three E_WARNINGs above.
- **Primary symptom (after):** same request → **0 events** (`events` table shows only the
  baseline INFO audit rows).
- Repeated 3× reload after clearing `gui/templates_c` → still 0 events.
- Rendered HTML verified: relations jump icon
  `<span class="clickable" title="The test case has relations. Click to jump to the relations section" onclick="...relations_4..."><i class="fa fa-sitemap">`,
  ghost copy icon `title="Click to copy the ghost string to clipboard"`.
- `php -l` clean on `tlsmarty.inc.php`.
- Event Viewer clean: no new ERROR/WARNING/L18N attributable to the fix.

## Regression Test Case

Suite `Regression — Issue #978` (5/5 PASS) appended to `tmp/TLU_Test_Cases.md` — primary
symptom (warnings → zero), relations icon, ghost-copy tooltip, repeated-load stability,
Event Viewer accounting.