# Execution Notes (read-only viewer) — Modernized

## Overview
The legacy read-only Execution Notes viewer (`lib/execute/getExecNotes.php` +
`gui/templates/dashio/execute/getExecNotes.tpl`) was replaced by a modern Dashio
standalone screen backed by a REST BFF. The legacy controller survives only as a
non-mutating shim: a browser navigation is redirected to the modern screen, and
the AJAX fragment contract the old `url2load()` call sites rely on is served by
the BFF with the same authorization, escaping and status codes.

Deep link: `gui/templates/execute/execNotesReadonly.html?exec_id=<execution id>`

Refs: #1807 (screen), #1808 (security fix of the legacy controller)

## Architecture (2.0.1)
- Modern UI: `gui/templates/execute/execNotesReadonly.html` (Dashio shell, context
  meta grid, no Smarty, no web editor)
- BFF API: `api/execnotesreadonly/index.php?action=view&exec_id=N` (JSON) and
  `?action=fragment&exec_id=N` (escaped `text/html`)
- Legacy shim: `lib/execute/getExecNotes.php` (302 for browser navigation; in-process
  call into the BFF for the XHR fragment)
- Canonical action: `$actions->execNotesReadonly` in `lib/functions/common.php`
- i18n: 29 `enro.*` keys in all ten bundles `gui/templates/i18n/{en,ro,de,fr,es,it,pt,ru,ja,zh}.json`

## Behavior / Features
- Renders the note together with its context: test case name + `#PREFIX-N` chip,
  suite, test plan, test project, build, platform, execution status badge and
  execution timestamp. The note is never shown without knowing which execution it
  belongs to.
- The stored RichEdit blob is flattened server-side to **plain text**
  (`<br>` and block ends become newlines, everything else is stripped, entities are
  decoded once) and lands in the page via `textContent`, so formatting stored by the
  rich editor is removed and a stored payload can never execute.
- Buttons: **Refresh** reloads the note; **Open editable notes** opens the existing
  editable popup (`execNotes.html?exec_id=N`) and is enabled **only** when the BFF
  reported `can_edit` for the owning project; **Close** closes the window.
- Locale switcher translates every chrome label; the note text itself is data and is
  never translated.
- Explicit state cards, keyed off the BFF `code` and never off the English message:
  missing `exec_id`, session expired, not authenticated, access denied, execution not
  found, bad request, backend unreachable.

## Permissions / Edge Cases
- The right is enforced on the **owning test project**, resolved by walking
  `executions -> testplans -> testproject` and never from a client-supplied id.
  Granted by `exec_ro_access`, `exec_edit_notes` or `testplan_execute` — identical to
  `api/execnotes`, so the standalone viewer and the in-page popup can never disagree.
- If the owning test plan cannot be resolved the endpoint **fails closed with 404**
  instead of falling back to the session project (that would be a confused-deputy read).
- The executed test case version is proved to live in the execution's own project by
  walking `nodes_hierarchy.parent_id` up to the `node_type_id = 1` root; a foreign or
  orphan version yields 404 rather than a foreign test case name next to the note.
- `exec_id` must be a strictly positive integer (the legacy `INT_N` accepted `0` and
  negatives, which then matched nothing and dereferenced `$map[0]` anyway).
- **Empty note** → "No execution notes recorded."; the context grid is still rendered.
- No ASIDE entry: without an `exec_id` the screen can only render a "pick an
  execution" state, so a menu link would be a dead end. The screen is deep-linked
  instead; an execution-picker route is a follow-up.

## Security fixes in the legacy controller (#1808)
The 1.9.20 controller called only `testlinkInitPage($db)` — a session, **no right and
no ownership** — then handed a bare `?exec_id=` straight to the view. Consequences:

- any authenticated user (role 3 `<no rights>` included) could read the notes of any
  execution on any test project by enumerating `?exec_id=`;
- `$map[0]['notes']` was dereferenced with no guard → E_WARNING + fatal 500 on an
  unknown id;
- the stored RichEdit blob was rendered by the web editor, i.e. a stored payload
  executing with the app origin's privileges.

