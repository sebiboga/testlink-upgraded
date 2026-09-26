#!/bin/bash
# Repro script for issue #1617 - issuetrackers unknown type -> HTTP 500
COOKIE=/tmp/tl_1617_cookie.txt
rm -f $COOKIE
curl -s -c $COOKIE -b $COOKIE -o /dev/null http://localhost:8082/index.php
curl -s -c $COOKIE -b $COOKIE -o /dev/null -L -d "tl_login=admin&tl_password=admin&tl_login_btn=Login" "http://localhost:8082/login.php?viewer=public"
# fixtures (table has no 'configurable' column)
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "DELETE FROM issuetrackers;"
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "INSERT INTO issuetrackers (name,type,cfg) VALUES ('BadType',0,'');"
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "INSERT INTO issuetrackers (name,type,cfg) VALUES ('GoodType',1,'<testlink/>');"
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id,name,type FROM issuetrackers;"
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "DELETE FROM events;" 
TP=$(mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT MIN(id) FROM nodes_hierarchy WHERE testproject_id IS NULL;")
echo "tproject_id=$TP"
echo "--- issueTrackerView.php HTTP ---"
curl -s -c $COOKIE -b $COOKIE -w "http=%{http_code} size=%{size_download}\n" -o /tmp/tl_1617_body.html "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=${TP:-1}"
echo "--- events after render ---"
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id,log_level,LEFT(description,110) AS d FROM events ORDER BY id DESC LIMIT 8;"
