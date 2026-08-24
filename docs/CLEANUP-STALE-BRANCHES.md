# Cleanup Stale fix/* Branches

## Problem

The CI factory (`fix-bug.yml`, `fix-bug-oldest.yml`) creates `fix/issue-<N>` branches for every bug it fixes. After the CI lands the fix onto the default branch, the `fix/*` branch becomes stale debris. Over time, dozens of these accumulate:

- **Fully merged branches**: content is on `sebiboga` — safe to delete
- **Orphaned branches**: the associated issue was closed but the branch has unmerged commits (usually docs/test additions that landed via another path)
- **Bot PRs**: `merge-prs.yml` handles most, but some slip through

## Solution

**Workflow**: `.github/workflows/cleanup-stale-branches.yml`

Runs daily at 04:00 UTC + on-demand via `workflow_dispatch`.

### What it does

1. **Deletes fully-merged `fix/*` branches** — any branch where all commits are already on the default branch. These are pure debris; the content is preserved in `sebiboga`.

2. **Flags orphaned `fix/*` branches** — where the issue is CLOSED but the branch has unmerged commits. These get a warning but are NOT auto-deleted (they need human review — usually it's just docs/tests that were never merged, but occasionally a real fix was missed).

3. **Reports remaining `fix/*` count** — so you can track cleanup progress.

### Safety

- **Dry-run by default** — `DRY_RUN=true` lists what would be deleted without touching anything. Re-run with `DRY_RUN=false` to actually delete.
- **Never touches branches with unmerged commits** unless they're fully merged.
- **Orphans are flagged, not deleted** — requires manual review.

### Usage

```bash
# Dry run — see what would be deleted
gh workflow run cleanup-stale-branches.yml -f dry_run=true

# Actually delete
gh workflow run cleanup-stale-branches.yml -f dry_run=false

# Or trigger manually from GitHub UI: Actions → Cleanup stale fix/* branches → Run workflow
```

### One-time manual cleanup

To delete all currently stale branches immediately:

```bash
# List fully-merged fix/* branches
git branch -r --merged origin/sebiboga | grep fix/

# Delete them all
for b in $(git branch -r --merged origin/sebiboga | grep fix/ | sed 's|origin/||'); do
  git push origin --delete "$b"
done
```
