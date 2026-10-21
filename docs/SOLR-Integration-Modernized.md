# Apache SOLR Integration — a project-scoped SOLR connection + search preview (Refs #1950, parent #1087)

Net-new for 2.0.1: TestLink 1.9.20 and 2.0.1 ship **no SOLR support at all** — the
parent feature "Integration with Apache SOLR" (#1087) was unimplemented. There is no
legacy twin to port and no orphan controller to retire. This module delivers the
smallest coherent slice of that feature: a project-scoped SOLR connection (enable
flag, endpoint, core/collection, timeout), a **real connection test** and a **search
preview** over the configured core.

Documentation mirror of the wiki page; screenshots omitted from this file.

## Entry point

- Screen: `gui/templates/system/solrIntegration.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/solr/index.php`
- ASIDE menu: section **System** (after *Webhook Subscriptions*), single
  project-scoped item; shown whenever the user can read (`mgt_view_tc`).
- URL of the action: `$actions->solrIntegration` (`lib/functions/common.php`).
- Server label: `$TLS_title_solr_integration` in all 19 `locale/*/strings.txt`.
- No legacy controller/template to retire — the module is net-new.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Configured (endpoint + core present), Enabled, Last test result, Latency |
| Connection settings | Enable checkbox, endpoint (absolute http(s)), core/collection, timeout 500–15000 ms |
| Save | Validates endpoint (http/https, no credentials, no query/fragment), core name (`[A-Za-z0-9_.-]`), timeout; a disabled integration may keep empty endpoint/core |
| Test connection | POSTs a `*:*` query with `rows=0`; updates the Last-test tile + persists `last_test_*` columns; connection failure → 502 |
| Search preview | Runs a query (default `*:*`) and lists the top 10 documents (`id` + `title`) with `numFound` and latency |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `solr.stateNoRight` / `solr.stateNoProject` / `solr.errTitle` machine-code states for 400/401/403/404/405 |

## Data model

One table, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly imported
database works unchanged; the matching DDL is also added to
`install/sql/mysql/testlink_create_tables.sql` and the table is whitelisted in
`lib/functions/object.class.php`:

`tc_solr_config` — one row per test project: `testproject_id` (PK), `enabled`,
`endpoint`, `core_select`, `timeout_ms`, `update_ts`, `last_test_ts`,
`last_test_code`, `last_test_ok`, `last_test_latency_ms`.

## BFF contract (`api/solr/index.php`)

| Route | Verb | Result |
|---|---|---|
| `?action=init&tproject_id=N` | GET/HEAD | `{context, settings, lastTest, limits}` |
| `?action=save` | POST | `{settings}` — validates endpoint/core/timeout |
| `?action=test` | POST | `{test:{ok,latency_ms,http_code,num_found}}` or 502 |
| `?action=preview` | POST | `{num_found, docs:[{id,title}], latency_ms}` or 502 |

Rights are checked **on the addressed project before it is resolved** (the #1697
no-oracle lesson): reads need `mgt_view_tc` **or** `mgt_modify_tc`, writes need
`mgt_modify_tc`. Machine codes: `400` (`unknown_action`, `no_testproject`,
`bad_endpoint`, `bad_core`, `bad_timeout`, `not_configured`, `blocked_target`),
`401` (`not_authenticated`, `user_not_found`, `session_expired`), `403` (`no_right`
and the CSRF same-origin guard), `404` (`tproject_not_found`), `405`
(`method_not_allowed`), `502` (`connection_failed`, `solr_error`, `invalid_response`,
`response_too_large`), `500` (`server_error`).

## Security

- **CSRF**: every non-safe verb passes the shared `bffSameOriginGuard()`.
- **SSRF**: the endpoint is http/https only, embedded credentials are refused,
  redirects are never followed, and loopback/link-local/metadata targets are
  refused (`blocked_target`) unless `$tlCfg->solrAllowLocalTargets` opts in; LAN
  targets stay allowed so an on-premise SOLR keeps working.
- **Resource caps**: 512 KB response cap, connect timeout capped at 2 s, total
  timeout 500–15000 ms; `set_exception_handler` keeps the JSON contract on error.
- **Audit**: writes emit `SOLR_CONFIG_SAVE` / `SOLR_TEST` AUDIT events
  (`object_type=testprojects`) so the Event Viewer tracks the module.

## Tests

Suite `Issue #1950` in `tmp/TLU_Test_Cases.md` (14 cases); fixture
`php tmp/fixtures_1950.php` creates/reuses test project `SOLR1950` (prefix `SLR`) and
grants admin project role 8. Browser-verified EN + RO, save/test/preview incl. the
`blocked_target` SSRF refusal, 400/401/403/404/405 contract, Event Viewer clean.
