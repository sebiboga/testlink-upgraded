# Test Plan Defect Verification Gap (defectVerification)

Modernized screen for issue [#2044](https://github.com/sebiboga/testlink-upgraded/issues/2044) in TestLink 2.0.1.

- Screen: `gui/templates/results/defectVerification.html`
- BFF: `api/defectverification/index.php`
- Rights: `testplan_metrics` on the owning test project (read-only)
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=projects`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&verdict=V][&bug_id=BUG]`,
  `GET|HEAD ?action=detail&tplan_id=N&bug_id=BUG[...]`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`
- Fixture: `tmp/fixtures_2044.php`
- Test suite: `Issue #2044` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 recorded bug links on individual executions, but only exposed them in per-execution popups. There was no plan-level answer to the release question: which linked defects still lack verification evidence, which were linked to executions that already passed (escaped defects), and which were later confirmed by a passing execution of the same test-case version? This 2.0.1 report turns those execution links into an auditable defect-verification gap list.

## Screen

- Toolbar: test plan / project context, Build, Platform, Verdict and Defect ID pickers, Refresh (reloads the report with the current controls, not stale URL defaults), Export CSV, Close.
- 10 KPI cards: linked defects, bug links, escaped, unverified, verified, verification rate, affected cases, builds in scope, platforms in scope and builds with links.
- Plan verdict banner (`no_data` / `clear` / `watch` / `at_risk`) and localized empty/error state cards including the 403 `no_right` state.
- Searchable/sortable DataTable with defect ID (opens the evidence modal), defect verdict, links, passed links, verified/unverified/escaped counts, verification rate, affected cases, builds, platforms and latest linked result.
- Per-defect evidence modal with every link, normalized link status, per-link verification badge, execution time, build, platform, tester, later-pass evidence and notes.
- CSV export with UTF-8 BOM, `;` separator, RFC-style quoting and formula-injection guard; disabled when there are no defects.
- Localized 400/401/403/404/405 states and a `TLi18n` locale switcher; all labels, KPIs, DataTables strings and messages come from all 10 client bundles.

## Semantics

- The unit is one literal `execution_bugs.bug_id` referenced by an execution in the selected plan. An execution counts only when its `tcversion_id` is assigned to the plan through `testplan_tcversions` (a `platform_id=0` assignment applies to every platform).
- Build and Platform filters re-scope both the links and the follow-up evidence. Defect ID is an exact case-sensitive filter. Verdict filters operate on the aggregated defect verdict.
- Link statuses are normalized as `p` → passed, `f` → failed, `b` → blocked and anything else → not run. A link made on a passing execution is `escaped`. A link made on a non-passing execution is `verified` when a strictly later passing execution of the same `tcversion_id` and platform exists inside the scoped plan (ordered by `execution_ts`, then execution ID); otherwise it is `unverified`.
- Defect verdict precedence is `escaped` > `unverified` > `verified`. Verification rate is `verified / (links - escaped) * 100`, rounded to one decimal; escaped links are excluded from the denominator.
- KPIs describe the current BFF scope, including Build, Platform and exact Defect ID re-scoping; the Verdict filter only narrows the DataTable, detail rows and CSV. Affected cases are unique external case IDs that occur in the defect evidence. `builds with links` counts distinct link build names.
- Plan verdict is `no_data` without defects, `at_risk` when any escaped defect exists, `watch` when any unverified defect exists, otherwise `clear`.
- `tproject_id` is asserted against the plan's owning project only after the plan is resolved and `testplan_metrics` is checked on that owner, so foreign-project probes cannot enumerate plans. Denials are written to AUDIT.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, invalid plan/build/platform/verdict/bug_id or missing detail defect | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `testplan_metrics` right | 403 | `no_right` (AUDIT) |
| Unknown plan / wrong project / defect outside plan | 404 | `tplan_not_found` / `project_mismatch` / `defect_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |
| POST without `Origin`/`Referer` | 403 | same-origin guard denial |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; responses carry `nosniff` and `Cache-Control: no-store`. CSV cells starting with `=`, `+`, `-` or `@` are prefixed with an apostrophe.

## Wiring

- `cfg/reports.cfg.php` — `defect_verification`
- `api/aside/index.php` — Reports entry for `defectVerification.html`
- `api/resultsnav/index.php` — `defect_verification` map row
- `lib/functions/common.php` — `$actions->defectVerification`
- `gui/templates/i18n/*.json` — 84 `dvg.*` + footer keys in all 10 bundles (`ai/verify_i18n_coverage.sh`: 9/9 PASS, 12922 keys)
- `locale/*/strings.txt` — `$TLS_link_report_defect_verification` (19 locales)

## Verification

Fixture `tmp/fixtures_2044.php` creates project DVG2044 (plan 2, empty plan 3, two builds, two platforms, four cases, six executions and five bug links) and a role-3 user `dvg2044norights`.

Suite `Issue #2044` has 32 executed checks (2044.1–2044.32) covering the BFF contract, security, filters, deep links, modal, CSV, RO locale, empty plan, no-right state, Event Viewer and gate checks; the repository suite gate reports 34/34 with no suite lost. Bug #2045 (build/platform names rendered as booleans) and bug #2046 (deep-link filters ignored) were filed with the `bug` label and fixed in separate commits. Event Viewer after the browser pass contained only AUDIT rows (project creation, logins and the expected no-right denial), with no new Error or Warning entries.

## Files

- `gui/templates/results/defectVerification.html`
- `api/defectverification/index.php`
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json`
- `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`, `lib/functions/common.php`
- `tmp/fixtures_2044.php`, `tmp/TLU_Test_Cases.md`
