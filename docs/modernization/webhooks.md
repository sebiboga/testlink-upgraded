# Webhook Subscriptions (webhooks)

- Issue: #1946 (parent #1072)
- Status: Done
- Screen: `gui/templates/system/webhooks.html`
- BFF: `api/webhooks/index.php`
- ASIDE/Actions: `api/aside/index.php` (System gated by `view_tc`), `lib/functions/common.php` (`$actions->webhooks`)
- i18n: `gui/templates/i18n/*.json` (`whk.*`), `locale/*/strings.txt` (`$TLS_title_webhooks`)
- DB: `tc_webhooks` (DDL in `install/sql/mysql/testlink_create_tables.sql`, whitelist in `lib/functions/object.class.php`)
- Tests: `tmp/TLU_Test_Cases.md` `## Issue #1946 ...`

## Features
- Project-scoped list with tiles (subscriptions/active/inactive/test ok/failed/never tested) and filters (status/topic/search)
- Create/edit modal (name, URL, topic, active, optional HMAC secret; secret kept on update if omitted)
- Details modal, delete confirmation, test delivery button with result modal
- Signed test delivery (HMAC-SHA256 header `X-TestLink-Signature` when secret set); delivery failures recorded (HTTP/code/error/duration)
- Rights: reads require `mgt_view_tc` OR `mgt_modify_tc` on addressed project; writes require `mgt_modify_tc` (anon 401, norights 403)
- URL safety: credentials in URL refused (400)

## Testing
- Browser: login admin/admin -> System -> Webhook Subscriptions; create/list/test/delete all OK
- BFF smoke matrix passed (duplicate 409, bad_url 400, 401/403/404/405/502)

## Changelog
- Refs #1946, #1072
