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
#   <candidate-file>  file to check (default: the working copy of
#                     tmp/TLU_Test_Cases.md, falling back to the version staged
#                     in the index). Pass a path to check a saved copy.
#   --allow-skip      exit 0 when a check cannot run (no git clone / no usable
#                     baseline / file absent). Without it an unrunnable check
#                     is a FAIL, so the gate can never report a success it did
#                     not verify.
#
# The script never writes, moves or deletes the suite file. It does create and
# remove scratch directories under $TMPDIR while resolving the baseline.
#
# EXIT: 0 = every invariant PASS, 1 = at least one FAIL.
#
# Refs #1805 (follow-up to #1793, #1701 lost in ce093fa54, #1740 lost in
# a2df484a8, Issue #1048 lost in 966a7997d).

set -uo pipefail

SUITE_RELPATH="tmp/TLU_Test_Cases.md"
ALLOW_SKIP=0
CANDIDATE=""

for arg in "$@"; do
  case "$arg" in
    --allow-skip) ALLOW_SKIP=1 ;;
    -h|--help) sed -n '2,41p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) printf 'unknown option: %s (see --help)\n' "$arg" >&2; exit 1 ;;
    *) CANDIDATE="$arg" ;;
  esac
done

pass=0
fail=0
skipped=0

ok()   { pass=$((pass+1)); printf '  PASS  %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  FAIL  %s\n' "$1"; }
skip() { skipped=$((skipped+1)); printf '  SKIP  %s\n' "$1"; }

summary() {
  printf 'G1805 result: %d PASS / %d FAIL / %d SKIP\n' "$pass" "$fail" "$skipped"
}

# A check that could not run must never be counted as a check that passed.
unrunnable() {
  if [ "$ALLOW_SKIP" = 1 ]; then skip "$1 (allowed)"; else bad "$1 (re-run with --allow-skip to tolerate)"; fi
}

# Suite headings. Every `## ` heading in the tracked file is a suite (measured on
# the default branch: 60 of 60), but four of them do not use the
# `## Regression — Issue #n:` prefixes — `## T1637 — Regression: …`,
# `## 1783 — Regression: …`, `## 1785 — Modernize …`, `## 1787 — Modernize …` —
# so the narrower prefix pattern silently ignored them. Matching the level is the
# safer default: the consequence of a false positive is a conservative FAIL, the
# consequence of a false negative is a lost suite.
H='^## '

in_git_repo() { git rev-parse --git-dir >/dev/null 2>&1; }

tmpdirs=""

new_tmpdir() {
  local d
  d="$(mktemp -d)" || return 1
  tmpdirs="$tmpdirs $d"
  printf '%s' "$d"
}

cleanup() {
  local d
  for d in $tmpdirs; do rm -rf "$d"; done
}
trap cleanup EXIT INT TERM

# --- 0. preconditions -------------------------------------------------------
if [ -n "$CANDIDATE" ] && [ ! -e "$CANDIDATE" ]; then
  bad "candidate file exists ($CANDIDATE)"
  summary
  exit 1
fi

# Resolve the suite path against the repository root, so the documented
# invocation also works from inside ai/.
SUITE_PATH="$SUITE_RELPATH"
if in_git_repo; then
  root="$(git rev-parse --show-toplevel 2>/dev/null)"
  if [ -n "$root" ]; then SUITE_PATH="$root/$SUITE_RELPATH"; fi
fi

if [ -z "$CANDIDATE" ]; then
  if [ -f "$SUITE_RELPATH" ]; then
    CANDIDATE="$SUITE_RELPATH"
  elif in_git_repo && git cat-file -e ":$SUITE_RELPATH" 2>/dev/null; then
    # File is tracked but absent from the worktree (sparse checkout, partial
    # rebase): fall back to the staged copy. The destination directory does not
    # exist in a fresh mktemp -d, so create it — otherwise the redirect fails and
    # the script goes on to "verify" a file it never wrote.
    tmp="$(new_tmpdir)" || { bad "cannot create a scratch directory"; summary; exit 1; }
    mkdir -p "$tmp/$(dirname "$SUITE_RELPATH")" \
      || { bad "cannot create $tmp/$(dirname "$SUITE_RELPATH")"; summary; exit 1; }
    if git show ":$SUITE_RELPATH" > "$tmp/$SUITE_RELPATH" 2>/dev/null; then
      CANDIDATE="$tmp/$SUITE_RELPATH"
    else
      bad "index fallback failed (git show :$SUITE_RELPATH)"
      summary
      exit 1
    fi
  else
    unrunnable "$SUITE_RELPATH not present in worktree or index"
    summary
    exit 1
  fi
