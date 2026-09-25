# Issue #1298 — reqView.html: relation management UI restored (gap vs legacy)

Modernized screen: **Requirement Viewer** (`gui/templates/requirements/reqView.html`)
backed by `api/requirements/index.php`. Refs
[#1298](https://github.com/sebiboga/testlink-upgraded/issues/1298).

## The gap

Legacy TestLink 1.9.20 lets you wire requirements to each other straight from the
Requirement Viewer, in a "Relations" block
(`gui/templates/dashio/requirements/reqViewVersions.tpl:323-419`):

| Legacy control | Legacy source |
|---|---|
| "New relation" row: relation-type select + destination `req_doc_id` input + optional cross-project select + **Add** | `reqViewVersions.tpl:340-365` |
| `validate_req_docid_input()` client-side check | `reqViewVersions.tpl:110-121`, wired at `:327` |
| per-row trash icon → confirm dialog → delete | `reqViewVersions.tpl:408-419` |
| disabled-delete image with `img_title_relation_frozen` tooltip | `reqViewVersions.tpl:415-418` |
| 7-column list (`#`, type, document, status, project, set-by, delete) | `reqViewVersions.tpl:368-404` |
| `title="Created <ts> by <author>"` tooltip on the author | `reqViewVersions.tpl:404` |
| `relation_add_result_msg` feedback line | `reqViewVersions.tpl:334-338` |

Write handling lived in `lib/requirements/reqCommands.class.php`:
`doAddRelation()` (`:666-733`) and `doDeleteRelation()` (`:745-769`), both reached
by POSTing to `reqEdit.php` with a `doAction` field.

The modern screen only ported the **read** projection: a 4-column DataTable
(`Relation | Target | Project | Status`) with no action column, no add form and
**no write route at all** (`grep "segments[0] === 'relation"` on the BFF → no
match).

The read side was lossy too — `relation_id`, the author column and the creation
tooltip were dropped, and the `Project` column was rendered unconditionally even
though legacy shows it only with `interproject_linking` on.

Worst of all: `renderRelations()` **hid the whole card when the list was empty**.
Legacy renders the add row *outside* the `{if $gui->req_relations.num_relations}`
guard (`reqViewVersions.tpl:340` vs `:368`), so on a fresh requirement the modern
screen offered no way at all to create the **first** relation.

## What was implemented

### BFF (`api/requirements/index.php`)

* `GET /view` now also returns
  * `grant.req_relations_rw` — legacy `$gui->req_relations['rw'] = !$isAlien`
    (`reqView.php:97-99, 222`): relations are read-only when the screen was
    reached through a `tproject_id` that does not own the requirement,
  * `relation_config` — `enabled`, `interproject_linking`, the `types` dropdown
    (port of `requirement_mgr::init_relation_type_select()`,
    `requirement_mgr.class.php:2779-2815`) and the cross-project `projects`
    selector (port of `reqView.php::initTestprojectSelect()`),
  * `relations[].author`, `relations[].creation_ts`, `relations[].is_open` and
    `relations[].can_delete` (the legacy trash-icon gate).
* `POST /relation` — port of `doAddRelation()`. Reproduces the whole error chain
  in order: destination doc id lookup on the (optionally cross-) destination
  project → `_destination` suffix **swaps source and destination** →
  self-relation → `check_if_relation_exists()` → destination's last version must
  be open → `add_relation()`. Every branch returns the legacy `lang_get()` text
  plus a `message_key` so the client can localize it.
* `DELETE /relation` — port of `doDeleteRelation()`. The relation id must actually
  hang on the requirement being viewed, so a forged/foreign id cannot delete
  somebody else's link.
* Both verbs re-check server-side, never trusting the form: relations enabled,
  `!$isAlien` (403) and the viewed version not frozen (403). The UI gates are
  cosmetic; the server is the authority.

### Screen (`gui/templates/requirements/reqView.html`)

* "New relation" button in the Relations card title → Dashio modal with the type
  select, the destination doc-id input and the cross-project selector (shown only
  with `interproject_linking` on, like legacy).
* The table is now the 7 legacy columns; `Project` hides itself when inter-project
  linking is off. `Set by` keeps the `Created <ts> by <author>` tooltip.
* Per-row trash icon → confirm modal (`Really delete relation #N?`) →
  `DELETE /relation`. When deletion is not permitted the icon renders greyed with
  `cursor:not-allowed` and the read-only/frozen tooltip, mirroring
  `$tlImages.delete_disabled` + `img_title_relation_frozen`.
* **The card is shown whenever relations are enabled, even with zero relations** —
  the blocker that made the first relation impossible to create.

### i18n

29 new keys × 10 bundles (`de, en, es, fr, it, ja, pt, ro, ru, zh`). Translations
come from the matching legacy `locale/<L>/strings.txt` entries where they exist.
This includes `rel_add_error_dest_frozen`, which the legacy tree defines in only
**5 of its 19** locale files (`en_GB, fr_FR, ja_JP, pt_BR, pt_PT`) — a `de`,
`es`, `it`, `ro`, `ru`, `zh` or `en_US` user got the raw key printed by
`lang_get()`, and now gets a real message.

## Legacy notes worth keeping

* **The `_source` / `_destination` suffix in the dropdown value is load-bearing**,
  not a display artifact: `doAddRelation()` does
  `strpos($relType, '_destination')` to reverse the direction
  (`reqCommands.class.php:694-698`). The existing `/search` `relation_types` list
  collapses equal relations to bare numeric ids, so the viewer needs its own
  list — that is why `relation_config.types` is built separately instead of
  reusing it.
* **Requirement doc IDs are free-form strings**, not numbers. `getByDocID()`
  compares `req_doc_id` with a string `=`, and `validate_req_docid_input()` only
  rejects an empty/whitespace value or the untouched placeholder label. A numeric
  check (the natural first guess) breaks the feature on any real dataset.
* **An "equal" relation type appears once in the dropdown.** `parent of` /
  `child of` and `blocks` / `depends on` are asymmetric and get two entries;
  `related to` is symmetric and gets one, preselected.
* The `rw` gate is about **project ownership, not roles**: an admin viewing
  requirement 7 through the URL of another project gets a read-only relations
  block. That is `$isAlien`, and the modern screen reproduces it.

## Verification

25/25 cases in `tmp/TLU_Test_Cases.md` suite **976** (all PASS), covering: the
empty-state add affordance, dropdown contents, the add round-trip, the mirrored
direction seen from the other requirement, the delete confirm round-trip, all 7
legacy error branches, the `$isAlien` and frozen-version gates on both the UI and
the server, the forged-relation-id rejection, the Romanian locale, XSS escaping of
a hostile requirement title, syntax gates, and the Event Viewer.

`events` gained no new Error/Warning after the fixes; every row written afterwards
is `log_level=16` (audit).

## Related

* Coverage / test-case link management, the sibling feature: `docs/Issue-1299-reqView-Coverage-Link-Management.md`.
