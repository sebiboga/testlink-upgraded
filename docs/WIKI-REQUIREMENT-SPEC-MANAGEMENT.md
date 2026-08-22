# Requirement Specification Management (reqSpecMgmt)

Modernized screen: **Requirement Specification Management** (ASIDE:
Requirements Design → Requirement Specification). Tracking issue
[#571](https://github.com/sebiboga/testlink-upgraded/issues/571).
Legacy reference: `lib/requirements/reqSpecListTree.php` (feature
`reqSpecMgmt`, ExtJS tree) + `reqSpecEdit.php` / `reqEdit.php` popups.

## What it does

Replaces the legacy ExtJS tree with a flat, searchable DataTables view of
every requirement specification of the active test project, plus a
per-specification requirements panel — all as a standalone Dashio page
backed by the JSON BFF `api/reqspec/index.php`.

### Specifications table

| Column | Notes |
|---|---|
| Doc ID | specification document id |
| Title | click to open/close the requirements panel of that spec |
| Type | localized label from `req_spec_cfg.type_labels` |
| Reqs | number of requirements linked to the spec |
| Expected | expected number of requirements (`total_req`) |
| Author | login of the latest revision author |
| Last Update | modification timestamp |
| Actions | edit pen + delete (only with modify rights), otherwise a read-only eye icon |

### Requirements panel

Opened by clicking a specification title:

| Column | Notes |
|---|---|
| Doc ID | requirement document id |
| Title | click to edit; hover shows plain-text scope excerpt |
| Status | color badge with localized status label (Draft/Review/Rework/Finish/Implemented/Valid/Not testable/Obsolete) |
| Type | localized requirement type |
| Ver | latest version number |
| Coverage | expected coverage |
| Author | version author login |
| Actions | edit pen + delete (modify rights only) |

## Toolbar

* **Create Spec** — modal with Document ID*, Title*, Specification Type
  (localized domain), Expected number of requirements, Scope (HTML allowed).
* **Create Requirement** (in panel) — modal with Doc ID*, Title*, Status,
  Type, Expected coverage, Scope.
* Locale switcher (all 10 bundles).

## Access & permission

* ASIDE entry switched in `lib/functions/common.php`
  (`$actions->reqSpecMgmt` → `/gui/templates/requirements/reqSpecMgmt.html`),
  same rights split as legacy: viewing needs `mgt_view_req` or
  `mgt_modify_req`; every write needs `mgt_modify_req`. Without rights the
  BFF answers HTTP 403 and the UI renders read-only.
* Unauthenticated API calls get HTTP 401.

## Behavior parity notes

* Create attaches the spec to the test project node itself (same as the
  legacy root-level create); orphaned parent 0 would make the spec
  invisible to every tree walk (see #569 fix inside the BFF).
* Duplicate document ids inside one spec are rejected server-side with the
  legacy message ("Duplicated document id …").
* Spec delete uses `delete_deep()` — removes contained requirements,
  versions and revisions, exactly like legacy `reqSpecCommands::doDelete`.
* Requirement delete removes ALL versions (`requirement_mgr::delete()`),
  like the legacy GUI action.

## i18n

42 new keys (`rs.*`) added to **all** locale bundles: en, de, es, fr, it,
ja, pt, ro, ru, zh. Every label, column header, modal title and message is
translated; validated with `python3 -m json.tool`.

## BFF endpoints (`api/reqspec/index.php`)

```
GET  ?action=options&tproject_id=N     -> domains, defaults, rights, project name
GET  ?action=specs&tproject_id=N       -> specs list (latest revision + req counts)
POST ?action=create_spec               {tproject_id,doc_id,title,type,total_req,scope}
POST ?action=update_spec&id=N          {…}
POST ?action=delete_spec&id=N&tproject_id=N
GET  ?action=reqs&spec_id=N&tproject_id=N -> requirements of a spec (latest versions)
POST ?action=create_req                {tproject_id,spec_id,req_doc_id,title,status,type,expected_coverage,scope}
POST ?action=update_req&id=N           {…}   (applies to latest version)
POST ?action=delete_req&id=N&tproject_id=N
```

Session-based auth; JSON I/O; per-request right checks
(`mgt_view_req` / `mgt_modify_req`).

## Bug found & fixed during testing

* [#572](https://github.com/sebiboga/testlink-upgraded/issues/572) —
  `requirement_mgr::delete()` emitted a PHP 8 E_WARNING ("Trying to access
  array offset on null") on **every** requirement deletion because
  `$notifyOn` is initialized `null` and only set via `setNotifyOn()`.
  Fixed with an `is_array()` guard (behavior unchanged). Verified clean
  Event Viewer after the fix.

## Testing

Suite 46 in `tmp/TLU_Test_Cases.md`: 16 / 16 PASS — CRUD round trip,
duplicate-id validation, permission paths (admin/guest/anonymous),
Romanian locale render, DB orphan check after deletes, Event Viewer check.
