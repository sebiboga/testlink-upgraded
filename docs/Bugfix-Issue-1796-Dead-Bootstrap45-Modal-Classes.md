# Bugfix — Issue #1796: three modernized screens carried dead Bootstrap 4/5 modal classes (`modal-xl`, `modal-dialog-centered`) so their dialogs were 600px and top-aligned

**Issue** — [#1796](https://github.com/sebiboga/testlink-upgraded/issues/1796) (label `bug`, opened
`2026-10-02T19:33:45Z` — the NEWEST open issue without a work-type label at the time of this run)
**Screens** — `gui/templates/documentation/documentation.html`,
`gui/templates/requirements/reqTcAssign.html`,
`gui/templates/requirements/reqTcBulkAssign.html`
**Component** — Bootstrap modal geometry (no PHP, no BFF endpoint, no data)
**Fix commits** — `904b0d88f` (fix) + `ed647bf89` (code-review follow-ups), branch `fix/issue-1796`
**Status** — fixed, verified error-free, regression suite **15/15 PASS**

This is the cosmetic residue of [#1683](https://github.com/sebiboga/testlink-upgraded/issues/1683),
which fixed the *functional* half of the same Bootstrap-3-vs-4/5 family on
`reqTreeReorder.html` and then asked for a repo-wide grep. That grep is what produced this
issue — and this page closes it for good.

---

## 1. Symptom

Three dialogs opened at the wrong size and never moved away from the top of the viewport.

| Screen | Dialog | Wanted (BS4/5 classes used) | Got (BS3 reality) |
|---|---|---|---|
| `documentation.html` | **View** (PDF/attachment preview) | 1140px (`modal-xl`), centred | **600px**, `margin-top:30px` |
| `reqTcAssign.html` | confirm (unlink requirement, …) | centred (`modal-dialog-centered`) | 520px, `margin-top:30px` |
| `reqTcBulkAssign.html` | confirm (bulk unlink, …) | centred | 520px, `margin-top:30px` |

The *View* dialog was the visible offender: a PDF preview squeezed into 600px and pinned
to the top. On a short window it was worse than "wrong look" — at 780x437 the dialog ran
from `top:30` to `bottom:451.6` in a 437px viewport, i.e. **15px of the dialog was below the
fold**.

Nothing was broken functionally: the dialogs open, close, are dismissible, are localised.
Bootstrap 3 ignores unknown class names with no console warning, no Event Viewer row and no
HTTP signal, which is precisely why this survived review.

## 2. Repro steps (pre-fix)

1. `http://localhost:8082/index.php` → sign in `admin` / `admin`
2. `http://localhost:8082/gui/templates/documentation/documentation.html`
3. click **View** on any PDF card (e.g. *User Manual* → `testlink_user_manual.pdf`)
4. read `#pdfModal .modal-content` — 600px wide, 30px from the top

For the confirm dialogs (the fresh test database ships no test cases to drive the click
path, so the screen's own helper is used — `confirmThen()` at `reqTcAssign.html:256`,
the function every destructive action on that screen calls):

```js
// run in the page console of reqTcAssign.html / reqTcBulkAssign.html
confirmThen('Unlink requirement', 'Remove <b>REQ-1</b> from this test case?', 'OK', function(){});
const m=document.querySelector('#confirmModal'),c=document.querySelector('#confirmModal .modal-content'),
      mr=m.getBoundingClientRect(),cr=c.getBoundingClientRect();
console.log({cls:c.parentElement.className,w:cr.width,
             vGap:[cr.top-mr.top,mr.bottom-cr.bottom]});   // -> [30, 694] pre-fix
```

## 3. Root cause

The Dashio bundle is **Bootstrap 3.4.1** (`gui/templates/dashio/lib/bootstrap/js/bootstrap.min.js:2`).
Its entire dialog vocabulary is:

| Class | Width | Where |
|---|---|---|
| `.modal-dialog` | 600px @≥768px, `margin:30px auto` | `bootstrap.css:5980` |
| `.modal-sm` | 300px | `bootstrap.css:6059` |
| `.modal-lg` | 900px | `bootstrap.css:6064` |
| `.modal-xl` | **does not exist** | — |
| `.modal-dialog-centered` | **does not exist** | — |

The three screens were authored against the BS4/5 names, so both `modal-xl` and
`modal-dialog-centered` matched no rule at all and the element degraded to the bare BS3
defaults. Verified by scanning every rule of every loaded stylesheet at runtime — no rule
matches either token.

The 25 other `modal-sm` / `modal-lg` usages across the modernized screens are **real BS3
classes** and were deliberately left untouched.

## 4. The fix — and the traps on the way

The dead tokens are removed and the intent is re-expressed in BS3-legal, per-screen scoped
rules (each modernized screen owns a page-level `<style>` block — `documentation.html:10`,
`reqTcAssign.html:10`, `reqTcBulkAssign.html:10`):

```css
/* documentation.html */
#pdfModal .modal-body   { height: 80vh; max-height: calc(100vh - 130px); }
#pdfModal .modal-dialog { display:flex; align-items:center; justify-content:center;
                          min-height:calc(100% - 20px); width:auto; margin:10px auto; }
#pdfModal .modal-content{ width: 1140px; max-width: calc(100% - 20px); }

/* reqTcAssign.html + reqTcBulkAssign.html */
#confirmModal .modal-dialog { display:flex; align-items:center; justify-content:center;
                               min-height:calc(100% - 20px); width:auto; margin:10px auto; }
#confirmModal .modal-content{ width: 520px; max-width: calc(100% - 20px); }
```

```html
<!-- before -->
<div class="modal-dialog modal-xl modal-dialog-centered">
<div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
<!-- after -->
<div class="modal-dialog">
```

Four things were measured the hard way and are worth writing down, because each one
silently defeats the obvious implementation:

1. **The width belongs on `.modal-content`, not on `.modal-dialog`.** Once the dialog is
   `display:flex`, `.modal-content` is a flex item, and a flex item with no width shrinks
   to its content — the first attempt measured **302px**, not 1140px.
2. **`margin:10px auto` on a `width:auto` block resolves to `margin:10px 0`** (auto side
   margins only act on an over-constrained box). The horizontal gutter therefore comes from
   the content's `max-width:calc(100% - 20px)`, not from the dialog's margin. Without it the
   dialog went full-bleed (765px content in a 765px dialog at a 780px window).
3. **`justify-content:center` is required, not optional.** BS3 keeps the container at 600px
   and centres *it* with `margin:auto`; the visible 520px `.modal-content` then sat at the
   container's left edge — measured `left 420 / right 500`, an 80px asymmetry.
4. **The `min-height` and the margin must agree** (`10px + (100% − 20px) + 10px = 100%`).
   With BS3's `margin-top:30px` still in force the dialog ended up 10px taller than the
   viewport and the flex centring pushed the top down: `vGap [382, 342]`.

Overflow safety: `.modal-dialog` keeps `height:auto`, so the flex line grows with the
content — a dialog taller than the viewport is never clipped at the top, and
`.modal-open .modal { overflow-y:auto }` (`bootstrap.css:5976`) keeps the scroll reachable.
The `#pdfModal .modal-body` `max-height` cap is what guarantees centring has slack on short
screens (80vh of a 437px viewport = 350px, which already exceeded the `min-height` box).

### Alternatives rejected

* **Upgrade the bundle to Bootstrap 4/5** — every modal, dropdown, DataTable and
  `$(el).modal()` call site on ~30 modernized screens is Bootstrap 3 API. Not a 3-line fix.
* **Add `.modal-xl` / `.modal-dialog-centered` shims to the shared Dashio CSS** — one file
  would fix three screens, but it re-introduces the exact dead names that #1683's grep hunts
  for, leaving the next reader (and the next grep) worse off. Screen-local rules are
  self-documenting.
* **JS positioning on `show.bs.modal`** — more code, no benefit, fights the CSS.

## 5. Verification

Measured in headless Chrome at four viewport sizes; the console was clean and the Event
Viewer (`events`) gained no Error/Warning rows (`events` = 1 row, the
`audit_login_succeeded` INFO row).

| # | Case | Before | After |
|---|---|---|---|
| 1 | `documentation.html` View, 1440x900 | `w=600`, vGap `[30,78]` | `w=1140`, hGap `[143,143]`, vGap `[54,54]` |
| 2 | `documentation.html` View, 1024x600 | 600px | `w=989` = `min(1140, window-20)`, centred |
| 3 | `documentation.html` View, 780x437 | 600px, **overflows 15px** | `w=745`, vGap `[29,29]`, `overflow=false` |
| 4 | `documentation.html` View → × → reopen | — | embed cleared by `hidden.bs.modal`, reopens centred |
| 5 | `reqTcAssign.html` confirm, 1440x900 | vGap `[30,694]` | `w=520`, hGap `[460,460]`, vGap `[362,362]` |
| 6 | `reqTcBulkAssign.html` confirm, 1440x900 | vGap `[30,694]` | `w=520`, hGap `[460,460]`, vGap `[362,362]` |
| 7 | confirm dialogs, 500x420 | hard 520px (overflows a 500px window) | `w=480`, vGap `[122,122]`, no overflow |
| 8 | Cancel / OK / × on both confirm dialogs | — | all three dismiss; the OK callback fires exactly once |
| 9 | i18n | — | unchanged; **no bundle touched** (`git diff --name-only` = 3 HTML files) |
| 10 | `grep -rn 'modal-dialog-centered\|modal-xl\|new BSS.Modal\|data-bs-dismiss' gui/templates/*/*.html` | 3 hits | **0 hits** |
| 11 | `grep -c 'modal-dialog modal-sm\|modal-dialog modal-lg'` | 25 | **25** (legit BS3 classes, untouched) |

Regression suite: `tmp/TLU_Test_Cases.md` → *Regression — Issue #1796* (R1796-1 … R1796-15),
**15/15 PASS**.

## 6. Screenshots

| | |
|---|---|
| `documentation.html` **View** dialog, before | `docs/screenshots/issue-1796-documentation-viewer-before.png` |
| `documentation.html` **View** dialog, after (1140px, centred) | `docs/screenshots/issue-1796-documentation-viewer-after.png` |
| `reqTcAssign.html` confirm, before | `docs/screenshots/issue-1796-reqtcassign-confirm-before.png` |
| `reqTcAssign.html` confirm, after (centred) | `docs/screenshots/issue-1796-reqtcassign-confirm-after.png` |
| `reqTcBulkAssign.html` confirm, after (centred) | `docs/screenshots/issue-1796-reqtcbulkassign-confirm-after.png` |

## 7. Rule of thumb for future screens

Modernized screens run on **Bootstrap 3.4.1**. For a dialog:

* size it with `.modal-sm` / `.modal-lg`, or — when you need something wider —
  an explicit `width` + `max-width:calc(100% - 20px)` on `.modal-content`;
* Bootstrap 3 has **no** vertical-centring utility — use
  `display:flex; align-items:center; justify-content:center; min-height:calc(100% - 20px);
  width:auto; margin:10px auto` on `.modal-dialog` and keep the *width* on `.modal-content`;
* drive it with `$(el).modal('show'|'hide')` and close it with
  `<button class="close" data-dismiss="modal">&times;</button>`.

A `grep -rn 'modal-xl\|modal-dialog-centered\|new BSS.Modal\|data-bs-dismiss\|btn-close'`
over `gui/templates/*/*.html` must stay empty — that is the check this issue came from.
