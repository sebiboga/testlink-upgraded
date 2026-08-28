# Bugfix — Issue #631: Dashio shell has no workframe frame — legacy `target="workframe"` links/forms open stray tabs

**Date:** 2026-08-28 · **Branch:** `fix/issue-631` · **Commit:** (see issue #631 `Fixes` PR)

## Summary

Modernized screens run inside the single-content-pane Dashio shell, but legacy screens loaded in the mainframe still carry classic two-pane navigation markers: links and forms with `target="workframe"`, and JavaScript that writes to `parent.workframe.location`. The Dashio shell registers no frame named `workframe`/`treeframe`, so browsers open these navigations in **new browser tabs** instead of the content pane, and legacy JS threw a `TypeError` dereferencing the missing frame. A shell-level shim now retargets those navigations onto the mainframe while leaving the classic layout untouched.

## Symptom

- Login → open any legacy report screen (e.g. `lib/results/resultsNavigator.php?format=0&tproject_id=1&tplan_id=6`) in the Dashio mainframe.
- Click any report link (first anchor carries `target="workframe"`, href `lib/results/resultsGeneral.php?...`).
- **Bug:** the report opens in a NEW browser tab; the mainframe still shows the navigator.
- Console: `Uncaught TypeError: Cannot set properties of undefined (setting 'location')` from `resultsNavigator.tpl:86`.

## Root Cause

- The classic layout builds a nested frameset via `lib/general/frmWorkArea.php` → `gui/templates/dashio/frmInner.tpl` which declares real `<frame name="treeframe">` and `<frame name="workframe">` (`frmInner.tpl:26-27`).
- The modernized shell `gui/templates/dashio/main.tpl` only declares `titlebar`, `asidebar`, `mainframe`.
- Per the HTML spec a `target="_top"`/named target resolves through *named browsing contexts*; a target naming a non-existent frame makes the browser open a new top-level window/tab.
- Affected code paths (verified by grep):
  - `target="workframe"` links: `resultsNavigator.tpl:72`, `reqSpecSearchForm.tpl:25`, `requirements/reqSearchForm.tpl:30`, `testcases/tcSearchForm.tpl:25`, `search/searchForm.tpl:25`, `searchForm.tpl:24`
  - `parent.workframe` JS: `resultsNavigator.tpl:15-16,20,86`, `plan/planTCNavigator.tpl:65,77,100`, `plan/planAddTCNavigator.tpl:83`, `include/inc_filter_panel_js.tpl:111,125`
- **Why now:** the Dashio shell is the modern layout; nothing re-mapped the legacy `workframe`/`treeframe` target onto the single `mainframe` content pane.

## Fix

Two layers, both minimal:

### 1. Shell shim `gui/javascript/frameTargetShim.js` (new)

Loaded once in `main.tpl` before `</body>` (`{$basehref}gui/javascript/frameTargetShim.js`). It binds **capture-phase** `click` and `submit` listeners into the mainframe's content document — on load and on every subsequent mainframe `load` event — and dedupes the binding per **Document** (`__tlFrameShimBound` on the document, precisely so a fresh document after navigation is re-bound; a Window-scoped marker would wrongly persist across navigations).

For each candidate `a[target]` / `form[target]` the shim resolves whether a named browsing context with that name actually exists anywhere up the ancestor chain (`hasNamedBrowsingContext(name, docWin)` — walks each window's `name` and `frames[name]`, treating cross-origin as *exists* so the shim never intercepts then). Only when NO such frame exists:

- `workframe`/`treeframe` target → the `target` attribute is **removed**.
- The browser's default action then navigates the document that owns the element — the mainframe, the single content pane.

When a real frame matches (the classic `frmWorkArea`/`frmInner.tpl` layout), the attribute is left alone and native frame resolution is preserved unchanged.

**Why removeAttribute instead of rewriting the target?** Dropping the attribute makes the navigation land in the element's own browsing context (the mainframe) with zero page-rewriting logic; rewriting to `mainframe` would break when the same template is served inside the classic nested layout. Only links/forms whose target names a truly-missing frame are touched.

**Safety rails:** ignores default-prevented events and click chords (ctrl/alt/shift/meta, non-left buttons); only `workframe`/`treeframe` are candidates; CSRF guard is unaffected (TestLink's server-side `csrfguard_replace_forms` only injects hidden inputs *inside* forms — untouched by attribute removal).

### 2. `resultsNavigator.tpl` hardening

- `reportPrint()` and `pre_submit()` now guard `parent["workframe"]` / `parent.workframe` with a null-check; in the flat shell the print becomes a no-op and `called_url` falls back to `window.location.href` instead of throwing.
- The initial-load `parent.workframe.location='{$gui->workframe}'` block is wrapped in `if (parent && parent.workframe)`.

## Approach — alternatives considered and rejected

1. **Patch every template's target** — huge blast radius across ~9 templates plus generated JS; would break classic layout which still legitimately uses the frames. Rejected.
2. **Register a real `workframe` frame in the shell** — would reintroduce a hidden second pane in an intentionally single-pane shell; layout and iframe weight for nothing. Rejected.
3. **Shell-document-only listeners** — empirically, browser `click` events do not cross the frame boundary, so a pure parent-document listener never sees mainframe clicks; `submit` events do cross. Hence the shim binds **into the mainframe document** (both click and submit), plus harmless shell-document fallbacks. This is the method chosen for #1 above.

## Files Changed

- `gui/javascript/frameTargetShim.js` — **new**; shell shim (document-scoped binding, named-browsing-context resolution, click+submit retargeting).
- `gui/templates/dashio/main.tpl` — includes the shim script before `</body>`.
- `gui/templates/dashio/results/resultsNavigator.tpl` — guards on `reportPrint()`, `pre_submit()`, initial `parent.workframe.location`.

## Verification

Full browser regression suite in `tmp/TLU_Test_Cases.md` (**Regression — Issue #631**, cases 631.1–631.6):

- Flat shell: report link → opens in mainframe, **no new tab**; repeated navigations keep retargeting (document-scoped rebind); format-select form submit → mainframe; Print → no TypeError.
- Classic layout (`frmWorkArea.php?feature=showMetrics`): report still opens in the right `workframe` pane, treeframe/mainframe unchanged, no new tab — shim does not intercept.
- Event Viewer: no new Error/Warning rows from the fix lifecycle.

![Flat shell — report opened inside the mainframe]…see tmp/wiki-repo screenshots
![Classic layout — workframe resolution preserved]…

**Known pre-existing (out of scope):** cosmetic `404` for `gui/themes/dashio/images/navbar.gif` referenced by the dashio theme CSS — present before this fix, unrelated to frame targets.