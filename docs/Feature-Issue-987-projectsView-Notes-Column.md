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

* New header `<th data-i18n="proj.notes">Notes</th>`, placed directly after
  `Project Name`, exactly where legacy puts it (`projectView.tpl:88,89`).
  Legacy marks that header `{#NOT_SORTABLE#}`, so the column is deliberately
  **neither sortable nor per-column filterable** — no `data-col-filter="1"`,
  and `columnDefs` gives it `orderable: false` alongside ID and Actions.
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
  editor is `none`. The modern equivalent is `white-space: pre-line`, which
  preserves the author's newlines without injecting `<br>` into the DOM.
* **Truncation.** The cell is clamped to 2 lines (`-webkit-line-clamp: 2`,
  `max-width: 260px`, `overflow: hidden`) and the full text goes in the `title`,
  so one 2 KB note cannot blow up a row height while the legacy content stays
  fully readable. **The clamp must live on the inner `<span>`, not on the
  `<td>`**: `display: -webkit-box` on a `<td>` cancels the table-cell layout and
  silently drops the `vertical-align: middle` that
  `#projectsTable.dataTable tbody td` sets, which measurably misaligned the whole
  column by 13px against every other cell. The `<td>` only carries
  `max-width`/`min-width`; the span carries the clamp and the
  `span.col-notes:empty::before { content:'-' }` placeholder (the `<td>` is never
  `:empty`, so the placeholder has to target the span).
* `columnDefs` gained `{ targets: 2, className: 'col-notes', orderable: false }`
  so the class lands on the `<td>` of exactly that column.

### 2.2 The API-id tooltip

* Legacy `projectView.tpl:104` renders
  `title="API {$tlCfg->api->id_format|replace:'%s':$id}"`, and
  `config.inc.php:637` sets `$tlCfg->api->id_format = "[ID: %s ]"` — so legacy
  shows `API [ID: 6 ]`.
* `projectRow()` follows the **established Dashio pattern** already used by the
  two modernized siblings that carry the same icon
  (`gui/templates/plans/buildsView.html:308-309`,
  `gui/templates/platforms/platformsView.html:254-255`):

  ```js
  const apiIcon = '<i class="fas fa-cubes api-id" title="' +
    esc(TLi18n.t('proj.apiId').replace('{id}', p.id)) + '"></i>';
  ```

  with the shared `.api-id { cursor: help; color: #999; font-size: 12px; margin-right: 6px; }`
  rule (same declaration as `buildsView.html:31` / `platformsView.html:31`) and
  `proj.apiId = "[ID: {id}]"`, mirroring the existing `pl.apiId = "[ID: {id}]"`.
  The `cursor: help` comes from legacy's inline `style="cursor: help"`.

### 2.3 Realignment forced by the new column

Inserting a column shifted every index to its right, so two hardcoded numbers
had to move (this is the class of bug a new column always introduces):

* the loading placeholder `colspan="7"` → `colspan="8"` (`:161`)
* `columnDefs`: `{ targets: 6, orderable: false }` (actions) → `targets: 7`,
  and the new `{ targets: 2, className: 'col-notes', orderable: false }`

Regression-checked after the shift: the **Actions** column is still the
non-sortable one, the Edit modal still opens with the description pre-filled,
and the Info popup (`projectInfoView.html?tproject_id=6`) still opens.

## 3. i18n

New keys in **all 10** bundles, each inserted in alphabetical position:

| key | value | note |
|---|---|---|
| `proj.notes` | en/de/es/fr/it/ja/pt/ro/ru/zh: Notes / Notizen / Notas / Notes / Note / 備考 / Notas / Notițe / Заметки / 备注 | real translation, legacy `$labels.th_notes` |
| `proj.apiId` | `[ID: {id}]` in all 10 | **not** translated: it is the format string of `config.inc.php:637` `$tlCfg->api->id_format = "[ID: %s ]"`, exactly like the existing `pl.apiId = "[ID: {id}]"` |

A **separate** key from the existing `proj.description` ("Description") was
required: that one labels the *edit modal* field, whereas legacy's list header is
`$labels.th_notes` = "Notes". Reusing `proj.description` would have relabelled
the modal as well.

Each bundle was inserted line-wise (not re-serialised) so the diff is one line
per key per locale — these files are shared with concurrent CI agents. All 10
files re-validated with `python3 -m json.tool`; each has exactly one `proj.notes`
and one `proj.apiId`.

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
5. **The global search listens on `keyup`, not `input`**, so a scripted `input`
   event produces a false "search does not work" FAIL — it must be dispatched
   as `KeyboardEvent('keyup')` (same trap as #986 gotcha 4).
6. **Code review caught four real defects in the first implementation** (all
   fixed before landing):
   a. `display: -webkit-box` on the `<td>` broke `vertical-align: middle` and
      measurably misaligned the column by 13px — the clamp belongs on the span.
   b. The tooltip was hardcoded English `"API "` (i18n rule 3) and used a
      one-off `project-api-icon` class instead of the shared `.api-id`.
   c. The first tooltip value `API testproject/6` was **wrong**: legacy
      `config.inc.php:637` `id_format` is `"[ID: %s ]"`, so legacy shows
      `API [ID: 6 ]`. The code comment also cited a `formatStringId()` in
      `lib/functions.php` — **neither the function nor the file exists in this
      repo**; the comment was fabricated and has been removed.
   d. The column was made sortable + per-column filterable, but legacy marks
      the Notes header `{#NOT_SORTABLE#}` — a false parity claim in the first
      CHANGELOG entry and docs, corrected to match legacy.

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
| truncation | span `clientHeight 30` vs `scrollHeight 225`, `-webkit-line-clamp: 2` |
| empty note | span `::before` content `"-"` |
| escaping (adversarial) | note `"><img src=x onerror=alert(1)>` produced 0 `<img>` elements, no attribute breakout |
| API tooltip | `[ID: 6]` … `[ID: 9]` (legacy `id_format`) |
| Notes **not** sortable / not filtered (legacy `NOT_SORTABLE`) | no `aria-sort` on the th, `cursor: auto`, filter inputs stay at **5** (the Notes header has no `data-col-filter`) |
| vertical alignment | every `<td>` of the row measured at `top 307 / height 57` — Notes no longer offset |
| other columns still filterable | per-column filter inputs 5, prefix/name/status filters unaffected |
| Notes sort asc / desc (regression: the *other* columns still sort) | name sort re-verified, `aria-sort` present on the sortable headers |
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
