# Public share-link gateway (lnl.php) — Modernized Screen

Modernization of the **public share-link gateway** `lnl.php` (the entry point
for "share" links of anonymous execution prints, direct file downloads and the
Metrics Dashboard) — GitHub issue
[#1541](https://github.com/sebiboga/testlink-upgraded/issues/1541).

The legacy `lnl.php` resolved the `type`/`id` parameters, authenticated the
`apikey` (32-char user key or 64-char "anonymous access" object key) and then
**301-redirected** to a legacy face of the target (Frameset execution print /
`attachmentdownload.php` / `metricsDashboard.php` + `reportPrint.php` variants)
that was session-only and often left anonymous visitors on a fatal broken
surface. This milestone turns the gateway into a **modern Dashio resolver
screen** (`gui/templates/links/publicLink.html`) backed by a plain-PHP REST BFF
(`api/publiclink/index.php`), and threads the same `apikey` through the three
modern BFF targets it drives (execution print, attachments download, metrics
dashboard).

**URL:** `lnl.php?type=<type>&id=<id>&apikey=<key>` → 302 → `gui/templates/links/publicLink.html`
**BFF API:** `api/publiclink/index.php` (`GET ?action=resolve`)
**Auth:** anonymous **object** keys (64 chars) **or** authenticated user keys (32 chars); legacy parity
**Targets:** `exec` (execution print), `file` (attachment download), `metricsdashboard` + req/report-cfg families

Screenshots: `docs/screenshots/issue-1541-execprint-anon.png`,
`docs/screenshots/issue-1541-metrics-anon.png`,
`docs/screenshots/issue-1541-gateway-error-card.png`.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | `lnl.php` hand-built URL params (`type`, `id`, `apikey`), server PHTML | `lnl.php` is a thin **302 dispatcher** → `gui/templates/links/publicLink.html?<original query>` (zero side effects, no DB writes, CR/LF-stripped `Location`) |
| Resolution | server-side `init_args()` guessed type + key flavour and 301'd to a legacy face | BFF `action=resolve` reuses the exact legacy heuristics, returns the modern target URL + a Dashio item card |
| Key model | 32-char → `tlUser::getByAPIKey` (user); 64-char → `setUpEnvForRemoteAccess`/`getEntityByAPIKey` (object) then swapped to the owning object's `api_key` | identical: `exec` swaps to the owning test plan's key, `file`/`metricsdashboard` bind to the owning project's key before the target is called |
| `exec` target | Frameset execution print | `execPrint.html?id=<exec_id>&apikey=<bind>`, rendering fully in anonymous context |
| `file` target | `attachmentdownload.php` (session-only → blank/fatal for anon) | `/api/attachments/index.php?action=download&id=<id>&apikey=<bind>` — real file bytes for anon |
| `metricsdashboard` target | `metricsDashboard.php` (project from session only) | `metricsDashboard.html?type=metricsdashboard&tproject_id=<owning project>&apikey=<bind>` — bound to the **owning project**, not the session's |
| Error handling | blank/phtml fatal | localized Dashio error card ("Link is not valid for anonymous access", 401/403/404 variants) + **Resolve again** retry link, no auto-redirect on failure |
| Auto-navigation | 301 (hard) | 450 ms soft redirect after a successful resolve; Copy link + Open target available on the card |
| Regressions from the apikey threads | — | `api/executionprint`, `api/attachments`, `api/metrics` gained anonymous apikey paths for the exact deep links the gateway targets |

The resolver URL scheme, the auto-redirect behaviour and the error card reuse
the `directLink.html` pattern from issue
[#1532](https://github.com/sebiboga/testlink-upgraded/issues/1532).

## 2. REST API Reference

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=resolve&type=<type>&id=<id>&apikey=<key>` | authenticate the key and map (`type`, `id`) → a modern target URL (`status/type/target/href` + grant) | 400 missing/unknown type or id, 401 no key, 403 key does not match the bound entity / plan, 404 unknown entity, 405 non-GET (same-origin guard first), 500 guarded |

For anonymous access the BFF requires `apikey` to be 64 chars (object keys) —
32-char keys are user keys and still need a session. The resolved `href`
carries the **bound** key (test-plan key for `exec`, project key for
`file`/`metricsdashboard`), because an anonymous share must be re-authenticated
by each target BFF in a fresh context.

## 3. Legacy parity notes

- All `type` families of the legacy `lnl.php` are mapped: the modern targets
  `exec`, `file`, `metricsdashboard` resolve to their modern screens; the
  report-document families (`test_report`, `test_plan`, `testreport_onbuild`,
  `testspec_only_onbuild`) still resolve to the legacy `reports_list.php`
  document URLs (same as legacy), which continue to work and are documented in
  #1541's ticket.
- Share links emitted by the modern screens are unchanged (`Public link` /
  "copy direct link" again produces `lnl.php?…`), so bookmarks, e-mails and
  CI artifacts keep working.
- Anonymous context: the execution-print body now rewrites the legacy inline
  `attachmentdownload.php` attachment links into `/api/attachments` download
  URLs carrying the same key; the delete form controls are not emitted for
  anonymous viewers.
- `tlUser::getByAPIKey()` returns a map **keyed by user id** (no `[0]`), and
  returns `null` on a no-match — all four BFFs guard with `is_array()` and read
  the id via `reset()`. Three in-browser bugs found and fixed this way: `$id`
  being read before its binding in `api/executionprint` (every anon exec link
  wrongly 404'd), a `count(null)` TypeError on fake 32-char keys, and the
  `[0]`-index lookup.
- Fixture used during testing (`tmp/fixtures_1541.php`, re-runnable): test
  project **PSL** (id 1), test plan **Plan PSL** (id 2), executions #1 (passed)
  and #2 (failed), attachment #1 (`hello-share.txt` on execution #1), and its
  plan/project api keys.

## 4. i18n Keys

Client-side keys live in the `plnk.*` namespace plus `footers.publicLink`, added
to **all 10 bundles** (`de, en, es, fr, it, ja, pt, ro, ru, zh`), validated
with `python3 -m json.tool` (22 `plnk.*` keys + the footer per bundle):

- `plnk.title`, `plnk.resolveAgain`, `plnk.resolved`, `plnk.typeLbl`,
  `plnk.targetLbl`
- `plnk.open`, `plnk.copy`, `plnk.copied`, `plnk.loading`
- `plnk.errParams`, `plnk.errBadApikey`, `plnk.errBadType`, `plnk.errBadId`,
  `plnk.errNotFound`, `plnk.errInvalid`, `plnk.errUnknown`, `plnk.errNoTarget`,
  `plnk.errSession`, `plnk.errMethod`, `plnk.errServer`, `plnk.errDefault`,
  `plnk.readonly`
- `footers.publicLink`

Server error codes map to the localized keys on the client (a raw API message
is only a fallback for an unmapped code), so the error card is fully
translated in every locale.

## 5. Security

- Two-key authentication model preserved from the legacy gateway (user keys +
  anonymous object keys); the BFF never trusts a bare `type/id` — it always
  authenticates the key first.
- The `exec`/`file`/`metricsdashboard` anonymous binds are **fail-closed**: the
  key must match the owning test plan (`exec`) or owning project
  (`file`/`metricsdashboard`, via `getEntityByAPIKey` on the exact object) — a
  foreign/forged key gets 403 before any data is produced.
- Same-origin CSRF guard (`bffSameOriginGuard()`) on the resolver; non-GET
  returns 405 past the guard, 403 without the `X-Requested-With` proof.
- All responses JSON with correct HTTP status codes; `Location` on `lnl.php`
  is CR/LF-stripped (header injection guard); no raw `$_GET` interpolation.
- The share targets remain read-only for anonymous users: execution print and
  file download are GETs, delete controls are suppressed.

## 6. Testing

Regression suite `Suite 1541 — Public share-link gateway (lnl.php)` appended to
`tmp/TLU_Test_Cases.md` — **all PASS**. Coverage: resolver BFF contract (ok /
400 / 401 / 403 / 404 / 405 / 500-guard), anonymous exec print rendering, file
download via the gateway, anonymous Metrics Dashboard bound to the owning
project, bad-key error card (no redirect), `Public link` emission from the
logged-in dashboard, fake 32-char anon key → 403 (no 500), Event Viewer clean
(the 8 pre-fix PHP-error rows from the debugging phase were removed after the
root causes were fixed). See the ticket for the browser-verified share URLs and
the full checklist.