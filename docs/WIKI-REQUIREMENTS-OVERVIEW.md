# Requirement Overview (reqOverview)

Modernized screen: **Requirement Overview** (ASIDE: Requirements Design →
Requirement Overview). Refs [#566](https://github.com/sebiboga/testlink-upgraded/issues/566).


## What it does

Lists every requirement of the active test project in a DataTables grid —
same data the legacy ExtJS table showed (`lib/requirements/reqOverview.php`),
rebuilt as a standalone Dashio page backed by a JSON BFF:

| Column | Notes |
|---|---|
| Requirement Specification | req-spec path of the requirement |
| Requirement | `docID : title` link + edit pen, opens `reqView.php` popup |
| Version | `[vN rM]` version/revision tag |
| Creation date | timestamp + author |
| Last update | timestamp + modifier, or "Never" |
| Frozen | Yes/No badge (blue = frozen, teal = open) |
| Coverage | only when *expected coverage management* is enabled; `pct% (covered/expected)`, red under 100 %, "n/a" for requirements without expected coverage |
| Type / Status | localized labels from project config |
| Relations | count per requirement, only when relations are enabled |

Custom fields linked to requirements at design time are appended as extra
columns automatically.

## Toolbar

* **Show all versions** — toggle between latest version only and all versions
  of each requirement. Legacy parity: the choice is persisted in
  `$_SESSION['all_versions']` and restored on reload; an explicit
  `all_versions=1|0` URL parameter wins.
* **Refresh** — re-fetches the overview.
* Locale switcher (all 10 bundles).


## Access & permission

* ASIDE entry switched from `lib/requirements/reqOverview.php` to
  `gui/templates/requirements/reqOverview.html`
  (`$actions->reqOverView` in `lib/functions/common.php`).
* Right required: `mgt_view_req` (BFF also accepts
  `req_tcase_link_management`, mirroring the shared requirements router).
  Without it the API answers HTTP 403 and the screen shows "No permission".

## i18n

22 new keys (`header.reqOverview`, `common.refresh`, `ro.*`,
`ro.col.*`) added to **all** locale bundles: en, de, es, fr, it, ja, pt,
ro, ru, zh.


## BFF

`GET /api/requirements/index.php/overview?tproject_id=N[&all_versions=1|0]`

Returns `meta` (config flags + label maps + custom fields), `items[]`
(one row per requirement version), `total`, effective `all_versions`,
`elapsed_seconds`. Data assembly mirrors the legacy controller:
bulk latest-version revision fetch, cached spec paths, coverage counter
set, relations counters, bulk custom-field values with date formatting.

## Bugs found while testing

* #569 — `api/reqspec` `create_spec` attached specs to tree parent 0,
  orphaning them (invisible everywhere). Fixed to attach to the test
  project node.
* All-versions toggle reset on plain reload instead of using the session —
  fixed for legacy parity.

## Test evidence

Suite 45 in `tmp/TLU_Test_Cases.md` — 14/14 PASS.
