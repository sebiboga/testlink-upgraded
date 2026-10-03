# Bugfix-Issue-1792-api-builds-tplan-id-existence-oracle (#1792)

## Symptom

`api/builds/index.php` — the BFF behind the modern **Builds & Releases** screen
(`gui/templates/plans/buildsView.html`, `gui/templates/plans/buildEdit.html`) — resolved the
addressed test plan **before** it checked rights, so the HTTP status code alone told any
authenticated caller which test plan ids exist in the installation.

Measured live on `http://localhost:8082` (MariaDB `testlink`, freshly imported) as
**`sm1792norights`** (`users.role_id = 3` = `<no rights>`, **0** rows in `role_rights`, **no**
`user_testproject_roles` row) against a **private** test project it holds no role on
(fixture: project **9018**, `is_public = 0`; plan **9019**; build **1**):

| # | Route | `tplan_id` **exists** (9019) | `tplan_id` **absent** (999999) |
|---|---|---|---|
| 1 | `GET /?tplan_id=` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` |
| 2 | `GET /cfields?tplan_id=` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` |
| 3 | `POST /` body `{"tplan_id":…}` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` |
| 4 | `PUT /{id}` body `{"tplan_id":…}` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` |
| 5 | `POST /{id}/flags` body | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` |
| 6 | `DELETE /{id}?tplan_id=` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` |
| 7 | `GET /{id}` (**build** axis) | **403** `Insufficient rights` | **404** `Build not found` |

Walking `tplan_id` upwards and reading only the status code painted the whole set of test
plans (and, on row 7, of builds) of the instance. No plan content is exposed — only
existence — but for a private project that set of ids is itself the first step to everything
else.

## Reproducing it by hand

Two traps, both of which produce a **false negative** (the bug looks absent):

- **The endpoint is path-routed, not `?action=`-routed.** `$segments = explode('/', $path)`
  (`api/builds/index.php:64` post-patch, `:49` pre-patch). The issue body's repro commands (`?action=delete`, …) do not
  reach these handlers at all.
- **The body is JSON.** `getBody()` = `json_decode(file_get_contents('php://input'))` (`:52` pre-patch, `:67` post-patch).
  Posting `tplan_id=…&name=…` as form data answers a uniform
  `400 {"message":"Invalid test plan id"}` for **every** id.
- Login is `POST /login.php` with **`tl_login` / `tl_password`**
  (`gui/templates/dashio/login/login-dashio.tpl:62-67`); the 1.9.20 field names
  (`login` / `password` / `formname`) silently leave the caller anonymous.

Harness: `php tmp/fixtures_1792.php` then `php tmp/verify_1792.php`.

## Root cause

Every plan-addressed handler resolved the row first and asked about rights second:

```php
$ctx = resolveTplan($db, $tplanId);   // :67  -> 404 when the row is absent
if (!canManage($user, $db, $ctx['tproject_id'])) {
    http_response_code(403);           // :142 -> reachable ONLY when the row is there
```

`resolveTplan()` necessarily runs before the permission check, and `canManage()` is only
reached for plans that exist — so the split **is** the status code. `assertBuildInTplan()`
(`:118` pre-patch, `:191` post-patch) carried the same inversion, which is why the build-addressed mutations
(`PUT /{id}`, `POST /{id}/flags`, `DELETE /{id}`) leaked too.

**Contrast with the clean sibling.** `api/plans/index.php:127-131` takes `tproject_id` **from
the request** and calls `canManage()` *before* resolving `/{id}`, so it may honestly answer 403
and then 404: the caller already named the project, so nothing is concealed. `api/builds`
cannot use that shape — its routes carry no `tproject_id` and derive it **from** the plan row
or from `build.testproject_id`. A permission check that needs the row cannot precede the row.

**Why it survived modernization.** The file grew route by route (`#503` project-scoped builds,
`#1030` the `tplan_id=0` list, the custom-fields and flags legs added later) and each leg
independently picked `resolveTplan()`-first — correct for a caller-supplied project, wrong for
a derived one.

## The fix

