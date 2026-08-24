# MERGE-STALE-BRANCHES.md — How to merge stale branches into sebiboga

**Why this file exists:** The repo accumulates branches over time — `fix/*`, `chore/*`,
`feature/*`, manual experiments, etc. This file is the rulebook for an autonomous agent
that merges those stale branches safely into the default branch `sebiboga`.

**Reference:** Every step must be documented on the source branch's issue, following
the same pattern as ISSUES.md.

---

## 0. Golden rules

1. **One branch at a time.** Process one stale branch fully before moving to the next.
2. **NEVER delete without proof.** Before deleting ANY branch you MUST verify that
   every unique commit on that branch exists on `sebiboga`. No exceptions.
3. **Never lose work.** Every commit must either land on `sebiboga` or be explicitly
   documented as intentionally skipped (with reason).
4. **Never break the default branch.** If cherry-pick causes conflicts or test
   failures, abort and flag for human review — do not force.
5. **Document everything.** Post a comment on the issue with what was cherry-picked,
   what was skipped, and verification results.

---

## 1. Find all stale branches

```bash
DEFAULT_BRANCH=$(gh repo view "$REPO" --json defaultBranchRef --jq .defaultBranchRef.name)
git fetch --all --prune

# List ALL remote branches except the default
for BRANCH in $(git branch -r | grep -v "origin/$DEFAULT_BRANCH" | grep -v 'HEAD' | sed 's|origin/||;s|^[[:space:]]*||'); do
  AHEAD=$(git rev-list --count "origin/$DEFAULT_BRANCH..origin/$BRANCH" 2>/dev/null || echo "0")
  BEHIND=$(git rev-list --count "origin/$BRANCH..origin/$DEFAULT_BRANCH" 2>/dev/null || echo "0")
  if [ "$AHEAD" != "0" ]; then
    echo "$BRANCH | ahead=$AHEAD behind=$BEHIND"
  fi
done
```

Rules for selection:
- Skip `sebiboga` (that's home)
- Skip branches with `AHEAD=0` (fully merged, already clean)
- Prefer branches with issues still OPEN (active work context)
- Then branches with issues CLOSED or no matching issue
- Process oldest issue number first

---

## 2. Analyze the branch

Before touching any code:

1. **List unique commits:**
   ```bash
   git log --oneline "origin/$DEFAULT_BRANCH..origin/$BRANCH"
   ```

2. **Read the issue body + comments** to understand context.

3. **Read the diff** to understand what changed:
   ```bash
   git diff --stat "origin/$DEFAULT_BRANCH..origin/$BRANCH"
   git diff "origin/$DEFAULT_BRANCH..origin/$BRANCH" -- <file>
   ```

4. **Categorize each commit:**
   - `CODE FIX` — actual PHP/JS/SQL changes that fix the bug → MUST cherry-pick
   - `DOCS` — documentation, wiki mirrors, screenshots → cherry-pick (preserves work)
   - `TEST` — regression suites, fixture scripts → cherry-pick (preserves work)
   - `FEAT` — new features, new files → cherry-pick (preserves work)
   - `CONFIG` — CI, workflows, config changes → cherry-pick (preserves work)
   - `TEMP` — tmp files, CI artifacts, leftover state → SKIP (safe to lose)

5. **Check if the changes are already on `sebiboga`:**
   ```bash
   git log --oneline origin/$DEFAULT_BRANCH --grep="<issue number>"
   git log --oneline origin/$DEFAULT_BRANCH --grep="<key function name>"
   git diff origin/$DEFAULT_BRANCH -- <file> | head -50
   ```
   If the fix already landed, only docs/tests need cherry-picking.

---

## 3. Cherry-pick

### Option A: Clean cherry-pick (no conflicts)
```bash
git checkout sebiboga
git cherry-pick <commit-hash>  # one at a time, CODE FIX first
```

### Option B: Conflict resolution
If cherry-pick conflicts:
1. Read both sides carefully
2. Accept both when they don't overlap (different sections of the same file)
3. When they overlap, prefer the sebiboga version (it's newer)
4. Never drop a feature/fix from either side

### Option C: Skip
If a commit is `TEMP` type or its content is already on `sebiboga`:
```bash
git cherry-pick --skip
```

---

## 4. VERIFY before deleting (MANDATORY)

**DO NOT delete the branch until ALL of these checks pass:**

### 4a. Every commit accounted for
```bash
# Count unique commits on the branch
UNIQUE=$(git rev-list --count "origin/$DEFAULT_BRANCH..origin/$BRANCH")
# Count how many we cherry-picked (the difference should be only TEMP/skipped commits)
echo "Branch had $UNIQUE unique commits. We skipped $SKIPPED_TEMP (TEMP)."
```

### 4b. Diff-level proof no work lost
```bash
# Show ONLY the changes that exist on the branch but NOT on sebiboga
# After cherry-picking, this should show only TEMP files or zero diff
git diff "origin/$DEFAULT_BRANCH..origin/$BRANCH" -- . ':!*.md' ':!tmp/' ':!.github/'
```
If any CODE FIX or FEAT remains in this diff → DO NOT DELETE, investigate.

### 4c. For each unique file changed on the branch, verify it exists on sebiboga
```bash
for FILE in $(git diff --name-only "origin/$DEFAULT_BRANCH..origin/$BRANCH"); do
  if [ ! -f "$FILE" ]; then
    echo "MISSING: $FILE was on the branch but is NOT on sebiboga!"
    exit 1
  fi
done
```

### 4d. Content comparison for critical files
```bash
# For CODE FIX and FEAT commits, compare the file content on both branches
for FILE in $(git diff --name-only "origin/$DEFAULT_BRANCH..origin/$BRANCH"); do
  diff <(git show "origin/$BRANCH:$FILE" 2>/dev/null) <(git show "origin/$DEFAULT_BRANCH:$FILE" 2>/dev/null) > /dev/null 2>&1
  if [ $? -ne 0 ]; then
    echo "DIFFERS: $FILE"
    # This is expected if we resolved conflicts by preferring sebiboga — verify manually
  fi
done
```

### 4e. Syntax checks
```bash
for FILE in $(git diff --name-only "origin/$DEFAULT_BRANCH..origin/$BRANCH" | grep '\.php$'); do
  php -l "$FILE" || exit 1
done
for FILE in $(git diff --name-only "origin/$DEFAULT_BRANCH..origin/$BRANCH" | grep '\.json$'); do
  python3 -m json.tool "$FILE" > /dev/null || exit 1
done
for FILE in $(git diff --name-only "origin/$DEFAULT_BRANCH..origin/$BRANCH" | grep '\.js$'); do
  node --check "$FILE" || exit 1
done
```

---

## 5. Push & cleanup

**Only after ALL checks in step 4 pass:**

```bash
git push origin sebiboga
git push origin --delete $BRANCH
```

Post a comment on the issue:
```markdown
## Branch merged & verified

**Branch:** `$BRANCH` → cherry-picked to `sebiboga`
**Commits landed:** <list hashes + descriptions>
**Commits skipped:** <list + reason>
**Verification:**
- [x] All unique commits accounted for
- [x] No work lost (diff proof)
- [x] All changed files exist on sebiboga
- [x] Syntax checks passed
**Branch deleted:** ✓
```

---

## 6. Flag for human review

If ANY verification step fails:
- Do NOT delete the branch
- Post a comment on the issue explaining what's blocked
- Move to the next branch