fi

printf 'suite file: %s\n' "$CANDIDATE"

if [ -r "$CANDIDATE" ] && [ -s "$CANDIDATE" ]; then
  ok "candidate is readable and non-empty"
else
  bad "candidate is readable and non-empty ($CANDIDATE)"
  summary
  exit 1
fi

# --- 1. structural invariants of the candidate -------------------------------
if grep -qE "$H" "$CANDIDATE"; then
  ok "candidate contains suite headings"
else
  bad "candidate contains suite headings (looks like a truncated/empty suite file)"
fi

# Balanced markdown fences: a suite rewritten from a stale copy is the usual way
# these blocks get cut in half. grep exits 1 on no match, so require a number.
fences="$(grep -c '^```' "$CANDIDATE")"
if [ -n "$fences" ] && [ "$fences" -gt 0 ] && [ $((fences % 2)) -eq 0 ]; then
  ok "markdown code fences balanced (= $fences)"
else
  bad "markdown code fences balanced (count = ${fences:-<none>})"
fi

# Every suite heading must have a body. The #1805 auto-resolution signature is a
# heading whose body was never restored, i.e. body == 0 — so 0 is the threshold.
# (A terse but valid suite is allowed; a body-count "quality" threshold would fire
# on legitimate short suites, which is a false positive on a gate agents run.)
trunc="$(awk '
  /^## / { if (have && body < 1) n++; heading=$0; body=0; have=1; next }
  have && NF > 0 { body++ }
  END { if (have && body < 1) n++; print n + 0 }
' "$CANDIDATE")"
if [ -n "$trunc" ] && [ "${trunc:-1}" -eq 0 ]; then
  ok "no suite heading left without a body (= 0)"
else
  bad "no suite heading left without a body (= ${trunc:-<error>})"
  awk '
    /^## / { if (have && body < 1) print "          EMPTY: " heading; heading=$0; body=0; have=1; next }
    have && NF > 0 { body++ }
    END { if (have && body < 1) print "          EMPTY: " heading }
  ' "$CANDIDATE" | head -10
fi

# --- 2. the merge-base gate (the part no previous rulebook check performed) ---
if ! in_git_repo; then
  unrunnable "cannot compute a baseline: not inside a git clone"
