#!/usr/bin/env bash
# verify_changelog_coverage.sh — gate for the CHANGELOG issue ledger (rule 22
# of ai/AGENTS.md).
#
# WHY THIS EXISTS — issue #1924: commit d6b3b860a resolved a CHANGELOG rebase
# conflict ("# Conflicts: CHANGELOG") by taking the incoming side wholesale,
# deleting 10 hunks / 1171 lines and dropping 53 unique issue refs (#1065-#1885)
# from the 2.0.1 ledger. ai/verify_test_suites.sh protects the shared test-case
# file, but NOTHING protected CHANGELOG, so the loss reached the default branch
# undetected and rule 22's evidence trail broke for 53 issues.
#
# INVARIANTS (mirrors verify_test_suites.sh: the invariant that matters is a
# merge-base property, and a movable baseline must additionally be anchored):
#
#   Check 1 — NO REF LOST vs merge-base: every "#NNNN" ref present in the
#             merge-base's CHANGELOG must still be present in the candidate.
#             Catches a clobber introduced anywhere on this run's branch.
#
#   Check 2 — RULE-22 COVERAGE: every ref named "Refs/Fixes/Closes #N" by a
#             commit in `git log <merge-base>..HEAD` must appear in the
#             candidate. Scoped to this run's own commits, so historical gaps
#             in untouched commits cannot fail the gate.
#
#   Check 3 — ANCESTOR REF-LOSING ANCHOR: a merge-base is MOVABLE (it already
#             carries any loss an ancestor made — the #1849 class), so find the
#             NEWEST ancestor commit that actually DROPPED CHANGELOG refs
#             (refs(parent) ⊄ refs(commit)) and require the candidate to contain
#             that parent's refs. A baseline that cannot move past the loss.
#
# CONTRACT — refs are `#[0-9]{3,4}` tokens. Appending refs is always allowed;
# a ref present in the baseline but absent in the candidate is a loss (FAIL).
#
# USAGE
#   bash ai/verify_changelog_coverage.sh [--allow-skip] [<candidate-file>]
#
#   <candidate-file>  CHANGELOG to check (default: working copy, else the
#                     version staged in the index, else the arg/HEAD).
#   --allow-skip      exit 0 when a check cannot run (no git / no baseline).
#                     Without it an unrunnable check is a FAIL, so the gate can
#                     never report a success it did not verify.
#
# ENVIRONMENT
#   TLU_CHANGELOG_REQUIRE_REF   e.g. "#1924" — a ref this run MUST have added
#                               to CHANGELOG (checked like TLU_REQUIRE_SUITE).
#   TLU_DEFAULT_BRANCH          default branch; default origin/HEAD else sebiboga.
#   TLU_ANCHOR_SCAN             how many CHANGELOG-touching commits to scan for
#                               a ref-losing ancestor (default 200).
#
# The script never writes the CHANGELOG. It reads versions via `git show`.
#
# EXIT: 0 = every invariant PASS, 1 = at least one FAIL.
#
# Refs #1924 (same shared-file clobber family as #1793, #1805, #1849, #1918).

set -uo pipefail

ALLOW_SKIP=0
CANDIDATE=""
while [ $# -gt 0 ]; do
  case "$1" in
    --allow-skip) ALLOW_SKIP=1; shift ;;
    *) CANDIDATE="$1"; shift ;;
  esac
done

REFPAT='#[0-9]{3,4}'
fails=0
pass() { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; fails=$((fails+1)); }
skip_or_fail() {
  if [ "$ALLOW_SKIP" = 1 ]; then printf '  SKIP  %s\n' "$1"; else fail "$1"; fi
}

if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
  skip_or_fail "no git repository / no readable HEAD"
  echo "CL1924 result: $fails FAIL (unrunnable)"; exit "$([ "$fails" = 0 ] && echo 0 || echo 1)"
fi

# ---- resolve candidate CHANGELOG text -------------------------------------
cand_text=""
if [ -n "$CANDIDATE" ] && [ -f "$CANDIDATE" ]; then
  cand_text=$(cat "$CANDIDATE")
elif [ -f CHANGELOG ]; then
  cand_text=$(cat CHANGELOG)
elif git show :CHANGELOG >/dev/null 2>&1; then
  cand_text=$(git show :CHANGELOG)
elif git show HEAD:CHANGELOG >/dev/null 2>&1; then
  cand_text=$(git show HEAD:CHANGELOG)
else
  skip_or_fail "no candidate CHANGELOG (working copy, index or HEAD)"
  echo "CL1924 result: $fails FAIL (unrunnable)"; exit 1
fi

refs_of() { printf '%s' "$1" | grep -oE "$REFPAT" | sort -u; }
log_refs_of_rev() { git show "$1:CHANGELOG" 2>/dev/null | grep -oE "$REFPAT" | sort -u; }

# ---- resolve default branch + merge-base ----------------------------------
DEFAULT_BRANCH="${TLU_DEFAULT_BRANCH:-}"
if [ -z "$DEFAULT_BRANCH" ]; then
  DEFAULT_BRANCH=$(git symbolic-ref -q --short refs/remotes/origin/HEAD 2>/dev/null | sed 's#^origin/##')
fi
[ -z "$DEFAULT_BRANCH" ] && DEFAULT_BRANCH="sebiboga"

