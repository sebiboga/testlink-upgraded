# Test Specification Complexity Profile (specComplexity) — Modernized (2.0.1)

Issue: **#2092**. Screen: `gui/templates/testcases/specComplexity.html`. BFF: `api/speccomplexity/index.php`.

This net-new read-only project report answers a question the 1.9.20 test-specification screens could not answer in one place: which test-case *designs* are unusually heavy, and what makes them heavy (steps, expected results, linked requirements, keywords, active project design custom fields and long summaries)?

## Why a new screen

Legacy screens list test cases and their steps, requirements and keywords separately. `specComplexity` joins the project suite tree, latest `tcversions`, `tcsteps`, `req_coverage`, `object_keywords` and active design custom fields into one Dashio report with a transparent score and high/medium/low bands. It is a design-time report: no execution evidence is used.

## Screen

- Toolbar: Suite (full nested path), Complexity band and Min. steps filters, Refresh, Export CSV, Apply and Close.
- Context panel: project, applied suite, band and minimum steps.
- Verdict banner: none / high / medium / clear, localized and derived from the applied scope.
- KPI strip: cases, high/medium/low counts, average score, steps, steps missing an expected result, cases without requirement, cases without keyword and cases with design fields.
- DataTable: prefixed external ID, case, suite path, version, steps, expected %, requirements, keywords, design fields, summary characters, score and band, plus a Details button.
- Detail modal: summary, every design step with expected result, keywords, linked requirement IDs and active design custom-field values; Escape and Close dismiss it and focus returns to the trigger.
- UTF-8 CSV with BOM, `;` delimiter, quoted cells and formula-injection guard; localized 400/401/403/404/405 state cards and a `TLi18n` locale switcher.

## Semantics

- Scope is the latest version of every test case under the requested project and optional suite subtree. Case IDs and version IDs are `intval`-guarded before being interpolated into SQL.
- `score = steps × 2 + round(expected-result coverage / 10) + requirements × 3 + keywords × 2 + filled design fields × 2 + min(5, floor(summary characters / 200))`.
- Bands: `high` when score >= 25, `medium` when score >= 12, otherwise `low`. Every input and its contribution is visible in the row and detail modal.
- Only project-linked custom fields with `ctp.active = 1` and `cf.enable_on_design = 1` count, both in the score and in the detail modal. Values must be non-empty.
- Requirements use active `req_coverage` links of the latest version; keywords use `object_keywords.fk_table = 'tcversions'`. Both are counted distinctly.
- External IDs are shown as `<project prefix>-<tc_external_id>`; when a legacy case has no positive external ID the raw value is shown without a leading dash.
- Rights (`mgt_view_tc`) are checked on the addressed project before the project record is loaded. Denials return an opaque `no_right` code and write an AUDIT event.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET\|HEAD` `action=init`, `action=report`, `action=case` success | 200 | `ok` |
| Unknown action / invalid parameters | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `mgt_view_tc` on the addressed project | 403 | `no_right` (AUDIT) |
| Unknown project / unknown test case | 404 | `tproject_not_found` / `tcase_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, `nosniff` and `no-store` headers, integer-only IDs, escaped client rendering, whitelisted bands and AUDIT logging for denials.

## Wiring

- `api/speccomplexity/index.php` — `init`, `report` and `case`.
- `lib/functions/common.php` — `$actions->specComplexity`.
- `api/aside/index.php` — Test Case Design entry with project and plan IDs.
- `gui/templates/i18n/*.json` — 82 `scx.*`/footer keys in all 10 bundles; `ai/verify_i18n_coverage.sh` passes 9/9 with 13872 keys.
- `locale/*/strings.txt` — `$TLS_href_spec_complexity` in all 19 server locales.

## Verification

- Fixture `tmp/fixtures_2092.php`: projects `SCX2092` and `SCX2092 EMPTY`, nested suites, four cases (1/4/8/0 steps), two design custom fields, three keywords, two requirement links and a role-3 `scxnorights` user.
- Suite `Issue #2092`: browser-verified normal/filtered/detail/CSV/RO/empty/no-right states, all error cards, i18n and syntax gates, Event Viewer and wiring. Suite gate reports 34 PASS / 0 FAIL / 0 SKIP.
- Bugs found during testing/code review: #2093 (external IDs lost the prefix and raised PHP warnings) and #2094 (design-field KPI mixed filled values with field count). Both were filed with the `bug` label, fixed in separate pushed commits, browser-reverified and closed. The review also aligned detail-modal custom fields with the active/enabled joins and deferred CSV object-URL revocation.
- PHP lint, extracted `node --check`, JSON validation, i18n coverage and suite gates pass. Event Viewer has 0 Error, 0 L18N and 3 historical Warning rows from #2093, all emitted before that fix; no Warning/Error was generated afterwards.
