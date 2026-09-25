# Bug fix — Issue #1581: stored XSS via tracker name and server URL in codetrackerView

## Symptom

The modern Code Tracker Management screen
(`gui/templates/codetracker/codetrackerView.html`) rendered the
manager-controlled tracker **name** and the **server URL** parsed out of the
stored `cfg` XML as HTML instead of text. A tracker named
`<img src=x onerror=document.body.dataset.xss=1>` therefore executed for **every**
user who opened the Code Tracker list — including users holding only the
`codetracker_view` right (52) and never having had management rights.

The server-URL column is a second instance of the same defect, and it also
reaches script execution: a config
`<codetracker><uribase><img src="x" onerror="document.body.dataset.xssURL=1"/></uribase></codetracker>`
is **well-formed XML**, so `tlCodeTracker::checkXMLCfg()`
(`lib/functions/tlCodeTracker.class.php:689-718`) accepts it, and the value that
`trackerToJSON()` extracts from `<uribase>` is rendered as HTML — measured
pre-fix `document.body.dataset.xssURL === "1"`. Only *malformed* payloads (for
example an unquoted attribute value such as `<img src=x onerror=…>`) are rejected
by the validator, so the validator is not a mitigation.

## Environment and fixtures

- TestLink 2.0.1, PHP built-in server on `http://localhost:8082` (docroot = repo root).
- MariaDB `127.0.0.1:3306`, database `testlink`, freshly imported — the
  `codetrackers` table was empty at the start of the run.
- `admin` (role 8): `codetracker_management` (51) + `codetracker_view` (52) →
  `canManage: true`.
- `ctonly1581` (role 20, created for this run and removed afterwards): **only**
  right 52 `codetracker_view` → `canManage: false`.
- Entry point: `http://localhost:8082/gui/templates/codetracker/codetrackerView.html?tproject_id=0&tplan_id=0`.

## Reproduction before the fix

1. Log in as `admin`.
2. From the authenticated same-origin page send
   `POST /api/codetracker/index.php` with body
   `{"name":"<img src=x onerror=document.body.dataset.xss=1>","type":1,"cfg":"<codetracker><uribase>http://example.test/</uribase></codetracker>"}`
   → HTTP 200, tracker id 1 created.
3. Reload the Code Tracker screen.
4. Inspect the Name cell and the page's `dataset`.

Measured pre-fix result:

- `POST` HTTP 200; the DB row stores the payload verbatim
  (`SELECT name FROM codetrackers` → `<img src=x onerror=…>`, no encoding).
- `document.body.dataset.xss === "1"` → **the payload executed**.
- Name cell `innerHTML` = `<img src="x" onerror="document.body.dataset.xss=1">`,
  `cell.querySelectorAll('img').length === 1` → a real element, not text.
- Row `ZZURLPWN2` (cfg `<codetracker><uribase><b>bold</b></uribase></codetracker>`)
  → Server URL cell contained a live `<b>` element.
- Well-formed payload row `URLXSS` (cfg
  `<codetracker><uribase><img src="x" onerror="document.body.dataset.xssURL=1"/></uribase></codetracker>`,
  created HTTP 200) → Server URL cell `innerHTML` =
  `<img src="x" onerror="document.body.dataset.xssURL=1">` with 1 child node and
  `document.body.dataset.xssURL === "1"` → **the server-URL column also executed
  script**.
- No console error and no Event Viewer row: the payload succeeds silently, which
  is what makes stored XSS dangerous.

The pre-fix state is preserved in
`docs/screenshots/issue-1581-codetracker-stored-xss-prefix.png`.

## Root cause chain

1. `api/codetracker/index.php:205,220` — the create route takes `name` from the
   JSON body and stores it verbatim through `tlCodeTracker`. No HTML encoding,
   *correctly so*: `lib/functions/database.class.php:408-415`
   `prepare_string()` only SQL-quotes, and HTML-escaping does not belong in the
   persistence layer.
