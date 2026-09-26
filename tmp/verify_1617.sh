#!/bin/bash
# Regression matrix for issue #1617
DB="mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e"
C=/tmp/tl_1617_cookie.txt
rm -f $C
curl -s -c $C -b $C -o /dev/null http://localhost:8082/index.php
curl -s -c $C -b $C -o /dev/null -L -d "tl_login=admin&tl_password=admin&tl_login_btn=Login" "http://localhost:8082/login.php?viewer=public"
W() { echo "warnings=$(mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e 'SELECT COUNT(*) FROM events;')"; }
G() { curl -s -b $C -c $C -w "%{http_code} %{size_download}" -o "$2" "$1"; }

echo "== M1: only unknown type (type=0), grid =="
$DB "DELETE FROM issuetrackers;" ; $DB "INSERT INTO issuetrackers (name,type,cfg) VALUES ('BadType',0,'');"
$DB "DELETE FROM events;"
echo -n "  "; G "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1" /tmp/m1.html; echo "  BadType_in_page=$(grep -c BadType /tmp/m1.html)"; W

echo "== M2: only valid type=1, grid (no regression) =="
$DB "DELETE FROM issuetrackers;" ; $DB "INSERT INTO issuetrackers (name,type,cfg) VALUES ('GoodType',1,'<testlink/>');"
$DB "DELETE FROM events;"
echo -n "  "; G "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1" /tmp/m2.html; echo "  GoodType_in_page=$(grep -c GoodType /tmp/m2.html)"; W

echo "== M3: mixed valid + unknown =="
$DB "DELETE FROM issuetrackers;" ; $DB "INSERT INTO issuetrackers (name,type,cfg) VALUES ('BadType',0,'');"
$DB "INSERT INTO issuetrackers (name,type,cfg) VALUES ('GoodType',1,'<testlink/>');"
$DB "INSERT INTO issuetrackers (name,type,cfg) VALUES ('JiraType',5,'<testlink/>');"
$DB "DELETE FROM events;"
echo -n "  "; G "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1" /tmp/m3.html; echo "  Bad=$(grep -c BadType /tmp/m3.html) Good=$(grep -c GoodType /tmp/m3.html) Jira=$(grep -c JiraType /tmp/m3.html)"; W

echo "== M4: ?id=<real bad row> / ?id=<real valid row> checkConnection path =="
BADID=$($DB "SELECT id FROM issuetrackers WHERE type=0 LIMIT 1")
GOODID=$($DB "SELECT id FROM issuetrackers WHERE type=1 LIMIT 1")
$DB "DELETE FROM events;"
echo -n "  bad  row id=$BADID : "; G "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=9901&id=$BADID" /tmp/m4.html; echo "  BadType_in_page=$(grep -c BadType /tmp/m4.html)"; W
$DB "DELETE FROM events;"
echo -n "  good row id=$GOODID: "; G "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=9901&id=$GOODID" /tmp/m4b.html; W
echo -n "  Test Connection POST type=0 : "
$DB "DELETE FROM events;"
curl -s -b $C -c $C -o /tmp/m4c.html -w "%{http_code} %{size_download}" -d "doAction=checkConnection&type=0&name=BadType&cfg=" "http://localhost:8082/lib/issuetrackers/issueTrackerEdit.php"
echo "  msg_present=$(grep -c 'is unknown' /tmp/m4c.html)"; W

echo "== M5: getLinkedTo() unit (bad type linked to a project) =="
$DB "DELETE FROM events;"
php tmp/unit_1617.php 2>&1 | sed 's/^/  /'
W

echo "== M7: ajax getissuetrackercfgtemplate (no regression) =="
$DB "DELETE FROM events;"
echo -n "  type=0: "; G "http://localhost:8082/lib/ajax/getissuetrackercfgtemplate.php?type=0" /tmp/m7a.html; echo "  body=$(cat /tmp/m7a.html)"
echo -n "  type=1: "; G "http://localhost:8082/lib/ajax/getissuetrackercfgtemplate.php?type=1" /tmp/m7b.html; echo "  len=$(wc -c </tmp/m7b.html)"
W

echo "== M8: Code Trackers screen (no regression from #1597 twin) =="
$DB "DELETE FROM events;"
echo -n "  "; G "http://localhost:8082/lib/codetrackers/codeTrackerView.php" /tmp/m8.html; W

echo "== M9: Event Viewer final =="
$DB "SELECT COUNT(*) FROM events;" | sed 's/^/  total_event_rows=/'
$DB "SELECT id,LEFT(description,90) FROM events ORDER BY id;" | sed 's/^/  /'
