# Task — Issue #1040: test case relations in the Test Case Viewer (add / delete)

## What was missing

TestLink 1.9.20 renders, **inside every version panel** of the Test Case Viewer, the relations
block from `gui/templates/dashio/testcases/include/relations.inc.tpl`, included once per
version by `gui/templates/dashio/testcases/tcView_viewer.tpl:590-596`:

* header **"Relations with other test cases (N)"** (count = rows after filtering),
* a **New relation:** row — type combo (`is parent of` / `is child of` / `blocks` /
  `depends on` / `is related to` / `Also Automates` / `Is Automated By` /
  `is executed together with`, each with a `_source`/`_destination` variant) plus an
  external-id box (`PREFIX-ID`) and an **Add** button,
* a table **`# / Type | Test Case | Set by | (icon)`** with one row per relation, a link to
  the related case (`EXTID: name [Version N]`), the author (tooltip *Created {ts} by {who}*),
  and either a **trash icon** (delete) or a **warning icon** carrying the reason the relation
  cannot be deleted,
* an `Ext.Msg.confirm` modal — **Really delete relation #%i?** — and an info line rendering
  the legacy `add_relation_feedback_msg` sentences.

The modern viewer had a stub for it that could never render anything:

1. `api/testcases/index.php` joined a table that **does not exist in TestLink**:

   ```sql
   LEFT JOIN relation_type RT ON TR.relation_type = RT.id
   ```

   `testcase_relations.relation_type` is a bare `smallint`; its meaning lives in the **config
   array** `testcase_cfg->relations->type_labels` (`config.inc.php:1331`), exactly like legacy
   `testcase::getRelationLabels()`. The table `relation_type` has never existed —
   `ERROR 1146 (42S02): Table 'testlink.relation_type' doesn't exist`.
2. The same query filtered on the **test case** id while `testcase_relations.source_id` /
   `destination_id` hold **tcversion** ids, so even a fixed JOIN would have matched nothing.
3. The DB call was wrapped in a silent `catch`, so `relations` was always `[]`.
4. `renderRelations()` in `tcView.html` was called once for the whole screen (not per version)
   and **returned early when the list was empty**, so the section could never render even a
   valid empty-but-editable state.
5. There were **no write endpoints at all** — add and delete existed nowhere in 2.0.1.

## What changed

### BFF — `api/testcases/index.php`

| Helper | Legacy twin |
|---|---|
| `tcRelationLabels()` | `testcase::getRelationLabels()` — reads `testcase_cfg->relations->type_labels` and `lang_get()`s it, returns `null` when relations are disabled |
| `tcRelationTypeDomain()` | `testcase::getRelationTypeDomainForHTMLSelect()` — `<code>_source` / `<code>_destination`, the `_destination` variant omitted when both labels are identical, `3_source` (`related_to`) preselected |
| `tcVersionRelations()` | `testcase::getTCVersionRelations()` — one query per tcversion, both sides (`source → related` and `related → source`), side-aware label, `id / <relation_type_localized>` composite key, `cannot_delete_reason` for the warning icon |

New response keys on `action=view`:

```jsonc
"relationsConfig": {
  "enabled": true,                     // $tlCfg->testcase_cfg->relations->enable
  "domain": [ {"value":"1_source","label":"is parent of"}, … ],
  "editEnabled": true,                 // args_edit_enabled: mgt_modify_tc OR (editOnExec AND can_execute)
  "onlyOnLatest": true                 // addTCVRelationsOnlyOnLatestTCVersion (config.inc.php:1391)
},
"relationsByVersion": { "4": [ … ], "5": [ … ] },  // per-version display rows
"relations": [ … ]                                  // the requested version's rows
```

Row shape (`cannot_delete_reason` is empty when the user simply may not edit — legacy
`$cannotDelMsg = ''`):

```jsonc
{
  "id": 14, "related_id": 4, "relation_type": 2, "relation_type_label": "blocks",
  "external_id": "TR1-3", "link_status": 3,
  "related_tcase_name": "TCR Case R Third",
  "related_tcversion_id": 9, "related_version_number": 1,
  "author": "admin", "author_ts": "2026-01-06 10:00:00",
  "can_delete": false, "cannot_delete_reason": "can_not_delete_a_frozen_relation"
}
```

Two new POST actions:

