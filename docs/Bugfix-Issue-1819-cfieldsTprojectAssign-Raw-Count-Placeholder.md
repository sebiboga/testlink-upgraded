# Bugfix — Issue #1819: cfieldsTprojectAssign.html Assign/Unassign toast printed the raw `{count}` placeholder

| | |
|---|---|
| **Issue** | [#1819](https://github.com/sebiboga/testlink-upgraded/issues/1819) |
| **Area** | Custom Fields — Test Project (`gui/templates/cfields/cfieldsTprojectAssign.html`) |
| **Symptom** | After a successful **Assign** the green toast read literally `{count} custom field(s) assigned.`; the **Unassign** toast read `{count} custom field(s) unassigned.` The user is never told how many fields moved. |
| **Impact** | Misleading feedback on the two primary actions of the screen. Low severity (no data loss), but the count is the only confirmation the user gets. |
| **Fix commit** | `3128139a4` (`{ count: intOf(r.count) }` in both `.done()` handlers) — already on `origin/sebiboga`; this run verified, regression-tested and documented it |
| **Files** | `gui/templates/cfields/cfieldsTprojectAssign.html:434,463` |

## Symptom

Reproduced with the pre-fix revision served unchanged from the docroot
(`git show 3128139a4^:gui/templates/cfields/cfieldsTprojectAssign.html > tmp/issue1819_prefix.html`),
fixture `php tmp/fixtures_1816.php`, logged in `admin/admin`:

1. Open `/tmp/issue1819_prefix.html?tproject_id=5`.
2. Tick one row under *Available custom fields* → **Assign**.
3. The toast reads `{count} custom field(s) assigned.` — the placeholder is never substituted.

Measured:

```json
{"toast":"{count} custom field(s) assigned."}
```

The BFF answered the very same call with the count, so the data was there to use:

```
POST /api/cfieldstproject/index.php?action=assign   {"tproject_id":5,"ids":[14]}
200  body: {"status":"ok","count":1,"tproject_id":5}
```

## Root cause

`TLi18n.t(key, params)` only substitutes `{name}` placeholders when a params object is passed —
`gui/templates/i18n/i18n.js:180`:

```js
function t(key, params) {
  var str = _strings[key] || key;
  if (params && typeof params === 'object') {          // <- no params => no substitution
    $.each(params, function(k, v) {
      str = str.replace(new RegExp('\\{' + k + '\\}','g'), function(){ return v; });
    });
  }
  return str;
}
```

The bundle string itself is correct (`gui/templates/i18n/en.json:713`):
`"cfpa.msg.assigned": "{count} custom field(s) assigned."`

Pre-fix, both write handlers dropped the AJAX response and called the translator with no params
(`cfieldsTprojectAssign.html:425` and `:453`):

```js
// pre-fix (3128139a4^)
}).done(function() {
  toast(t('cfpa.msg.assigned'), true);     // -> "{count} custom field(s) assigned."
```

So the `{count}` literal survived to the toast. The sibling `saveAll()` already interpolated correctly
(`t('cfpa.msg.saved', { count: n })`), which is why the bug was easy to miss on a code read.

## Fix

Pass the BFF's `count` straight into the translator on both success paths — the issue's own suggestion:

```js
}).done(function(r) {
  toast(t('cfpa.msg.assigned', { count: intOf(r.count) }), true);
  release();
  load();
}).fail(...);
```

and the same for `cfpa.msg.unassigned` in the confirm-dialog handler. `intOf()` guards a missing/`NaN`
`count` down to `0`. Landed as commit `3128139a4`.

Alternatives rejected:

| Option | Verdict |
|---|---|
| Interpolate server-side and return a ready-made sentence | These messages are client-side `TLi18n` bundle strings shared with the locale switcher; building them in PHP would duplicate i18n and break the locale toggle. |
| A `TLi18n.t` fallback that strips unknown `{...}` when no params are passed | Masks genuine bugs (a missing param would silently print gibberish) and hides the failing call site — rejected. |

No PHP change and no i18n bundle change were needed.

## Verification (live, chrome-devtools MCP)

| Action | Toast / modal | Result |
|---|---|---|
| Assign (post-fix) | `1 custom field(s) assigned.` | interpolated, screenshot `issue-1819-postfix-count-interpolated.png` |
| Unassign → confirm modal | `Unassign 1 selected custom field(s) from this test project?` | interpolated |
| Unassign (confirmed) | `1 custom field(s) unassigned.` | interpolated |
| Save display attributes | `N change(s) saved.` / `No changes to save.` | unchanged, still correct |
| Re-assign an already-linked field | BFF `409 already_assigned` → `Those custom fields are already assigned to this test project.` | no raw placeholder |

Event Viewer / `events`: **34** rows, **all `log_level=16` (INFO/audit)**, **0** Error/Warning before and after.

## Testing it yourself

```bash
php tmp/fixtures_1816.php     # prints CFP Main=<id> and the cfield ids
```

Log in `admin/admin`, open
`http://localhost:8082/gui/templates/cfields/cfieldsTprojectAssign.html?tproject_id=<CFP Main id>`,
tick an available field and press **Assign** — the toast must read `1 custom field(s) assigned.`
(not `{count} ...`). Repeat with a linked field → **Unassign**.

Regression suite: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1819`, TC-1819-01 … TC-1819-09,
**9/9 PASS**; `TLU_REQUIRE_SUITE="Issue #1819" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
