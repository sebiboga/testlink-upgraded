# Bugfix: Issue #771 — GitHub OAuth cannot create a NEW tracker (always re-uses/updates the linked one)

## Summary

On the global **Issue Tracker Management** screen (System → Issue Tracker
Management, `gui/templates/issuetracker/issuetrackerView.html`), after a
GitHub OAuth login it was impossible to define a **second/another** GitHub
tracker (e.g. for a different repository). The flow kept re-using the
already-existing linked tracker instead of creating a new one:

- **API:** `POST /oauth/create` aliased `$existing` to whatever GitHub tracker
  the current `tproject_id` was linked to — picking a different repo silently
  **renamed/overwrote** the linked tracker row in place and created **zero**
  new rows (verified: selecting `owner3/repo3` renamed tracker id 1, formerly
  `owner1/repo1`, and produced no new `issuetrackers` row).
- **UI:** `checkOAuthConnected()` only reacted to `?oauth=connected` with a
  silent `createGithubTracker(r.repo)` using the **session** repo; the repo
  picker (`showRepoPicker()`) was reachable only when no repo was stored in
  session. Once a repo was stored there was no UI path to reopen the picker
  and add another tracker, and the `?oauth=connected` flag was never cleared
  (reloads re-POSTed the same create).

## Root Cause

1. `api/issuetracker/index.php:410-414` (`/oauth/create`) — `$existing` was
   set from the project link, not from the repo the user picked:
   `if ($linked && intval($linked['type'] ?? 0) === $type) { $existing = $linked; }`.
2. `api/issuetracker/index.php:417-424` — the `$existing != null` branch
   called `$mgr->update()` on that linked tracker, renaming `name`/`cfg` to
   the newly picked repo — the old repo silently disappeared and no INSERT
   ever happened.
3. `gui/templates/issuetracker/issuetrackerView.html:341-350` —
   `checkOAuthConnected()`: `r.connected && r.repo` + `?oauth=connected` →
   `createGithubTracker(r.repo)`; `showRepoPicker()` only in the
   `r.connected && !r.repo` branch (lines 351-353).
4. `api/issuetracker/index.php:320` — the callback appends `oauth=connected`
   on every return; nothing ever stripped it, so reload re-POSTed the create.

Blast radius: only `api/issuetracker/index.php` `/oauth/create` and
`gui/templates/issuetracker/issuetrackerView.html` (the only modernized client
of this BFF). `tlIssueTracker` (`lib/functions/tlIssueTracker.class.php`) was
not changed — `create()` already enforces a unique `name`, `update()` refuses
renaming onto an existing name, `link()` already upserts the project link.

## Fix

**Backend — `api/issuetracker/index.php` `/oauth/create`:**
- Track by **tracker NAME** (`owner/repo`, globally name-unique) instead of by
  project link: `$existing = $mgr->getByName($name)`.
- Update in place **only** when the name-matching tracker is ALSO the one the
  project is currently linked to (`$isLinkedSame`) — the session restore /
  token-refresh path (refreshes the `apikey` inside the cfg XML).
- Otherwise: a brand-new repo name calls `create()` (new `issuetrackers`
  row); an existing name is **reused by name**; then `link($id,$tprojectId)`
  re-points the project at the resulting tracker.
- The previously linked tracker is never renamed/destroyed.
- Guard: malformed repo names (`owner`, `owner/`, `/repo`, `owner//extra`)
  now return HTTP 400 instead of creating a bogus row.

**Frontend — `gui/templates/issuetracker/issuetrackerView.html`:**
- `checkOAuthConnected()`: on `?oauth=connected` the page **always opens the
  repo picker** (the global page is exactly where multiple GitHub trackers
  must be definable), including when a repo is already stored in session.
- The `oauth` query flag is removed with `history.replaceState` (deletes all
  occurrences via `URLSearchParams.delete`) so reloads no longer re-POST a
  silent create.
- Helper text of the repo picker updated: it is no longer "only asked the
  first time".

**i18n —** `it.chooseRepoHelp` updated in **all 11 bundles**
(`en,de,es,fr,it,ja,pt,ro,ru,zh`); every bundle re-validated with
`python3 -m json.tool`.

## Verification (measured)

Post-fix regression against http://localhost:8082 (admin session, simulated
OAuth session state — `/oauth/create` makes no outbound GitHub call, it only
needs the session token + DB):

| Case | input (`repo`,`tproject_id`) | result |
|---|---|---|
| A new repo name | `owner3/repo3`, 1 | NEW row id 3; project relinked → 3; `owner1/repo1` + `owner2/repo2` intact |
| B same as linked | `owner3/repo3`, 1 again | in-place update id 3, no duplicate, link kept |
| C exists, not linked | `owner2/repo2`, 1 | reused id 2, project relinked → 2, no duplicate |
| D no project context | `owner4/repo4`, 0 | new row id 4, NOT linked; repeat → reused id 4 |
| malformed repo | `owner`, `owner/`, `/repo`, `owner//extra` | HTTP 400, no DB mutation |
| no token | (session without `gh_oauth.token`) | HTTP 401, no DB mutation |

UI (headless Chrome): after `?oauth=connected` with a repo already in session
the picker opens automatically, the URL flag disappears (`history.replaceState`),
and a reload issues **no** POST `/oauth/create`; the footer shows "GitHub
connected as … — ownerN/repoN (token expires in N days)". Console: no JS errors
(only pre-existing a11y notices). `events` table shows no new ERROR/WARNING rows.

Commits: `644911d74` (fix), `e96f32393` (repo-name guard) on branch
`fix/issue-771`. Regression suite: `tmp/TLU_Test_Cases.md` Suite 67 (11/11 PASS).