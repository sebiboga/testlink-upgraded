# Bugfix — Issue #1829: `reqTreeReorder.html` — the edge-row Up/Down tooltips leaked the raw i18n KEY instead of a string

**Number:** #1829
**Status:** Fixed (verified — reproduced pre-fix in the DOM, root-caused to `TLi18n.t()`'s own missing-key behaviour defeating the call site's `||` guard, fixed with the two keys added to all 10 bundles plus an `has()`-based helper, pinned with a 12-case regression suite)
**Component:** `gui/templates/requirements/reqTreeReorder.html`, `gui/templates/i18n/*.json`
**Area:** Requirements / Specification Tree Reordering — i18n
**Branch:** `fix/issue-1829`
**Related:** #1824 (the same screen; the neighbouring i18n gap was spotted during its mandatory code review and deliberately filed out of it), #1821 (same class of i18n-affordance defect), #1804 (added `pointer-events: none` to the disabled `.rm` buttons — see *Known limitation*), #1681 (the screen itself)

## Symptom

The per-row **Up / To top** and **Down / To bottom** buttons of
`gui/templates/requirements/reqTreeReorder.html` carry a tooltip that explains *why* the
direction is unavailable. Those tooltips contained the **raw i18n key**:

```
reqTreeReorder.html?tproject_id=1&req_spec_id=2   (locale en)
  row 1  up   title="reqtr.alreadyFirst"
  row 1  top  title="reqtr.alreadyFirst"
  row 3  down title="reqtr.alreadyLast"
  row 3  bottom title="reqtr.alreadyLast"
```

The issue as filed expected the English fallback sentence *"Already the first/last
requirement"* to be what the user saw. That expectation was **corrected during
investigation**: the fallback was unreachable dead code, so the user saw neither
English nor a translated string — only `reqtr.alreadyFirst` / `reqtr.alreadyLast`.

This was **locale-independent**: switching to `&locale=ro_RO` or `&locale=de_DE` left
the four titles unchanged while every other string on the screen was translated.

## Root cause

`gui/templates/i18n/i18n.js:175`:

```js
function t(key, params) {
  var str = _strings[key] || key;   // missing entry -> the KEY, which is truthy
  …
```

`t()` **never** returns `undefined` or `''` for a missing bundle entry — it returns
the key string. The call site in `btn()` was written as if it did:

```js
// gui/templates/requirements/reqTreeReorder.html:414,417 (pre-fix)
title = t('reqtr.alreadyFirst') || 'Already the first requirement';
title = t('reqtr.alreadyLast')  || 'Already the last requirement';
```

Because the left operand is always truthy, the `||` branch was unreachable, so:

1. the guard silently **masked the omission** — a missing key and a translated key
   both render *something* non-empty, hence no error, no console warning and no
   `events` row to catch it;
2. the **English literal was dead code**, i.e. a hardcoded user-visible string
   (a violation of rule 3 of `ai/AGENTS.md`);
3. the key reached the DOM `title` via `reqTreeReorder.html:420-422`
   (`if (title) b.attr('title', title);`).

And the two keys genuinely did not exist: `grep -c 'reqtr\.' gui/templates/i18n/*.json`
returned **53 in all ten bundles** — an identical set in every locale, none of them
`reqtr.alreadyFirst` / `reqtr.alreadyLast`.

### Why no verification step caught it

The module ships the correct existence check, `TLi18n.has()`
(`gui/templates/i18n/i18n.js:261`), but this call site did not use it. The dead
`|| 'English literal'` made the missing key indistinguishable from a translated one:
both produced a non-empty tooltip, so a manual pass over the screen could not
surface the gap without reading the attribute in the DOM or the a11y tree.

## Fix

**1. The two keys now exist in all ten bundles**, inserted at the alphabetical slot
the bundles already keep (between `reqtr.actions` and `reqtr.applyOrder`), so each
bundle diff is exactly **+2 / −0** lines:

| bundle | value |
|---|---|
| en | Already the first/last requirement |
| ro | Deja este prima/ultima cerință |
| de | Bereits die erste/letzte Anforderung |
| es | Ya es el primer/último requisito |
| fr | Déjà la première/dernière exigence |
| it | Già il primo/l’ultimo requisito |
| pt | Já é o primeiro/último requisito |
| ru | Уже первое/последнее требование |
| ja | すでに最初/最後の要件です |
| zh | 已经是第一个/最后一个需求 |

Terminology was matched to each bundle's own word for "requirement" (de
*Anforderung*, fr *exigence*, ro *cerință*, ja *要件*, zh *需求*, …) rather than
machine-translated in isolation.

**2. The unreachable literals were deleted** and the lookup routed through the
module's own existence check — the pattern already used in
`cfieldsTprojectAssign.html:174`, `cfieldsView.html:179` and `installView.html:173`:

```js
// TLi18n.t() echoes the KEY for a bundle entry that does not exist
// (gui/templates/i18n/i18n.js:175), so a plain `t(key) || 'English literal'`
// never falls through: it renders the raw key and the literal stays dead code.
// has() is the module's own existence check - when the active bundle lacks the
// entry the tooltip is omitted instead of leaking the key or an untranslated
// string. Refs #1829.
function edgeTitle(key) {
  if (!TLi18n.has(key)) { return ''; }
  var v = t(key);
  // has() is hasOwnProperty, t() is `_strings[key] || key`: an entry present but
  // blank (there are such entries in the bundles) still resolves to the KEY, so
  // guard the value too - that is the leak this helper exists to prevent.
  return (v && v !== key) ? v : '';
}
```

