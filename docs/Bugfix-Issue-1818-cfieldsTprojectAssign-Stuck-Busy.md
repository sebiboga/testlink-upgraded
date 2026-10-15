# Bugfix — Issue #1818: cfieldsTprojectAssign.html a successful Assign/Unassign left `busy=true`, killing every later button

| | |
|---|---|
| **Issue** | [#1818](https://github.com/sebiboga/testlink-upgraded/issues/1818) |
| **Area** | Custom Fields — Test Project (`gui/templates/cfields/cfieldsTprojectAssign.html`) |
| **Symptom** | After one successful **Assign** (or confirm-dialog **Unassign**) the screen's `busy` mutex stayed `true` forever: **Refresh** and **Back** stayed greyed out and **Save display attributes** — which *looks* enabled — was silently dropped. The edit stays in the input, so a lost write is indistinguishable from a saved one. |
| **Impact** | Every write/navigation control on the screen (Refresh, Back, Save, Assign, Unassign) became a dead no-op after the first assign/unassign. Silent data loss: the DB never receives the Save. |
| **Fix commit** | `3128139a4` (`release()` in both `.done()` handlers) — already on `origin/sebiboga`; this run verified, regression-tested and documented it |
| **Files** | `gui/templates/cfields/cfieldsTprojectAssign.html:435,464` |

## Symptom

Reproduced with the pre-fix revision served unchanged from the docroot
(`git show 3128139a4^:gui/templates/cfields/cfieldsTprojectAssign.html > tmp/issue1818_prefix.html`),
fixture `php tmp/fixtures_1816.php`, logged in `admin/admin`:

1. Tick an *Available* row → **Assign** → the field IS linked (DB row appears), but
   `window.busy === true`, and Refresh/Back stay `disabled`.
2. Change **Display order** of an assigned row (e.g. `1` → `7`) → **Save display attributes** →
   **zero** requests to `?action=save`; the input still shows `7`; the DB keeps `display_order=1`.

Measured after the Assign:

```json
{"busy":true,
 "toolbar":[{"label":"Refresh","disabled":true},
            {"label":"Back to Custom Fields","disabled":true},
            {"label":"Save display attributes","disabled":false}],
 "linked":[3,1,2]}
```

Measured after the Save click (resource timeline):

```json
{"busy":true,"saveRequestsBefore":0,"saveRequestsAfter":0,
 "apiCalls":["action=projects","action=init&tproject_id=1","action=assign","action=init&tproject_id=1"],
 "orderValueStillInInput":"7"}
```

DB — the edit never landed:

```
field_id=1  testproject_id=1  display_order=1  location=1  active=1  required=0  monitorable=0
```

## Root cause

`guard(fn)` is the screen's single write mutex; `release()` is the only place that resets it:

```js
function guard(fn) {
  if (busy) { return; }          // <- every later action dies here
  busy = true;
  $('.toolbar button').prop('disabled', true);
  fn();
}
function release() {
  busy = false;
  $('.toolbar button').prop('disabled', false);
  $('#btnSave').prop('disabled', !STATE.linked.length);
  syncSelState();
}
```

Pre-fix, only `saveAll()` called `release()` on success. The `.done()` handler of `doAssign()` and
the one inside the `$('#confirmOk').on('click')` handler (the confirm-dialog Unassign; `doUnassign()`
itself only opens the modal) ended with `toast(...); load();` and never released, so after the first
Assign/Unassign `busy` never went back to `false`. All failure paths were already fine
(`writeError()` calls `release()`).

The failure mode is worse than "greyed out": `render()` re-enables `#btnSave`
(`$('#btnSave').prop('disabled', !STATE.linked.length)`) when the table repaints, and
`syncSelState()` re-enables Assign/Unassign — so those buttons *look* alive while `guard()` drops
their click at the `if (busy) return;` line.

## Fix

Call `release()` in the `.done()` of both handlers, **before** `load()` (so the toolbar is usable
immediately and is re-painted correctly when the re-render lands). This is the issue's own
suggestion, landed as commit `3128139a4`.

Alternatives rejected:

| Option | Verdict |
|---|---|
| Auto-release `guard()` on every settled promise (`.always()`) | Larger change to the guard contract than needed: every write path already releases explicitly — `.done()` → `release()`, `.fail()` → `writeError()` → `release()`. |
| Drop the `busy` mutex | Would allow interleaved writes to clobber each other. |

No PHP change was needed.

## Verification (live, chrome-devtools MCP)

| Action | `busy` after | Toolbar after | Request | DB / state |
|---|---|---|---|---|
| Assign (post-fix) | `false` | all enabled | `action=assign` | linked `[3,1,2]`, toast `1 custom field(s) assigned.` |
| Save display attributes (order 1→7) | `false` | all enabled | `action=save` ×1 (was ×0) | `display_order=7` |
| Refresh (idle) | `false` | — | `action=init` ×1 | repainted |
| Save, no changes | `false` | all enabled | `action=save` | toast `No changes to save.` |
| Unassign via confirm modal | `false` | all enabled | `action=unassign` | linked `[1,2]`, available `[5,4,3]`, DB row gone |

Event Viewer / `events`: **0** Error/Warning rows before and after.

## Testing it yourself

```bash
php tmp/fixtures_1816.php     # CFP Main=1 with cfields 1+2 linked; 3/4 available
```

Log in `admin/admin`, open
`http://localhost:8082/gui/templates/cfields/cfieldsTprojectAssign.html?tproject_id=1`,
Assign a field, then edit **Display order** and press **Save display attributes** — a POST must hit
`?action=save` and the DB row must change.

Regression suite: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1818`, TC-1818-01 … TC-1818-09,
**9/9 PASS**; `TLU_REQUIRE_SUITE="Issue #1818" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
