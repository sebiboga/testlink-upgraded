# REST API Reference hub — Modernized (TestLink 2.0.1, Refs #1937, parent #1072)

Net-new in-app **REST API reference / endpoint catalogue** for TestLink 2.0.1,
added as the Documentation ASIDE section's sixth entry (below **Documentation**,
**Learn Testing**, **Accessibility Resources**, **Accessibility Review** and
**ISTQB Syllabus**). TestLink 1.9.20 shipped no API documentation at all, while
2.0.1 exposes a large `api/**` BFF surface that was previously documented only
outside the product — this screen documents it *inside* the product. Tracking
issue [#1937](https://github.com/sebiboga/testlink-upgraded/issues/1937), parent
feature [#1072](https://github.com/sebiboga/testlink-upgraded/issues/1072).


## Why this screen

- Parent enhancement **#1072** (documented REST API / OpenAPI / webhooks).
- The ledger's TODO section is empty (every ASIDE entry already maps to a modern
  `.html` + BFF), so a genuine net-new Documentation-hub screen was taken instead
  of a legacy port.
- Integrators (CI pipelines, scripts, external tools) need one page that states
  how to authenticate, which HTTP statuses and machine codes are stable, which
  endpoints exist, and how to publish CI results.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/documentation/apiReference.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/apireference/index.php` (`GET\|HEAD ?action=init`, session auth + same-origin guard + session-inactivity check) |
| Wiring | `$actions->apiReference` in `lib/functions/common.php`, consumed by `api/aside/index.php` section 12 (Documentation) |
| i18n | `apiref.*` (87 keys) + `footers.apiReference` in **all 10** client bundles; `$TLS_title_api_reference` in **all 19** server locales |

## Content

- **About this API** — intro card plus a live badge counting the API modules
  discovered *on this installation* (the catalogue is produced by globbing
  `api/<area>/index.php`, so it can never drift from reality).
- **Authentication & request contract** — four rules: session cookie
  (`/login.php` or `POST api/auth` → `not_authenticated` on 401), same-origin on
  state-changing routes (403 on a foreign `Origin`/`Referer`, `POST` only → 405),
  session inactivity (`session_expired`), and the JSON error envelope
  `{ status, code, message }` — branch on status/code, never on message text.
- **HTTP status & machine codes** — the stable error contract shared by every
  route: 400, 401, 403, 404, 405, 409, 413, 422, 500 with example machine codes.
- **Endpoint catalogue** — one card per functional group (session, admin,
  project, spec, reqs, plans, execute, reports, integrations) with the module
  count and machine chips for every `/api/<area>/`; groups with no modules are
  skipped rather than shown empty.
- **Frequently used endpoints** — the 11 routes an integrator needs first
  (auth, aside, projects, plans, test case edit, set results, results import,
  CI/CD publish, attachments, reports, report export) with method + path.
- **Automated result publishing (CI/CD)** — the `cicdpublish` endpoint, the
  accepted formats (`junit`, `json`) and the required `testplan_execute` right.
- **Related in TestLink** — deep links (current `tproject_id`/`tplan_id`,
  `target=_top`) to the Documentation hub, Learn Testing, ISTQB Syllabus,
  Accessibility Resources and Test Strategy.
- **Toolbar** — Refresh and a live filter over endpoints, groups and routes,
  with a translated empty state.



## State cards

Machine-code state cards replace raw failures: **401** (session — shows the
*Log in* action → `/login.php?note=expired`), **403**, **400**, **405** and
**500**, each with the translated message and the raw `HTTP nnn - code` line.
The page content is hidden while a state card is shown.

## BFF contract

`GET|HEAD /api/apireference/index.php?action=init` (default) →

```json
{ "status":"ok", "auth":{...}, "ctx":"tproject_id=..&tplan_id=..",
  "totalModules":160, "groups":[...], "featured":[...], "errors":[...],
  "publish":{...}, "related":[...] }
```

Errors: `400 unknown_action`, `401 not_authenticated|session_expired`,
`403 foreign-origin` (state-changing methods only — `bffSameOriginGuard()`
returns early for `GET`/`HEAD`/`OPTIONS`, see `api/_guard.php:104`),
`405 method_not_allowed`. Every human label is returned as an **i18n key**
(`apiref.*`) — no string is hardcoded server-side.

## Verification

- Browser suite **Issue #1937 = 26/26 PASS** (append-only suite in
  `tmp/TLU_Test_Cases.md`; gate `TLU_REQUIRE_SUITE="Issue #1937"
  bash ai/verify_test_suites.sh` → 34 PASS / 0 FAIL, 927 → 928 suites, none lost).
- `bash ai/verify_i18n_coverage.sh` → 9/9 bundles, 8714 keys, 0 missing.
- Event Viewer clean (3 rows, all `LOGIN` INFO, no Error/Warning).
- Console clean on the screen; one **pre-existing** shell a11y issue
  (navBar `<label>`s without `for=`) filed as
  [bug #1938](https://github.com/sebiboga/testlink-upgraded/issues/1938).

## Discovery

`bffSameOriginGuard()` skips `GET`/`HEAD`/`OPTIONS`, so a foreign `Origin` on a
**read** is answered 200, not 403. The first draft of `apiref.authOriginDesc`
implied every foreign-origin request is refused; the wording was corrected in
all 10 bundles + the HTML fallback to: *"state-changing routes verify
Origin/Referer first — a foreign origin is refused with HTTP 403, and only POST
is accepted (HTTP 405 otherwise)."*
