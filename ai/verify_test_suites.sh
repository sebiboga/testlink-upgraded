#!/usr/bin/env bash
# verify_test_suites.sh — gate for the shared regression-suite file
# tmp/TLU_Test_Cases.md (rule 9 of ai/AGENTS.md, §5 of ai/FIX-ISSUE.md and
# ai/IMPLEMENT-TASK.md).
#
# WHY THIS EXISTS — issue #1805 (3rd occurrence of the class tracked in #1793):
# a concurrent agent's rebase resolution silently dropped a whole suite from the
# shared file, and the gate mandated by the rulebooks passed on the result:
#
#   * `git diff --numstat HEAD~1 HEAD -- tmp/TLU_Test_Cases.md` -> "4  0",
#     i.e. additions only, because the loss entered the branch in an EARLIER
#     commit (the rebase resolution) than the commit being gated. A per-commit
#     diff cannot see it.
#   * `grep -cE "^## (Regression|Suite|Task|Modernize) "` must not drop — also
#     blind: the measured reproduction grew 55 -> 56 suites while deleting one.
#     A count is not a set.
#   * `git diff --cached` is empty by construction once the file is staged with
#     `-f` (the file is git-ignored, .gitignore:47).
#
# The invariant that actually matters is a MERGE-BASE property: every suite
# heading that existed before this run must still exist afterwards. That is what
# this script computes.
#
# USAGE
#   bash ai/verify_test_suites.sh [--allow-skip] [<candidate-file>]
#
#   <candidate-file>  file to check (default: the working copy
#                     tmp/TLU_Test_Cases.md, falling back to the version staged
#                     in the index). Pass a path to check a saved copy.
#   --allow-skip      exit 0 when a check cannot run (no git clone / no remote /
#                     file absent). Without it, an unrunnable check is a FAIL,
#                     so the gate can never report success it did not verify.
#
# The script only READS (git show / cat). It never writes, moves or deletes the
# suite file or anything else.
#
# EXIT: 0 = every invariant PASS, 1 = at least one FAIL.
#
# Refs #1805 (follow-up to #1793, #1701 lost in ce093fa54, #1740 lost in
# a2df484a8, Issue #1048 lost in 966a7997d).

set -uo pipefail

SUITE_PATH="tmp/TLU_Test_Cases.md"
ALLOW_SKIP=0
CANDIDATE=""

for arg in "$@"; do
  case "$arg" in
    --allow-skip) ALLOW_SKIP=1 ;;
    -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
    *) CANDIDATE="$arg" ;;
  esac
done

pass=0
fail=0
skipped=0

ok()   { pass=$((pass+1)); printf '  PASS  %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  FAIL  %s\n' "$1"; }
skip() { skipped=$((skipped+1)); printf '  SKIP  %s\n' "$1"; }

# Suite headings only: `## Regression — Issue #n: …`, `## Task — Issue #n: …`,
# `## Suite …`, `## Modernize …`. Suite bodies quote issue numbers in prose, so a
# plain `Issue #<n>` grep would both over-match and miss a suite whose heading was
# rewritten; the heading is the suite's identity.
H='^## (Regression|Suite|Task|Modernize) '

in_git_repo() { git rev-parse --git-dir >/dev/null 2>&1; }

# --- 0. preconditions -------------------------------------------------------
if [ -n "$CANDIDATE" ] && [ ! -f "$CANDIDATE" ]; then
  bad "candidate file exists ($CANDIDATE)"
  printf 'G1805 result: %d PASS / %d FAIL / %d SKIP  (exit 1)\n' "$pass" "$fail" "$skipped"
  exit 1
fi

if [ -z "$CANDIDATE" ]; then
  if [ -f "$SUITE_PATH" ]; then
    CANDIDATE="$SUITE_PATH"
  elif git cat-file -e ":$SUITE_PATH" 2>/dev/null; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    git show ":$SUITE_PATH" > "$tmp/$SUITE_PATH"
    CANDIDATE="$tmp/$SUITE_PATH"
  else
    if [ "$ALLOW_SKIP" = 1 ]; then
      skip "$SUITE_PATH not present in worktree or index"
    else
      bad "$SUITE_PATH not present in worktree or index (re-run with --allow-skip to tolerate)"
    fi
    printf 'G1805 result: %d PASS / %d FAIL / %d SKIP  (exit 1)\n' "$pass" "$fail" "$skipped"
    exit 1
  fi
fi

printf 'suite file: %s\n' "$CANDIDATE"

# --- 1. candidate is readable and is the suite file --------------------------
if grep -qE "$H" "$CANDIDATE"; then
  ok "candidate contains suite headings"
else
  bad "candidate contains suite headings (looks like a truncated/empty suite file)"
fi

# Balanced markdown fences: a rewritten-from-stale copy is the usual way these
# blocks get cut in half.
fences=$(grep -c '^```' "$CANDIDATE")
if [ $((fences % 2)) -eq 0 ]; then
  ok "markdown code fences balanced (= $fences)"
else
  bad "markdown code fences balanced (odd count = $fences, file cut mid-block)"
fi

