# Webhook Subscriptions (webhooks) — Modernized (TestLink 2.0.1)

- Issue: #1946 (tracking) / parent #1072
- Status: Done
- Screen: `gui/templates/system/webhooks.html`
- BFF: `api/webhooks/index.php`
- ASIDE/Actions: `api/aside/index.php` (System, gated on `view_tc`),
  `lib/functions/common.php` (`$actions->webhooks`)
- i18n: `gui/templates/i18n/*.json` (`whk.*`, 78 keys incl. `whk.noMatch`),
  `locale/*/strings.txt` (`$TLS_title_webhooks`)
- DB: `tc_webhooks` (installer DDL `install/sql/mysql/testlink_create_tables.sql`,
  lazy `CREATE TABLE IF NOT EXISTS`, whitelisted in `lib/functions/object.class.php`)
- Tests: `tmp/TLU_Test_Cases.md` → `## Issue #1946`

Net-new for 2.0.1: neither 1.9.20 nor the initial BFF layer shipped a webhook
entity, so nothing was dropped from legacy. This is the third delivery slice of
parent #1072 after the REST reference hub (#1937) and the OpenAPI spec (#1944).

## Features

- Project-scoped list with six metric tiles (subscriptions / active / inactive /
  test OK / test failed / never tested) and status + topic filters with a
  translated no-match state.
- Registry DataTable (Name / Endpoint URL / Topic / Status / Secret / Last test /
  Updated / Actions); the HMAC secret is never echoed back (Set/None chips).
- Create/edit modal (name, URL, event topic, active, optional HMAC secret; an
  empty secret on edit keeps the current one), details modal, delete
  confirmation, and a test-delivery result modal.
- Signed delivery: `X-TestLink-Event: webhook.test` +
  `X-TestLink-Signature: sha256=<HMAC-SHA256 of the raw body with the secret>`.
- Rights: reads require `mgt_view_tc` OR `mgt_modify_tc` on the addressed
  project; writes require `mgt_modify_tc`; anon 401, role-3 403.
- URL safety: credentials embedded in the URL refused (400); loopback /
  link-local / metadata delivery targets refused (SSRF), RFC1918 LAN allowed
  (`$tlCfg->webhooksAllowLocalTargets` opts loopback back in).
- Verb gate before the rights check (405 for a foreign verb); writes prove the
  project exists (no orphan rows); `UNIQUE(testproject_id, name)` with a
  duplicate-key race fallback to 409.

## BFF routes

- `GET|HEAD ?action=init&tproject_id=N` → `{ status, context, domains, stats, items }`
- `GET|HEAD ?action=list&tproject_id=N` → `{ status, items }`
- `POST ?action=save { tproject_id, id?, name, url, topic, is_active, secret? }`
- `POST ?action=delete { tproject_id, id }`
- `POST ?action=test { tproject_id, id }` → `{ status, test }` or 502 `delivery_failed`

Stable codes: 400 `no_testproject`/`name_required`/`bad_url`/`bad_topic`/
`unknown_action`, 401 `not_authenticated`, 403 `no_right`, 404 `tproject_not_found`/
`webhook_not_found`, 405 `wrong_method`, 409 `duplicate_name`, 500 `server_error`,
502 `delivery_failed`.

## Testing

- Suite `Issue #1946` 15/15 PASS in `tmp/TLU_Test_Cases.md`; gate
  `TLU_REQUIRE_SUITE="Issue #1946"` PASS (none lost).
- Browser (admin + role-3 `whknorights`): full CRUD, signed test delivery,
  duplicate 409, credentials-in-URL 400, filters + no-match, Romanian locale,
  403 state card, ASIDE entry hidden for role 3, console clean (0 issues).
- Event Viewer AUDIT-only (0 Error/Warning).
- Code review (subagent) fixed 1 BLOCKER (missing `bffEnforceSession`) + 2 MAJOR
  (JSON 500 handler, SSRF) — commit `9cb41e038`.

## Changelog

- Refs #1946, parent #1072.
