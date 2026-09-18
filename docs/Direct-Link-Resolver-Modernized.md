# Direct-Link Resolver — Modernized Screen

Modernization of the **Deep-link resolver** entry point (`linkto.php`) for
requirements — GitHub issue
[#1532](https://github.com/sebiboga/testlink-upgraded/issues/1532).

Legacy direct links (`linkto.php?item=req&id=<doc_id>`) used to land in a
frameset shell pointing at the legacy requirement viewer. After the ASIDE menu
was fully modernized the *other* remaining legacy surface was the
`linkto.php` deep-link gateway: bookmarks, e-mail links and "copy link" URLs
emitted by the modern requirement screens still pointed at it. This milestone
repointed the **requirement** deep-link family onto a modern, self-contained
resolver page (`gui/templates/links/directLink.html`) backed by a plain-PHP
REST BFF (`api/directlink/index.php`), without touching the other item types.

**URL:** `gui/templates/links/directLink.html?tprojectPrefix=<prefix>&item=req&id=<doc_id>[&version=<n>]`
**BFF API:** `api/directlink/index.php` (`GET ?action=resolve`)
**Rights:** authenticated session required; `mgt_view_req` on the owning project (403 otherwise)
**Compatible alias:** `tproject_id=<numeric>` may be used instead of `tprojectPrefix=<prefix>`

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
| Entry point | `linkto.php?item=req&id=<doc_id>` → frameset shell + legacy viewer | `linkto.php` (outer frame **and** inner frame) 302-redirects `item=req` onto the resolver; modern screens emit the resolver URL directly (`api/requirements` `direct_link`, `reqRevisionView.html` copy-link) |
| Resolution | server-side frameset that dumped the id into the legacy viewer | BFF resolves doc ID → project (prefix or numeric id), requirement record, latest or requested version + revision, then the page shows a Dashio item card |
| Item card | n/a (immediate redirect) | project, requirement (doc id), title, version, revision, plus a **copy-link** read-only box and an **Open viewer** button |
| Open viewer | legacy frameset workframe URL | `reqView.html?id=<req_id>&tproject_id=<id>` — the modern requirement viewer |
| Copy link | n/a | read-only input pre-filled with the resolved viewer URL + Copy button (clipboard API with fallback) |
| Version pinning | literal `version` passthrough to the viewer | `/api/directlink` validates the exact version exists (404 on unknown version) and re-links the viewer URL to that version |
| Resolve again | n/a | re-runs the same resolution (also acts as Retry after an error) |
| Locale | per-session UI | the standard `TLi18n` locale switcher; page reads `locale` query param for a fresh explicit locale |
| Anonymous | sent to the login form | resolver detects 401 and bounces to `/index.php` (→ login), same as legacy |
| No right | generic viewer error | 403 → localized "No permission to view requirements in this project" |

## 2. REST API Reference

Only one route is needed. Requires a session (401 anonymous). `item=req` is the
only supported item type; anything else is a 400 (unknown item family kept on
the legacy `linkto.php` shell for backwards compatibility).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=resolve&tprojectPrefix=<prefix>&item=req&id=<doc_id>[&version=<n>]` | resolve doc id to project + requirement + version/revision; returns project and item metadata plus the modern viewer URL | 400 missing/unknown params, 400 unsupported `item`, 401 anonymous, 403 no `mgt_view_req`, 404 unknown project prefix / doc id / version, 405 non-GET (same-origin guard runs first), 500 guarded |

Request may use `tproject_id=<numeric>` instead of `tprojectPrefix` (only one
of the two must be present — both resolve the same project).

`200` payload shape:
```json
{
  "status": "ok",
  "tproject_id": 4, "tproject_name": "DL1532", "tproject_prefix": "DLS2",
  "req_id": "7", "req_doc_id": "DLREQ-001", "req_title": "…",
  "version": "1", "version_id": 8, "revision": "1",
  "href": "/gui/templates/requirements/reqView.html?id=7&tproject_id=4",
  "grant": { "req_mgmt": "yes" }
}
```
When `version` is requested it is validated (`requirement_mgr::get_by_id`,
human version number), the card metadata comes from THAT version, and
`href` gains `&req_version_id=<version_id>` so the modern viewer opens the
pinned version (request params `version_id`/`req_version_id` are honored by
`reqView.html`; its own "This version" direct link then round-trips back via
`&version=N`).

## 3. Legacy parity notes

- `linkto.php?item=req&id=<doc_id>` (no `load`) — outer frame — now 302s to
  the resolver. `linkto.php?load&item=req&…` (inner-facing bookmark) does the
  same.
- Emitting **new** legacy linkto URLs has stopped: `api/requirements`
  `direct_link` and `reqRevisionView.html` copy-link now produce
  `gui/templates/links/directLink.html?…` URLs.
- The other `item=` families (`reqspec`, `testcase`, `testsuite`) stay behind
  `linkto.php` and open the legacy frame shell. Issue #1533 fixed the HTTP 500
  that broke them (the removed `testproject::setSessionProject()` call on the
  inner-frame path is replaced by direct session assignment), so those legacy
  deep links work again — they are not redirected to the resolver, only `req`
  is (the resolver BFF supports requirements only for now).
- Fixture used during testing (created by `tmp/fixtures_1532.php`): test
  project *DL1532* (prefix `DLS2`, id 4), requirement spec *RS-DL* (id 5),
  requirements **DLREQ-001** (id 7) and **DLREQ-002** (id 9).

## 4. i18n Keys

Client-side keys live in the `dl.*` namespace plus `footers.directLink`, added
to **all 10 bundles** (`en`, `ro`, `de`, `es`, `fr`, `it`, `ja`, `pt`, `ru`,
`zh`), validated with `python3 -m json.tool`:

- `dl.resolve` (headline), `dl.resolveTitle` (doc title / page `<title>`)
- `dl.resolveAgain`, `dl.resolvedItem`, `dl.fieldProject`, `dl.fieldRequirement`,
  `dl.fieldTitle`, `dl.fieldVersion`, `dl.fieldRevision`
- `dl.openViewer`, `dl.copyLink`, `dl.copySuccess`, `dl.copyError`
- `dl.errTitle`, `dl.errBoxTitle`, `dl.errViewer`, `dl.loading` (fallback text)
- `footers.directLink`

## 5. Security

- Session-only auth (401 anonymous); the resolver page never leaves the
  app origin.
- Per-project right check `mgt_view_req` inside the BFF (403 for the
  `<no rights>` role, verified both via curl and in-browser).
- Same-origin CSRF guard on every BFF action (shared
  `bffSameOriginGuard()` helper); non-GET returns 405 past the guard, 403
  without the `X-Requested-With` proof.
- Every legacy-class touch is wrapped in guarded try/catch → 500 JSON, no
  leaked stack traces. No raw `$_GET` interpolation anywhere; all ids /
  prefixes are validated before use.
- No secrets in this milestone; no new writes to the DB.

## 6. Testing

Full suite recorded in `tmp/TLU_Test_Cases.md` (Suite 1532), covering:
BFF contract (200 ok / 400 missing params / 400 unsupported item / 404 bad
prefix / 404 bad doc id / 404 bad version / 405 method / 401 anonymous / 403
no-right / `tproject_id` alias), the resolver page state changes (ok card,
not-found, no-permission), locale switch (en→ro), anonymous bounce to login,
`linkto.php` outer+inner frame 302 redirects, `api/requirements`
`direct_link` + `reqRevisionView.html` copy-link emission, and Event Viewer
cleanliness. See GitHub issue
[#1533](https://github.com/sebiboga/testlink-upgraded/issues/1533) for the
pre-existing inner-frame `setSessionProject` fatal found while testing.