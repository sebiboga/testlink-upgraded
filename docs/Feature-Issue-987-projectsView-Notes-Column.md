# Feature — Issue #987: Notes (description) column + API-id tooltip in `projectsView.html`

**Status:** implemented, verified, pushed
**Issue:** [#987](https://github.com/sebiboga/testlink-upgraded/issues/987) — *Implement Notes (description) column in projectsView list (gap vs legacy)*
**Branch:** `task/issue-987`
**Test suite:** `tmp/TLU_Test_Cases.md` → *Suite 987* (16/16 PASS)
**Follow-up to:** [#986](https://github.com/sebiboga/testlink-upgraded/issues/986) — whose §5 *Out of scope* explicitly deferred this work here.

## 1. The gap

Issue #986 rebuilt the grid and listed what it deliberately left out. Two legacy
list decorations were still missing from `gui/templates/projectsView.html`:

| Legacy source | Capability | Modern state before this change |
|---|---|---|
| `dashio/project/projectView.tpl:88` | `<th {#NOT_SORTABLE#}>{$labels.th_notes}</th>` — a **Notes** column header | header had 7 `<th>`, no Notes |
| `projectView.tpl:113-115` | the Notes cell: `{if $gui->editorType == 'none'}{$testproject.notes\|nl2br}{else}{$testproject.notes}{/if}` | dropped entirely — the description was only visible inside the edit modal |
| `projectView.tpl:104` | `<i class="fas fa-cubes" style="cursor: help" title="API {$tlCfg->api->id_format\|replace:"%s":$testproject.id}"></i>` on the name cell | dropped — the name cell was a bare `<strong>name</strong>` |

Measured before the change (Chrome, `evaluate_script` on
`/gui/templates/projectsView.html`):

```json
{"ths":["ID","Project Name","Prefix","Issue Tracker","Code Tracker","Status","Actions"],
 "rowCount":4,
 "firstRow":["#6","Fixture Alpha","AL","none","none","Active","Info\n Edit\n Deactivate\n Delete"]}
```

**No BFF change was needed.** `api/projects/index.php:88` already selects
`tp.notes` in `projectSelect()` and `:115` already serialises it as
`'description' => $row['notes'] ?? ''` in `formatProject()`. The data reached the
browser and was thrown away by the row builder, so this is a **front-end-only**
change with no API contract impact.

## 2. What was implemented

### 2.1 The Notes column

* `gui/templates/projectsView.html:151` — new header
  `<th data-col-filter="1" data-i18n="proj.notes">Notes</th>`, placed directly
  after `Project Name`, exactly where legacy puts it (`projectView.tpl:88,89`).
  It carries `data-col-filter="1"`, so it is one of the legacy
  `{#SMART_SEARCH#}` columns and gets a per-column filter box from
  `buildColumnFilters()` (#986).
* `notesCell(p)` (`:425-431`) renders the value:

  ```js
  function notesCell(p) {
    const notes = (p.description || '').trim();
    if (!notes) {
      return `<span class="col-notes" aria-label="${TLi18n.t('proj.notes')}"></span>`;
    }
    return `<span class="col-notes" title="${esc(notes)}">${esc(notes)}</span>`;
  }
  ```

  * `esc()` on both the text and the `title` — legacy trusted the DB
    (`{$testproject.notes}` raw inside a `<td>`), the modern grid escapes
    everywhere. Verified: a note containing `<b>markup</b>` renders as literal
    text, no `<b>` element is created.
  * `.trim()` because `api/projects` can hand back `''`/`null`; the empty branch
    gets the `-` placeholder from CSS.
* **Multi-line parity.** Legacy runs the notes through `nl2br` when the project
  editor is `none`. The modern equivalent is
  `white-space: pre-line` on the cell, which preserves the author's newlines
  without injecting `<br>` into the DOM.
* **Truncation.** `:106-113` clamps the cell to 2 lines
  (`-webkit-line-clamp: 2`, `max-width: 260px`, `overflow: hidden`) and puts the
  full text in the `title` attribute, so one 2 KB note cannot blow up a row
  height while the legacy content stays fully readable. The `-` placeholder for
  an empty project is `td.col-notes > .col-notes:empty::before` — the selector
  has to target the inner span, because the `<td>` is never `:empty`.
* `columnDefs` gained `{ targets: 2, className: 'col-notes' }` so the class lands
  on the `<td>` of exactly that column.

### 2.2 The API-id tooltip

* `apiIdFormat(projectId)` (`:418-420`) returns `testproject/<id>`, mirroring
  1.9.20's `tlCfg->api->id_format` (`%s/%s`, service/resource) with the
  project service name, and legacy's `formatStringId()` in `lib/functions.php`.
* `projectRow()` prepends the legacy cube icon to the name cell (`:373-376`):

  ```js
  const apiIcon = `<i class="fas fa-cubes project-api-icon" title="API ${esc(apiIdFormat(p.id))}"></i>`;
  ```

  Styled `color: #95a5a6; cursor: help; margin-right: 6px` — the `cursor: help`
  comes from legacy's inline `style="cursor: help"`.

### 2.3 Realignment forced by the new column

Inserting a column shifted every index to its right, so two hardcoded numbers
had to move (this is the class of bug a new column always introduces):

* the loading placeholder `colspan="7"` → `colspan="8"` (`:161`)
* `columnDefs`: `{ targets: 6, orderable: false }` (actions) → `targets: 7`,
  and the new `{ targets: 1, className: 'project-name-col' }` / `{ targets: 2,
  className: 'col-notes' }`

Regression-checked after the shift: the **Actions** column is still the
non-sortable one, the Edit modal still opens with the description pre-filled,
and the Info popup (`projectInfoView.html?tproject_id=6`) still opens.

## 3. i18n

New key `proj.notes` in **all 10** bundles, inserted in alphabetical position
after `proj.noProjectsHint`:

| locale | value | locale | value |
|---|---|---|---|
| en | Notes | pt | Notas |
| de | Notizen | ro | Notițe |
| es | Notas | ru | Заметки |
| fr | Notes | zh | 备注 |
| it | Note | ja | 備考 |

A **separate** key from the existing `proj.description` ("Description") was
required: that one labels the *edit modal* field, whereas legacy's list header is
`$labels.th_notes` = "Notes". Reusing `proj.description` would have relabelled
the modal as well.

Each bundle was inserted line-wise (not re-serialised) so the diff is one line
per locale — these files are shared with concurrent CI agents.

## 4. Gotchas found while implementing

1. **`testprojects.api_key` is `UNIQUE` with a *shared* default value**
   (`0d8ab81dfa2c77e8235bc829a2ded3edfa2c78235bc829a27eded3ed0d8ab81d`). A fixture
   INSERT that omits the column succeeds once and then dies with
   `Duplicate entry '0d8ab81d…'` on the second row. Every seeded project needs
   its own `api_key`.
2. **`nodes_hierarchy` in 2.0.1 has `node_type_id`, not legacy `nodetype`** — the
   same 1.9.20-era fixture SQL does not run here.
3. **`esc()` must cover the `title` attribute too**, otherwise a note containing
   a double quote breaks out of the attribute. `esc()` is applied to both.
4. **`:empty` does not match the `<td>`** when the cell content is wrapped in a
   `<span>` (needed for the clamp). First attempt rendered no placeholder for
   empty projects; measured `::before` content was `"none"` until the selector
   was moved to `> .col-notes:empty::before`.
5. **The global search listens on `keyup`, not `input`** (`:757`), so a scripted
   `input` event produces a false "search does not work" FAIL — it must be
   dispatched as `KeyboardEvent('keyup')` (same trap as #986 gotcha 4).

## 5. Verification

Suite `tmp/TLU_Test_Cases.md` → *Suite 987*: **16/16 PASS**, over 4 fixtures
(2-line note with markup, short note, empty note, 320-byte note).

| Check | Measured |
|---|---|
| header | `["ID","Project Name","Notes","Prefix","Issue Tracker","Code Tracker","Status","Actions"]` |
| multi-line note | `"Main regression project.\nSecond line of notes <b>with markup</b> & an ampersand."` |
| newline preservation | computed `white-space: pre-line` |
| escaping | `hasRealBold: false` |
| full text in `title` | identical to the stored note |
| truncation | `clientHeight 46` vs `scrollHeight 166`, `-webkit-line-clamp: 2` |
| empty note | `::before` content `"-"` |
| API tooltip | `API testproject/6` … `API testproject/9` |
| Notes column filter `one-liner` | `["#7"]` → cleared → all 4 |
| Notes sort asc / desc | `["#8","#6","#7","#9"]` / `["#9","#7","#6","#8"]` |
| global search `Gamma` / `regression` | `["#8"]` / `["#6"]` (notes text is searchable) |
| Actions sortability | no `aria-sort` (still `orderable: false`) |
| Edit modal | opens "Edit Test Project", description pre-filled |
| Info popup | opens `projectInfoView.html?tproject_id=6` |
| console | no `error` / `warn` |
| Event Viewer | `events` unchanged — only the login audit row (`log_level 16`) |

Screenshots: `docs/screenshots/issue-987-projectsview-before.png` (7-column
list) and `docs/screenshots/issue-987-projectsview-after.png` (Notes column +
cube tooltips).

## 6. Still open in this area

* **#988** — requirement-feature quick toggle in the projectsView list
  (legacy `projectView.tpl:118-124`).
* **#990** — API key display in the projectsView edit modal.
