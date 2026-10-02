#!/bin/bash
# verify_1793.sh — regression gate for Issue #1793
#
# Guards the invariants that were violated when the "Issue #1701" suite was
# destroyed by a concurrent agent's full-file rewrite of the shared suite file
# tmp/TLU_Test_Cases.md (commit ce093fa54: 129 insertions, 92 deletions).
#
# Usage:
#   bash tmp/verify_1793.sh                # check the TRACKED content (HEAD)
#   bash tmp/verify_1793.sh /path/to/copy  # check an arbitrary copy (negative control)
#
# The script NEVER deletes the path given as $1 — it only removes the mktemp
# files it creates itself (an earlier revision ended with an unconditional
# `rm -f "$FILE"` and deleted tmp/TLU_Test_Cases.md when a path was passed;
# see the G10 row of the #1793 suite in tmp/TLU_Test_Cases.md).
#
# Exit 0 = every check PASS, 1 = at least one FAIL, 2 = harness could not run.
set -u

FILE="${1:-}"
ALLOW_SKIP="${2:-}"
PASS=0
FAIL=0
TMP_OLD=""
TMP_SEC=""
TMP_NEW=""

ok()   { echo "  PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
skip() { if [ "$ALLOW_SKIP" = "--allow-skip" ]; then
           echo "  SKIP  $1"; else
           echo "  FAIL  $1 (cross-check unavailable; re-run inside a git clone, or with --allow-skip)"; FAIL=$((FAIL+1))
         fi; }
check(){ # check <description> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1 (= $3)"; else bad "$1 (expected $2, got $3)"; fi; }
rm_f(){ [ -n "${1:-}" ] && rm -f "$1" 2>/dev/null; return 0; }
trap 'rm_f "$TMP_OLD"; rm_f "$TMP_SEC"; rm_f "$TMP_NEW"' EXIT INT TERM

# --- resolve the input ------------------------------------------------------------
if [ -n "$FILE" ]; then
  INPUT_EXISTED=1; [ -f "$FILE" ] || INPUT_EXISTED=0
  if [ "$INPUT_EXISTED" -eq 0 ]; then
    echo "HARNESS ERROR: not a readable file: $FILE"; exit 2
  fi
  echo "source: $FILE (caller-supplied; never modified or removed)"
else
  INPUT_EXISTED=""
  TMP_NEW="$(mktemp)" || { echo "HARNESS ERROR: mktemp failed"; exit 2; }
  FILE="$TMP_NEW"
fi

# --- resolve the repo (needed for every cross-check) ------------------------------
ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
[ -n "$ROOT" ] || ROOT="."
IN_REPO=0
if git rev-parse --git-dir >/dev/null 2>&1; then IN_REPO=1; fi

if [ "$INPUT_EXISTED" = "" ] && [ "$IN_REPO" -eq 1 ]; then
  if git cat-file -e HEAD:tmp/TLU_Test_Cases.md 2>/dev/null; then
    git show HEAD:tmp/TLU_Test_Cases.md > "$FILE"
    echo "source: tracked content of HEAD:tmp/TLU_Test_Cases.md"
  else
    echo "  FAIL  tmp/TLU_Test_Cases.md is not tracked in HEAD (git cat-file -e HEAD:...)"
    exit 1
  fi
fi
if [ ! -s "$FILE" ]; then echo "HARNESS ERROR: $FILE is empty or unreadable"; exit 2; fi

echo "G1793 — shared-suite clobber invariants"

# --- 1. the suite that was destroyed is back, intact ------------------------------
TMP_SEC="$(mktemp)" || exit 2
SEC="$TMP_SEC"
sed -n '/^## Regression — Issue #1701:/,$p' "$FILE" > "$SEC"
check "Issue #1701 heading present"             1 "$(grep -c '^## Regression — Issue #1701:' "$FILE")"
check "Issue #1701 measured rows R1-R7 intact"   7 "$(grep -c '^| R[1-7] ' "$SEC")"
check "Issue #1701 records a PASS verdict"       7 "$(grep -cE '^\| R[1-7] .*\*\*PASS\*\*' "$SEC")"
check "Issue #1701 keeps its 1.9.20 target"      1 "$(grep -c 'issueTrackerInterface::connect()' "$SEC")"
if [ -n "$INPUT_EXISTED" ]; then
  if [ -f "$FILE" ]; then ok "caller-supplied path still on disk after the run"
  else bad "caller-supplied path '$FILE' is gone after the run (existed before: yes)"; fi
fi

# --- 2. no OTHER suite may have been lost ----------------------------------------
# Reference set = every suite heading in BOTH the recovery baseline and the last
# commit that touched the file, so neither an old (#1701-era) nor a brand new
# suite (#1740) can disappear unnoticed.
MISSING=0; CHECKED=0; SOURCES=""
for REF in 47905e3e8 "$(git log -1 --format=%H -- tmp/TLU_Test_Cases.md 2>/dev/null)"; do
  [ -n "$REF" ] || continue
  git cat-file -e "$REF":tmp/TLU_Test_Cases.md 2>/dev/null || continue
  SOURCES="$SOURCES $REF"
  for h in $(git show "$REF":tmp/TLU_Test_Cases.md 2>/dev/null |
             grep -oE '^## (Regression|Suite|Task|Modernize|Todo) — Issue #[0-9]+' |
             grep -oE '[0-9]+' | sort -u); do
    CHECKED=$((CHECKED+1))
    # Match the suite HEADING, not any prose mention: a suite is only alive if
    # its own "## ... — Issue #n" heading survived (a later suite quoting the
    # number must not mask its loss).
    grep -qE "^## [A-Za-z]+ . Issue #$h( |:)" "$FILE" || { echo "        suite #$h from $REF is missing"; MISSING=$((MISSING+1)); }
  done
done
if [ "$CHECKED" -eq 0 ]; then
  skip "no suite-loss baseline reachable ($SOURCES)"
else
  echo "        baselines:$SOURCES — $CHECKED suite references checked"
  check "no suite lost vs the baselines" 0 "$MISSING"
fi

# --- 3. the file itself is still well formed -------------------------------------
FENCES="$(grep -c '^```' "$FILE" 2>/dev/null || true)"; FENCES="${FENCES:-0}"
check "markdown fences balanced" 0 "$(( FENCES % 2 ))"
SUITES="$(grep -cE '^## (Regression|Suite|Task|Modernize) ' "$FILE")"
if [ "$SUITES" -ge 19 ]; then ok "suite sections still present (= $SUITES, was 17 before the #1701 restore)"
else bad "suite sections collapsed (= $SUITES, expected >= 19)"; fi

# --- 4. the clobber guard itself (see ai/AGENTS.md rule 9) ------------------------
if [ "$IN_REPO" -eq 1 ]; then
  PARENT="$(git rev-parse HEAD~1 2>/dev/null || true)"
  if [ -n "$PARENT" ] && git cat-file -e "$PARENT":tmp/TLU_Test_Cases.md 2>/dev/null; then
    TMP_OLD="$(mktemp)" || exit 2
    git show "$PARENT":tmp/TLU_Test_Cases.md > "$TMP_OLD"
    DEL="$(diff "$TMP_OLD" "$FILE" 2>/dev/null | grep -c '^<' || true)"; DEL="${DEL:-0}"
    check "last commit on the suite file deletes nothing" 0 "$DEL"
  else
    skip "no parent commit touching the suite file to diff against"
  fi
else
  skip "not inside a git repository — deletion gate unavailable"
fi
echo "        NOTE: rule 9 gate = 'git diff --cached --numstat -- tmp/TLU_Test_Cases.md' shows 0 deletions"
echo "              ('git diff --numstat' cannot work: the file must be staged with -f first)"

rm_f "$TMP_OLD"; rm_f "$TMP_SEC"; rm_f "$TMP_NEW"
echo "G1793 result: $PASS PASS / $FAIL FAIL"
[ "$FAIL" -eq 0 ]