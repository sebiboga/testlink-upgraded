#!/bin/bash
# verify_1793.sh — regression gate for Issue #1793
#
# Guards the invariants that were violated when the "Issue #1701" suite was
# destroyed by a concurrent agent's full-file rewrite of the shared, git-ignored
# suite file tmp/TLU_Test_Cases.md (commit ce093fa54, 129+/92-).
#
# Usage:
#   bash tmp/verify_1793.sh                    # check the TRACKED content (HEAD)
#   bash tmp/verify_1793.sh /path/to/copy      # check an arbitrary copy (negative control)
# Exit 0 = all checks PASS, 1 = at least one FAIL.
set -u

FILE="${1:-}"
PASS=0
FAIL=0

ok()  { echo "  PASS  $1"; PASS=$((PASS+1)); }
bad() { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
skip(){ echo "  SKIP  $1"; }
check(){ # check <description> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1 (= $3)"; else bad "$1 (expected $2, got $3)"; fi
}
rm_f(){ rm -f "$1" 2>/dev/null; }

TMP_OLD=""
TMP_SEC=""
TMP_NEW=""
if [ -z "$FILE" ]; then
  # Read the TRACKED content, not the working copy: the whole point of the bug
  # is that a working copy can silently differ from what was committed.
  TMP_NEW="$(mktemp)"; FILE="$TMP_NEW"
  if git cat-file -e HEAD:tmp/TLU_Test_Cases.md 2>/dev/null; then
    git show HEAD:tmp/TLU_Test_Cases.md > "$FILE"
    echo "source: tracked content of HEAD:tmp/TLU_Test_Cases.md"
  else
    echo "  FAIL  tmp/TLU_Test_Cases.md is not tracked in HEAD (git cat-file -e HEAD:...)"
    rm -f "$TMP_NEW"; exit 1
  fi
else
  echo "source: $FILE"
fi

echo "G1793 — shared-suite clobber invariants"

# --- 1. the suite that was destroyed is back, intact -----------------------------
SEC="$TMP_SEC"; TMP_SEC="$(mktemp)"; SEC="$TMP_SEC"
sed -n '/^## Regression — Issue #1701:/,$p' "$FILE" > "$SEC"
check "Issue #1701 heading present"            1 "$(grep -c '^## Regression — Issue #1701:' "$FILE")"
check "Issue #1701 measured rows R1-R7 intact"  7 "$(grep -c '^| R[1-7] ' "$SEC")"
check "Issue #1701 records a PASS verdict"      7 "$(grep -cE '^\| R[1-7] .*\*\*PASS\*\*' "$SEC")"
check "Issue #1701 keeps its 1.9.20 target"     1 "$(grep -c 'issueTrackerInterface::connect()' "$SEC")"
if [ -n "${1:-}" ] && [ -f "$1" ]; then
  ok "caller-supplied path still on disk after the run"
else
  [ -z "${1:-}" ] || bad "the script REMOVED the caller-supplied path '$1'"
fi
rm_f "$TMP_SEC"

# --- 2. no OTHER suite may have been lost with it --------------------------------
BASE="$(git cat-file -e 47905e3e8:tmp/TLU_Test_Cases.md 2>/dev/null && echo 47905e3e8 || true)"
if [ -n "$BASE" ]; then
  MISSING=0
  for h in $(git show "$BASE":tmp/TLU_Test_Cases.md | grep -oE '^## Regression — Issue #[0-9]+' | grep -oE '[0-9]+' | sort -u); do
    grep -q "Issue #$h" "$FILE" || { echo "        suite #$h from $BASE is missing"; MISSING=$((MISSING+1)); }
  done
  check "no suite lost vs $BASE" 0 "$MISSING"
else
  skip "baseline 47905e3e8 not reachable (shallow clone?)"
fi

# --- 3. the file itself is still well formed -------------------------------------
check "markdown fences balanced" 0 "$(( $(grep -c '^```' "$FILE") % 2 ))"
if [ -n "$(tail -1 "$FILE" | tr -d '[:space:]')" ]; then
  ok "file does not end mid-block (last line non-blank)"
else
  bad "file ends with a blank line - restore was truncated"
fi

# --- 4. the clobber guard itself (see ai/AGENTS.md rule 9) ----------------------
if [ -d .git ] && [ -z "${1:-}" ]; then
  PARENT="$(git rev-parse HEAD~1 2>/dev/null || true)"
  if [ -n "$PARENT" ] && git cat-file -e "$PARENT":tmp/TLU_Test_Cases.md 2>/dev/null; then
    TMP_OLD="$(mktemp)"
    git show "$PARENT":tmp/TLU_Test_Cases.md > "$TMP_OLD"
    DEL="$(diff "$TMP_OLD" "$FILE" | grep -c '^<' || true)"
    check "last commit on the suite file deletes nothing" 0 "$DEL"
  fi
  echo "        NOTE: rule 9 gate = 'git diff --numstat -- tmp/TLU_Test_Cases.md' shows 0 deletions"
fi

# Clean up ONLY the temp files this script created. A path passed as $1 belongs
# to the caller and must never be removed here (it was deleted once, taking
# tmp/TLU_Test_Cases.md with it - see the G10 control in the #1793 suite).
rm_f "$TMP_OLD"
rm_f "$TMP_SEC"
rm_f "$TMP_NEW"
echo "G1793 result: $PASS PASS / $FAIL FAIL"
[ "$FAIL" -eq 0 ]