MERGE_BASE=$(git merge-base HEAD "origin/$DEFAULT_BRANCH" 2>/dev/null)
[ -z "$MERGE_BASE" ] && MERGE_BASE=$(git merge-base HEAD origin/sebiboga 2>/dev/null)

echo "verify_changelog_coverage.sh — CHANGELOG issue-ledger gate (Refs #1924)"
echo "  candidate: ${CANDIDATE:-working copy of CHANGELOG}"
echo "  default branch: origin/$DEFAULT_BRANCH"
echo "  merge-base: ${MERGE_BASE:-<none>}"

if [ -z "$MERGE_BASE" ]; then
  skip_or_fail "cannot resolve merge-base with origin/$DEFAULT_BRANCH"
else
  cand_refs=$(refs_of "$cand_text")

  # ---- Check 1: no ref lost vs merge-base ---------------------------------
  base_refs=$(log_refs_of_rev "$MERGE_BASE")
  if [ -z "$base_refs" ]; then
    skip_or_fail "check 1 (no ref lost): merge-base CHANGELOG unreadable"
  else
    lost=$(comm -23 <(printf '%s\n' "$base_refs") <(printf '%s\n' "$cand_refs"))
    if [ -z "$lost" ]; then
      pass "check 1 — no ref lost vs merge-base ($(printf '%s\n' "$base_refs" | grep -c . || true) refs retained)"
    else
      fail "check 1 — $(printf '%s\n' "$lost" | grep -c .) ref(s) present at merge-base but missing now: $(printf '%s\n' "$lost" | tr '\n' ' ')"
    fi
  fi

  # ---- Check 2: rule-22 coverage of this run's own refs -------------------
  run_refs=$(git log --format='%B' "$MERGE_BASE"..HEAD 2>/dev/null \
             | grep -oE '\b(Fixes|Refs|Closes|Close)\s+#([0-9]{3,4})' \
             | grep -oE "$REFPAT" | sort -u)
  if [ -z "$run_refs" ]; then
    pass "check 2 — rule-22 coverage: no Refs/Fixes ref in merge-base..HEAD (nothing to check)"
  else
    missing=$(comm -23 <(printf '%s\n' "$run_refs") <(printf '%s\n' "$cand_refs"))
    if [ -z "$missing" ]; then
      pass "check 2 — rule-22 coverage: all $(printf '%s\n' "$run_refs" | grep -c .) run ref(s) present in CHANGELOG"
    else
      fail "check 2 — $(printf '%s\n' "$missing" | grep -c .) run ref(s) referenced but not in CHANGELOG: $(printf '%s\n' "$missing" | tr '\n' ' ')"
    fi
  fi

  # ---- Check 3: ancestor ref-losing anchor (movable baseline guard) -------
  SCAN="${TLU_ANCHOR_SCAN:-200}"
  anchor_parent=""
  anchor_commit=""
  for C in $(git log --format=%H -"$SCAN" -- CHANGELOG 2>/dev/null); do
    p="$C^"
    git rev-parse --verify -q "$p" >/dev/null 2>&1 || continue
    # only consider commits that actually DELETED CHANGELOG lines
    del=$(git show --numstat --format= "$C" -- CHANGELOG 2>/dev/null | awk '{print $2+0}')
    del="${del:-0}"
    # numstat may report several rows (shouldn't for one file) — take the sum
    del=$(git show --numstat --format= "$C" -- CHANGELOG 2>/dev/null | awk '{s+=$2+0} END{print s+0}')
    [ "$del" -gt 10 ] || continue
    prefs=$(log_refs_of_rev "$p")
    crefs=$(log_refs_of_rev "$C")
    [ -z "$prefs" ] && continue
    if [ -n "$(comm -23 <(printf '%s\n' "$prefs") <(printf '%s\n' "$crefs"))" ]; then
      anchor_parent="$p"; anchor_commit="$C"; break
    fi
  done

  if [ -z "$anchor_commit" ]; then
    pass "check 3 — no ref-losing ancestor found in last $SCAN CHANGELOG commits (nothing to anchor)"
  else
    arefs=$(log_refs_of_rev "$anchor_parent")
    missing=$(comm -23 <(printf '%s\n' "$arefs") <(printf '%s\n' "$cand_refs"))
    if [ -z "$missing" ]; then
      arefs_n=$(printf '%s\n' "$arefs" | grep -c . || true)
      pass "check 3 — ancestor anchor ${anchor_commit:0:9} ($arefs_n refs of its parent all retained)"
    else
      fail "check 3 — ref-losing ancestor ${anchor_commit:0:9} dropped $(printf '%s\n' "$missing" | grep -c .) ref(s) still missing now: $(printf '%s\n' "$missing" | tr '\n' ' ')"
    fi
  fi

  # ---- optional: this run must have added its required ref ----------------
  if [ -n "${TLU_CHANGELOG_REQUIRE_REF:-}" ]; then
    if printf '%s\n' "$cand_refs" | grep -qx -- "$TLU_CHANGELOG_REQUIRE_REF"; then
      pass "required ref $TLU_CHANGELOG_REQUIRE_REF present in CHANGELOG"
    else
      fail "required ref $TLU_CHANGELOG_REQUIRE_REF missing from CHANGELOG (rule 22)"
    fi
  fi
fi

echo "CL1924 result: $fails FAIL"
exit "$([ "$fails" = 0 ] && echo 0 || echo 1)"
