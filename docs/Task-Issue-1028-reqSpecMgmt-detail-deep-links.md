# Task #1028 — Spec / requirement detail deep links in Requirement Specification Management

**Issue:** [#1028 — Implement spec/requirement detail navigation from reqSpecMgmt (gap vs legacy)](https://github.com/sebiboga/testlink-upgraded/issues/1028)
**Status:** implemented, verified, pushed on `task/issue-1028`
**Touched screen:** `gui/templates/requirements/reqSpecMgmt.html`
**BFF:** unchanged — `api/reqspec/index.php` and `api/requirements/index.php` already had
everything the feature needs.

## What legacy 1.9.20 did

Every node of the requirement-specification tree carried an `href` produced by
`render_reqspec_treenode()`:

- `lib/functions/treeMenu.inc.php:2086-2110`

```php
$js_functions = array('testproject'    => 'TPROJECT_REQ_SPEC_MGMT',
                      'requirement_spec' => 'REQ_SPEC_MGMT',
                      'requirement'      => 'REQ_MGMT');
...
$node['href'] = "javascript:{$js_functions[$node_type]}({$node_id});";
```

- `gui/javascript/testlink_library.js:1213-1224` — `REQ_SPEC_MGMT(tproj_id, id)` navigated to
  `fRoot + req_spec_manager_url + "?item=req_spec&tproject_id=…&req_spec_id=…"`.
- `gui/javascript/testlink_library.js:1234-1244` — `REQ_MGMT(tproj_id, id)` navigated to
  `fRoot + req_manager_url + "?item=requirement&tproject_id=…&requirement_id=…"`.

`req_spec_manager_url` / `req_manager_url` are `lib/requirements/reqSpecView.php` and
`lib/requirements/reqView.php`. So in legacy:

| clicked node | screen opened | what it showed |
|---|---|---|
| requirement **spec** | `reqSpecView.php` | header, scope, type, author/editor, custom fields, attachments, revision history, **Freeze** / **Create Revision**, all requirements of the spec |
| **requirement** | `reqView.php` | all versions, per-revision attributes, custom fields, attachments, linked test cases, relations, monitoring |

Both are `mgt_view_req` gated.

## The gap in 2.0.1

`reqSpecMgmt.html` reimplemented the tree as a Dashio DataTable, but the node `href` contract was
never ported. Measured on the rendered DOM before the fix:

```
spec rows (6):  links = ["selectSpec(2) -> javascript:void(0)", …]     # only the inline expander
req rows  (3):  links = ["openReqEdit(14) -> javascript:void(0)"]      # only the inline editor
document.documentElement.innerHTML.indexOf('reqSpecView.html') === -1
document.documentElement.innerHTML.indexOf('reqView.html')     === -1
```

`reqSpecView.html` and `reqView.html` were **not missing** — they existed, already parsed
`?id=…&tproject_id=…` (`reqSpecView.html:309-310`, `reqView.html:328-331`) and were already fed by
the complete BFF actions `spec_view` / `freeze_spec` / `create_revision` and `/view`. The missing
piece was only the **entry point**.

## The implementation

Pure front-end port; the BFF was not modified (`git diff --stat api/` is empty).

`gui/templates/requirements/reqSpecMgmt.html`

| line | change |
|---|---|
| 59-62 | `.view-btn` — teal (`#4ECDC4`) icon-link style, the same visual language as the existing `.edit-btn` |
| 455-458 | new `canView` flag, documented as the `mgt_view_req` mirror of the legacy gate |
| 520 | `canView = !!r.rights.view;` in `loadOptions()` — the BFF already returned `rights.view` |
| 898-919 | `viewSpecBtn(id)` / `viewReqBtn(id)` helpers |
| 824-836 | spec row action cell: eye link prepended to new-child / edit / delete |
| 975-988 | requirement row action cell: same treatment |

```js
function viewSpecBtn(id) {
  return '<a class="view-btn" title="' + esc(TLi18n.t('rs.viewSpecDetail')) +
         '" href="/gui/templates/requirements/reqSpecView.html?id=' +
         encodeURIComponent(id) + '&tproject_id=' + encodeURIComponent(tprojectId) +
         '" target="_self"><i class="fas fa-eye"></i></a>';
}
```

Design decisions:

- **Front-end only.** Both detail screens and both BFF actions were already complete; the issue's
  own SUGGESTED FIX describes exactly this entry point.
- **The eye is a real `href`, not an `onclick`.** Legacy used a real `href` too, so middle-click /
  "open in new tab" / copy-link work exactly as they did in 1.9.20.
- **`target="_self"`** — the screen navigates its own frame, the same convention as the existing
  `goImportReqs()` / `goExportReqs()` / `goReqReorder()` helpers.
- **`tproject_id` is passed on purpose** — without it the detail screen self-resolves the project
  from the object and its own rights check would not be scoped to the project being viewed.
- **The modern inline gestures are untouched.** Clicking the spec title still expands the
  "Requirements of …" panel and clicking the requirement title still opens the inline editor; the
  eye is an *addition*, not a replacement.
- **`rights.view`, not `rights.manage`, is the gate** — legacy gated both deep links on
  `mgt_view_req`, so a read-only user still gets the detail screens (verified with a custom role 10
  holding `mgt_view_req` only: 6 spec + 3 requirement eye links, 0 edit, 0 delete).
- A defensive `fa-eye-slash` marker (with the existing `rs.viewOnly` tooltip) replaces the old
  decorative eye when neither `view` nor `manage` is granted, so no dead link can be rendered.

## i18n

Two new keys, in **all 10** locale bundles, inserted after `rs.viewOnly`:

| key | meaning |
|---|---|
| `rs.viewSpecDetail` | Open specification detail (custom fields, attachments, revision history) |
| `rs.viewReqDetail` | Open requirement detail (versions, test case links, relations, attachments) |

```bash
for f in gui/templates/i18n/*.json; do python3 -m json.tool "$f" >/dev/null; done   # 10/10 OK
```

## Verification

Test suite: `tmp/TLU_Test_Cases.md`, **Suite 1028** (17 cases, all PASS).

| # | Check | Measured |
|---|---|---|
| 1 | pre-fix DOM | only `selectSpec(id)` / `openReqEdit(id)`; `reqSpecView.html` / `reqView.html` never referenced |
| 2 | post-fix, admin | 6/6 spec rows → `reqSpecView.html?id=<id>&tproject_id=1` |
| 4 | post-fix, admin | 3/3 requirement rows → `reqView.html?id=<id>&tproject_id=1` |
| 5 | **click** the spec eye | `reqSpecView.html?id=2` → *"Requirement Specification Viewer"*, toolbar shows *Compare revisions / Freeze / Create revision* |
| 6 | **click** the requirement eye | `reqView.html?id=14` → *"Requirement Viewer"*, body shows `IDENTIFIER R1028-1`, `Version v1r1`, *Freeze this version*, *Start monitoring*, *New Revision* |
| 7 | round trip | the spec detail keeps its own "Open in Spec Management" link — not a dead end |
| 8 | read-only user (`mgt_view_req` only) | `canManage=false, canView=true`; `specViewBtns=6`, `reqViewBtns=3`, `editBtns=0`, `delBtns=0` |
| 9 | read-only, click the eye | `reqSpecView.html?id=2` renders, no write toolbar |
| 10 | rights wiring | `api/reqspec/index.php:571-574` already exposes `rights.view`; `git diff --stat api/` empty |
| 12 | i18n | 10/10 bundles valid, 2 keys each, `10 files changed, 20 insertions(+)` |
| 15 | syntax gate | `node --check` on the extracted inline script → *JS SYNTAX OK* |
| 16 | browser console | *no console messages found* |
| 17 | Event Viewer | no new Error/Warning — only `log_level=16` audit rows, no `log_level<=2` row |

Fixture: `php tmp/fixtures_1028.php` → tproject `VIEW1028` (id 1, prefix R1028) with specs
A/A1/A2/B/C/D and requirements `R1028-1..3`, plus the `ro1028readonly` and `ro1028norights` users.

## Screenshots

- `docs/screenshots/issue-1028-before-no-view-links.png` — the un-patched screen (no detail links)
- `docs/screenshots/issue-1028-after-view-links.png` — teal eye link on every spec and requirement row
- `docs/screenshots/issue-1028-spec-detail-reached.png` — the full spec detail reached by one click
- `docs/screenshots/issue-1028-readonly-view-links.png` — read-only user: eyes present, edit/delete absent