# Every suite heading must be followed by real content. This is the signature of
# the #1805 auto-resolution mode: git took upstream for the `## Task — Issue #1048`
# heading and the suite body was never restored, so the heading survived with an
# empty block. Threshold 3 non-blank lines; the shortest real suite on the default
# branch has 6, so this cannot fire on a healthy file.
trunc="$(awk '
  /^## (Regression|Suite|Task|Modernize) / { if (have && body < 3) n++; heading=$0; body=0; have=1; next }
  have && NF > 0 { body++ }
  END { if (have && body < 3) n++; print n + 0 }
' "$CANDIDATE")"
if [ "${trunc:-1}" -eq 0 ]; then
  ok "every suite heading has a body (0 header-only blocks)"
else
  bad "every suite heading has a body ($trunc header-only/truncated blocks)"
  awk '
    /^## (Regression|Suite|Task|Modernize) / { if (have && body < 3) print "          TRUNC: " heading " [" body " non-blank lines]"; heading=$0; body=0; have=1; next }
    have && NF > 0 { body++ }
    END { if (have && body < 3) print "          TRUNC: " heading " [" body " non-blank lines]" }
  ' "$CANDIDATE" | head -10
fi

# --- 2. the merge-base gate (the part no previous rulebook check performed) ---
if ! in_git_repo; then
  msg="cannot compute a merge-base: not inside a git clone"
  if [ "$ALLOW_SKIP" = 1 ]; then skip "$msg"; else bad "$msg (re-run with --allow-skip to tolerate)"; fi
else
  base=""
  default_branch="${TLU_DEFAULT_BRANCH:-}"
  if [ -z "$default_branch" ]; then
    default_branch="$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's|^origin/||')"
  fi
  [ -n "$default_branch" ] || default_branch="sebiboga"

  if git rev-parse --verify --quiet "origin/$default_branch" >/dev/null 2>&1; then
    base="$(git merge-base HEAD "origin/$default_branch" 2>/dev/null)"
  fi
  if [ -z "$base" ] && git rev-parse --verify --quiet origin/HEAD >/dev/null 2>&1; then
    base="$(git merge-base HEAD origin/HEAD 2>/dev/null)"
  fi
  if [ -z "$base" ]; then
    base="$(git rev-list --max-parents=0 HEAD 2>/dev/null | tail -1)"
  fi

  if [ -z "$base" ] || ! git cat-file -e "$base:$SUITE_PATH" 2>/dev/null; then
    msg="no baseline for $SUITE_PATH (no merge-base with origin/$default_branch, no origin/HEAD)"
    if [ "$ALLOW_SKIP" = 1 ]; then skip "$msg"; else bad "$msg (re-run with --allow-skip to tolerate)"; fi
  else
    btmp="$(mktemp -d)"
    git show "$base:$SUITE_PATH" > "$btmp/base.md"
    base_suites=$(grep -cE "$H" "$btmp/base.md")
    cand_suites=$(grep -cE "$H" "$CANDIDATE")
    printf 'baseline: %s (%s suites) -> candidate: %s suites\n' \
      "$(git rev-parse --short "$base")" "$base_suites" "$cand_suites"

    # 2a. THE GATE: set difference over suite headings. A count comparison is not
    #     enough (measured in #1805: 55 -> 56 suites while one was deleted).
    lost="$(comm -23 \
      <(grep -E "$H" "$btmp/base.md" | LC_ALL=C sort) \
      <(grep -E "$H" "$CANDIDATE" | LC_ALL=C sort))"
    if [ -z "$lost" ]; then
      ok "no suite lost vs merge-base with origin/$default_branch (= 0)"
    else
      n=$(printf '%s\n' "$lost" | grep -c .)
      bad "no suite lost vs merge-base with origin/$default_branch ($n lost)"
      printf '%s\n' "$lost" | sed 's/^/          LOST: /'
      printf '          recover with: git show <sha>:%s  (re-append the block verbatim)\n' "$SUITE_PATH"
    fi

    # 2b. Deletions relative to the base. Unlike `git diff --numstat HEAD~1 HEAD`
    #     this compares against the base of the work, so it also catches a loss
    #     that entered an earlier commit of this branch.
    del=$(diff "$btmp/base.md" "$CANDIDATE" | grep -c '^<' || true)
    if [ "${del:-0}" -eq 0 ]; then
      ok "no line removed from the suite file vs merge-base (= 0)"
    else
      bad "no line removed from the suite file vs merge-base (= $del)"
      diff "$btmp/base.md" "$CANDIDATE" | grep '^<' | head -20 | sed 's/^/          - /'
    fi

    # 2c. The suite this run is obliged to add must be present.
    if [ -n "${TLU_REQUIRE_SUITE:-}" ] && grep -qF "$TLU_REQUIRE_SUITE" "$CANDIDATE"; then
      ok "own suite present (${TLU_REQUIRE_SUITE})"
    elif [ -n "${TLU_REQUIRE_SUITE:-}" ]; then
      bad "own suite present (${TLU_REQUIRE_SUITE})"
    else
      skip "own suite not checked (set TLU_REQUIRE_SUITE=\"Issue #<n>\" to check it)"
    fi

    rm -rf "$btmp"
  fi
fi

printf 'G1805 result: %d PASS / %d FAIL / %d SKIP\n' "$pass" "$fail" "$skipped"
[ "$fail" -eq 0 ] || exit 1
exit 0