else
  base=""
  base_kind=""
  default_branch="${TLU_DEFAULT_BRANCH:-}"
  if [ -z "$default_branch" ]; then
    default_branch="$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's|^origin/||')"
  fi
  [ -n "$default_branch" ] || default_branch="sebiboga"

  if git rev-parse --verify --quiet "origin/$default_branch" >/dev/null 2>&1; then
    base="$(git merge-base HEAD "origin/$default_branch" 2>/dev/null)"
    [ -n "$base" ] && base_kind="merge-base with origin/$default_branch"
  fi
  if [ -z "$base" ] && git rev-parse --verify --quiet origin/HEAD >/dev/null 2>&1; then
    base="$(git merge-base HEAD origin/HEAD 2>/dev/null)"
    [ -n "$base" ] && base_kind="merge-base with origin/HEAD"
  fi

  if [ -z "$base" ]; then
    # Last resort: the root commit. It is NOT a merge-base and only contains the
    # suites that existed at repository start, so it cannot prove "nothing that
    # existed before this run is missing". Report it as unrunnable rather than
    # passing on a baseline that cannot support the claim.
    root="$(git rev-list --max-parents=0 HEAD 2>/dev/null | tail -1)"
    if [ -n "$root" ] && git cat-file -e "$root:$SUITE_RELPATH" 2>/dev/null; then
      base="$root"
      base_kind="ROOT COMMIT (weak baseline - cannot prove no suite was lost)"
    fi
  fi

  if [ -z "$base" ] || ! git cat-file -e "$base:$SUITE_RELPATH" 2>/dev/null; then
    unrunnable "no usable baseline for $SUITE_RELPATH (no merge-base with origin/$default_branch, no origin/HEAD)"
  elif [ "${base_kind%% *}" = "ROOT" ]; then
    unrunnable "baseline fell back to the root commit $(git rev-parse --short "$base") — no origin/$default_branch or origin/HEAD reachable, so 'no suite lost' cannot be verified"
  else
    btmp="$(new_tmpdir)" || { bad "cannot create a scratch directory"; summary; exit 1; }
    git show "$base:$SUITE_RELPATH" > "$btmp/base.md" \
      || { bad "cannot read $base:$SUITE_RELPATH"; summary; exit 1; }
    base_suites=$(grep -cE "$H" "$btmp/base.md")
    cand_suites=$(grep -cE "$H" "$CANDIDATE")
    printf 'baseline: %s (%s) -> candidate: %s suites\n' \
      "$(git rev-parse --short "$base")" "$base_suites" "$cand_suites"

    # 2a. THE GATE: set difference over suite headings. A count comparison is not
    #     enough (measured in #1805: 55 -> 56 suites while one was deleted).
    lost=""
    if ! lost="$(comm -23 \
          <(grep -E "$H" "$btmp/base.md" | LC_ALL=C sort) \
          <(grep -E "$H" "$CANDIDATE" | LC_ALL=C sort))"; then
      bad "set difference over suite headings could not be computed"
    elif [ -z "$lost" ]; then
      ok "no suite lost vs $base_kind (= 0)"
    else
      n=$(printf '%s\n' "$lost" | grep -c .)
      bad "no suite lost vs $base_kind ($n lost)"
      printf '%s\n' "$lost" | sed 's/^/          LOST: /'
      printf '          recover with: git show <sha>:%s  (re-append the block verbatim)\n' "$SUITE_RELPATH"
    fi

    # 2b. Deletions relative to the base. Unlike `git diff --numstat HEAD~1 HEAD`
    #     this compares against the base of the work, so it also catches a loss
    #     that entered an earlier commit of this branch.
    #
    #     Both sides are normalised for a missing final newline first (#1876):
    #     the clobber lineage (#1849/#1851) left the baseline unterminated at
    #     EOF, and GNU diff reports `last-line` vs `last-line\n` as `2c` — a
    #     false "line removed" for ANY append. Normalisation keeps real
    #     deletions visible (a removed line is still removed) and does not
    #     touch check 2a, the heading set-difference.
    norm_eof() { awk '{print}' "$1"; }
    del="$(diff <(norm_eof "$btmp/base.md") <(norm_eof "$CANDIDATE") | grep -c '^<')"
    if [ -z "$del" ]; then
      bad "line comparison against the baseline could not be computed"
    elif [ "$del" -eq 0 ]; then
      ok "no line removed from the suite file vs $base_kind (= 0)"
    else
      bad "no line removed from the suite file vs $base_kind (= $del)"
      diff <(norm_eof "$btmp/base.md") <(norm_eof "$CANDIDATE") | grep '^<' | head -20 | sed 's/^/          - /'
    fi

    # 2c. The suite this run is obliged to add must be present. Matched against
    #     the SUITE HEADINGS only: the issue number also occurs in prose, so a
    #     whole-file grep passes even when the suite itself is gone (measured),
    #     and some headings spell it lowercase ("(issue #1644)").
    if [ -n "${TLU_REQUIRE_SUITE:-}" ]; then
      req="$(printf '%s' "$TLU_REQUIRE_SUITE" | sed 's/.*#/#/')"
      if grep -iE "^## .*${req}" "$CANDIDATE" >/dev/null 2>&1; then
        ok "own suite heading present (${TLU_REQUIRE_SUITE})"
      else
        bad "own suite heading present (${TLU_REQUIRE_SUITE})"
      fi
    else
      skip "own suite not checked (set TLU_REQUIRE_SUITE=\"Issue #<n>\" to check it)"
    fi
  fi
fi

summary
[ "$fail" -eq 0 ] || exit 1
exit 0