# Issue #1837: resultsBugs — Bugs column linked to percent-encoded tracker HTML

**Files changed:** `api/reports/index.php`, `gui/templates/results/resultsBugs.html`, `CHANGELOG`

## Symptom (measured pre-fix)

Every bug in the *Bugs* column of **Results by Issues** rendered an `href` that is the *HTML fragment* the issue
tracker returned, percent-encoded by `esc()` and resolved against the current directory:

```
link " 101" url="http://localhost:8082/gui/templates/results/%3Cdiv%20%20title=%22Access%20issue%20tracking%20system%22…%3C/a%3E%3C/div%3E"
```

`fetch(anchor.href)` from the page context answered **HTTP 404** (`404 Not Found`) — all 3 bug links of the
fixture plan, i.e. the report's main column was unusable for its purpose (reaching the bug). No console error and
no Event Viewer row: it is a *contract* bug, not a runtime error, which is why it stayed silent.

## Root cause chain

1. `issueTrackerInterface::buildViewBugLink()` returns an **HTML fragment**, not a URL
   (`lib/issuetrackerintegration/issueTrackerInterface.class.php:365` builds the anchor, `:437-442` wraps it in a
   status-coloured `<div>`); `lib/functions/exec.inc.php:449-453` stores it as `link_to_bts`. Legacy screens echoed
   it **as HTML** — correct (`lib/results/resultsBugs.php:204`, `lib/results/resultsByStatus.php:281`,
   `lib/functions/print.inc.php:2143`).
2. `api/reports/index.php` (`action=results_bugs`) copied that fragment verbatim into a neutrally named `link`
   field and exposed **no URL at all**, so the field read like a URL.
3. `gui/templates/results/resultsBugs.html` `renderBugLinks()` escaped the fragment into `href` — the mismatch
   that produced the dead link.

The **bare** URL was always available but never left the tracker object:
`issueTrackerInterface::buildViewBugURL()` (`issueTrackerInterface.class.php:485-488`,
`cfg->uriview . urlencode($id)`), inherited/overridden by every tracker (`mantisdbInterface.class.php:79`,
`fogbugzdbInterface.class.php:43`, `githubrestInterface.class.php:198`, `gitlabrestInterface.class.php:197`,
`kaitenrestInterface.class.php:151`, `mantissoapInterface.class.php:67`, `trellorestInterface.class.php:195`).

## The fix (both ends of the contract)

**BFF** — `api/reports/index.php`

* New helper `bugViewUrl($its, $bugId, $linkHtml)` next to `minutesToHHMMSS()`: returns the bare tracker URL via
  `buildViewBugURL()`, guarded by `is_object()` + `method_exists()` + `try/catch(Throwable)`; falls back to
  extracting the `href` out of the fragment (`preg_match('/href=[\'"]([^\'"]+)[\'"]/i')` + `html_entity_decode`)
  and finally to `''`. The fallbacks matter for the demo stub
  `lib/issuetrackerintegration/mantisrestInterface.class.php:105-108`, whose `buildViewBugLink()` returns a plain
  string instead of an object.
* Each bug entry now carries both fields: `'link'` = legacy HTML fragment (unchanged, still for HTML consumers)
  and `'url'` = the bare tracker URL.

**Renderer** — `gui/templates/results/resultsBugs.html`

* New `safeHttpUrl(u)`: accepts only `http://`, `https://`, `//host/…`, `/path`; everything else → `''`.
* `renderBugLinks()` builds `href` from `b.url` through that guard, adds `rel="noopener"` next to
  `target="_blank"`, and degrades to a `<span class="bug-link">` (same styling, no href) when no usable URL exists
  instead of emitting a dead link. `b.link` is no longer read by this renderer.

Rejected alternatives: echoing the fragment as HTML again (re-introduces raw tracker HTML into the modern DOM and
loses the screen's own bug-icon/resolved-colour design, keeping a contract that invites the same bug); changing
shared `lib/functions/exec.inc.php` (5+ callers — blast radius bigger than the bug warrants); parsing the href
client-side only (leaves the API field ambiguous).

## Verification (fixture `php tmp/fixtures_1269.php`, project `RB1269` / plan `RB Plan`)

* Post-fix DOM: `href="http://mantis.local/view_bug.php?bug_id=101"` (open, `.bug-link`) and
  `…bug_id=102` (resolved, `.bug-link resolved`), both `rel="noopener"` — for `type=0` *and* `type=1`.
* Payload: `bugs[].url` = bare URL; `bugs[].link` still the unchanged HTML fragment.
* Client guard matrix (11 inputs) and PHP helper matrix (7 inputs incl. missing method / throwing method / legacy
  error string / entity-encoded href / id with space → `…bug_id=ABC+1`, legacy `urlencode()` semantics): markup,
  `javascript:` and `data:` never reach an `href`.
* Console: only the pre-existing `A form field element should have an id or name attribute` advisory; `events`
  table unchanged (5 audit/info rows, no Error/Warning).
* Regression: `action=by_status` + `gui/templates/results/resultsByStatus.html` (which deliberately echoes the
  fragment as HTML) unchanged; no legacy `lib/` file modified.

### Code review (subagent) outcome
A review subagent was run over the fix diff. Its reported BLOCKERs were checked against the
committed code and **did not reproduce** (they described a `new URL()`-based guard, an
`ENT_QUOTES | ENT_HTML5` decode, a `$bug['links']` shadowing loop and a `vertical-align:middle`
CSS rule — none of which exist in this diff). One valid hardening suggestion was folded in:
`bugViewUrl()` now applies the **same scheme allow-list server-side** (`http(s)://`, `//`, `/`)
before publishing `bugs[].url`, so the API can never hand a future consumer `javascript:`,
`data:` or markup even if that consumer forgets its own guard. Verified with a 9-case PHP matrix
(suite rows POST-FIX 11/12).

Related, reported separately and **not** fixed here: `gui/templates/execute/bugDelete.html:250-251` puts
`b.link_to_bts` (same HTML fragment, fed by `api/bugdelete/index.php:137`) into an `href`.