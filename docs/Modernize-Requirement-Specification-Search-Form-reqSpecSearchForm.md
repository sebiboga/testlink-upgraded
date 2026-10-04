# Modernize: Requirement Specification Search Form (`reqSpecSearchForm`)

- **Issue:** [#1825](https://github.com/sebiboga/testlink-upgraded/issues/1825) (enhancement) — bugs found while testing: [#1826](https://github.com/sebiboga/testlink-upgraded/issues/1826) (array parameter), [#1827](https://github.com/sebiboga/testlink-upgraded/issues/1827) (deep link never pre-filled)
- **Legacy screen:** `lib/requirements/reqSpecSearchForm.php` + `gui/templates/dashio/requirements/reqSpecSearchForm.tpl` (and the classic twin `gui/templates/tl-classic/requirements/reqSpecSearchForm.tpl`)
- **Modern screen:** `gui/templates/requirements/reqSpecSearchForm.html`
- **BFF:** `api/reqspecsearchform/index.php` — `GET|HEAD ?action=init&tproject_id=N…`
- **Entry point:** `$actions->reqSpecSearchForm` (`lib/functions/common.php`), the `reqSpecSearchForm` row of the `$feature_map` in `lib/general/frmWorkArea.php`, and the new **Criteria form** button on the modern results screen

## Why this screen

The TODO section of `docs/MODERNIZATION-STATUS.md` is empty (every ASIDE entry maps to a
modern screen), so the smallest coherent legacy-only slice was picked: the
requirement-specification **search form**, the last un-ported screen of the
requirement search pair. Its target, `searchReqSpec.html`, was already modern, but the
criteria page in front of it was not — and it had three things worth fixing.

1. **It authorized nothing but the session.** `reqSpecSearchForm.php` read the test
   project from `$_SESSION['testprojectID']` and listed the design-time custom fields
   of that project to *any* authenticated user — a global `<no rights>` role
   (`role_id = 3`) included. Same class as [#1696](https://github.com/sebiboga/testlink-upgraded/issues/1696),
   [#1765](https://github.com/sebiboga/testlink-upgraded/issues/1765) and
   [#1770](https://github.com/sebiboga/testlink-upgraded/issues/1770).
2. **Its results target was un-ported too** (`lib/requirements/reqSpecSearch.php`, still a
   full Smarty renderer), so the pair could not be fixed one screen at a time without
   leaving a hole. Both are retired in this pass.
3. **The legacy page had no state at all** — it listed the criteria and POSTed them; the
   URL never carried them, so no search was bookmarkable or shareable.

## Legacy behaviour carried over

| Legacy | Modern |
|---|---|
| Seven criteria: Doc ID, Title, Type, Scope, Log message, Custom field, Custom field value | same seven, same order, in the Dashio criteria grid |
| Type list from `config_get('req_spec_cfg')->type_labels` | same source, so the codes sent to the results BFF are exactly the ones the DB stores |
| Custom fields from `cfield_mgr::get_linked_cfields_at_design(..., 'requirement_spec')` | same call, same `ENABLED` filter |
| Doc ID filter shown only when `testproject::GET_NOT_EMPTY_REQSPEC` is non-empty (`reqSpecSearchForm.php:37`) | kept, with a `no_req_specs` notice card explaining why the field is absent |
| All criteria combined with a logical AND (`reqSpecSearch.php` `implode('', $filter)`) | stated explicitly above the grid (`All criteria are combined with a logical AND.`) |
| `Open results` with an empty form silently searched everything | asks first (`No criteria entered: open the results screen with no filter?`) |
| Session test project only, no way to move | `param > session` resolution, so the page is deep-linkable per project |
| POST to `reqSpecSearch.php` with the legacy criterion names | `auto_search=1` hand-off to `searchReqSpec.html` with the modern names |

## Hardening over legacy

- **The BFF enforces `mgt_view_req` OR `mgt_modify_req` on the ADDRESSED project, and does
  it BEFORE resolving the project**, so a caller without the right cannot turn the endpoint
  into a test-project existence oracle (403, never 404 — the [#1697](https://github.com/sebiboga/testlink-upgraded/issues/1697) lesson).
  Both rights are tested because `propagateRights()` copies global project rights
  wholesale and establishes no modify→view implication of its own; a requirement author
  must reach this page too.
- Status codes with stable machine codes: `401 not_authenticated`, `403 no_right`,
  `404 tproject_not_found`, `405 wrong_method` (`Allow: GET, HEAD`), `400 unknown_action`,
  `400 invalid_tproject`, `400 invalid_parameter`.
- **Every echoed criterion is trimmed and length-capped** (255 / 2000 chars): the page is
  deep-linkable, so criteria travel in the query string and must never be unbounded.
- **A repeated parameter (`?scope[]=x`) is rejected** with `400 invalid_parameter` instead of
  being cast with `trim((string)$v)` — which returned the literal `"Array"` *and* raised an
  `Array to string conversion` E_WARNING (an Event Viewer row per request), and the polluted
  value then went on into the results screen's `LIKE '%Array%'` (bug #1826).
- An unknown `reqSpecType` or `custom_field_id` in the URL is **dropped, not forwarded**:
  a form must not hand out a filter the server will refuse (`400 Unknown custom field`).
- The pre-filled value on a deep link is the **server echo**, never a client-side copy, so it
  is always exactly what the server accepted.

## Bugs found and fixed while testing

| Issue | Symptom | Fix |
|---|---|---|
| [#1827](https://github.com/sebiboga/testlink-upgraded/issues/1827) | `?tproject_id=1&doc_id=TL-REQ-9&name=Deep` opened a **blank** form — every bookmark landed on an empty criteria page. `load()` built the first `/init` request with `criteriaQuery()`, which reads controls that are still empty at that point, so the echo was always empty. | `initQuery()` sends the incoming criteria with the first `/init` call; the form is still filled from the server echo. |
| [#1826](https://github.com/sebiboga/testlink-upgraded/issues/1826) | Array parameter → `"Array"` + E_WARNING per request (see above). | `is_scalar()` guards on `action`, `tproject_id` and every criterion → `400 invalid_parameter` + one WARNING log; both shims drop such keys. |

Both were also caught by the code review pass, which additionally required: `Refresh` no
longer discards a Type / Custom-field choice (it snapshots and restores both selects), the
**Criteria form** button no longer carries the label of a *different* action (new
`rssf.criteria` key), `$actions->reqSpecSearchForm` is wired into `frmWorkArea.php`
instead of being dead, and the unread `grant` payload was dropped from the response.

## Both legacy controllers are retired

| Legacy file | Now |
|---|---|
| `lib/requirements/reqSpecSearchForm.php` (242 lines) | session-guarded **302** to the modern screen, forwarding every criterion a bookmark carried so the reader lands on a **pre-filled** form; `405` for a write verb; `405 retired_endpoint` (JSON) for an XHR/crawler instead of an HTML login body to parse; anonymous → the legacy `login.php?note=expired` bounce. |
| `lib/requirements/reqSpecSearch.php` (242 lines) | session-guarded **302** to `searchReqSpec.html`, translating the criterion names (`requirement_document_id` → `doc_id`) and adding `auto_search=1` when criteria were supplied. `coverage` is deliberately **dropped**: legacy `init_args()` collected it and no query ever read it, so no row changes — and the modern results screen shows the coverage of what it returns. |

Neither shim holds state or runs a legacy code path.

## i18n

50 new keys: `rssf.header`, `subInProject`, `testProject`, `find`, `openResults`,
`refresh`, `reset`, `backToSearch`, `close`, `contextCaption`, `prefix`, `user`,
`requirements`, `enabled`, `disabled`, `reqSpecs`, `yes`, `no`, `resultCap`,
`customFields`, `criteriaCaption`, `filterModeAnd`, `docId`, `title`, `type`, `scope`,
`logMessage`, `customField`, `customFieldValue`, `findHint`, `loading`, `deniedTitle`,
`deniedBody`, `notFoundTitle`, `notFoundBody`, `badRequestTitle`, `badRequestBody`,
`serverErrorTitle`, `serverErrorBody`, `reqsDisabledTitle`, `reqsDisabledBody`,
`noSpecsTitle`, `noSpecsBody`, `searchingIn`, `anyType`, `anyField`, `emptyCriteria`,
`projectScope`, `criteria` + `footers.reqSpecSearchForm` — translated in **all 10** bundles
(en/ro/de/es/fr/it/pt/ru/ja/zh). The prefix is `rssf.*` and not `rsf.*`: the latter is
already taken by Reorder Requirements.

## Testing

23 executed cases in the suite **“Issue #1825 — Requirement Specification Search Form”**
(`tmp/TLU_Test_Cases.md`, 77 suites, none lost — `ai/verify_test_suites.sh`: 7 PASS):
the admin happy path and the config-driven criteria domain, the Find/Results hand-off, the
empty-criteria confirm, Reset, Refresh, deep-link pre-fill (including the custom-field
pair), server-side length caps, the three project states (requirements disabled, no
requirement specification, no right → 403), every BFF error code, both retired shims
(302 navigation, 405 write verb, 405 XHR, anonymous bounce), the results↔form round trip,
and Back/Close.

- Event Viewer / `events`: **0** unexpected rows — only the intentional WARNINGs (2 ×
  `mgt_view_req missing` from the guest-role user, 2 × `BFF shim: refused POST`) plus the
  `log_level 16` audit logins.
- Browser console: no errors, no warnings.
- `php -l` on all touched PHP, `node --check` on the screen's inline script,
  `python3 -m json.tool` on all 10 bundles.

## Screenshots

- `issue-1825-reqspecsearchform-form.png` — the criteria page of `TL-DEMO` (context card:
  requirements Enabled, requirement specifications Yes, result cap 200, 1 custom field)
- `issue-1825-reqspecsearchform-prefilled.png` — the same page reached by deep link with all
  five text criteria, the Type and the custom-field pair pre-filled from the server echo

Both in `docs/screenshots/` and in the wiki repository root.
