# Bugfix Issue #703: Sanitize Real OAuth Secrets from Tracked Files

## Problem
Five tracked files contained real OAuth client_id and client_secret values committed as
working examples. The original 4 files listed in the issue (custom_config variants) were
already deleted in commit de80e49e1 (Fixes #736), but 5 additional files still had secrets:

| File | Secret Type |
|------|-------------|
| `cfg/oauth_samples/oauth.github.inc.php` | GitHub OAuth client_id + secret |
| `cfg/oauth_samples/oauth.gitlab.inc.php` | GitLab OAuth client_id + secret |
| `cfg/oauth_samples/oauth.google.inc.php` | Google OAuth client_id + secret |
| `lib/experiments/google.php` | Google OAuth client_id + secret |
| `lib/functions/oauth_providers/test.txt` | GitHub client_id + access_token |

## Root Cause
These files were committed as "working examples" for the TestLink Development Team's test
site (fman.hopto.org). The secrets were never replaced with placeholders before being pushed.

## Fix
Replaced all real credentials with `CHANGE_WITH_*` placeholders, matching the pattern
already used in `oauth.azuread.inc.php` and `oauth.microsoft.inc.php`.

## Files Changed
- `cfg/oauth_samples/oauth.github.inc.php` — lines 40-41
- `cfg/oauth_samples/oauth.gitlab.inc.php` — lines 43-44
- `cfg/oauth_samples/oauth.google.inc.php` — lines 37-39
- `lib/experiments/google.php` — lines 20-22
- `lib/functions/oauth_providers/test.txt` — all secret values

## Remaining Action Required
The real secrets were for a test site but should still be rotated externally:
1. Regenerate GitHub OAuth app credentials
2. Regenerate GitLab OAuth app credentials
3. Regenerate Google OAuth app credentials

Note: secrets still exist in git history (via the deleted files in commit de80e49e1 and
the old values in these files). Full history rewriting (git filter-branch / BFG) is
recommended if the secrets were used in production.

## Commit
- `243ee93d9` — fix(security): sanitize real OAuth secrets from 5 tracked files — Refs #703
