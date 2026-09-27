# Requirement / Requirement Spec Log Message Viewer — Modernized Screen

The **Log Message** viewer shows the full, untruncated change log of a requirement specification revision, a requirement version, or a requirement revision. It replaces the two legacy 1.9.20 AJAX readers `lib/ajax/getreqspeclog.php` and `lib/ajax/getreqlog.php` with a modern Dashio popup backed by a plain-PHP REST BFF. It is not an ASIDE entry: it is reached from the *open the full log* affordance on the requirement screens and from legacy deep links.

**URL:** `gui/templates/requirements/logViewer.html?type=<type>&id=<id>&tproject_id=<id>`
**BFF API:** `api/logviewer/index.php` — `GET ?action=log&type=<type>&id=<id>&tproject_id=<id>`
**Rights:** `mgt_view_req` on the **owning** test project (resolved server-side, not taken from the request)
**Tracking issue:** [#1652](https://github.com/sebiboga/testlink-upgraded/issues/1652)

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Data Flow and Parity](#2-data-flow-and-parity)
3. [The three object types](#3-the-three-object-types)
4. [i18n](#4-i18n)
5. [Permission Path and Error States](#5-permission-path-and-error-states)
6. [Legacy Endpoints](#6-legacy-endpoints)
7. [BFF API Reference](#7-bff-api-reference)
8. [Bugs Found and Fixed](#8-bugs-found-and-fixed)
9. [Files](#9-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Log Message — full, untruncated change log" with the locale switcher |
| **Toolbar** | Dark bar with the type badge, the object title, and three actions: **Refresh**, **Copy log**, **Close** |
| **Context card** | Object type, Test project, Doc ID, Specification Doc ID (requirement versions/revisions only), Version, Item ID |
| **Log card** | The full log rendered as **pre-wrapped plain text**, with a localized "character / byte" counter |
| **Empty state** | "Log message is empty" placeholder; **Copy log** is disabled |
| **Not found** | "Log not found" state for a wrong type or another project's id |
| **Access denied** | "Access Denied" state when the user lacks `mgt_view_req` on the owning project |
| **Footer** | "TestLink 2.0.1 - Log Message" |

## 2. Data Flow and Parity

The legacy readers did `SELECT log_message FROM <table> WHERE id = <intval>` and then `echo`ed the result with `nl2br()` after stripping a wrapping `<p>`. The BFF keeps the same normalization but operates on the **decoded** text, so the payload stays plain text:

1. `preg_replace('#</?p\s*>#i', "\n", …)` — the legacy `<p>` wrapper becomes a newline (legacy: stripped / turned into `<br>`).
2. `preg_replace('#<br\s*/?>#i', "\n", …)` — remaining line markup becomes a newline.
3. `strip_tags()` — residual editor markup (fonts, divs, spans) is dropped.
4. `html_entity_decode()` — the browser used to render the entities in the fragment.
5. Newline and whitespace tidy-up, then `trim()`.

The screen renders the result with **`.text()`** (`logViewer.html`), never `.html()`. That is the security difference from the legacy endpoint, which echoed the stored blob straight into the DOM.

**Titles.** Neither `req_specs`, `req_specs_revisions`, `requirements`, `testprojects` nor `req_revisions` is a reliable title source in this schema, so:

- a spec revision's title is the name of its `nodes_hierarchy` node (`node_type_id = 6`);
- a requirement's title is the name of its `nodes_hierarchy` node (`node_type_id = 7`);
- the test project name comes from `testproject::getName()`, which reads the `nodes_hierarchy` testproject node (`node_type_id = 1`).

## 3. The Three Object Types

| `type` | Table | Id is | Version label |
|--------|-------|-------|---------------|
| `requirement_spec_version` | `req_specs_revisions` | the revision row id (which is the revision node id) | `rev#N` |
| `requirement_version` | `req_versions` | the version row id (which is the version node id) | `vN / rev#M` |
| `requirement` | `req_revisions` | the revision row id | `rev#N` |

For the two requirement types the BFF also reads `nodes_hierarchy` to prove the node really is of the declared type, and joins the owning requirement for its Doc ID, the owning spec's Doc ID, and the owning test project. `requirement_spec_version` needs no such proof: `req_specs_revisions.id` *is* the revision node id by construction.

The screen also accepts the legacy aliases `reqspec` → `requirement_spec_version` and `req` → `requirement`, and the legacy `item_id` query parameter, so an old bookmark still lands on the right screen.

## 4. i18n

24 `logv.*` keys plus `footers.logViewer`, defined **identically** in all 10 locale bundles (`en`, `ro`, `de`, `es`, `fr`, `it`, `pt`, `ru`, `ja`, `zh`); every bundle is validated with `python3 -m json.tool`. There are no hardcoded user-visible strings: the type badge, the context-row labels, all six error states and the character counter are translated.

The generic error box switches on the BFF **machine code** and shows a translated string — the API's English `message` is a developer string and is never painted into a non-English UI.

> `logv.testProject` was originally translated as *"Requirement specification"* while the value it labelled was the **test project**. Corrected in all 10 bundles ([#1655](https://github.com/sebiboga/testlink-upgraded/issues/1655)).

## 5. Permission Path and Error States

| Situation | HTTP | Screen state |
|-----------|------|--------------|
| Normal | 200 | context + log |
| Log is empty | 200 | "Log message is empty", **Copy log** disabled |
| No session / dead session | 401 | redirect to `/login.php?note=expired` |
| No `mgt_view_req` on the owning project | 403 | "Access Denied" |
| Unknown id, wrong node type, or an id from another project | 404 | "Log not found" |
| Missing/unknown `action`, missing/unknown `type`, `id <= 0`, non-numeric `id`, non-numeric `tproject_id` | 400 | localized error box |
| Unsafe verb | 405 | — |

Authorization is enforced on the project **resolved from the database**, and the "does this id belong to the project you asked for" check runs *before* the rights check, so an id from another project answers 404 and never leaks whether it exists. Every SQL value is `intval()`-guarded, including the `tproject_id` used in the `Location` header of the legacy shims.

`X-Requested-With` / same-origin proof is required for unsafe verbs (the shared CSRF guard answers 403 first); `GET` is a safe read-only verb and stays available. `X-Content-Type-Options: nosniff` is sent, and a `register_shutdown_function()` backstop turns any unexpected fatal into a valid JSON `500` instead of TestLink's raw HTML error page.

## 6. Legacy Endpoints

`lib/ajax/getreqspeclog.php` and `lib/ajax/getreqlog.php` are **still live**: six legacy Smarty templates build the log tooltip with `new Ext.ToolTip({ autoLoad:{ url: … } })`, and Ext injects the response **body** as HTML:

| Template | Endpoint |
|----------|----------|
| `reqSpecCompareRevisions.tpl:34`, `reqSpecViewRevision.tpl:31`, `include/reqSpecViewJS.inc.tpl:30` | `getreqspeclog.php` |
| `reqViewVersions.tpl:174`, `reqViewRevisionRO.tpl:28`, `reqCompareVersions.tpl:34` | `getreqlog.php` |

So each endpoint now answers the contract its caller actually has:

- **XHR** (`X-Requested-With`, which Ext sets) → an HTML **fragment**: the same normalization as the BFF, then `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE)` + `nl2br()`. Visually the same tooltip, and a log containing `<script>` renders as visible text instead of executing in the caller's page. An empty log keeps the 1.9.20 `empty_log_message` hint instead of a blank bubble.
- **Plain browser GET** (a deep link) → a 302 to the modern screen, which enforces the rights the legacy readers never checked. `getreqlog.php` reproduces the 1.9.20 **node-type auto-detection** first: a `req_versions` row is a requirement *version* (`type=requirement_version`), any other requirement node is a *revision* (`type=requirement`), so a revision deep link no longer lands on the version screen and its 404. An id that is not a requirement node at all is traced in the Event Viewer.

**Authorization on the fragment branch.** The live tooltip callers pass `item_id` only, so scoping by a caller-supplied `tproject_id` is not enough. Both shims therefore **derive the owning test project from the id itself** (`req_specs.testproject_id`, reached through `nodes_hierarchy → requirements` for the requirement logs) and enforce `mgt_view_req` on it before emitting anything:

| Situation | XHR fragment answer |
|-----------|--------------------|
| user has `mgt_view_req` on the owning project | 200 + the escaped log |
| no right on the owning project (e.g. global role 3) | **403** + the localized `user_has_no_right_for_action` text, no log body |
| right granted only through `user_testproject_roles` on that one project | 200 + the log (the production branch of `tlUser::hasRight()`) |
| `tproject_id` stated and it is not the owning project | scoped out → the empty-log hint, never the log |
| unknown id, `id <= 0`, a node that is not a requirement, broken `testproject_id`, missing session user | empty-log hint, nothing leaked (fails closed) |

The revision branches also prove the node type in SQL (`nodes_hierarchy.node_type_id` 10 for a requirement revision, 11 for a spec revision) instead of trusting the shared id space, and the node type is compared numerically (`8` / `10`), the same way `api/logviewer/index.php` does, so a renamed or localized `node_types.description` cannot mis-route a deep link.

An anonymous caller gets TestLink's standard `login.php?note=expired&destination=…` script, on both branches.

## 7. BFF API Reference

`GET api/logviewer/index.php?action=log&type=<type>&id=<id>&tproject_id=<id>`

```jsonc
{
  "status": "ok",
  "context": {
    "type": "requirement_version",
    "item_id": 233,
    "testproject_id": 313,
    "object_label": "First requirement",
    "object_id": "REQ-LOGV-1",
    "version_label": "v1 / rev#1",
    "revision": 1,
    "parent_id": 322,
    "parent_doc_id": "RS-LOGV",
    "parent_name": "LOGV"
  },
  "log": {
    "text": "Version 1 of REQ-LOGV-1: initial & final.\nSecond line with markup kept as text.",
    "is_empty": false,
    "raw_length": 97,
    "legacy_note_empty": "empty_log_message"
  }
}
```

Error payloads always carry `status: "error"`, an English `message` for developers, and a stable machine `code`:

| Code | HTTP | Meaning |
|------|------|---------|
| `session_expired` / `NOT_AUTHENTICATED` | 401 | no valid session |
| `NOT_PERMITTED` | 403 | no `mgt_view_req` on the owning project |
| `UNKNOWN_ACTION` | 400 | missing or unknown `action` |
| `UNKNOWN_TYPE` | 400 | missing or unknown `type` |
| `INVALID_ID` / `INVALID_TPROJECT_ID` | 400 | non-numeric or non-positive id (`tproject_id` is coerced with `intval()`, so a non-numeric value simply means "no project stated") |
| `NOT_FOUND` | 404 | unknown id, wrong node type, or another project |
| `METHOD_NOT_ALLOWED` | 405 | unsafe verb |
| `db_error` / `internal_error` | 500 | failed query / fatal |

## 8. Bugs Found and Fixed

| Issue | Symptom | Root cause |
|-------|---------|-----------|
| [#1653](https://github.com/sebiboga/testlink-upgraded/issues/1653) | unknown id answered `500 db_error`; a failed query escaped as a raw HTML 500; three nonexistent `name` columns 500'd **every** happy path | `get_recordset()` returns `null` for both an empty result *and* a failed query, so the row-count guard converted every legitimate 404 into a 500. Fixed by branching on `exec_query()` **throwing** (`try`/`catch Throwable`), deleting the probe, adding a JSON shutdown backstop, and reading titles from `nodes_hierarchy` / `testproject::getName()` |
| [#1654](https://github.com/sebiboga/testlink-upgraded/issues/1654) | 44 `E_WARNING Undefined array key "tproject_name"` rows, invisible in the response | when the column was dropped from the SQL the three `parent_name` reads were left behind, and their `''` was overwritten by `testproject::getName()` three lines later — dead reads. The Event Viewer was the only place they showed |
| [#1655](https://github.com/sebiboga/testlink-upgraded/issues/1655) | the context table read `Requirement specification = LOGV` | the BFF fills `parent_name` with the **test project**, but the label was translated as *"Requirement specification"* in all 10 bundles. Corrected, and a `Specification Doc ID` row was added |
| (code review 1) | six legacy log tooltips rendered the whole modern page inside a 500px bubble | the shims redirected unconditionally, but `Ext.ToolTip` `autoLoad` needs a **fragment**. The shims now branch on the XHR proof |
| (code review 2) | the XHR branch still leaked: any authenticated user could read **any** project's log | the shims enforced the login but not the right, and only scoped by a caller-supplied `tproject_id` — which none of the six live tooltip callers send. They now derive the owning project from the id and check `mgt_view_req` on it |
| (code review 3) | a browser deep link to a requirement **revision** opened the requirement-*version* screen and a 404 | the redirect hardcoded `type=requirement_version`; the 1.9.20 node-type auto-detection is restored (`type=requirement`) |
| (code review 4) | an empty log rendered a blank tooltip | 1.9.20 showed the localized `empty_log_message` hint; the shim had dropped it |
| (code review 5) | a log holding invalid UTF-8 rendered as a blank tooltip | `htmlspecialchars()` returns `''` for invalid UTF-8 without `ENT_SUBSTITUTE`; both shims now escape with `ENT_QUOTES | ENT_SUBSTITUTE` |

## 9. Files

| File | Purpose |
|------|---------|
| `gui/templates/requirements/logViewer.html` | the screen (standalone HTML + JS + CSS) |
| `api/logviewer/index.php` | the BFF |
| `lib/ajax/getreqspeclog.php` | legacy spec-log endpoint → escaped fragment (XHR) / 302 (browser) |
| `lib/ajax/getreqlog.php` | legacy requirement-log endpoint → escaped fragment (XHR) / 302 (browser) |
| `gui/templates/requirements/reqSpecView.html` | caller — opens the viewer from the revision row |
| `gui/templates/requirements/reqSpecCompare.html` | caller — opens the viewer from a revision log cell |
| `gui/templates/requirements/reqCompare.html` | caller — opens the viewer from a version log cell |
| `lib/functions/common.php` | `$actions->logViewer` |
| `gui/templates/i18n/*.json` | the 24 `logv.*` keys in 10 bundles |
| `tmp/fixtures_1652.php` | re-runnable fixture (two projects, rich + empty logs) |
| `tmp/verify_1652.sh` | 257-assertion regression harness (auth, deep links, escaping, i18n, Event Viewer) |
| `tmp/TLU_Test_Cases.md` | Suite 1652, with the recorded results |
