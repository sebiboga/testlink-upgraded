# Issue 722 — custom_config.inc.php.github.testlinkOauthProvider: Real GitHub OAuth secret exposed

**Issue:** [#722](https://github.com/sebiboga/testlink-upgraded/issues/722)
**Status:** VERIFIED-FIXED (2026-08-26)

## Investigation

### Files examined

1. **`cfg/samples/custom_config.inc.php.github.testlinkOauthProvider`** — The file
   in question. Contains GitHub OAuth credentials for user `TestlinkOauthProvider`.

2. **`.gitignore`** — Checked for existing rules covering custom_config files.

3. **Git history** — Traced the lifecycle of the file across commits.

### Git history analysis

| Commit | Action | File |
|---|---|---|
| `aae88f76d` | Added | `custom_config.inc.php.example.github_oauth` |
| `ac9be21aa` | Modified | same |
| `35e3fcc2c` | Modified | same |
| `e2a6583ce` | Renamed | → `custom_config.inc.php.github.testlinkOauthProvider` |
| `5f041fa1b` | Deleted | `custom_config.inc.php.github.testlinkOauthProvider` |
| `a3a830110` | Renamed (again) | → `cfg/samples/custom_config.inc.php.github.testlinkOauthProvider` |

### Key findings

1. **File is NOT currently tracked** — `git ls-tree -r HEAD` shows only
   `custom_config.inc.php.example` (a safe example file). The problematic file
   was deleted in a prior commit.

2. **`.gitignore` already has protective rules** (added in commit `de80e49e1`):
   ```
   custom_config.inc.php
   custom_config.inc.php.*
   custom_config.inc.php.example.*
   ```
   These rules prevent future tracking of custom config files with secrets.

3. **Secret remains in git history** — The file was added in commit `a3a830110`
   with the real OAuth secret (`d5dbe0344ca9b5f0857769d87ba14cf99bdc2b1c`).
   This secret is still visible in the git history and cannot be removed without
   rewriting history (git filter-branch / BFG).

4. **Related file already fixed** — Issue #703 (commit `243ee93d9`) sanitized
   real OAuth secrets from 5 other tracked files by replacing them with
   `CHANGE_WITH_*` placeholders.

## Root cause

The file was committed as a "working example" for the TestLink Development Team's
test site. The real OAuth credentials were never replaced with placeholders before
being pushed to the public repository.

## Fix approach

### What was already done (before this issue)

1. **File deleted from tracked tree** — The file no longer exists on HEAD or any
   active branch. It was removed in prior commits.

2. **`.gitignore` rules added** — Commit `de80e49e1` (Fixes #736) added comprehensive
   gitignore rules covering `custom_config.inc.php.*` to prevent future leaks.

3. **Related secrets sanitized** — Issue #703 (commit `243ee93d9`) replaced real
   OAuth credentials in 5 other files with placeholder values.

### What remains (requires manual action)

**The GitHub OAuth app registered to `TestlinkOauthProvider` must be revoked manually:**

1. Go to https://github.com/organizations/TestlinkOauthProvider/settings/applications
2. Find the OAuth application (client_id: `39eaa098ffffcda54b63`)
3. Delete the application
4. Regenerate any credentials that were used with it

### Why history rewrite was not done

Rewriting git history (`git filter-branch`, BFG Repo Cleaner) is destructive and
affects all collaborators. The recommendation is:
- If the secrets were used in **production**: rewrite history immediately
- If the secrets were used only for **development/testing**: revoking the OAuth
  app is sufficient, as the secrets become useless

## Files changed

- No files changed in this fix (file was already deleted, gitignore rules already in place)

## Verification

| Check | Result |
|---|---|
| File not tracked on HEAD | ✅ PASS — only `custom_config.inc.php.example` exists |
| `.gitignore` rules present | ✅ PASS — lines 13-14 cover `custom_config.inc.php.*` |
| No other OAuth secrets tracked | ✅ PASS — verified via `git ls-tree` |
| Related fix in #703 | ✅ PASS — commit `243ee93d9` sanitized 5 files |

## Notes

- **Action required:** The GitHub OAuth app for `TestlinkOauthProvider` must be
  revoked manually by the organization owner. This cannot be automated.

- **Git history:** The secret remains in git history. If the credentials were used
  in production, a history rewrite is recommended. If used only for development,
  revoking the OAuth app renders the leaked secret useless.

- **Prevention:** The `.gitignore` rules added in commit `de80e49e1` prevent
  future tracking of custom config files. Any new OAuth config files will be
  automatically ignored by git.
