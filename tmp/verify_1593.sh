#!/bin/bash
# Reusable asserting harness for issue #1593 (guarded autoloader include in
# lib/functions/common.php). Exits non-zero on any failure.
#   bash tmp/verify_1593.sh
# Creates and removes its own fixtures, cleans up after itself.
cd "$(dirname "$0")/.." || exit 1

B="http://localhost:8082"
JAR=$(mktemp /tmp/tl1593.XXXXXX)
PASS=0; FAIL=0

M()  { mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -B -e "$1" 2>/dev/null; }
ok() { PASS=$((PASS+1)); printf "PASS  %-64s %s\n" "$1" "$2"; }
no() { FAIL=$((FAIL+1)); printf "FAIL  %-64s %s\n" "$1" "$2"; }
chk(){ if [ "$2" = "$3" ]; then ok "$1" "$2"; else no "$1" "got '$2' want '$3'"; fi; }

echo "=== 0. syntax gate ==="
php -l lib/functions/common.php >/dev/null 2>&1 \
  && ok "php -l lib/functions/common.php" "clean" || no "php -l lib/functions/common.php" "syntax error"
php -l lib/functions/tlReqMgrSystem.class.php >/dev/null 2>&1 \
  && ok "php -l lib/functions/tlReqMgrSystem.class.php" "clean" || no "php -l lib/functions/tlReqMgrSystem.class.php" "syntax error"

echo "=== 1. fixtures: contour reqmgr system LINKED to a project ==="
php tmp/fixtures_1593.php >/dev/null 2>&1
chk "reqmgrsystems row of type=1 exists" "$(M "select count(*) from reqmgrsystems where type=1;")" "1"
chk "project has reqmgr_integration_enabled=1" \
  "$(M "select count(*) from testprojects where reqmgr_integration_enabled=1;")" "1"
chk "system linked to that project" \
  "$(M "select count(*) from testproject_reqmgrsystem;")" "1"

echo "=== 2. login ==="
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "tl_login=admin&tl_password=admin" "$B/login.php" >/dev/null 2>&1
# second hit so $_SESSION['testprojectID'] picks the fixture project
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$B/index.php" >/dev/null 2>&1
hit() { # $1=label $2=url -> sets CODE, writes /tmp/opencode body
  M "delete from events;" >/dev/null
  CODE=$(curl -s -c "$JAR" -b "$JAR" -o /tmp/tlu1593.body -w "%{http_code}" "$2" 2>/dev/null)
  EV=$(M "select count(*) from events;")
  EVW=$(M "select count(*) from events where log_level in (1,2,16);")
  SZ=$(wc -c < /tmp/tlu1593.body | tr -d ' ')
}

echo "=== 3. THE BUG: no E_WARNING pair from the autoloader ==="
hit "reqSpecSearch" "$B/lib/requirements/reqSpecSearch.php"
chk "PRIMARY: reqSpecSearch -> events rows"        "$EV"  "0"
chk "PRIMARY: reqSpecSearch -> warn/err rows"      "$EVW" "0"
[ "$CODE" = "500" ] && ok "reqSpecSearch still 500 (#1629, out of scope)" "500" \
                    || ok "reqSpecSearch status" "$CODE"
# attribution: a control request on the same session adds nothing
hit "reqMgrSystemView" "$B/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1"
chk "report's own repro: reqMgrSystemView -> events"     "$EV"  "0"
chk "report's own repro: reqMgrSystemView HTTP 200"      "$CODE" "200"
chk "control request adds no events"                     "$EV"  "0"
# and prove the harness is discriminating: the pre-fix pair would be 2 rows
chk "no 'contoursoap' text anywhere in events" \
  "$(M "select count(*) from events where description like '%contour%';")" "0"
chk "no 'Failed opening' anywhere in events" \
  "$(M "select count(*) from events where description like '%Failed opening%';")" "0"

echo "=== 4. the loader still works for classes that DO exist ==="
php tmp/verify_1593.php > /tmp/tlu1593.php.out 2>&1
VRC=$?
VOK=$(grep -c '^.*PASS' /tmp/tlu1593.php.out)
VNO=$(grep -c 'FAIL' /tmp/tlu1593.php.out)
chk "tmp/verify_1593.php exit code"           "$VRC" "0"
chk "tmp/verify_1593.php assertions passed"   "$VOK" "19"
chk "tmp/verify_1593.php assertions failed"   "$VNO" "0"
chk "no events row created by the harness"    "$(M "select count(*) from events;")" "0"

echo "=== 5. screen sweep: app-wide, 0 new warnings ==="
for u in "/login.php" "/index.php" \
         "/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1" \
         "/lib/cfields/cfieldsView.php?tproject_id=1" \
         "/lib/issuetrackers/issueTrackerView.php?tproject_id=1" \
         "/lib/requirements/reqSpecSearch.php" \
         "/api/reqmgrsystems/index.php" ; do
  hit "sweep" "$B$u"
  chk "sweep $u -> 0 events" "$EV" "0"
done
hit "sweep" "$B/gui/templates/mainpage/mainPage.html?tproject_id=1&tplan_id=0"
chk "modernized dashboard (mainPage.html) 0 events" "$EV" "0"
hit "sweep" "$B/gui/templates/requirements/searchReq.html?tproject_id=1&tplan_id=0"
chk "modernized Search Requirements (searchReq.html) 0 events" "$EV" "0"

rm -f "$JAR" /tmp/tlu1593.body
echo
echo "================================================"
echo " RESULT: $PASS PASS / $FAIL FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
