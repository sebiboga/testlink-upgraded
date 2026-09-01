# Bugfix: Issue #789 — execHistory shows the step number for step-bound linked bugs (regression gap)

## Summary

The #789 feature (GitHub bug **filing/linking** from the modernized **Execute
Tests** screen) already worked end-to-end: a tester can link an existing bug or
create a new one at case level (`tcstep_id = 0`) or bound to a specific step
(`tcstep_id = <step>`), and the execution history shows the linked bugs. The one
remaining gap on the history side was that `execHistory.html` rendered every
linked bug identically — a bug bound to step 2 looked exactly like a case-level
bug, because **no step number was shown** for step-bound bugs.

## Legacy Behavior

- `lib/functions/exec.inc.php` persists `execution_bugs(execution_id, tcstep_id,
  bug_id)` — `tcstep_id = 0` means the bug is linked to the execution (case)
  level; a non-zero value binds it to that test-step id.
- The legacy history/report surfaces show which test step a bug is bound to, so
  a step-bound bug is distinguishable from a case-level one.

## Root Cause

1. `api/execute/index.php` `?action=history` (the endpoint backing
   `execHistory.html`) already emitted `tcstep_id` **and** a resolved
   `step_number` per bug in the `bugs` array — so the data needed to show the
   step was present.
2. `gui/templates/execute/execHistory.html` `renderDetail()` (the Bugs block)
   only rendered `(b.link_to_bts ? b.link_to_bts : esc(b.id))` inside each
   `.bug-item`. It never read `b.step_number`, so step-bound bugs carried no
   visual step marker and were indistinguishable from case-level bugs.

## Fix

**`gui/templates/execute/execHistory.html`:**
- In `renderDetail()`'s `e.bugs.forEach(...)`, build an optional
  `stepMark` string: when `b.step_number > 0`, append a
  `<span class="bug-step-mark">` containing the already-translated
  `exe.bugOnStepShort` (`· st. {n}`) label; otherwise empty. Append `stepMark`
  after the bug's link inside the `.bug-item` div.
- Added one CSS rule `.bug-item .bug-step-mark { color:#8a8f98; font-weight:400;
  margin-left:4px; }` so the marker reads as a subdued, inline annotation.
- No new i18n keys: `exe.bugOnStepShort` is present in all 10 locale bundles
  (en/ro/de/fr/es/it/pt/ja/ru/zh, all `python3 -m json.tool` valid) — the same
  key used by the per-step bug badges in `execTest.html`.

**Verified rendering (fresh fixtures, admin/admin):** with `execution_bugs`
containing `#789`→tcstep_id=0, `#788`→tcstep_id=22, `#802`→tcstep_id=22, the
rendered `#detail_0` HTML shows:
- Bug #789 (case-level, `step_number=0`): link with **no** `bug-step-mark`. ✓
- Bug #788 and #802 (step 2): link + `<span class="bug-step-mark">· st. 2</span>`. ✓

Everything else (filing, linking, modal, case/step target lines, persistence) was
already verified by TC-386 and is unchanged by this fix.

## Files Changed

- `gui/templates/execute/execHistory.html` — `renderDetail()` bugs loop +
  `.bug-step-mark` CSS rule (minimal additive change).

## Out-of-scope observation (pre-existing)

Rendering these executions whose `link_to_bts` embeds the **full raw GitHub issue
body** (escaped `<br>`, backticks, quotes) inside the anchor triggers a console
`SyntaxError: Failed to execute 'appendChild' on 'Node': Unexpected identifier
'events'` — a jQuery `.html()` parser fragility on the server-generated anchor
content. It reproduces **identically on the unmodified HEAD file** (verified by
temporarily restoring HEAD) and is **not** introduced by this fix; it also does
not stop the bug links / step markers from rendering. Tracked as out of scope for
#789.
