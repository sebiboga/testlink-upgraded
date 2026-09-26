#!/bin/bash
# Regression matrix for issue #1617 -- HTTP rows.
#   bash tmp/verify_1617.sh     -> prints PASS/FAIL per row, exits 1 if any FAIL
# Rows M5/M6b live in tmp/unit_1617.php (run that too).
# Asserts (not just echoes) so a regression cannot produce a false PASS.
DB="mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e"
C=/tmp/tl_1617_cookie.txt
FAILURES=0

ok()   { if [ "$1" = "0" ]; then echo "  PASS  $2"; else echo "  FAIL  $2"; FAILURES=$((FAILURES+1)); fi; }
warn() { echo "        (events: $1)"; }
ev()   { mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e 'SELECT COUNT(*) FROM events;'; }
# req <url> -> echoes "http size"
req()  { curl -s -b $C -c $C -w '%{http_code} %{size_download}' -o "$2" "$1"; }
setrow() { $DB "DELETE FROM issuetrackers;"; $DB "DELETE FROM events;"; }
addrow() { $DB "INSERT INTO issuetrackers (name,type,cfg) VALUES ('$1',$2,'<testlink/>');"; }
getid() { mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM issuetrackers WHERE type=$1 LIMIT 1;"; }
hasc()  { grep -c "$2" "$1" >/dev/null 2>&1 && [ "$(grep -c "$2" "$1")" -gt 0 ] && echo 0 || echo 1; }

rm -f $C
curl -s -c $C -b $C -o /dev/null http://localhost:8082/index.php
curl -s -c $C -b $C -o /dev/null -L -d "tl_login=admin&tl_password=admin&tl_login_btn=Login" "http://localhost:8082/login.php?viewer=public"
BASE="http://localhost:8082/lib/issuetrackers"

echo "== M1: only unknown type (0), grid with checkEnv =="
setrow; addrow BadType 0
R=$(req "$BASE/issueTrackerView.php?tproject_id=9901" /tmp/m1.html)
[ "${R%% *}" = "200" ]; ok $? "HTTP 200 (got $R)"
[ "$(hasc /tmp/m1.html BadType)" = "0" ]; ok $? "bad row is listed and visible"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"

echo "== M2: only valid type (1), grid =="
setrow; addrow GoodType 1
R=$(req "$BASE/issueTrackerView.php?tproject_id=9901" /tmp/m2.html)
[ "${R%% *}" = "200" ]; ok $? "HTTP 200 (got $R)"
[ "$(hasc /tmp/m2.html GoodType)" = "0" ]; ok $? "good row is listed"
[ "$(hasc /tmp/m2.html 'bugzilla (Interface: xmlrpc)')" = "0" ]; ok $? "type_descr rendered (no regression)"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"

echo "== M3: mixed 0 + 1 + 5 =="
setrow; addrow BadType 0; addrow GoodType 1; addrow JiraType 5
R=$(req "$BASE/issueTrackerView.php?tproject_id=9901" /tmp/m3.html)
[ "${R%% *}" = "200" ]; ok $? "HTTP 200 (got $R)"
[ "$(hasc /tmp/m3.html BadType)$(hasc /tmp/m3.html GoodType)$(hasc /tmp/m3.html JiraType)" = "000" ]; ok $? "ALL THREE rows listed (one bad row no longer hides the others)"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"

echo "== M4: checkConnection routes =="
setrow; addrow BadType 0; addrow GoodType 1
BADID=$(getid 0); $DB "DELETE FROM events;"
R=$(req "$BASE/issueTrackerView.php?tproject_id=9901&id=$BADID" /tmp/m4.html)
[ "${R%% *}" = "200" ]; ok $? "grid ?id=<bad row>  -> HTTP 200 (got $R)"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"
$DB "DELETE FROM events;"
R=$(req "$BASE/issueTrackerView.php?tproject_id=9901&id=$(getid 1)" /tmp/m4b.html)
[ "${R%% *}" = "200" ]; ok $? "grid ?id=<good row> -> HTTP 200 (got $R)"
# NOTE: a good type=1 row whose cfg is not real Bugzilla XML logs exactly one
# "Undefined property: stdClass::$uribase" from the interface's own catch block.
# That is #1619, unrelated to this fix, so it is not asserted here.
echo "        (events on the good row: $(ev) -- 1 expected, see #1619)"
$DB "DELETE FROM events;"
curl -s -b $C -c $C -o /tmp/m4c.html -w '  (http %{http_code} %{size_download} bytes)\n' \
  -d "doAction=checkConnection&type=0&name=BadType&cfg=" "$BASE/issueTrackerEdit.php"
[ "$(hasc /tmp/m4c.html 'Issue Tracker type 0 is unknown')" = "0" ]; ok $? "Test Connection with type=0 shows the localized 'type is unknown' message"
[ "$(hasc /tmp/m4c.html 'alert-danger')" = "0" ]; ok $? "message is rendered as an ERROR (alert-danger), not neutral info"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"

echo "== M7: ajax cfg-template loader (regression guard) =="
$DB "DELETE FROM events;"
curl -s -b $C -c $C -o /tmp/m7a.html "http://localhost:8082/lib/ajax/getissuetrackercfgtemplate.php?type=0"
[ "$(hasc /tmp/m7a.html 'Issue Tracker type 0 is unknown')" = "0" ]; ok $? "type=0 still returns the localized invalid-type message"
curl -s -b $C -c $C -o /tmp/m7b.html "http://localhost:8082/lib/ajax/getissuetrackercfgtemplate.php?type=1"
[ "$(hasc /tmp/m7b.html '<pre>')" = "0" ]; ok $? "type=1 still returns a real cfg template"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"

echo "== M8: Code Trackers screen (the #1597 twin) =="
$DB "DELETE FROM events;"
R=$(req "http://localhost:8082/lib/codetrackers/codeTrackerView.php" /tmp/m8.html)
[ "${R%% *}" = "200" ]; ok $? "HTTP 200 (got $R)"
W=$(ev); [ "$W" = "0" ]; ok $? "0 event rows"; warn "$W"

echo "== M9: Event Viewer at end of matrix =="
W=$(ev); [ "$W" = "0" ]; ok $? "0 total event rows"; warn "$W"

echo
if [ "$FAILURES" -eq 0 ]; then echo "ALL PASS"; exit 0; else echo "$FAILURES FAILURE(S)"; exit 1; fi
