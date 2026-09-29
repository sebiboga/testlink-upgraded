#!/usr/bin/env bash
# Regression harness for Issue #1718 - legacy lib/results/tcNotRunAnyPlatform.php
# is fatally broken (requires the removed results.class.php, calls a method on null).
#
# Run from repo root:  bash tmp/verify_1718.sh
# Exit 0 = all assertions pass, 1 = at least one failure.
#
# The fix is a DELETION, so the suite has to prove two opposite things at once:
#   (a) the broken legacy route is gone and cannot produce a 500 / a warning row;
#   (b) everything the modern replacement depends on is untouched.
# A pure "the file is gone" check would pass even if the deletion had taken the
# report with it, so the BFF + the #1717 harness + the menu wiring are asserted too.
set -u

BASE="${BASE:-http://localhost:8082}"
CJ="$(mktemp -t cj1718.XXXXXX)"
CJ2="$(mktemp -t cj1718b.XXXXXX)"
trap 'rm -f "$CJ" "$CJ2"' EXIT
PASS=0; FAIL=0; ID=0

ok() { # ok <condition-exit> <label> <expected> <actual>
  ID=$((ID+1))
  if [ "$1" = "0" ]; then
    PASS=$((PASS+1)); printf '| %d | %s | PASS |\n' "$ID" "$2"
  else
    FAIL=$((FAIL+1)); printf '| %d | %s | **FAIL** (expected %s, got %s) |\n' \
      "$ID" "$2" "$3" "$4"
  fi
}
code() { curl -s -b "$1" -o /dev/null -w '%{http_code}' "$2"; }
json() { curl -s -b "$1" "$2"; }

# --- login (the BFF is CSRF-guarded: a same-origin Origin header is REQUIRED) ---
login() { # login <cookiejar> <user> <pass>
  curl -s -c "$1" -b "$1" "$BASE/login.php" -o /dev/null
  curl -s -c "$1" -b "$1" -X POST "$BASE/api/auth/login" \
       -H "Origin: $BASE" -d "login=$2&password=$3" -o /dev/null
}
login "$CJ" admin admin
login "$CJ2" tnrap1717norights admin      # role 3 = no testplan_metrics

# 1. PRIMARY: the legacy route is retired. Pre-fix it answered 500 with a 0-byte
#    body and wrote an E_WARNING "require_once(results.class.php): Failed to open
#    stream" row per request (logs/userlog1.log + events).
got=$(code "$CJ" "$BASE/lib/results/tcNotRunAnyPlatform.php?tplan_id=2")
[ "$got" = "404" ]; ok $? "legacy controller answers 404, not 500" "404" "$got"

# 2. ... and the retired route is gone from BOTH Smarty theme trees.
for t in dashio tl-classic; do
  f="gui/templates/$t/results/tcNotRunAnyPlatform.tpl"
  [ ! -e "$f" ]; ok $? "$f is deleted" "absent" "$([ -e "$f" ] && echo present || echo absent)"
done

# 3. ... and the controller itself is gone from lib/results/.
[ ! -e lib/results/tcNotRunAnyPlatform.php ]; ok $? "lib/results/tcNotRunAnyPlatform.php is deleted" "absent" "$([ -e lib/results/tcNotRunAnyPlatform.php ] && echo present || echo absent)"

# 4. No orphan CALL SITE of the removed class survives anywhere in lib/.
#    Only COMMENTS may still name it (lib/general/asideMenu.php and
#    lib/functions/common.php explain WHY the file was retired), so the
#    assertion strips comments and PHP openers first - a plain grep -r would
#    match those two explanatory comments and pass/fail for the wrong reason.
n=$(grep -rn --include='*.php' 'getMapOfLastResult' lib/ 2>/dev/null \
      | sed -e 's#//.*##' -e 's#/\*.*##' -e 's#^\([^:]*\):[0-9]*:[[:space:]]*\*.*#\1#' \
      | grep -c 'getMapOfLastResult')
[ "$n" = "0" ]; ok $? "no getMapOfLastResult() CALL SITE left in lib/ (comments exempt)" "0" "$n"

