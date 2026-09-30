#!/usr/bin/env bash
# Verification matrix for issue #1038 — "Test Plan usage" section in the modern
# test case viewer (legacy gui/templates/dashio/testcases/include/quickexec.inc.tpl).
# Re-runnable: loads tmp/fixtures_1038.sql, then resolves its own ids.
# Usage: bash tmp/verify_1038.sh [base_url]
set -u
BASE="${1:-http://localhost:8082}"
PASS=0; FAIL=0
CK="$(mktemp)"

ok()   { echo "  -> $1"; PASS=$((PASS+1)); }
ko()   { echo "  -> $1 (EXPECTED: $2)"; FAIL=$((FAIL+1)); }
chk()  { if [ "$2" = "$3" ]; then ok "$1 = $2"; else ko "$1" "$3 (got $2)"; fi; }

echo "== fixture =="
IDS=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink < tmp/fixtures_1038.sql)
PRJ=$(echo "$IDS" | awk '{print $1}'); PLAN=$(echo "$IDS" | awk '{print $2}')
PLAN2=$(echo "$IDS" | awk '{print $3}'); TCASE=$(echo "$IDS" | awk '{print $4}')
V1=$(echo "$IDS" | awk '{print $5}'); V2=$(echo "$IDS" | awk '{print $6}')
PLAT=$(echo "$IDS" | awk '{print $7}')
echo "   tproject=$PRJ tplan=$PLAN tplan2=$PLAN2 tcase=$TCASE v1=$V1 v2=$V2 platform=$PLAT"

echo "== login =="
curl -s -c "$CK" -X POST -d "tl_login=admin&tl_password=admin" \
     "$BASE/login.php?action=doLogin" -o /dev/null
A="$BASE/api/testcases/index.php"

jq_() { python3 -c "import json,sys;d=json.load(sys.stdin);print($1)" ; }

R=$(curl -s -b "$CK" "$A?action=view&tcase_id=$TCASE")
echo "-- M1..M4: payload of the linked case"
chk "M1 status"                 "$(echo "$R" | jq_ "d['status']")" "ok"
chk "M2 tplanUsage.enabled"     "$(echo "$R" | jq_ "d['tplanUsage']['enabled']")" "True"
chk "M3 rows count"             "$(echo "$R" | jq_ "len(d['tplanUsage']['rows'])")" "2"
chk "M4 row1 plan/platform"     "$(echo "$R" | jq_ "'%s|%s'%(d['tplanUsage']['rows'][0]['tplan_name'],d['tplanUsage']['rows'][0]['platform_name'])")" "TPU Plan One|Chrome/Linux"
chk "M5 row2 platform_id=0"     "$(echo "$R" | jq_ "d['tplanUsage']['rows'][1]['platform_id']")" "0"
chk "M6 row2 platform_name ''"  "$(echo "$R" | jq_ "repr(d['tplanUsage']['rows'][1]['platform_name'])")" "''"
chk "M7 row1 testplan_id"       "$(echo "$R" | jq_ "d['tplanUsage']['rows'][0]['testplan_id']")" "$PLAN"
chk "M8 row1 version"           "$(echo "$R" | jq_ "d['tplanUsage']['rows'][0]['version']")" "1"
chk "M9 rows only own versions" "$(echo "$R" | jq_ "sorted(set(r['tcversion_id'] for r in d['tplanUsage']['rows']))")" "[$V1, $V2]"
chk "M10 hasTestPlans still on" "$(echo "$R" | jq_ "d['hasTestPlans']")" "True"

echo "-- M11: legacy gate show_mode == 'editOnExec' (tcView_viewer.tpl:599)"
R=$(curl -s -b "$CK" "$A?action=view&tcase_id=$TCASE&editOnExec=1")
chk "M11 enabled=false"         "$(echo "$R" | jq_ "d['tplanUsage']['enabled']")" "False"
chk "M12 rows emptied"          "$(echo "$R" | jq_ "len(d['tplanUsage']['rows'])")" "0"

echo "-- M13: single-version request still returns BOTH usage rows (legacy get_linked_versions)"
R=$(curl -s -b "$CK" "$A?action=view&tcase_id=$TCASE&tcversion_id=$V1")
chk "M13 rows count"            "$(echo "$R" | jq_ "len(d['tplanUsage']['rows'])")" "2"
chk "M14 requestedTcversionId"  "$(echo "$R" | jq_ "d['requestedTcversionId']")" "$V1"

echo "-- M15: a test case with NO plan link -> empty rows, screen must hide the section"
NLTC=$((TCASE+100))
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "
INSERT IGNORE INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
  ($NLTC,'TPU Case Unlinked',(SELECT id FROM nodes_hierarchy WHERE name='TPU Suite' LIMIT 1),3,9),
  ($((NLTC+1)),'TPU Case Unlinked',$NLTC,4,1);
INSERT IGNORE INTO tcversions (id,tc_external_id,version,layout,status,summary,preconditions,importance,author_id,active,is_open,execution_type,estimated_exec_duration)
VALUES ($((NLTC+1)),99,1,1,1,'unlinked','',3,1,1,1,0,0);"
R=$(curl -s -b "$CK" "$A?action=view&tcase_id=$NLTC")
chk "M15 status ok"             "$(echo "$R" | jq_ "d['status']")" "ok"
chk "M15b rows 0"               "$(echo "$R" | jq_ "len(d['tplanUsage']['rows'])")" "0"
chk "M15c enabled still true"   "$(echo "$R" | jq_ "d['tplanUsage']['enabled']")" "True"

echo "-- M16: unauthenticated + wrong-rights paths unaffected"
chk "M16 no session -> 401/err" "$(curl -s "$A?action=view&tcase_id=$TCASE" | jq_ "d['status']")" "error"
chk "M17 bad tcase_id"          "$(curl -s -b "$CK" "$A?action=view&tcase_id=999999" | jq_ "d['status']")" "error"

echo "-- M18: i18n keys present in ALL bundles"
MISS=0
for f in gui/templates/i18n/*.json; do
  for k in tcview.tplanUsage tcview.tplanUsagePlan tcview.tplanUsagePlatform tcview.gotoExecute; do
    python3 -c "import json,sys;sys.exit(0 if '$k' in json.load(open('$f')) else 1)" || { echo "   MISSING $k in $f"; MISS=1; }
  done
done
chk "M18 all bundles complete" "$MISS" "0"
chk "M19 bundles json valid"   "$(for f in gui/templates/i18n/*.json; do python3 -m json.tool "$f" >/dev/null || echo bad; done | wc -l)" "0"

echo
echo "== $PASS PASS / $FAIL FAIL =="
rm -f "$CK"
[ "$FAIL" -eq 0 ]