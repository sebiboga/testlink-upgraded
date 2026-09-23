# Task 961 — Restore per-tracker Environment column (checkEnv) in the Issue Trackers screen (gap vs legacy)

**Issue:** [#961](https://github.com/sebiboga/testlink-upgraded/issues/961)
**Status:** IMPLEMENTED & VERIFIED (2026-09-23) — branch `task/issue-961`

## The gap

Legacy surfaced each tracker's **environment state** and rendered every tracker
URL:

- `lib/issuetrackers/issueTrackerView.php:23` called
  `getAll(array('output' => 'add_link_count', 'checkEnv' => true))`.
- `tlIssueTracker.class.php:610-616` then ran `$impl::checkEnv()` per tracker
  and filled `env_check_ok` + `env_check_msg` (base
  `issueTrackerInterface::checkEnv()` → `['status'=>true,'msg'=>'OK']`; the
  jira/mantis/gforge SOAP interfaces override it with
  `extension_loaded('soap')` else `'You need to enable SOAP extension'`).
- `gui/templates/dashio/issuetrackers/issueTrackerView.tpl:16,77` rendered the
  **Environment** column (`th_issuetracker_env`) showing `{$item_def.env_check_msg}`.

The modern screen dropped that entirely:

- `api/issuetracker/index.php:97` called `getAll(['output'=>'add_link_count'])`
  WITHOUT `checkEnv`, so env data was never computed server-side.
- `gui/templates/issuetracker/issuetrackerView.html:196-200` derived the "Server
  URL" cell by regex-matching only `<uribase>(.*?)</uribase>`. Tracker templates
  that write `<url>` instead (redmine `redminerestInterface`, gitlab
  `gitlabrestInterface`, and the GitHub-OAuth flow at
  `api/issuetracker/index.php` `POST /oauth/create` →
  `<url>https://api.github.com</url>`) rendered an **empty** cell.

## Measured evidence gathered during INVESTIGATION (before the fix)

Fixtures via SQL on the fresh DB (`issuetrackers` was empty):
`bugzilla-demo` (type 1, `<uribase>`), `redmine-demo` (type 15, `<url>`),
`github-demo` (type 25, `<url>`), `gitlab-demo` (type 22, `<url>`).

- BFF `GET /` JSON items contained only `id/name/type/typeLabel/typeDescr/cfg/
  implementation` — no env fields.
- Screen snapshot: green row URLs only for bugzilla; redmine/github/gitlab
  Server URL cells EMPTY; no Environment column.
- Console: clean (only pre-existing a11y "no label" issue).

## Implementation

### BFF — `api/issuetracker/index.php`

- `GET /` now requests `getAll(['output'=>'add_link_count','checkEnv'=>true])`
  (legacy `issueTrackerView.php:23` parity).
- `trackerToJSON()` now emits `env_check_ok` (bool, default true) and
  `env_check_msg` (string, default '') — the values computed by
  `tlIssueTracker.class.php:610-616`.

### Screen — `gui/templates/issuetracker/issuetrackerView.html`

- New **Environment** column `<th data-i18n="it.environment">` between Type and
  Active (`:55`) — legacy `th_issuetracker_env` parity, shown to ALL viewers.
- `renderTable()` URL extraction now matches `<uribase>` **or** `<url>` so
  redmine/gitlab/github cfg render their URL (previously blank).
- Env cell: green `badge-env-ok` (label `it.envOk`) when `env_check_ok`; red
  `badge-env-ko` showing the raw impl message (e.g. "You need to enable SOAP
  extension") or `it.envKo` otherwise; tooltip carries the impl message — same
  raw output as legacy `issueTrackerView.tpl:77` `{$item_def.env_check_msg}`.

### i18n — all 10 locale bundles

New keys `it.environment`, `it.envOk`, `it.envKo` added to en/ro/fr/de/es/pt/
it/ja/ru/zh, inserted after `it.serverUrl`, each validated with
`python3 -m json.tool`.

## Verification

- **Admin (manager):** all 4 tracker URLs render (bugzilla + the three `<url>`
  trackers); Environment column shows green `OK` for all 4.
- **KO path (in-page render of a fake failed item):** red badge
  `badge-env-ko`, computed bg `rgb(230,96,94)`, white text, message shown —
  exactly the legacy failure branch.
- **View-only user `itview` (right 32 only):** Environment column still
  rendered; Create/GitHub buttons and Actions column hidden (#960 gating
  untouched); BFF `canManage:false`, env fields present.
- **Event Viewer:** `events.log_level IN (1,2)` → 0 rows; only LOGIN/LOGOUT/
  LOGIN_FAILED audit rows (failures = deliberate bad-auth fixture attempts).
- **Console:** no JS errors.

## Notes

Fixture gotcha discovered during testing: a user row with `auth_method='db'`
(lowercase) is treated as EXTERNAL password management by
`tlUser::isPasswordMgtExternal()` (`lib/functions/tlUser.class.php:212-234`), so
`comparePassword` returns `S_PWDMGTEXTERNAL` and login fails; `'DB'` (or NULL,
falling back to `$tlCfg->authentication['method']='DB'`) works. Test-only.

Test suite: `tmp/TLU_Test_Cases.md` → "Suite 961 — Task — Issue #961 …" (6/6 PASS).
Screenshots: `docs/screenshots/issue-961-before-empty-url.png`,
`issue-961-after-env-column.png`, `issue-961-itview-env-column.png`.