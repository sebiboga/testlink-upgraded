# Bug fix — Issue #813: GitHub connect button always reports "OAuth not configured" on a fresh install

## Symptom

On a fresh TestLink install the **GitHub** connect button on the Issue Trackers
screen (`gui/templates/issuetracker/issuetrackerView.html`) always alerted:

> GitHub OAuth is not configured (missing oauth_client_id/oauth_client_secret)

with the underlying API returning HTTP 503. The GitHub issue/BTS link
integration was unreachable out of the box: the required OAuth credentials were
never collected anywhere and no local config file was produced by the installer.

## Root cause

1. `api/issuetracker/index.php:151-171` — `gh_oauth_config()` is the single
   source of truth for GitHub OAuth credentials for the Issue Tracker. It
   returns `null` whenever `cfg/oauth_github_local.inc.php` is missing, not
   loadable, or holds empty/`CHANGE_*` placeholder values.
2. `api/issuetracker/index.php:255-260` (`GET /oauth/start`) and `:279-284`
   (`/oauth/callback`) short-circuit to **HTTP 503** with the "not configured"
   message whenever `gh_oauth_config()` is null.
3. `gui/templates/issuetracker/issuetrackerView.html:263-281` —
   `githubOAuthStart()` `alert()`s the server message on the error path.
4. The config file was **never produced**: the 1.9.20-era installer
   (`install/installDbInput.php` / `install/installNewDB.php`) had no GitHub
   OAuth fields and never wrote `cfg/oauth_github_local.inc.php`. So every
   fresh install ran with null config and the button hard-failed.

Blast radius: the affected code is confined to the GitHub-OAuth paths of
`api/issuetracker/index.php` and its only modernized client
(`gui/templates/issuetracker/issuetrackerView.html`). The login-page OAuth
subsystem (`login.php`, `api/auth/index.php`, `lib/functions/oauth_api.php`)
reads the same `$tlCfg->OAuthServers` map for *authentication* providers but is
independent: an absent local config file changes nothing there.

## Fix approach

Issue #813 was implemented by commit **`b767d45c0`**
(`feat(install): collect GitHub OAuth credentials at install time and generate
local config`, already on the default branch). The approach chosen — collecting
credentials at install time and generating a local config file — required **no
backend change**, because `gh_oauth_config()` already reads
`cfg/oauth_github_local.inc.php`:

1. **Installer form** (`install/installDbInput.php:215-242`, gated on new
   installs `$_SESSION['isNew']`): optional "GitHub OAuth" section with an
   enable checkbox, `oauth_client_id` and `oauth_client_secret` fields, plus a
   hint showing the required Authorization callback URL
   (`/api/issuetracker/index.php/oauth/callback`).
2. **Config generation** (`install/installNewDB.php:679-713`,
   `write_oauth_github_config()`, invoked at :570): copies the POSTed values
   through `$_SESSION` (`installNewDB.php:46-53`), validates them (rejects
   `CHANGE_*` placeholders, skips silently when disabled/empty), and writes
   `cfg/oauth_github_local.inc.php` with **mode 0600**.
3. **Documentation** (`cfg/oauth_github_local.inc.php.example`): documents the
   manual path (copy to `cfg/oauth_github_local.inc.php`, replace placeholders)
   and where to create the OAuth App.
4. Because the local file is **gitignored** (`.gitignore:54`), secrets can never
   be committed.

Rejected alternatives: hard-coding example credentials, changing the backend to
not require the file, or storing secrets in the DB — all worse than a local
file the installer generates.

## Verification (measured)

Reproduced on http://localhost:8082 (admin session, fresh DB import):

- **Pre-config:** `GET /api/issuetracker/index.php/oauth/start` → HTTP 503
  `{"status":"error","message":"GitHub OAuth is not configured (missing
  oauth_client_id/oauth_client_secret)"}`; clicking GitHub alerts the same.
- **Post-config** (config file in the exact format
  `write_oauth_github_config()` emits): `GET /oauth/start` → **HTTP 200**
  `{"status":"ok","url":"https://github.com/login/oauth/authorize?client_id=…"}`
  and the UI button opens a real GitHub authorize popup (no alert).
- Generated-file format validated standalone: `php -l` clean; loads into
  `$tlCfg->OAuthServers['github']` exactly as `gh_oauth_config()` expects; file
  mode 600.
- Event Viewer: no new Error/Warning rows from the flow.

When credentials are genuinely missing the button still warns — that is the
intended behaviour after the fix.

## Files changed (this run)

No runtime code change was needed this run — the fix commit `b767d45c0`
(pushed on the default branch, `Refs #813`) already landed the installer +
config generation + example file. This run verified the fix error-free and
added the regression test suite, this documentation and the wiki page, then
closed the issue.

Regression suite: `tmp/TLU_Test_Cases.md`, Suite 818 (12/12 PASS).