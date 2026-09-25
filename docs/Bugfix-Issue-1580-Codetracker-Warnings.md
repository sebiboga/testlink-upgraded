# Bug fix — Issue #1580: legacy Code Tracker list emitted PHP warnings and dropped pagination/label values

## Symptom

The legacy Dashio Code Tracker list returned HTTP 200, but every populated render
logged PHP 8.3 Error/Warning events. The page-length selector had no options and
the delete icons had blank tooltips.

Entry point: `http://localhost:8082/lib/codetrackers/codeTrackerView.php`.

## Reproduction before the fix

1. Authenticate as `admin` with Code Tracker management rights.
2. Open the entry point for test project 2.
3. Inspect the page-length selector, the two unlinked tracker rows' delete icons,
   and the `events` table.

Measured pre-fix result:

- Event IDs 2-4 reported the missing `codeTrackerView` config object, missing
  `pagination`, and missing `length` on null.
- Event IDs 5-6 reported `Undefined array key "testproject_alt_delete"`, one for
  each unlinked tracker rendered.
- The page-length selector had zero options.
- Both delete tooltips were empty.

## Root cause chain

1. `gui/templates/dashio/codetrackers/codeTrackerView.tpl` loads
   `input_dimensions.conf` through `TLSmarty::display()`.
2. The template then read the pagination length through
   `$tlCfg->gui->{$cfg_section}->pagination->length`, but no `codeTrackerView`
   object exists in `config.inc.php` or the loaded input-dimensions file.
3. PHP therefore produced three chained warnings and assigned no page length.
4. Before the tracker loop, the template requested only
   `$labels.alt_delete`.
5. The delete icon read `$labels.testproject_alt_delete`, a different key that
   was never loaded, producing one missing-key warning for every unlinked tracker
   and an empty tooltip.

The `codeTrackerView` section does contain
`pagination_length = 20` in `gui/templates/conf/input_dimensions.conf`, so the
value was already loaded correctly; only the access path was wrong.

**Why it breaks now:** the defect was latent in the Dashio theme introduced by
commit `4fe583e2d094ebe692dac13fd4c384c2322036fa`. PHP 8 converts the missing
property and array-key diagnostics into Event Viewer warnings.

**Blast radius:** a template/config audit found the same invalid config-property
pattern in `codeTrackerView.tpl`, `issueTrackerView.tpl`, and
`platformsView.tpl`. Only the two contracts covered by #1580 were changed. The
`issueTrackerView.tpl` sibling remains out of scope for this issue.

## Fix approach

The template was corrected at the two bad consumers without changing the
controller, configuration, database, or language bundles:

- `gui/templates/dashio/codetrackers/codeTrackerView.tpl:26` now consumes the
  value already supplied by the Smarty configuration loader with
  `{$ll = #pagination_length#}`.
- `gui/templates/dashio/codetrackers/codeTrackerView.tpl:77` now reads the
  already-requested generic label with `{$labels.alt_delete}`.

This mirrors the working legacy Code Tracker template and the established
`#pagination_length#` idiom used by other Dashio screens.

### Alternatives considered and rejected

- **Add a fabricated `$tlCfg->gui->codeTrackerView` object:** this duplicates
  configuration state and would hide the mismatch between the loaded Smarty
  configuration and the template access path.
- **Use a Smarty default such as `|default:20`:** a fallback could suppress the
  warning but would silently ignore configured values. The configured value must
  be consumed directly.
- **Request `testproject_alt_delete` in addition to `alt_delete`:** this would
  load another translation key and retain a screen-specific name. The generic
  key requested by this template is the correct one and is already available.
- **Suppress warnings or alter global error reporting:** this leaves the empty
  pagination value and tooltip behavior unchanged.
- **Refactor sibling templates in the same commit:** issue #1580 is limited to
  the reproduced Code Tracker contract; unrelated refactoring would enlarge the
  regression surface.

## Verification

- Forced Smarty recompilation; the generated Code Tracker PHP passed `php -l`.
- Inspected the generated PHP: it reads `_getConfigVariable(...,
  'pagination_length')` and `labels['alt_delete']`.
- Two cache-bypassing populated requests returned HTTP 200 and rendered all
  three tracker rows.
- The page-length select contained the configured option `20`.
- Both unlinked rows had `title="delete"`; the linked row had no delete icon.
- An empty-list matrix removed and restored both tracker tables. The page
  returned HTTP 200 with its heading and Create button, no tracker rows, page
  control, or icons, and no new Error/Warning event.
- A Romanian-locale pass resolved `alt_delete`; expected level-32 localization
  fallback events were recorded, but the PHP Error/Warning delta was zero.
- A temporary global `codetracker_view` user received HTTP 200 with management
  controls hidden and no PHP Error/Warning event. A separate pre-existing
  DataTables column-contract error was isolated as issue #1582, not included in
  this fix.
- Event Viewer rendered with all ten network requests returning HTTP 200. Its
  summary was `ERROR: 0`, `WARNING: 5`; all five warnings were the retained
  pre-fix rows. The database query for `id > 6 AND log_level IN (1,2)` returned
  zero rows.
- Final fixture state: three trackers, exact project link `(2,3)`, admin locale
  `en_GB`, and the temporary view-only user removed.
- Regression suite in `tmp/TLU_Test_Cases.md`: 7/7 PASS for issue #1580.

The fix intentionally produces no visual redesign, so a duplicate before/after
image would not add evidence. The retained screenshot shows the corrected
`delete` tooltip and the populated page-size control; the probative comparison is
the measured DOM and Event Viewer delta documented above.

## Files changed

- `gui/templates/dashio/codetrackers/codeTrackerView.tpl` — corrected config
  value and label key access.
- `tmp/TLU_Test_Cases.md` — issue #1580 regression suite and result.
- `docs/screenshots/issue-1580-after.png` — corrected-state evidence.
- `CHANGELOG` and this documentation mirror.

## Result

The warning-producing PHP path is removed for manager and view-only Code Tracker
list permissions. On the manager path, configured pagination and the delete label
resolve correctly and empty-list behavior remains intact. On the view-only path,
management controls stay hidden and no PHP warning is generated, but the separate
DataTables column-contract defect still leaves its page-size control empty. That
independent read-only defect is tracked as #1582; no new Error/Warning event is
generated by this fix.