| Action | Legacy twin | Notes |
|---|---|---|
| `add_relation` | `testcaseCommands::doAddRelation()` | `tcversion_id`, `relation_type` (`3_source`…), `relation_destination_tcase`; resolves the typed id with `getInternalID()` (same project, `_fcp_id: int|ext_id`, or plain internal id), **swaps source and destination** when the `_destination` variant is used, guards the frozen source version, then `testcase::addRelation()`. Answers 200 `{feedback_code}` / 4xx `{message}` |
| `delete_relation` | `testcaseCommands::doDeleteRelation()` | requires `mgt_modify_tc`, verifies the relation **belongs to one of the case's tcversions**, blocks frozen relations, then `testcase::deleteRelationByID()` |

Both actions inherit the endpoint's existing CSRF/same-origin and rights gates.

### Screen — `gui/templates/testcases/tcView.html`

* The obsolete global card is gone; `renderRelations(v)` is called from `renderVersion(v)`,
  reproducing the legacy per-version inclusion.
* Section visibility reproduces the legacy `$canWork`:
  `editEnabled && (is_latest || !onlyOnLatest)`. The add row additionally requires
  `!v.is_frozen` (legacy `if is_frozen == 0`).
* `REL_I18N` maps the BFF's snake_case legacy codes (`relation_added`,
  `testcase_doesnot_exists`, `can_not_delete_a_frozen_relation`, …) to the camelCase bundle
  keys. Unknown codes fall through to `TLi18n.t()` so a missing translation is visible
  instead of silent.
* `platApiPost()`'s `.fail()` handler was fixed: it forwarded only `{status, message}` and
  **discarded `feedback_code` / `feedback_arg`**, so every 4xx showed the generic
  "Error adding new relation." instead of the legacy sentence.

### i18n — all 10 bundles

26 new `tcview.rel*` / `tcview.newRelation` / `tcview.deleteRel*` / `tcview.tcaseRelation*`
keys in `de, en, es, fr, it, ja, pt, ro, ru, zh` (the 26 legacy label/meta keys from
`gui/templates/dashio/testcases/include/relations.inc.tpl`).

## Gotchas found while implementing

1. **`relation_type` is not a foreign key.** 5 configured values (`config.inc.php:1331-1339`):
   `1` parent/child, `2` blocks/depends, `3` related, `4` automates/is automated,
   `5` executed together. Legacy **drops** any row whose type is not in that array — kept
   (fixture relation `13` with `relation_type = 9` proves it).
2. **Relations are per-version.** `source_id`/`destination_id` are tcversion ids, so the same
   test case shows different rows on different versions. The modern screen only ever asked
   the API for one version, so `relationsByVersion` is keyed by tcversion id and `relations`
   carries the requested one.
3. **Side-aware labels.** When we are the *destination* the label is the other half of the
   pair (`is child of` instead of `is parent of`) — legacy `getTCVersionRelations()` reads
   `type_labels[tc_id]['destination']`.
4. **Non-latest versions show nothing** while
   `$tlCfg->testcase_cfg->addTCVRelationsOnlyOnLatestTCVersion` is `TRUE`
   (`config.inc.php:1391`) — legacy wraps the whole section in `$canWork`. Reproduced, not
   "fixed".
5. **`fetchFirstRow()` returns `false`, not `null`,** when nothing matches; the legacy-style
   `if ($x == null)` guard silently let `false` through.
6. **Legacy duplicate detection is broken and stays that way.**
   `testcase::addRelation()` calls `relationExits()` with the *unmapped* arguments
   (`lib/functions/testcase.class.php:8206-8211`), so a duplicate is never detected and the
   row is inserted twice. Do not "fix" this without a separate issue.

## Verification

Full suite in `tmp/TLU_Test_Cases.md` — **Suite 1040, 34 PASS / 0 FAIL** (A3 records the
pre-fix baseline). Covers: the broken query, payload before/after, per-version rendering,
combo contents, side-aware labels, unconfigured-type drop, frozen / non-latest versions,
add (UI + duplicate + empty + bogus type + frozen source + frozen destination), delete
(UI + modal text + frozen + foreign relation), rights, CSRF, `editOnExec=1` read-only,
console, Event Viewer, PHP log, all 10 i18n bundles, and the untouched blocks on the screen.

## Files

| File | Purpose |
|---|---|
| `api/testcases/index.php` | `tcRelationLabels()`, `tcRelationTypeDomain()`, `tcVersionRelations()`, `relationsConfig` / `relationsByVersion` payload, `add_relation`, `delete_relation` |
| `gui/templates/testcases/tcView.html` | per-version relations section, add form, table, delete modal, warning icons, `platApiPost()` error-body forwarding |
| `gui/templates/i18n/*.json` | 26 new keys × 10 locales |
| `tmp/fixtures_1040.sql` | idempotent fixture: 3 cases / 4 versions / 7 relations covering every legacy branch |
| `docs/screenshots/issue-1040-tcview-relations.png` | screenshot of the restored section |