2. `api/codetracker/index.php:150,155` — `trackerToJSON()` returns
   `'name' => $item['name']` and `'serverUrl' => $serverUrl` (extracted by the
   regex at `:120-125` from the stored `<uribase>`). A JSON API must return raw
   values: the edit form prefills from them and `PUT` round-trips them.
3. `gui/templates/codetracker/codetrackerView.html:232-238` (pre-fix numbering)
   pushes those raw values straight into the DataTables array data:
   `rows.push([t.name, t.typeDescr || t.typeLabel, t.serverUrl || '', …])`, and
   the `columns` contract declares the first three columns as `null` — i.e. **no
   `render` callback**.
4. DataTables 1.13.7 (`https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js`,
   loaded at `codetrackerView.html:136`) builds a cell with
   `t.innerHTML = S(n,a,e,"display")` (un-minified:
   `nTd.innerHTML = _fnGetCellData(settings, rowIdx, colIdx, 'display')`).
   Anything in a `null`-column value is therefore parsed as HTML.
5. The injected `<img src=x>` fires its `onerror` handler the moment the row is
   created — for every viewer of the list.

`git blame` attributes the offending lines to `2babef32fc`, the original
modernization commit: this is a standing defect of the 2.0.1 rewrite of this
screen, not a recent regression, and it predates #971. Legacy 1.9.20
`codeTrackerView.tpl` was not affected because Smarty escaped `{$name}`.

## Scope of impact and blast radius

| Check | Result |
|---|---|
| Vulnerable cells on this screen | `t.name`, `t.serverUrl` — 2 cells |
| Other cells | `typeDescr`/`typeLabel` (server-side type registry), the `ct.active` badge (i18n constant) and the `actions` string (built by us, `jsAttrEsc` for the `onclick` JS-string context) — safe |
| Other sinks on this screen | `#modalTitle` uses `.text()`, form fields use `.val()` — safe |
| Bounded by the XML validator? | **No.** `checkXMLCfg` (`tlCodeTracker.class.php:689-718`) only tests well-formedness via `simplexml_load_string`, with no element/attribute allowlist, so a well-formed `<uribase><img src="x" onerror="…"/></uribase>` is stored and the URL cell executes it (measured pre-fix `document.body.dataset.xssURL === "1"`, 1 injected `img` node). Only malformed payloads (e.g. unquoted `src=x`) are rejected with HTTP 400 |
| Sibling modernized screens | already escape (`issuetrackerView.html:281`, `reqMgrSystemView.html:203`, `cfieldsView.html:258-261`, `pluginView.html:164-168`) — not affected |

## Fix approach

Only `gui/templates/codetracker/codetrackerView.html` changed:

- Add the same `esc()` helper the sibling screens already use, placed directly
  above `renderTable()`:
  ```js
  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }
  ```
- Apply it to the user-controlled cells: `esc(t.name)`,
  `esc(t.serverUrl || '')`, plus `esc(t.typeDescr || t.typeLabel)` as defence in
  depth for the same sink.
- The per-row `onclick="deleteTracker(id,'…')"` keeps `jsAttrEsc`, which is the
  correct escaping for its JS-string context.
- The BFF, the database layer, the i18n bundles and the legacy Smarty template
  are untouched — no user-facing string was added.

**Rationale**: escaping is applied at the point where a value crosses into the
HTML sink. The rule becomes "anything concatenated into a DataTables data value
is escaped unless we built it ourselves", which is exactly the convention the
other modernized screens already follow, so the outlier is removed rather than a
new pattern introduced.

### Alternatives considered and rejected

- **Escape in the BFF `trackerToJSON()`** — would double-encode for the edit form
  and for `PUT` round-trips; a JSON payload is a raw-data contract.
- **Escape on write (`database::prepare_string()` / the create route)** — corrupts
  stored data for every consumer and would not protect data that already exists.
