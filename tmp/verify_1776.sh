#!/bin/bash
# Regression matrix for Fixes #1776 - out($data, $code = 200) reset the HTTP status
# line, so every "http_response_code(N); out([...])" branch of the 14 affected
# BFF helpers answered HTTP 200 with an error body.
#
# Usage: bash tmp/verify_1776.sh
# Requires: app on http://localhost:8082, DB testlink@127.0.0.1 (fixtures created
#           by this script, see "fixture" section below).
set -u
H="http://localhost:8082"
CK=/tmp/ck1776.txt
PASS=0; FAIL=0

# NOTE: rows carrying 'Undefined array key "tplan"' are the already-filed #1775
# (tlUser::hasRight 3-argument regression); they are counted separately so a
# rights check in the matrix cannot be mistaken for a regression of #1776.
WNB() { mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
        -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2) $1"; }
WBASE=$(WNB "AND description NOT LIKE '%Undefined array key \"tplan\"%'")
WKEEP=$(WNB "")

# --- fixture: one test project + one test plan, enough for a 200 success path ---
mysql -h 127.0.0.1 -utestlink -ptestlink testlink >/dev/null 2>&1 <<'SQL'
INSERT INTO testprojects (id,prefix,notes,active,is_public,tc_counter,api_key)
  VALUES (9001,'RFP','Issue 1776 regression fixture',1,0,0,'fix1776apikey0000000000000000000000000000')
  ON DUPLICATE KEY UPDATE notes='Issue 1776 regression fixture';
INSERT INTO testplans (id,testproject_id,notes,active,is_open,is_public,api_key)
  VALUES (95001,9001,'Issue 1776 regression fixture plan',1,1,0,'fix1776plankey0000000000000000000000000000')
  ON DUPLICATE KEY UPDATE notes='Issue 1776 regression fixture plan';
INSERT INTO nodes_hierarchy (id,parent_id,name,node_type_id,node_order)
  VALUES (9001,1,'Issue 1776 Fixture Project',1,1)
  ON DUPLICATE KEY UPDATE name='Issue 1776 Fixture Project';
INSERT INTO nodes_hierarchy (id,parent_id,name,node_type_id,node_order)
  VALUES (95001,9001,'Issue 1776 Fixture Plan',2,1)
  ON DUPLICATE KEY UPDATE name='Issue 1776 Fixture Plan';
SQL

rm -f $CK
curl -s -c $CK -b $CK -X POST -H 'Content-Type: application/json' \
     -H 'Referer: http://localhost:8082/' -d '{"login":"admin","password":"admin"}' \
     "$H/api/auth/index.php/login" -o /dev/null

# g <label> <expected_status> <url>
g() {
  local label=$1 xs=$2 u=$3 st body
  st=$(curl -s -b $CK -H 'Referer: http://localhost:8082/' -o /tmp/b1776.txt -w '%{http_code}' "$u")
  body=$(head -c 80 /tmp/b1776.txt)
  if [ "$st" = "$xs" ]; then
    echo "PASS  $label  [$st] $body"; PASS=$((PASS+1))
  else
    echo "FAIL  $label  got [$st] want [$xs]  body=$body"; FAIL=$((FAIL+1))
  fi
}

echo "--- convention A: caller sets http_response_code() before out() (the fix) ---"
g "tcassignments/rows  no tproject_id -> 400"            400 "$H/api/tcassignments/index.php/rows"
g "tcassignments/rows  unknown project -> 404"          404 "$H/api/tcassignments/index.php/rows?tproject_id=99999"
g "tcassignments/rows  unknown route     -> 404"         404 "$H/api/tcassignments/index.php/unknownroute"
g "tcassignments/init  no tproject_id   -> 400"         400 "$H/api/tcassignments/index.php/init"
g "execassignment/rows unknown route     -> 404"         404 "$H/api/execassignment/index.php/unknownroute"
g "execassignment     no plan context   -> 400"         400 "$H/api/execassignment/index.php/init"
g "execassignment     unknown plan      -> 404"         404 "$H/api/execassignment/index.php/items?tproject_id=9001&tplan_id=999999"
g "execassignment     no assign right   -> 403"         403 "$H/api/execassignment/index.php/items?tproject_id=9001&tplan_id=95001"
g "tcunassignall      unknown action    -> 404"         404 "$H/api/tcunassignall/index.php?action=unknown&tproject_id=9001"
g "tcstepsreorder     no tcversion_id   -> 400"         400 "$H/api/tcstepsreorder/index.php/unknownroute"
g "tcunassignall      invalid build id  -> 400"         400 "$H/api/tcunassignall/index.php?action=info&tproject_id=9001&tplan_id=95001"

