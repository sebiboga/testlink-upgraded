# Bug fix — Issue #1576: codetracker GET list/detail leaks raw cfg (plaintext token) to codetracker_view-only users

## Symptom
The modern Code Tracker BFF (`api/codetracker/index.php`) returns each
tracker's raw `cfg` field — the DB `codetrackers.cfg` XML — on the READ routes
(`GET /api/codetracker/index.php` list and `GET /api/codetracker/index.php/{id}`
detail). For GitHub trackers that XML stores the access token in plaintext
(`<token>ghp_…</token>`). A user holding only `codetracker_view` (right 52, no
`codetracker_management` right 51) receives the full list/detail including
those tokens, while the same payload already masks the parsed
`github.token` as `********` — the raw `cfg` defeats that masking. Gap vs
legacy `codeTrackerView.tpl`, which only ever rendered name/type/env-check and
never the config XML.

## Reproduction (this run, fresh DB)
1. Fixtures: user `ctviewonly` (role 10 holding ONLY right 52
   `codetracker_view`, verified via `role_rights`), admin (role 8).
   GitHub tracker `repro-1576-gh` (id 2, type 200) created via the API with
   cfg `<codetracker>\n  <repository>https://github.com/acme/secret</repository>\n  <branch>main</branch>\n  <token>ghp_SUPERSECRETTOKEN123</token>\n</codetracker>`.
2. Logged in as `ctviewonly` (isolated browser context):
   `GET /api/codetracker/index.php` and `GET /api/codetracker/index.php/2`.
3. Pre-fix: HTTP 200 on both; `cfg` returned the full XML verbatim with the
   plaintext token, `github.token` = `********`. Post-fix: HTTP 200,
   `cfg` = `""`, `github.token` still `********`.

## Root cause chain
- `api/codetracker/index.php:37` — the page-level gate only splits *allowed*
  (`codetracker_view` OR `codetracker_management`) vs *denied*. A view-only
  user passes and reaches every data route.
- `api/codetracker/index.php:77-88` — the GitHub native parser (issue #433)
  masks only the *parsed* copy: `github['token'] = '********'` when non-empty.
- `api/codetracker/index.php:96` (pre-fix) — `'cfg' => $item['cfg'] ?? ''`
  copied the raw XML (token/API keys in plaintext) verbatim into EVERY item for
  EVERY user. The parsed-member mask is invisible to it.
- Both read routes reuse the same helper: beacon list `:110`, detail `:134`.
- Legacy expectation: `gui/templates/dashio/codetrackers/codeTrackerView.tpl`
  never surfaces the XML; the cfg only reaches the browser through the legacy
  EDIT page which is gated on `codetracker_management`
  (`codeTrackerEdit.php:181-184`).

**Why it breaks NOW**: the GitHub-native BFF work (issue #433) kept raw `cfg`
round-tripping because the edit modal prefills the generic XML textarea from it
(`gui/templates/codetracker/codetrackerView.html:263`), but forgot to gate that
field on the management right.

**Blast radius**: `trackerToJSON()` call sites returning cfg — list `:110`,
detail `:134`, create-response `:156`, update-response `:178`, delete-response
`:297`. Frontend consumer: `codetrackerView.html:263`/`:340` (edit prefill /
generic save). Data source: single DB column `codetrackers.cfg`. No i18n keys
involved (no new strings).

## Fix approach
1. `api/codetracker/index.php` — after the read gate:
   `$canManage = ($user->hasRight($db, 'codetracker_management') == 'yes');`
   — mirrors legacy `$gui->canManage` (codeTrackerView.php:24). `hasRight()`
   returns the string `'yes'` or null (lib/functions/roles.inc.php:254-273),
   hence the explicit `== 'yes'`.
2. `trackerToJSON($item, $mgr)` → `trackerToJSON($item, $mgr, $canManage)`;
   the `cfg` member is now `$safeCfg = $canManage ? ($item['cfg'] ?? '') : ''`.
   View-only users get `cfg:''`; managers keep the full raw XML (edit modal
   still prefills). `serverUrl` and the parsed `github.*` fields are derived
   from cfg before this point and keep working without it.
3. All 5 call sites updated (list, detail, create-response, update-response,
   delete-response).

### Alternatives considered and rejected
- Partially masking secret tags inside the XML — the XML is opaque and may
  hold arbitrary credential shapes; not masking everything guarantees another
  leak. Blanking the whole field for view-only matches legacy, which never
  showed it.
- Returning `cfg` on list but redacting on detail — inconsistent; both routes
  use the same helper and the same principle.

## Verification (measured)
- `php -l api/codetracker/index.php` → no syntax errors.
- View-only `ctviewonly`: `GET /list` HTTP 200, every `items[].cfg` = `""`,
  `github.token` = `********`; `GET /detail` `item.cfg` = `""`. Network payload
  confirmed in the browser (request/response captured).
- Admin: list + detail + `PUT` response all keep the full cfg XML; edit modal
  opens with repository/branch prefilled, token input blank.
- Browser screen renders for both roles; console clean (pre-existing a11y
  notice only); Event Viewer `log_level=2 (ERROR/WARNING)` = 0 for the
  valid-typed tracker flow.
- Regression suite 1576 6/6 PASS appended to `tmp/TLU_Test_Cases.md`.

## Files changed
- `api/codetracker/index.php` — `$canManage` + cfg gating in `trackerToJSON`
  (23 insertions, 7 deletions).
- `tmp/TLU_Test_Cases.md` — `Regression — Issue #1576` suite (6/6 PASS).
- `CHANGELOG` — `[KEY BUGFIX] - #1576` entry.
- Wiki mirror page `Bugfix-Issue-1576-CodeTracker-Cfg-Token-Leak.md` (with
  screenshots) + this docs mirror.

## Result
Issue #1576 fixed error-free and pushed (`fix/issue-1576-cfg-token-leak`,
commits 2a75f2e05 + 54149e3eb + docs commit); regression suite 6/6 PASS;
Event Viewer clean. A related out-of-scope finding (BFF accepts unregistered
tracker `type` → E_WARNING noise) was filed separately as issue #1577.