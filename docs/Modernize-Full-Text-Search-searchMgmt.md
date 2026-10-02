# Modernize-Full-Text-Search-searchMgmt (#1785)

The standalone **Full-Text Search** screen — `lib/search/searchMgmt.php` — is
modernized as a standalone Dashio screen backed by a REST BFF. In 1.9.20 it was
reached only from the navBar magnifier: a single free-text box POSTed one
`target` term and the controller force-enabled every search dimension across the
current test project.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry already maps to a modern screen + BFF. Per the `modernize.yml` guidance,
the smallest coherent remaining legacy entry point with no dedicated modern
screen was chosen: the navBar **Full-Text Search** (`lib/search/searchMgmt.php`).
The sibling **Advanced Search** (`searchAdvancedView.html`) already existed;
this one is the one-box cross-entity search (test cases, test suites,
requirement specifications and requirements).

## Deliverables

- **Screen:** `gui/templates/search/searchMgmt.html` — Dashio page with a teal
  header + locale switcher, a dark toolbar (Refresh / Open Advanced Search /
  Back to Search Test Cases), a context card (project, prefix,
  requirements-enabled chip), one free-text box + AND/OR radio, read-only
  checked-per-area criteria boxes (uncheck narrows), optional
  keyword/created-by/edited-by refinements, and four result blocks linking to the
  modern detail screens by primary key. Receives `?target=` from the navBar
  hand-off and runs the search immediately.
- **BFF:** `api/searchmgmt/index.php` — `GET ?action=init&tproject_id=N` and
  `GET ?action=results&tproject_id=N&target=...&and_or=or|and[&<crit>=1|0]`,
  session auth + `bffSameOriginGuard` + `bffEnforceSession`. Reuses
  `lib/search/searchCommands.class.php` for exact 1.9.20 parity; enforces
  `mgt_view_tc` **before** resolving the project (no id enumeration). Stable
  machine codes: 400 (`invalid_tproject`/`need_criteria`/`need_checkbox`), 401,
  403 (+ `audit_security_user_right_missing`), 404, 405.
- **Link switch:** `$actions->searchMgmt` added in `lib/functions/common.php`.
- **Shim:** `lib/search/searchMgmt.php` is now a session-guarded 302 redirect
  onto the modern screen (anon → login, forwards the target term and the current
  project/plan context) so the navBar form and old deep links still resolve. No
  Smarty left.
- **i18n:** 72 `sfm.*` keys + `footers.searchMgmt` in all 10 bundles
  (en/ro/de/es/fr/it/ja/pt/ru/zh), validated with `python3 -m json.tool`.

## BFF defects found and fixed while testing

1. `array_keys($tcCriteria) + array_keys($tsCriteria)` is a **union keyed by the
   integer positions**, so every test-suite key was dropped and
   `searchTestSuites()` was never invoked → suites were silently missing from all
   results. Fixed with `array_merge`.
2. `initSchema()` ran **after** the searches, but `searchReqSpec()`/`searchReq()`
   interpolate `$this->views`/`$this->tables` into their SQL → every search that
   reached the requirement-spec query was a 500 with a raw MariaDB backtrace
   leaking server paths.
3. Client-side `$db->prepare_string()` double-escaped the target terms (the
   engine escapes again when building the LIKE) → a legitimate apostrophe
   (`o'brien`) produced invalid SQL / 500. Terms are now forwarded raw.
4. `oneValueOK` could not gate the search once `and_or` was always set (it scans
   the `$strIn` bucket and `trim('0') != ''` is true in PHP), so an **empty
   target returned the whole project**. The guard is now computed from what the
   caller actually sent.
5. `get_full_path_verbose()` takes its first argument **by reference**;
   passing `array_keys(...)` directly emitted an E_NOTICE and a **warning Event
   Viewer entry** on every search.

## Verification

- Browser (admin session): `?action=results&target=password` → 1 test case +
  1 requirement; `target=Suite` → 2 suites; `Sprint Specification` → 1 spec;
  AND vs OR differ; unchecking a criterion narrows; empty target → localized
  "Type at least one word"; no match → no-records notice; requirements-disabled
  project hides the req groups; `sfm1785norights` → Access denied box; locale
  switch to `ro` translates every label; `?target=` hand-off auto-runs.
- XSS: the fixture test case named `XSS <script>window.__sfmxss=1;</script>
  probe` renders escaped (`window.__sfmxss` stays undefined).
- Foreign project isolation: searching `Foreign` on SFM1 returns nothing from
  SFM2; empty test project returns `warning=empty_testproject`.
- BFF contract: 401 anon, 403 no-rights (+ audit event), 404 unknown project,
  405 POST, 400 empty target.
- Legacy shim: `lib/search/searchMgmt.php?target=password` → 200 login anon,
  302 → `searchMgmt.html?...&target=password` authenticated.
- 24/24 test cases PASS; browser console clean; Event Viewer unchanged after the
  by-ref fix; `php -l` + `json.tool` clean.