echo "--- convention B: caller passes the code to out() (must not regress) ---"
g "tcassign2tplan     unknown action    -> 404"         404 "$H/api/tcassign2tplan/index.php/unknownroute"
g "tcassign2tplan     missing context   -> 400"         400 "$H/api/tcassign2tplan/index.php/init"
g "testcasesedit      unknown action    -> 404"         404 "$H/api/testcasesedit/index.php/unknownroute"
g "tcbulkop           unknown action    -> 404"         404 "$H/api/tcbulkop/index.php/unknownaction"
g "execassignmentcopy unknown route     -> 404"         404 "$H/api/execassignmentcopy/index.php/unknownroute"

echo "--- guards: not authenticated stays 401 (never reached out()) ---"
st=$(curl -s -H 'Referer: http://localhost:8082/' -o /dev/null -w '%{http_code}' \
     "$H/api/tcassignments/index.php/rows")
if [ "$st" = "401" ]; then echo "PASS  no cookie -> 401  [401]"; PASS=$((PASS+1));
else echo "FAIL  no cookie  got [$st] want [401]"; FAIL=$((FAIL+1)); fi

echo "--- success paths must stay 200 with a real payload ---"
g "tcreorder          init (fixture project) -> 200"   200 "$H/api/tcreorder/index.php/init?tproject_id=9001" 
st=$(curl -s -b $CK -H 'Referer: http://localhost:8082/' -o /tmp/s1776.txt -w '%{http_code}' \
     "$H/api/tcassignments/index.php/init?tproject_id=9001")
body=$(head -c 60 /tmp/s1776.txt)
if [ "$st" = "200" ] && grep -q '"status":"ok"' /tmp/s1776.txt; then
  echo "PASS  tcassignments/init?tproject_id=9001 -> 200 ok  [200] $body"; PASS=$((PASS+1))
else
  echo "FAIL  tcassignments/init  got [$st]  body=$body"; FAIL=$((FAIL+1))
fi

echo "--- no new Error/Warning Event Viewer rows ---"
WNOW=$(WNB "AND description NOT LIKE '%Undefined array key \"tplan\"%'")
WKEEP_NOW=$(WNB "")
echo "Event Viewer Error/Warning rows: before=$WBASE after=$WNOW (new=$((WNOW-WBASE)))"
echo "  (known #1775 'Undefined array key tplan' rows, not part of #1776: +$((WKEEP_NOW-WKEEP)))"
if [ "$WNOW" -le "$WBASE" ]; then echo "PASS  no new Error/Warning rows"; PASS=$((PASS+1));
else echo "FAIL  $((WNOW-WBASE)) new Error/Warning rows"; FAIL=$((FAIL+1)); fi

echo "--- php -l on every patched file ---"
for f in execassignment execassignmentcopy ltx suitemove tcassign2tplan tcassignments \
         tcbulkop tcprintlaunch tcreorder tcstepsreorder tcsummary tcunassignall \
         testcasesedit logviewer; do
  if php -l "api/$f/index.php" >/dev/null 2>&1; then
    echo "PASS  php -l api/$f/index.php"; PASS=$((PASS+1))
  else
    echo "FAIL  php -l api/$f/index.php"; FAIL=$((FAIL+1))
  fi
done

echo
echo "TOTAL: $PASS passed / $FAIL failed"
[ "$FAIL" -eq 0 ]