The second guard is the one the mandatory code review of this fix added: `has()`
answers "does the entry exist", but `t()` answers "what is the value **or the key**",
so a key that is present with a **blank** value would re-open exactly the leak this
helper exists to close. The 10 new entries are non-empty, so it is defensive.

The surrounding guards are unchanged: non-edge rows still get **no** `title`
attribute, and an unavailable translation now degrades to *no tooltip* rather than to
a leaked key or an untranslated literal.

## Verification

Live, in headless Chrome, with the fixture `php tmp/fixtures_1681.php`
(`tproject=1`, `req_spec_id=2`, 3 requirements `TR1-1..TR1-3`):

| # | case | expected | measured | verdict |
|---|------|----------|----------|---------|
| 1 | **en**, row 1 Up + To top | translated tooltip | `Already the first requirement` | PASS |
| 2 | **en**, last row Down + To bottom | translated tooltip | `Already the last requirement` | PASS |
| 3 | **ro_RO**, same 4 buttons | Romanian | `Deja este prima cerință` / `Deja este ultima cerință` | PASS |
| 4 | **de_DE**, same 4 buttons | German | `Bereits die erste/letzte Anforderung` | PASS |
| 5 | middle row, all 4 buttons | no `title` | `title: null` × 4 | PASS |
| 6 | view-only user `tr1681readonly` | all disabled, no key leak | all `aria-disabled="true"`, `rawKeyLeak: []` | PASS |
| 7 | `TLi18n.has()` forced `false` + `render()` | tooltip omitted | `tips: []`, `leak: []`; restoring `has()` brings the 4 tooltips back | PASS |
| 8 | `t()` forced to echo the key (blank value) | tooltip omitted | `tips: []`, `leak: []` | PASS |
| 9 | reorder still functional (admin) | order changes, dirty chip appears | `[6,8,10]` →Down→ `[8,6,10]` (chip `true`) →To top→ `[10,8,6]`; `#discardBtn` restores `[6,8,10]` | PASS |
| 10 | JSON validity of all 10 bundles | valid | `python3 -m json.tool` → OK × 10 | PASS |
| 11 | Event Viewer / `events` table | no new Error/Warning | 4 rows, all `log_level = 16` (audit: project created + 3 logins); 0 Error/Warning, 0 LOCALIZATION | PASS |
| 12 | `node --check` on the screen's inline script | OK | OK | PASS |

The a11y tree now exposes `description="Already the first requirement"` on the
disabled row-1 Up / To-top buttons and `"Already the last requirement"` on the
last-row Down / To-bottom buttons.

## Known limitation — the tooltip is not hover-visible

`reqTreeReorder.html:54`:

```css
.rm.dis, .rm:disabled { opacity: .35; cursor: not-allowed; pointer-events: none; }
```

This rule (added by `4f074a2b5` for #1804) removes the disabled arrow buttons from
the hover chain, so the browser's native `title` bubble is **not** raised by hovering
with a mouse — measured, `document.elementFromPoint()` over the disabled Up button
returns `TD.act`, not the button. The fix is therefore observable through the
**DOM `title` attribute and the accessibility tree**, which is where the defect was
reproduced, but not as a mouse hover bubble.

This is a separate defect class (a `pointer-events` policy on disabled affordances
that silently suppresses their explanatory tooltips) and was **filed as its own bug**
rather than folded into this fix. Assert on the attribute / a11y tree, not on hover.

## Files changed

| file | change |
|---|---|
| `gui/templates/i18n/en.json` … `zh.json` (10 files) | + `reqtr.alreadyFirst`, + `reqtr.alreadyLast` (+2/−0 each) |
| `gui/templates/requirements/reqTreeReorder.html` | + `edgeTitle()` helper; the two `t(key) \|\| 'English …'` lines replaced; the hardcoded literals deleted |
| `CHANGELOG` | one `### KEY BUGFIX — #1829` entry |
| `docs/Bugfix-Issue-1829-reqTreeReorder-Edge-Row-Tooltips-Leak-i18n-Key.md` | this page |
| `docs/screenshots/issue-1829-reqtr-already-first-last-tooltip.png` | post-fix screen state |
| `tmp/TLU_Test_Cases.md` | suite `## Regression — Issue #1829` (12 cases, append-only) |

## Regression suite

`tmp/TLU_Test_Cases.md` → `## Regression — Issue #1829: reqTreeReorder.html edge-row Up/Down tooltips leaked the raw i18n KEY`
— precondition, pre-fix repro, expected post-fix behaviour, the 12-case table above,
the `pointer-events` known limitation and a RESUME block. Gate:

```
TLU_REQUIRE_SUITE="Issue #1829" bash ai/verify_test_suites.sh
  PASS  no suite lost vs merge-base with origin/sebiboga (= 0)
  PASS  no line removed from the suite file vs merge-base (= 0)
  PASS  own suite heading present (Issue #1829)
  GATE EXIT=0
```

## RESUME

```bash
php tmp/fixtures_1681.php
# http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2
# then, in the console:
#   [...document.querySelectorAll('button.rm[title]')].map(b => b.getAttribute('title'))
#   -> ["Already the first requirement", …, "Already the last requirement"]
python3 -m json.tool gui/templates/i18n/en.json > /dev/null   # 10/10 bundles
```

No API change, no schema change, no PHP change — the fix is 20 lines of JSON plus a
6-line helper.