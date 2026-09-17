# Issue 1529 — User Management tab bars: reflected XSS via unescaped tproject_id/tplan_id in href

**Issue:** [#1529](https://github.com/sebiboga/testlink-upgraded/issues/1529)
**Branch:** `fix/issue-1529`
**Status:** VERIFIED-FIXED (2026-09-17)

## Symptom

The four modern **User Management** screens (`usersView.html`,
`usersAssignProject.html`, `usersAssignPlan.html`) interpolate the raw
`tproject_id` and `tplan_id` URL query params into the `href="..."` attribute of
their tab-bar anchors. A crafted URL injects attributes (e.g. `onclick`) into
every anchor → arbitrary JS execution in the logged-in session. Reflected XSS,
no auth requirement beyond login. `rolesView.html` (same tab bar) carries the
identical vulnerable pattern.

Measured before the fix on
`usersAssignProject.html?tproject_id=1%22%20onclick=%22alert(1)%22%20x=%22` —
every anchor parsed with injected attributes:

```html
<a href="usersView.html?tproject_id=1" onclick="alert(1)" x="&amp;tplan_id=0">User Management</a>
```

## Repro steps

1. Log in (admin/admin) and browse to:
   `http://localhost:8082/gui/templates/usermanagement/usersAssignProject.html?tproject_id=1%22%20onclick=%22alert(1)%22%20x=%22`
2. Inspect any tab anchor in `#tabsBar` (DevTools
   `document.querySelectorAll('#tabsBar a')[0].attributes`).
3. **Before fix:** `onclick="alert(1)"` present on all 4 anchors.
   **After fix:** anchor is `href="usersView.html?tproject_id=1%22%20onclick%3D%22alert(1)%22%20x%3D%22&amp;tplan_id=0"`,
   no `onclick`, no `x` attribute.

## Root cause

All five tab-bar `ctx` builders concatenated the decoded param value **raw**
into the `href="..."` attribute:

```js
var ctx = 'tproject_id=' + (p.get('tproject_id') || 0) + '&tplan_id=' + (p.get('tplan_id') || 0);
...
html += '<a href="' + t.url + '"' + ... + '>...';
```

Chain:

1. `window.location.search` carries `tproject_id=1" onclick="alert(1)" x="`.
2. `usersAssignProject.html:94-95` (and `usersView.html:254/317`,
   `usersAssignPlan.html:87`, `rolesView.html:200`) read it with
   `URLSearchParams.get()` — already fully decoded, quotes/spaces intact.
3. The value is concatenated into `ctx`, which is interpolated into the
   `href="..."` attribute of the tab anchors (`html += '<a href="' + t.url + '"'`
   at `usersAssignProject.html:107` etc.).
4. `$('#tabsBar').html(html)` parses the fragment: the `"` closes the attribute,
   `onclick="alert(1)"` becomes a real DOM attribute → payload executes.

**Why it broke:** the legacy 1.9.20 Smarty templates escaped their variables
server-side (menu.inc.tpl built tab hrefs via `urlPath` + escaped vars). The
Dashio rewrite carried raw values straight into string-concatenated HTML with no
encoding. No fresh blocker — the bug shipped with the screen modernization.

## Fix

`gui/templates/usermanagement/*.html`: wrap both params in `encodeURIComponent()`
when the tab-bar `ctx` query string is built (5 sites):

```js
var ctx = 'tproject_id=' + encodeURIComponent(p.get('tproject_id') || '0') + '&tplan_id=' + encodeURIComponent(p.get('tplan_id') || '0');
```

Affected lines: `usersView.html:254` + `:317` (gotoExport),
`rolesView.html:200`, `usersAssignProject.html:95`, `usersAssignPlan.html:87`.

Why this method: the params are the only untrusted input reaching the `href`
attribute (tab labels are i18n-sourced). `encodeURIComponent` encodes `"`, `<`,
`>`, spaces, `&` and `#` — no character that can terminate a double-quoted HTML
attribute or open a new one survives, so the breakout is structurally
impossible. The value is inserted pre-encoded and the HTML parser does not
percent-decode attribute values, so no revert happens at parse time. The raw
decoded param is still passed untouched to `loadProjects(tp)`/`loadPlans(pid)`
(the API preselection path), so normal navigation and preselection are
byte-identical. Fallback `(tp || 0)` → `(tp || '0')` is a no-op (both serialize
to `"0"`).

Alternatives rejected: per-anchor DOM insertion via `.prop('href', ...)`
(same effect, more churn), server-side whitelist/redirect (needs BFF changes +
breaks link semantics).

## Blast radius

- 4 screens / 5 sites share the tab-bar pattern, all fixed in this pass.
- `usersExport.html:214` builds its back-link href from a `parseInt()` value →
  safe, unchanged.
- A repository-wide grep confirmed only these four files emit raw URL params
  into an HTML `href` attribute; the remaining 202 `'tproject_id=' +` hits are
  API `$.getJSON` query strings (non-attribute context) or already-encoded values.

## Verification (regression matrix, all PASS)

| Case | Result |
|---|---|
| Payload URL `?tproject_id=1" onclick="alert(1)" x="` on all 4 screens → no injected attribute, payload only URL-encoded in href | PASS |
| `tplan_id=3` carried through payload hrefs verbatim | PASS |
| Normal URL `?tproject_id=1&tplan_id=0` → exact hrefs, project preselected | PASS |
| No-param URL → degradation to `tproject_id=0&tplan_id=0`, first project fallback | PASS |
| Assign-plan flow (plan 2) loads user table | PASS |
| Tab click navigation between the 4 screens | PASS |
| `node --check` on all four screens' inline JS | PASS |
| Console + `events` table after full walk | no new Error/Warning; only AUDIT/16 login row |

Post-fix DOM measurement (payload URL):

```html
<a href="usersView.html?tproject_id=1%22%20onclick%3D%22alert(1)%22%20x%3D%22&amp;tplan_id=0">User Management</a>
```

Screenshots:
- Pre-fix evidence: `docs/screenshots/issue-1529-tabbar-reflected-xss.png` (issue body)
- Post-fix: `docs/screenshots/issue-1529-tabbar-xss-fixed.png`

Regression suite: `tmp/TLU_Test_Cases.md` → **Regression — Issue #1529** (10/10 PASS).