# Test Strategy Documents — editable, versioned strategy documents per project (Refs #1943, parent #1052)

Closes the authoring gap of the Test Strategy feature (#1052): the 18
ISTQB-style chapters modernized so far are **fixed, read-only content**.
This screen adds the missing **editable half** — a project-scoped registry
where a team authors its own Test Strategy documents (quality objectives,
scope, approach, entry/exit criteria, environments, tools, roles, risks),
tracks them through a Draft → Under review → Approved → Archived lifecycle,
and keeps every save as a new version with author and timestamp.

Net-new for 1.9.20: legacy TestLink has no such feature (nothing to retire).

## Entry point

- Screen: `gui/templates/strategy/strategyDoc.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/strategydoc/index.php` (session auth, JSON I/O)
- ASIDE menu: **Test Strategy** section, first item after *General Overview*
  (icon `fas fa-file-signature`); shown to any user who can read test cases
  (`mgt_view_tc` **or** `mgt_modify_tc`); writes need `mgt_modify_tc`.
- URL of the action: `$actions->strategyDoc` (`lib/functions/common.php`).
- Server label: `$TLS_href_test_strategy_doc` in all 19 `locale/*/strings.txt`.
- i18n namespace: `sdoc.*` (74 keys) in all 10 client bundles.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Documents, Draft, Under review, Approved, Archived — recomputed from the list on every load |
| Filter | Status dropdown (all / draft / review / approved / archived); an explicit `sdoc.noMatch` empty state when documents exist but none match |
| Registry table | DataTable with Name / Status / Version / Last author / Updated / Actions (search, sort, pagination) |
| New / Edit | Modal with name, status and the 9 strategy section textareas; a duplicate project name is rejected (`duplicate_name`, 409) |
| Details | Read-only modal with the metadata (version, author, status changed by, updated) and every section; empty sections show `-` |
| Change status | Modal with the dynamic "Change status of {name} to …" line and the 4-value select (`bad_status` guarded server-side) |
| Delete | Bootstrap confirm with the document name interpolated |
| Versioning | Every content save bumps `version` (v1, v2, …) and stamps `author_id` + `update_ts` |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `sdoc.noProject` (400/404), `sdoc.noRight` (403), `sdoc.errTitle` (other errors) |

## Data model

One table, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly
imported database works unchanged; the DDL is also mirrored in
`install/sql/mysql/testlink_create_tables.sql` and the name is whitelisted in
`lib/functions/object.class.php`:

- `testproject_strategy_docs` — one row per document:
  `id, testproject_id, name, status, version, objectives, scope, approach,
  entry_criteria, exit_criteria, environments, tools, roles, risks,
  created_by, author_id, status_by, creation_ts, update_ts`
  with `UNIQUE KEY uq_sdoc_project_name (testproject_id, name)` and status /
  project indexes.

## BFF contract

| Verb + action | Rights (checked on the addressed project before resolution, #1697) | Notable responses |
|---|---|---|
| `GET ?action=init\|list` | read | 200 payload: `context` (project + `can_view`/`can_modify`), `domains`, `stats`, `items` |
| `GET ?action=doc&id=N` | read | 404 `doc_not_found` |
| `POST ?action=save` | write | 200 (creates v1 or bumps version), 409 `duplicate_name`, 400 `name_required`/`bad_id` |
| `POST ?action=status` | write | 200, 400 `bad_status`, 404 `doc_not_found` |
| `POST ?action=delete` | write | 200, 404 `doc_not_found` |
| other verbs | — | 405 `wrong_method` (verb gate **before** rights) |
| anonymous | — | 401 `not_authenticated` |
| no/unknown project | — | 400 `no_testproject` / 404 `tproject_not_found` |

Unresolvable `tproject_id` falls back to `$_SESSION['testprojectID']` —
same resolution chain as `api/risk/index.php`. Audit events:
`STRATEGY_DOC_CREATE / SAVE / STATUS / DELETE` (visible in Event Viewer).

## Tests

Suite 1943 in `tmp/TLU_Test_Cases.md` — 28 cases: full BFF contract
(11), browser pass for render/create/details/edit/version bump/status change/
filter/search/locale switch RO/delete (14), state cards 404 + 403, console
clean, aside entry, Event Viewer clean, i18n coverage gate.

## Screenshots

(see the wiki page for current screenshots)