- **A per-column DataTables `render` callback** — more code than two `esc()`
  calls and inconsistent with the sibling screens.
- **Content-Security-Policy or a sanitizer dependency** — there is no CSP
  infrastructure in the app; disproportionate for this defect.

## Verification

Regression suite `tmp/TLU_Test_Cases.md` → **10/10 PASS** (suite
`Regression — Issue #1581`).

- Syntax gate: the page's inline script extracted and checked with `node --check`
  → `NODE SYNTAX OK`.
- Manager (`admin`): `document.body.dataset.xss` **undefined**,
  `#trackersTable img` count `0`, `b` count `0`; the Name cell `textContent` is
  the literal payload with `children.length === 0`; the Server URL
  `<b>bold</b>` renders as text.
- Server URL `<img src="x" onerror=…>` (well-formed cfg, id 7): post-fix the cell
  shows the payload as text (`innerHTML` =
  `&lt;img src="x" onerror="document.body.dataset.xssURL=1"/&gt;`, `children.length === 0`,
  `img` count `0`) and `dataset.xssURL` is never set.
- View-only user `ctonly1581` (right 52 only): `dataset.xssVO` undefined,
  `#trackersTable img, b` count `0`, `#actionsTh` and `#createBtn` hidden — the
  escaping sits outside `if (canManage)`, so both roles share it.
- No double escaping: `Tom & Jerry <test> "q" s` renders with `&`/`<`/`>`/`"`
  escaped exactly once; plain names stay byte-identical
  (`innerHTML === textContent === "ZZURLPWN"`).
- DataTables features intact: `search('Tom')` filters to 1 row.
- Delete confirmation still shows the **raw** name:
  `Are you sure you want to delete code tracker "<img src=x onerror=document.body.dataset.xss=1>"?`.
- Edit modal on the crafted tracker: title rendered via `.text()` (0 element
  children), fields keep the raw values so the tracker stays editable.
- Sink proof: bypassing `esc()` by initialising a DataTable with a raw value and
  the same `columns: [null, …]` contract still injects an element
  (`innerHTML = <img src="x" onerror="…">`, 1 child node) — the library is not
  hardened, so `esc()` must stay on every cell of this table.
- Browser console: no errors, no warnings. Event Viewer:
  `SELECT count(*) FROM events WHERE log_level IN (1,2)` → **0** (only the
  `audit_login_succeeded` INFO row, log_level 16).
- Fixtures restored: all 6 trackers deleted, `codetrackers` = 0 rows, the
  `ctonly1581` user and role 20 removed, `users` back to 1 and `roles` to 9.

Post-fix screenshot:
`docs/screenshots/issue-1581-codetracker-stored-xss-fixed.png`.

## Files changed

- `gui/templates/codetracker/codetrackerView.html` — `esc()` helper + escaped
  name/server-URL/type cells.
- `tmp/TLU_Test_Cases.md` — regression suite and measured results.
- `docs/Bugfix-Issue-1581-Codetracker-Stored-XSS.md` — this mirror.
- `docs/screenshots/issue-1581-codetracker-stored-xss-prefix.png` — pre-fix evidence.
- `docs/screenshots/issue-1581-codetracker-stored-xss-fixed.png` — post-fix evidence.
- `CHANGELOG` — issue #1581 summary.
- `tmp/wiki-repo/Bugfix-Issue-1581-Codetracker-Stored-XSS.md` — GitHub Wiki page
  with screenshots.

## Result

Issue #1581 is fixed by HTML-escaping the two manager-controlled values on their
way into the DataTables cells. A crafted tracker name is now inert text for every
viewer, the server-URL column can no longer inject markup, the management
affordances and the raw delete confirmation are unchanged, and the Event Viewer
gained no Error/Warning entry. The fix is committed and pushed on
`fix/issue-1581` as `0cf740a14` (fix), `e6305ef12` (regression suite) and the
documentation commit of the same branch.
