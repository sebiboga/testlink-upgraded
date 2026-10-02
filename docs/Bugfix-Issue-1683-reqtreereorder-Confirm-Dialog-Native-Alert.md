# Bugfix — Issue #1683: `reqTreeReorder.html` fell back to a native `alert()` for every confirm (the Dashio bundle is Bootstrap 3.4.1, not 4/5)

**Issue** — [#1683](https://github.com/sebiboga/testlink-upgraded/issues/1683) (label `bug`, opened
`2026-09-28T05:29:45Z`, the OLDEST open issue without a work-type label at the time of this run)
**Screen** — `gui/templates/requirements/reqTreeReorder.html`
**Component** — Requirement Specification Tree move / reorder screen (ported in #1681)
**Fix commit** — `f790f7241 fix(reqtreereorder): use the Bootstrap 3 modal API - the dialog was an alert() (Refs #1681)`
**Verification branch** — `fix/issue-1683`
**Commit under test** — `47ae21765` (== `origin/sebiboga`)
**Status** — **the fix was already on the default branch and is verified error-free; the ticket was simply never closed.**
This page records the verification, the root cause and the grep that must be run whenever a new
modernized screen adds a modal.

---

## 1. Symptom

Clicking **Move requirement** or **Apply order** on
`gui/templates/requirements/reqTreeReorder.html` opened a **native browser `alert()`** instead of the Dashio
confirm dialog: a blocking, unstyled, non-translatable OS-level box with only an *OK* button. There was no
Cancel path in the dialog chrome, no dimmed backdrop, no Dashio teal/dark palette — and because the text was
hand-concatenated into `alert()`, it bypassed `TLi18n` entirely, so it never translated.

Affected actions (both destructive): moving a requirement into another specification, and saving a new
reorder — plus any `confirmBox()` error path on the same screen.

## 2. Repro steps

1. `php tmp/fixtures_1681.php` — creates a public test project with requirements enabled, two specs
   (`TR1-SPEC-A` with 3 requirements, `TR1-SPEC-B` empty) and the permission-path users.
   On a **freshly imported** database the ids are `tproject=1 specA=2 specB=4 reqs=6,8,10` (the original
   report's `13` / `14` came from a database that had been imported earlier).
2. Login `admin` / `admin` at `http://localhost:8082/index.php`.
3. Open `http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2`.
4. Click **Select** on a row, pick a target specification, click **Move requirement**.
5. **Pre-fix:** a native `alert()` appears — *"Move the requirement / Move requirement TR1-3 to TR1-SPEC-B…?"*
6. **Post-fix:** the Dashio Bootstrap modal appears, with Cancel + the primary action.

## 3. Measured evidence

### Pre-fix (state at `dcd23815a`, the screen's introducing commit)

```js
var BSS = null;                                   // declared, never assigned
...
function confirmBox(title, body, okLabel, onOk) {
  ...
  if (BSS && BSS.Modal) { new BSS.Modal(el).show(); }
  else { alert(title + '\n\n' + body); }          // <-- ALWAYS taken
}
```

```html
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="cmTitle"></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body" id="cmBody"></div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" …>Cancel</button>
```

### Post-fix — the Dashio bundle is 3.4.1

```
$ head -c 60 gui/templates/dashio/lib/bootstrap/js/bootstrap.min.js
/*!
 * Bootstrap v3.4.1 (https://getbootstrap.com/)
```

The Move dialog, read out of the live DOM after clicking **Move requirement**:

```json
{ "jquery": "3.7.1",
  "modalClasses": "modal fade in", "display": "block", "opacity": "1", "zIndex": "1050",
  "backdropPresent": true, "backdropOpacity": "0.5",
  "closeBtnMarkup": "<button type=\"button\" class=\"close\" data-dismiss=\"modal\" aria-label=\"Close\">×</button>",
  "okBtn": "<button type=\"button\" class=\"btn btn-primary\" id=\"cmOk\" style=\"font-size:13px;\">Move requirement</button>",
  "titleText": "Move the requirement",
  "bodyText": "Move requirement TR1-3 to TR1-SPEC-B - Specification B (0)?",
  "jqueryPlugin": "function" }
```

`modal fade in` plus a `.modal-backdrop` at `opacity 0.5` is the Bootstrap 3 show state. A native
`alert()` would have raised a DevTools `Page.javascriptDialogOpening` event and produced no DOM at all —
none was raised.

The **Apply order** dialog behaves identically (`modal fade in`, title `Apply the new order`, OK `Apply order`).

The dialog still **gates** the write, and the write still lands:

```json
// Cancel clicked
{ "cls": "modal fade", "display": "none", "backdrop": false }

// Down on row 1, then Apply order -> OK
{ "order0": ["TR1-1","TR1-2"], "order1": ["TR1-2","TR1-1"],
  "afterConfirm": ["TR1-2","TR1-1"],
  "msg": "The new order was saved.", "msgCls": "msg ok" }
```

```sql
-- the move really persisted
select r.id, r.req_doc_id, r.srs_id, s.doc_id as spec
from requirements r join req_specs s on s.id = r.srs_id order by r.id;

id  req_doc_id  srs_id  spec
 6  TR1-1       2       TR1-SPEC-A
 8  TR1-2       2       TR1-SPEC-A
10  TR1-3       4       TR1-SPEC-B     -- moved out of TR1-SPEC-A via the modal
```

Event Viewer / `events` table after the whole pass:

```
select log_level, count(*) c from events group by log_level;
log_level  c
16         2      -- audit_testproject_created (fixture) + audit_login_succeeded
```

**No Error (level 1) and no Warning (level 2) rows at all.**

## 4. Root cause

The Dashio bundle is Bootstrap **3.4.1** and `reqTreeReorder.html:160` loads exactly that file. Bootstrap 3
exposes the modal **only** as a jQuery plugin — every part of the v4/v5 API the screen reached for is absent:

| used by the screen | Bootstrap 3.4.1 reality |
|---|---|
| `new BSS.Modal(el)` | no `BSS` global is ever defined by the BS3 UMD bundle |
| `BSS.Modal.getInstance(el)` | added in BS 5.2 — does not exist |
| `data-bs-dismiss="modal"` | BS4/5 attribute; BS3 honours only `data-dismiss` |
| `class="btn-close"` | BS5 element; BS3 uses `<button class="close">&times;</button>` |
| `class="modal-dialog-centered"` | BS4/5; BS3 has no vertical centring for dialogs |

The chain, as introduced by `dcd23815a feat(reqtreereorder): Dashio screen for requirement-spec tree move/reorder (Refs #1681)`:

1. `var BSS = null;` — declared and never assigned; BS3 never assigns it either, so it stays `null` for the
   whole page lifetime.
2. `confirmBox()`'s guard `if (BSS && BSS.Modal) new BSS.Modal(el).show()` — the left side is `null`, so the
   guard is **always false**.
3. `else { alert(title + '\n\n' + body); }` — therefore **every** confirm on the screen took the fallback.
4. The same falsy `BSS.Modal` made the OK handler run `el.style.display = 'none'` — a raw inline style on a
   `.modal.fade` element, which would have fought the real BS3 plugin's own `display` management on the next
   open even if the dialog had worked.
5. Independently, the **markup** was v4/v5 too, so even a correct constructor would have yielded a dialog whose
   close button was inert (`data-bs-dismiss` does nothing in BS3) and no vertical centring.

**Why it was not a repo-wide problem.** `reqTreeReorder.html` was the only screen authored against the v4/v5
API. Every other modernized screen (`gui/templates/keywords/keywordsEdit.html`,
`gui/templates/requirements/reqTcAssign.html`, `reqTcBulkAssign.html`, …) already used the BS3 idiom
(`button.close` + `data-dismiss`, `$(el).modal('show'|'hide')`). It was a new-screen authoring slip at
`dcd23815a`, not an architectural defect — hence the report's own note: *"it is worth a grep when a new screen
adds a modal."*

## 5. The fix (as landed in `f790f7241`)

```diff
-  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
+  <div class="modal-dialog modal-sm"><div class="modal-content">
     <div class="modal-header"><h5 class="modal-title" id="cmTitle"></h5>
-      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
-    <div class="modal-body" id="cmBody"></div>
+      <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
+    <div class="modal-body" id="cmBody" style="font-size:13px;"></div>
     <div class="modal-footer">
-      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" data-i18n="common.cancel">Cancel</button>
-      <button type="button" class="btn btn-primary btn-sm" id="cmOk"></button>
+      <button type="button" class="btn btn-secondary" data-dismiss="modal" style="font-size:13px;" data-i18n="common.cancel">Cancel</button>
+      <button type="button" class="btn btn-primary" id="cmOk" style="font-size:13px;"></button>
@@
-var BSS = null;
@@
-    if (BSS && BSS.Modal) {
-      var inst = BSS.Modal.getInstance(el);
-      if (inst) { inst.hide(); }
-    } else { el.style.display = 'none'; }
+    $(el).modal('hide');
@@
-  if (BSS && BSS.Modal) { new BSS.Modal(el).show(); }
-  else { alert(title + '\n\n' + body); }
+  // The Dashio bundle is Bootstrap 3.4.1: the modal is a jQuery plugin, there
+  // is no BSS/bootstrap.Modal constructor and no getInstance().
+  if ($.fn && $.fn.modal) {
+    $(el).modal('show');
+  } else {
+    // No Bootstrap JS at all - never leave the user without a confirmation.
+    if (window.confirm(title + '\n\n' + body)) { onOk(); }
+  }
```

| step | why this, and not the alternative |
|---|---|
| markup → `button.close` + `data-dismiss` + `&times;`, `modal-dialog modal-sm` | matches BS3 and reuses the idiom the other modernized screens already follow, so the repo keeps **one** modal pattern |
| driver → `$(el).modal('show' \| 'hide')` | the only show/hide API BS 3.4.1 exposes; nothing else needs a polyfill |
| `var BSS` + the construction branch deleted | removes the *trap*: a `null` global can never again silently select a fallback branch. Guarding on an optional global is what made the failure invisible |
| `alert()` → `window.confirm()` | the JS-only fallback still gates the write. Rejected "just call `onOk()`": a missing confirmation must never degrade into a silent write. Rejected shipping Bootstrap 5 as well: it would break every other screen at once |

**No other change was needed**: the BFF contract (`api/reqtreereorder/index.php`), the i18n keys, the
`.modal` container attributes (`modal fade`, `tabindex="-1"`, `role="dialog"`, `aria-hidden="true"` are all
valid BS3) and every other screen were left untouched.

## 6. Blast radius

At `47ae21765`:

```
$ grep -rn 'BSS\|bootstrap\.Modal\|getInstance()\|getOrCreateInstance' gui/templates/ api/
gui/templates/requirements/reqTreeReorder.html:534:  // is no BSS/bootstrap.Modal constructor and no getInstance().

$ grep -rn 'new BSS.Modal\|data-bs-' gui/templates/*/*.html
(no output)
```

The single surviving hit is the **comment** that `f790f7241` left behind explaining *why* the v4/v5 API is not
used — not a call site.

* **1 file, 1 screen**; **2 destructive user actions** affected.
* Purely client-side — **no `api/` code was involved**, so there was no data-integrity exposure and nothing
  was written without the user having been shown *something*.

## 7. Verification

Full suite: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1683: …` (12 cases, 12 PASS). Summary:

| # | case | expected | measured | result |
|---|---|---|---|---|
| 1 | bundle version | Bootstrap 3.4.1 | `Bootstrap v3.4.1` | PASS |
| 2 | static grep for the BS4/5 JS API | no hits | no functional hits | PASS |
| 3 | screen renders | 3 rows, live toolbar | rows + context tiles populated | PASS |
| 4 | **Move requirement** → dialog | Dashio modal, no `alert()` | `modal fade in`, backdrop `0.5`, no JS dialog raised | PASS |
| 5 | close button shape | `button.close[data-dismiss=modal]` | exact markup asserted | PASS |
| 6 | **Apply order** → dialog | Dashio modal | `modal fade in`, backdrop `0.5` | PASS |
| 7 | **Cancel** gates the write | hidden, nothing written | `display:none`, no backdrop, DB unchanged | PASS |
| 8 | OK performs the write | order persisted | banner `.msg.ok` "The new order was saved." | PASS |
| 9 | DB corroboration | `TR1-3` in `TR1-SPEC-B` | `srs_id 2 → 4` | PASS |
| 10 | browser console | no errors from the screen | clean | PASS |
| 11 | Event Viewer | no new Error/Warning | only `log_level=16`; zero at level 1/2 | PASS |
| 12 | fallback with no Bootstrap JS (review) | still gated | `window.confirm(...)` → `onOk()` | PASS |

## 8. The grep — run it whenever a modernized screen adds a modal

`reqTreeReorder.html` was a **new-screen authoring error**, so the class of defect is not self-policing.
After this verification the repo-wide grep for the BS4/5 **JS** API is clean:

```
grep -rn 'new BSS.Modal\|bootstrap\.Modal\|getInstance()\|data-bs-' gui/templates/*/*.html api/
```

The same grep for **dead BS4/5 CSS classes** is *not* clean — those fail silently because Bootstrap 3
ignores unknown classes. Only `modal-xl` and `modal-dialog-centered` qualify: `modal-lg` is a **valid** BS 3.4.1
class (`bootstrap.min.css`: `.modal-lg{width:900px}`), so it must stay out of the pattern.

```
$ grep -rn 'modal-dialog-centered\|modal-xl' gui/templates/*/*.html
gui/templates/documentation/documentation.html:82:  <div class="modal-dialog modal-xl modal-dialog-centered">
gui/templates/requirements/reqTcAssign.html:167:  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
gui/templates/requirements/reqTcBulkAssign.html:134:  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
```

Exactly three screens still carry them — tracked as
**[#1796](https://github.com/sebiboga/testlink-upgraded/issues/1796)**:

* `gui/templates/documentation/documentation.html:82` — `modal-dialog modal-xl modal-dialog-centered`; measured
  on the live dialog: `width: 600px`, `margin-top: 30px`, i.e. the *View* dialog is BS3-default-width and
  top-aligned instead of 1140px and centred. Cosmetic only — the dialog is driven correctly.
* `gui/templates/requirements/reqTcAssign.html:167` and `gui/templates/requirements/reqTcBulkAssign.html:134` —
  `modal-dialog-centered` only; their width is already handled by an inline `max-width:520px`, so this is
  centring-only.

## 9. Residual risk

None introduced by `f790f7241`. The pre-existing BS4/5 CSS leftovers are cosmetic and are tracked in #1796.

## 10. Files

| file | role |
|---|---|
| `gui/templates/requirements/reqTreeReorder.html` | the screen: confirm modal markup (`:146-156`), Bootstrap driver (`:160`), `confirmBox()` (`:524-541`) |
| `gui/templates/dashio/lib/bootstrap/js/bootstrap.min.js` | the bundled **Bootstrap 3.4.1** that dictates the modal API |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1683: …` — 12 cases, 12 PASS |
| `tmp/fixtures_1681.php` | fixture (tproject 1, specs 2/4, requirements 6/8/10, permission-path users) |