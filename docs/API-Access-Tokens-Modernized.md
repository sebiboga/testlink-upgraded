# API Access Tokens manager — central review / copy / rotate of the two API credentials (Refs #1958, parent #1072)

Net-new slice of the REST API feature (#1072). TestLink 1.9.20 and 2.0.1 expose
**no UI** for the credentials its remote API already uses:

- the **personal token** `users.script_key` (32 hex) that XML-RPC / REST callers
  authenticate with — 1.9.20 had no screen for it at all;
- the **per-plan API key** `testplans.api_key` (64 hex) used by the public
  share-link / automation endpoints — it only ever appeared inside the plan Edit
  form.

There is no legacy twin to port, so this is a net-new Dashio screen + BFF that
gives one place to see, copy and rotate both.

Documentation mirror of the wiki page; screenshots omitted from this file.

## Entry point

- Screen: `gui/templates/system/apiTokens.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/apitokens/index.php`
- ASIDE menu: section **System**, item **API Access Tokens** (icon `fa fa-key`),
  always visible to an authenticated user (the personal token is always theirs).
- URL of the action: `$actions->apiTokens` (`lib/functions/common.php`).
- Server label: `$TLS_title_api_tokens` in all 19 `locale/*/strings.txt`.
- No legacy controller/template to retire — the module is net-new.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Manageable plans, Personal token (Active / Not set), Key length |
| Signed-in account | Login + email of the caller |
| Personal token | Masked value; **Show / Hide** toggles the full value, **Copy** writes it to the clipboard, **Rotate** re-issues it behind a confirmation modal (the old value stops working immediately) |
| Test plan API keys | DataTable of every plan the caller may manage (project / plan / state chips / masked key / show-copy-rotate actions), sortable + searchable, translated no-plans empty state |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `apitok.noRight` / `apitok.errTitle` machine-code states for 401/403/500 |

Masking: a key is shown as `*` for all but the last 8 characters until the user
presses Show; the raw value is only ever placed into the DOM text node / clipboard
by an explicit user gesture.

## BFF contract (`api/apitokens/index.php`)

| Route | Verb | Result |
|---|---|---|
| `?action=init` | GET/HEAD | `{context, stats, user_token, plans:[{id,name,project_id,project,active,is_public,api_key,can_rotate}], rights}` |
| `?action=rotate_user` | POST | `{token:"<32 hex>"}` — rotates the **caller's own** `users.script_key` |
| `?action=rotate_plan` `{tplan_id, project_id}` | POST | `{tplan_id, api_key:"<64 hex>"}` |

Security:

- session auth + `bffSameOriginGuard()` + `bffEnforceSession()` (the shared
  middleware); verb gate before the rights check.
- the personal token is **only ever rotated for the caller** — the endpoint takes
  no user id, so it cannot act on another account;
- a plan key is listed/rotated only for a caller holding `testplan_planning` on
  the addressed project, and the check runs **before** the plan is resolved so a
  caller without the right gets an opaque `404` for both an absent and an
  unauthorized plan (the #1697 no-oracle lesson); `rotate_plan` therefore takes
  the `project_id` the screen already has and re-proves the plan belongs to it;
- the new plan key retries on the `UNIQUE(testplans.api_key)` collision and is
  generated with `random_bytes(32)` (`bin2hex`);
- every write emits an AUDIT event (`API_TOKEN_ROTATE_USER` / `API_TOKEN_ROTATE_PLAN`);
- a fatal is turned into a JSON `500` by `set_exception_handler`.

Machine codes: `400` (`unknown_action`, `invalid_request`), `401`
(`not_authenticated`, `session_expired`), `403` (`no_right` + the CSRF
same-origin guard), `404` (`tplan_not_found`), `405` (`method_not_allowed`),
`500` (`server_error`).

## Wiring

- `$actions->apiTokens` in `lib/functions/common.php`.
- ASIDE System entry `apiTokens` in `api/aside/index.php` (always visible, after
  the event viewer).
- `apitok.*` (48 keys) in **all 10** client bundles —
  `ai/verify_i18n_coverage.sh` 9/9 PASS.
- `$TLS_title_api_tokens` in **all 19** server locales.

## Verification

- BFF: init / rotate_user / rotate_plan / unknown-plan 404 / role-3 403 / CSRF
  foreign-origin 403 / 405 / unknown-action 400 / anon 401.
- Browser (admin + role 3): render, tiles, show/hide, plan-key reveal, rotate
  user + plan, Romanian locale (no raw keys), no-right empty table, console
  clean (only the shared DataTables filter a11y hint), Event Viewer AUDIT-only.
- Suite `Issue #1958` in `tmp/TLU_Test_Cases.md` (gate 34 PASS / 0 FAIL).
