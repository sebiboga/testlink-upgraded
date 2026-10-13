# Issue 1756 — Move/Reorder Test Suites: a write after the session expires shows a raw message instead of the login page

**Issue:** [#1756](https://github.com/sebiboga/testlink-upgraded/issues/1756)
**Branch:** `fix/issue-1756-session-expiry-write` · **Fix commit:** `13f2ca183` (landed in the `#1740` code-review commit)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** VERIFIED FIXED (2026-10-13) — stale report of a fix that had already landed

## Symptom

With the inactivity timeout reached, a **write** on the screen (**Move up** / **Move down** / **Move to…**) painted
the red error banner with the **raw, untranslated** server string (`session_expired`) and left the user on a dead
screen. The read path (`load()`) already bounced to the login page; the write path did not.

## Root cause chain

`postAction()` is the only mutating path of the screen: Move up / Move down (`doMove`) and Move to… (`confirmModal`)
both funnel into it.

| # | Site | Role |
|---|---|---|
| 1 | `suiteMove.html:380-419` (`postAction()`) | the single POST to `api/suitemove/index.php` for every move/reorder |
| 2 | `api/suitemove/index.php:64` → `api/_guard.php:175-198` (`bffEnforceSession()`) | answers `401 {"status":"error","code":"session_expired"}` once `checkSessionValid()` reports the session stale |
| 3 | `suiteMove.html:403-413` (pre-fix `.fail()`) | handled `403` (CSRF) only; a `401 session_expired` fell to `setNotice('err', esc(r.message))` → **raw** `session_expired` |
| 4 | `suiteMove.html:485-494` (`load()` `.fail()`) | already called `isSessionGone(xhr)` → redirect; the write path did not |

The asymmetry between #3 and #4 is exactly what the issue reports.

**Fix already present:** `13f2ca183` ("#1740: apply code-review fixes to Move/Reorder Test Suites") added the missing
guard at the top of the write error path:

```js
}).fail(function (xhr) {
  var r = (xhr && xhr.responseJSON) ? xhr.responseJSON : {};
  if (isSessionGone(xhr)) {                         // suiteMove.html:405-408
    window.location.href = '/login.php?note=expired';
    return;
  }
  ...
```

with `isSessionGone()` (`suiteMove.html:258-261`) accepting either the `401` status or a `session_expired` body
code. No code path can reach the raw-message branch for an expired session any more.

### Why the report was stale

The defect was introduced with the screen (`0674bbdd5`) and fixed during the mandatory code review of #1740
(`13f2ca183`; authored `2026-09-30T13:30:30Z`, committed/landed `2026-09-30T13:35:13Z`). Issue #1756 was filed at
`2026-09-30T13:31:05Z` — **after** the fix was authored but **before** it landed — describing the then-current code.
`git merge-base --is-ancestor 13f2ca183 origin/sebiboga` is true, so no build carries the reported behaviour today.
This is the same stale-report pattern already documented for the sibling issue #1755 on this same screen.

## Fix

No new change was required: the `isSessionGone(xhr)` guard is already on the write error path. This run verified it,
added the `Regression — Issue #1756` suite, mirrored this documentation and, with the fix confirmed on the default
branch, closed the tracking issue.

### Alternatives considered (and why the shipped fix is right)

| Option | Verdict |
|---|---|
| Redirect only on `xhr.status === 401` | Rejected: `isSessionGone()` also accepts a `session_expired` body code, so a `403`-class gate (or a future guard that changes the status) is covered too. |
| Redirect on *every* failed write | Rejected: a `403`/`404`/`409` (CSRF, absent node, cycle) is a real domain error the user must see; only the session loss is a navigation condition. |
| A global `$.ajaxSetup` error handler | Rejected as out of scope: the modern screens deliberately keep the bounce local to each screen and use the shared `window.location.href = '/login.php?note=expired'` convention (40+ screens). |
| **`isSessionGone()` at the top of `postAction`'s `.fail()`** (shipped) | Chosen: colocated with the write, matches every sibling screen, and leaves the domain-error branch intact. |

## Verification (measured)

Pre-fix control: `gui/templates/testcases/suiteMove.html` reconstructed verbatim from `git show 13f2ca183^:…`.
Fixture: project `1756` (`SMV1756`, "SMV Project") with suites `1756001` Suite A / `1756002` Suite B /
`1756003` Suite C. `sessionInactivityTimeout` temporarily set to `1` minute, the screen loaded (refreshing
`$_SESSION['lastActivity']`), then left idle > 60 s before the write.

| Step | Pre-fix file (`13f2ca183^`) | Shipped file (default branch) |
|---|---|---|
| `GET ?action=init` | 200 (screen renders 3 suites) | 200 |
| `POST ?action=move … position=down` | **401** `{"code":"session_expired"}` | **401** `{"code":"session_expired"}` |
| Result on screen | red banner with the **raw** string `session_expired` (uid `7_0`), URL stays `suiteMove.html?…`, **no redirect** | redirect to `http://localhost:8082/login.php?note=expired`, banner **"Session expired. Please log in again."** |
| Network after the write | no navigation request | `GET /login.php?note=expired` → 200 |

- Shipped code, same scenario: `reqid=97 POST /api/suitemove/index.php → 401`, then `reqid=98 GET /login.php?note=expired → 200`.
- Release site: `gui/templates/testcases/suiteMove.html:405-408` (guard) + `258-261` (`isSessionGone`).
- `git merge-base --is-ancestor 13f2ca183 origin/sebiboga` → true;
  `git show origin/sebiboga:gui/templates/testcases/suiteMove.html | grep -c isSessionGone` → 3.
- `events` table after the run: only audit rows (login/session), **no Error/Warning**; browser console clean.
- Screenshots: `docs/screenshots/issue-1756-prefix-raw-session-expired.png` (pre-fix),
  `docs/screenshots/issue-1756-fixed-login-redirect.png` (fixed).

Regression suite `Issue #1756` in `tmp/TLU_Test_Cases.md`: **7/7 PASS** (pre-fix control + 6 post-fix
verifications). Fixture: `tmp/fixtures_1756.sql`.