All three now live in the BFF. The shim additionally requires a **same-origin caller**
for the fragment branch, because the legacy call sites assign the answer into
`innerHTML` (`URL/Referer` are validated authoritatively and the `X-Requested-With`
hint never overrides a foreign `Origin`, issue #1679).

### Follow-up: the BFF's own fragment route had no such gate (#1808)

Investigating the above re-opened the security question, and the requirement turned out to be
attached to only **one of the two** entry points that emit the identical fragment.

`api/execnotesreadonly/index.php` exposes `?action=fragment` as a first-class public route — it is
documented in the file's own header and is the whole reason the shim exists. But the same-origin
check lived only inside `lib/execute/getExecNotes.php`, so the same request against the BFF's own
route answered **200 + the notes**:

| Request (as `admin`) | `lib/execute/getExecNotes.php?readonly=1&exec_id=1` | `api/execnotesreadonly/index.php?action=fragment&exec_id=1` |
|---|---|---|
| `Origin: http://evil.example` | `403 cross_origin` | **200 + the note** |
| no `Origin` at all | `403 cross_origin` | **200 + the note** |

**Root cause.** `bffSameOriginGuard()` (`api/_guard.php:102-106`) starts with

```php
if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') { return; }
```

It is a **write-verb CSRF guard**, keyed on the verb rather than on the response shape. On this
endpoint `enro_require_safe_verb()` has already narrowed the verb set to `GET|HEAD`, so the call at
`api/execnotesreadonly/index.php:52` always returned on its first statement — it was structurally
unreachable. The protection therefore depended on which URL a caller happened to use, one rename
away from being gone.

**Fix.** `enro_require_same_origin_fragment()` in the BFF, scoped to `action=fragment` only, with
the same semantics the shim already used — a present `Origin`/`Referer` is authoritative
(unparseable → `403 unparseable_origin`, foreign → `403 cross_origin`, and the
`X-Requested-With` hint never overrides it, issue #1679); neither header → the XRW fallback that a
same-origin `$.ajax` sends; otherwise `403`. It runs **before** the user row is resolved and before
`bffEnforceSession()`, so a foreign caller learns nothing and cannot keep a stale session alive.

`action=view` is deliberately **not** gated: it is JSON, its only caller is the same-origin
`$.getJSON` at `execNotesReadonly.html:169`, and the endpoint sends no
`Access-Control-Allow-Origin`, so it is already unreadable cross-origin. Gating it would have meant
editing a guard shared by 100+ BFF endpoints.

**Scope, stated honestly.** The fragment body is `htmlspecialchars`-escaped and no CORS header is
sent, so the pre-fix 200 was **not** a readable cross-origin disclosure and nothing executed with it
— the invariant was broken, not the confidentiality of that one route. What changed is that the
`innerHTML`-sink requirement now holds regardless of which of the two URLs a caller uses.

### Code review findings applied

The mandatory subagent review returned **no blockers** and 5 minor items. Applied:

1. **`?action[]=fragment` / `?exec_id[]=1` wrote an E_WARNING into the Event Viewer.** A bare
   `(string)` cast on an array emits *"Array to string conversion"*, and
   `watchPHPErrors()` (`lib/functions/logger.class.php:1483`) routes that into the `events` table —
   **unauthenticated**, since the parameter parse runs before the session check. Both parameters are
   now `is_scalar()`-guarded. A non-scalar is refused outright rather than coerced to `''`: `''`
   falls through to the `if ($action === '') { $action = 'view'; }` default and would silently serve
   the view payload for a malformed parameter. *That regression was introduced by the first attempt
   and caught by the new group-10 test, which measured `200` with the note body before the
   correction.* The repo-wide pattern (~12 remaining call sites) is filed as **#1810**.
2. **`enro_forbid_origin()` gained the `headers_sent()` guard** its siblings `enro_fail()` and
   `bffRejectForbidden()` already have, so a refusal can never degrade into a `200` should the
   bootstrap ever emit output first.
3. **The "IDENTICAL to the shim" claim was false** and has been corrected. The shim requires *every*
   present header to match; the gate returns on the first match, matching the shared guard at
   `api/_guard.php:126-145`. The only divergent combination is a same-origin `Origin` paired with a
   foreign `Referer` — measured on both endpoints as group 10.6/10.7 — where the shim runs first and
   is the stricter one, so the effective verdict never changes and no legacy call site can break.

Accepted but **not** applied: the gate runs after the DB connect, so a request destined for `403`
still costs one. Reordering it would disturb the documented verb-check sequence for a cosmetic gain.

**Verified** — the 8-case origin matrix passes 8/8, and all 15 status codes of the pre-existing
rights / id-validation / no-session / POST / 302 probes are unchanged (captured before and after the
change and diffed programmatically). In headless Chrome the stored
`<img src=x onerror=alert(1)>` + `<script>` payload arrives as **one text node with 0 injected
elements** and no console errors. The `events` table gained no Error/Warning row — a refused read is
logged `AUDIT` on purpose, so ordinary denials never pollute the Event Viewer. Suite
`Issue #1808` in `tmp/TLU_Test_Cases.md`.


The shim `require`s the BFF **in-process** rather than proxying it over HTTP: the
fragment must keep the same session cookie, status codes and body, and a nested
self-request is not available (the dev server is single-threaded, so it deadlocks
into a 502).

## Screenshots

## Regression
Regression Suite #1807 (30 cases) covers: both BFF routes, id validation
(400/404/405/401/403), cross-project fail-closed, the role-3 refusal on **both** the
modern and the legacy-fragment path, the shim's 302 vs fragment split, the same-origin
gate, XSS inertness on the fragment and on the page, the missing-id and not-found
cards, the edit button being correctly enabled/disabled, the Romanian locale switch,
all ten bundles validating, and a clean Event Viewer. All PASS.