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
  missing `exec_id`, session expired, not authenticated, access denied (safety net — the
  BFF collapses it into the not-found answer), execution not found, bad request, backend
  unreachable.
- A note stored as invalid UTF-8 (a Latin-1 byte, a truncated sequence — exactly what a
  1.9.20 database carries) is preserved and shown, not silently reported as "no notes".

## Permissions / Edge Cases
- The right is enforced on the **owning test project**, resolved by walking
  `executions -> testplans -> testproject` and never from a client-supplied id.
  Granted by `exec_ro_access`, `exec_edit_notes` or `testplan_execute` — identical to
  `api/execnotes`, so the standalone viewer and the in-page popup can never disagree.
- If the owning test plan cannot be resolved the endpoint **fails closed with 404**
  instead of falling back to the session project (that would be a confused-deputy read).
- **"No rights" and "no such execution" are byte-identical answers.** Answering 403
  for one and 404 for the other is an existence oracle: any authenticated user could
  enumerate `?exec_id=1..N` and learn which executions exist across every test project
  (same ruling as issue #1792). The client keeps an "Access denied" card as a permanent
  safety net, but the BFF never emits 403.
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

The shim `require`s the BFF **in-process** rather than proxying it over HTTP: the
fragment must keep the same session cookie, status codes and body, and a nested
self-request is not available (the dev server is single-threaded, so it deadlocks
into a 502).

## Screenshots
![Execution Notes (read-only) — rich note](../images/execNotesReadonly_rich.png)
![Execution Notes (read-only) — no notes recorded](../images/execNotesReadonly_empty.png)
![Execution Notes (read-only) — stored XSS payload rendered inert](../images/execNotesReadonly_xss_inert.png)
![Execution Notes (read-only) — Access denied for a role-3 user](../images/execNotesReadonly_noright.png)

## Regression
Regression Suite #1807 (34 cases) covers: both BFF routes, id validation
(400/404/405/401/403), cross-project fail-closed, the role-3 refusal on **both** the
modern and the legacy-fragment path, the shim's 302 vs fragment split, the same-origin
gate, XSS inertness on the fragment and on the page, the missing-id and not-found
cards, the edit button being correctly enabled/disabled, the Romanian locale switch,
all ten bundles validating, and a clean Event Viewer. All PASS.