**Collapse, do not reorder.** Two helpers now carry every refusal of each family, so "absent",
"belongs to another project" and "you may not manage it" are byte-identical:

| family | routes | helper | answer |
|---|---|---|---|
| plan-addressed | `GET /`, `GET /cfields`, `POST /` | `outPlanNotFound()` | `404 "Invalid Test Plan ID"` |
| build-addressed | `GET /{id}`, `PUT /{id}`, `POST /{id}/flags`, `DELETE /{id}` | `outBuildNotFound()` | `404 "Build not found"` + `error_code: build_not_found` |

`resolveTplanGated()` fuses `resolveTplan()` + `canManage()` into one opaque step, so the three
plan routes can no longer express the split even by accident. `resolveTplan()` and
`assertBuildInTplan()` were rewired to the helpers as well, which also removes the split
*between* the two 404 bodies `assertBuildInTplan()` used to emit.

**Why 404 and not 403:** the 403 branch is reachable *only* when the row exists, so keeping 403
for it **is** the bug. A 403 is still returned where it is honest — `api/plans`, and the
`tplan_id=0` project-scoped list, whose project comes from `$_SESSION['testprojectID']` and not
from the caller, so nothing is being hidden there.

**Why not reorder (the issue's suggested fix):** the report asks to "check rights against the
request's `tproject_id` before trusting the plan row". These routes have no request
`tproject_id`, so that step does not exist to be moved. Collapsing is also strictly simpler than
collapsing one axis — which is why the fix closes the `build_id` axis (row 7) as a side effect.

**Alternatives rejected:** returning 403 for both cases (breaks the legitimate "this plan does not
exist" signal for entitled users and still tells an unentitled caller the id was understood);
always 404 including the session-scoped list (hides a real, non-leaking permission fact behind a
lie for no security gain); an opaque token instead of a status (a new mechanism for a defect one
shared helper solves).

### Two corrections to the issue body

1. **The `build_id` axis was not already clean.** The body quotes `:115` as saying a
   non-existent build "answers a plain 404 identical to the refusal" and instructs the fixer to
   "mirror what `build_id` already does". It did not: `GET /{id}` answered **403 vs 404**. That
   comment described an intent the code never implemented, so following the instruction verbatim
   would have copied the defect. The comment has been rewritten to state what the code does.
2. **The split was wider than reported** — six route families, not four, because
   `assertBuildInTplan()` had the same inversion.

## Impact on the UI

No **new** i18n key is needed — every message is one this endpoint already emitted on its 404
path, and all the keys referenced below already exist in **all ten** locale bundles. But the
fix is *not* invisible to the client, and the first version of this document claimed
otherwise; that claim was wrong and is corrected here.

Code review caught two real defects in the client half:

- **`buildEdit.html:193` mapped the wrong string.** `errText()` looked up `'Invalid test plan
  id'` (lower-case `test`, lower-case `id`) while the server emits `'Invalid Test Plan ID'`.
  Case-sensitive key lookup, so the entry never fired: `buildEdit.html:268` (the old 403 →
  `common.forbidden` + `bedit.noRight` card) became unreachable and the modal fell back to
  rendering the **raw English machine string** with code `http_404`, and so did `save()` →
  `fail()` at `:461` on `POST /`. Corrected to the exact server string (the old spelling is
  kept as a second key so no other caller of it can regress).
- **`buildsView.html:255` had no mapping for `'Build not found'` at all**, so `setFlag`,
  `saveBuild` and `doDelete` rendered raw English where they had rendered localized
  `common.forbidden` before. `'Build not found': 'bedit.msg.buildNotFound'` added.

Net effect on an unentitled caller: the localized "no rights" card is replaced by the localized
"not found" state on the two plan-addressed legs — deliberate, since a page that says "you may
not manage this" has just confirmed the plan exists. On `GET /{id}` the not-found state was
already reachable (`buildEdit.html:314-315` keys off `error_code: build_not_found`), so that
route is unchanged. `buildsView.html:255` still maps `Insufficient rights`, which the
`tplan_id=0` list keeps using.

### Residual risk (stated, not papered over)

- **A third body existed on the build axis, one level down.** `resolveBuild()` answered
  `404 "Invalid Test Project ID"` when `build.testproject_id` pointed at a project node that no
  longer resolved — distinguishable from an absent build's `404 "Build not found"`, i.e. the
  same oracle one level deeper. It now fails through the caller's family
  (`resolveBuild(&$db,$b,'plan'|'build')`).
  **Honest caveat:** I could **not** demonstrate this branch was *reachable* pre-patch — every
  `GET` route 404s on the build lookup first, and the mutating routes are stopped earlier by
  the same-origin guard. So it is defence-in-depth that removes a distinct string from the
  code, not a proven live leak, and the new suite section 2b is deliberately labelled
  **invariant-preserving**: it asserts the post-fix property and passes both pre- and
  post-fix, so it must not be counted as discriminating evidence.
- **A timing side channel survives.** An absent id short-circuits after one lookup, a present
  one continues into `canManage()`. Status and body are now identical, but existence remains
  measurable in response time. Not fixed here; it needs constant-work refusal, which is a
  larger change than this bug warrants.

## Verification

| # | Check | Result |
|---|---|---|
| 1 | Oracle closed on the `tplan_id` axis, 6 families × {exists, absent} | **PASS** — identical status **and** body in every pair |
| 2 | Oracle closed on the `build_id` axis, 4 routes × {exists, absent} | **PASS** — identical |
| 3 | No over-blocking: `admin` on all six legs | **PASS** — 200 + plan context; create/rename/flags/delete all still work |
| 4 | Honest 404 retained for `admin` on an absent plan/build | **PASS** — not a blanket denial |
| 5 | `tplan_id=0` session-scoped list keeps its 403 / 400 | **PASS** |
| 6 | Input hygiene: `abc`, `-1`, `1e999`, `0`, `tplan_id[]=1`, omitted, out-of-range, malformed JSON bodies | **PASS** — no 5xx, no PHP diagnostic |
| 7 | Every *positive* `tplan_id` collapses onto one answer for a no-rights caller | **PASS** |
| 8 | Browser (chrome-devtools MCP): screen renders, API calls from the page's origin | **PASS** — 0 console errors |
| 9 | Event Viewer `log_level IN (1,2)` before/after | **PASS** — 0 new ERROR/WARNING |
| 10 | Suite detects the defect | **PASS** — 22 of its 69 assertions fail against the pre-patch file |
| 11 | `resolveBuild()` emits no third 404 body (review finding) | **PASS** — dangling-owner build answers exactly like an absent one (2b; non-discriminating, see residual risk) |
| 12 | Client maps the new answers to existing i18n keys (review finding) | **PASS** — `buildEdit.html` key corrected to the exact server string, `buildsView.html` mapping added; all keys present in 10/10 bundles |

`php tmp/verify_1792.php` → **69 passed, 0 failed**; against the pre-patch file
(`git show c8da709a7^:api/builds/index.php`) → **47 passed, 22 failed**. `php -l` clean. Regression suite: `## Regression — Issue #1792` in
`tmp/TLU_Test_Cases.md`.

![Builds & Releases for an entitled admin after the fix](https://github.com/sebiboga/testlink-upgraded.wiki/blob/master/1792-builds-opaque.png)

## Files changed

| file | change |
|---|---|
| `api/builds/index.php` | `outPlanNotFound()`, `outBuildNotFound()`, `resolveTplanGated()`; all 7 handlers rewired; `resolveTplan()` + `assertBuildInTplan()` use the helpers; header docblock and the misleading `:115` comment rewritten |
| `gui/templates/plans/buildEdit.html` | `errText()` key corrected to the exact server string `Invalid Test Plan ID` (see "Impact on the UI") |
| `tmp/fixtures_1792.php` | isolated project/plan/build + `role_id = 3` user, self-validating, idempotent |
| `tmp/verify_1792.php` | 69-assertion regression harness (fails 22 against the pre-patch file) |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1792` suite, sections A–G |
| `CHANGELOG` | `[KEY BUGFIX - SECURITY] - #1792` |