# 5. The removed dependency was NOT re-introduced (the fix must not add a
#    replacement file that keeps the old coupling alive).
[ ! -e lib/functions/results.class.php ]; ok $? "lib/functions/results.class.php stays absent" "absent" "$([ -e lib/functions/results.class.php ] && echo present || echo absent)"

# 6. The report is still reachable, and still renders: the modern BFF answers 200
#    with the #1717 fixture's 3-of-5 never-run set.
bff=$(json "$CJ" "$BASE/api/reports/index.php?action=not_run_any_platform&tproject_id=1&tplan_id=2")
echo "$bff" | grep -q '"status":"ok"'; ok $? "modern BFF not_run_any_platform answers status ok" "status ok" "$(echo "$bff" | head -c 60)"
echo "$bff" | grep -q '"number_of_not_run":3'; ok $? "modern BFF reports 3 of 5 never-run" "number_of_not_run 3" "$(echo "$bff" | grep -o '"number_of_not_run":[0-9]*')"
echo "$bff" | grep -q '"number_of_testcases":5'; ok $? "modern BFF reports 5 test cases in the plan" "number_of_testcases 5" "$(echo "$bff" | grep -o '"number_of_testcases":[0-9]*')"
for id in TNR1717-1 TNR1717-4 TNR1717-5; do
  echo "$bff" | grep -q "\"external_id\":\"$id\""; ok $? "never-run row $id still returned" "$id" "absent"
done

# 7. Guard matrix unchanged by the deletion: 400 missing ids, 400 foreign plan,
#    403 no rights (measured live as the no-rights user, not asserted by copy).
for q in "tproject_id=0&tplan_id=0" "tproject_id=1&tplan_id=999" "tproject_id=1&tplan_id=1"; do
  got=$(code "$CJ" "$BASE/api/reports/index.php?action=not_run_any_platform&$q")
  [ "$got" = "400" ]; ok $? "guard 400 for [$q]" "400" "$got"
done
got=$(code "$CJ2" "$BASE/api/reports/index.php?action=not_run_any_platform&tproject_id=1&tplan_id=2")
[ "$got" = "403" ]; ok $? "guard 403 for a user without testplan_metrics" "403" "$got"

# 8. No new Error/Warning row was produced by any of the requests above.
#    BASELINE = 1: `events` id 3, the E_WARNING raised by the deleted
#    controller's own reproduction probe. The ORIGINAL pre-fix fatal wrote NO
#    events row at all - it died at the include with no session, so it only
#    ever reached logs/userlog1.log ("[26/Sep/29 13:38:01] ... Line 16").
#    So the bound is the baseline, not "baseline + slack": anything above 1
#    means this diff (or the harness) started raising diagnostics.
warns=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
        -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);" 2>/dev/null | tr -d ' ')
[ "${warns:-99}" -le 1 ]; ok $? "no new Error/Warning row beyond the 1 pre-fix baseline row" "<= 1" "${warns:-unreadable}"

# 9. Nothing else in lib/results/ lost its syntax gate.
bad=0
for f in lib/results/*.php; do php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "   syntax FAIL: $f"; }; done
[ "$bad" = "0" ]; ok $? "php -l clean on all $(ls lib/results/*.php | wc -l | tr -d ' ') remaining lib/results/*.php" "0 failures" "$bad failures"

# 10. The #1717 harness (40 assertions over the modern BFF helpers, the i18n
#     bundles and the ASIDE label) must still be 40/40 - this is the guard that
#     the deletion did not cost the report any behaviour.
if [ "${SKIP_HARNESS:-0}" = "1" ]; then
  printf '| - | #1717 harness (php tmp/test_1717.php) | SKIPPED (SKIP_HARNESS=1) |\n'
else
  h=$(php tmp/test_1717.php 2>&1 | tail -1)
  echo "$h" | grep -q '40 PASS, 0 FAIL'; ok $? "#1717 harness still 40/40 PASS" "40 PASS, 0 FAIL" "$h"
fi

echo
echo "**Result: $((ID)) assertions, $PASS PASS, $FAIL FAIL.**"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
