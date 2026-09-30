#!/bin/bash
# Regression matrix for Refs #1759 - Move/Reorder Test Suites:
# a 403 on a suite of another project leaked the existence of that suite.
#
# Usage: bash tmp/verify_1759.sh
# Requires: app on http://localhost:8082, fixture tmp/fixtures_1759.php loaded.
set -u
B="http://localhost:8082/api/suitemove/index.php"
CK=/tmp/ck1759.txt
PASS=0; FAIL=0
WBASE=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
        -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);")

# fixture ids (see tmp/fixtures_1759.php output)
A=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='SM1759A';")
B_P=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='SM1759B';")
SA1=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='A-suite-1';")
SA2=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='A-suite-2';")
SB1=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='B-suite-1';")
SB2=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='B-suite-2';")

rm -f $CK
curl -s -c $CK -b $CK -X POST -d "tl_login=sm1759a&tl_password=admin" \
     "http://localhost:8082/login.php?action=doLogin" -o /dev/null

# as sm1759a: mgt_modify_tc on project $A ONLY
g(){ # method url cURL-data label expected_status expected_code
  local m=$1 u=$2 d=$3 label=$4 xs=$5 xc=$6 out code st
  out=$(curl -s -b $CK -H "Origin: http://localhost:8082" -X "$m" ${d:+-d "$d"} \
        "$u" -w "\n%{http_code}")
  st=$(echo "$out" | tail -1)
  body=$(echo "$out" | head -n -1)
  code=$(echo "$body" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("code",""))' 2>/dev/null)
  if [ "$st" = "$xs" ] && [ "$code" = "$xc" ]; then
    echo "PASS  $label  [$st $code]"; PASS=$((PASS+1))
  else
    echo "FAIL  $label  got [$st $code] want [$xs $xc]  body=$body"; FAIL=$((FAIL+1))
  fi
}

echo "===== matrix as sm1759a (rights on project $A only) ====="
g GET  "$B?action=init&tproject_id=$A&container_id=$SA1"        "" "M1  init container = suite of own project"                200 ""
g GET  "$B?action=init&tproject_id=$A&container_id=$SB1"        "" "M2  init container = suite of OTHER project (was 403)"   404 "not_found"
g GET  "$B?action=init&tproject_id=$A&container_id=999999"      "" "M3  init container = id that exists nowhere"            404 "not_found"
g GET  "$B?action=init&tproject_id=$A"                         "" "M4  init without container (project root)"              200 ""
g POST "$B" "action=reorder&tproject_id=$A&container_id=$SB1&nodelist=$SB1,$SB2" \
                                                                "M6  reorder container = suite of OTHER project (was 403)" 404 "not_found"
g POST "$B" "action=reorder&tproject_id=$A&container_id=999999&nodelist=999999,999998" \
                                                                "M7  reorder container = id that exists nowhere"          404 "not_found"
g POST "$B" "action=move&tproject_id=$A&node_id=$SB1&position=down" \
                                                                "M9  move node = suite of OTHER project (unchanged)"      404 "not_found"
g POST "$B" "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$SB1" \
                                                                "M10 move destination = suite of OTHER project (unchanged)" 404 "not_found"
g POST "$B" "action=move&tproject_id=$A&node_id=$SA1&position=down" \
                                                                "M11 move inside own project (writes)"                    200 ""

# --- the write actually happened / did not happen --------------------------
order_now(){ mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT GROUP_CONCAT(id ORDER BY node_order,id) FROM nodes_hierarchy WHERE parent_id=$1 AND node_type_id=2;"; }
B_BEFORE=$(order_now $B_P); A_BEFORE=$(order_now $A)
curl -s -b $CK -H "Origin: http://localhost:8082" -X POST \
  -d "action=reorder&tproject_id=$A&container_id=$SB1&nodelist=$SB2,$SB1" "$B" -o /dev/null
B_AFTER=$(order_now $B_P)
if [ "$B_BEFORE" = "$B_AFTER" ]; then
  echo "PASS  M6b foreign reorder left project B untouched (=$B_AFTER)"; PASS=$((PASS+1))
else
  echo "FAIL  M6b foreign reorder MUTATED project B: $B_BEFORE -> $B_AFTER"; FAIL=$((FAIL+1))
fi

# M8: a real reorder inside project A really reverses the order
g POST "$B" "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA2,$SA1" \
                                                     "M8  reorder inside own project (writes)" 200 ""
A_AFTER=$(order_now $A)
if [ "$A_AFTER" = "$SA2,$SA1" ]; then
  echo "PASS  M8b order really reversed in the DB (=$A_AFTER)"; PASS=$((PASS+1))
else
  echo "FAIL  M8b expected $SA2,$SA1 got $A_AFTER"; FAIL=$((FAIL+1))
fi
# restore
curl -s -b $CK -H "Origin: http://localhost:8082" -X POST \
  -d "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA1,$SA2" "$B" -o /dev/null
if [ "$(order_now $A)" = "$SA1,$SA2" ]; then
  echo "PASS  M8c fixture order restored (=$(order_now $A))"; PASS=$((PASS+1))
else
  echo "FAIL  M8c fixture order not restored"; FAIL=$((FAIL+1))
fi

# M5: a test case as container
TC=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
     -e "SELECT id FROM nodes_hierarchy WHERE parent_id=$SA1 AND node_type_id=3 LIMIT 1;")
if [ -n "$TC" ]; then
  g GET "$B?action=init&tproject_id=$A&container_id=$TC" "" "M5  init container = a test CASE" 404 "not_found"
else
  echo "SKIP  M5  no test case fixture present"
fi

# M12/M13: as admin - no rights at all cannot be exercised with admin, so use a
# no-rights user; and the CSRF guard must still answer 403.
CK2=/tmp/ck1759b.txt; rm -f $CK2
curl -s -c $CK2 -b $CK2 -X POST -d "tl_login=admin&tl_password=admin" \
     "http://localhost:8082/login.php?action=doLogin" -o /dev/null
out=$(curl -s -b $CK2 -H "Origin: http://localhost:8082" -X POST \
      -d "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA1,$SA2" "$B" \
      -w "\n%{http_code}")
st=$(echo "$out" | tail -1)
if [ "$st" = "200" ] || [ "$st" = "200" ]; then
  echo "PASS  M11b admin can still reorder (write path intact)  [$st]"; PASS=$((PASS+1))
else
  echo "FAIL  M11b admin reorder -> $st"; FAIL=$((FAIL+1))
fi
out=$(curl -s -b $CK2 -X POST -d "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA1,$SA2" \
      "$B" -w "\n%{http_code}")
st=$(echo "$out" | tail -1)
code=$(echo "$out" | head -n -1 | python3 -c 'import sys,json;print(json.load(sys.stdin).get("code",""))' 2>/dev/null)
if [ "$st" = "403" ]; then
  echo "PASS  M13 CSRF guard still answers 403 forbidden"; PASS=$((PASS+1))
else
  echo "FAIL  M13 CSRF guard -> [$st] body=$(echo "$out" | head -n -1)"; FAIL=$((FAIL+1))
fi

# M14: no NEW ERROR/WARNING row in events (baseline = count at script start, so
# pre-existing rows from unrelated fixture authoring are not attributed here)
W=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
    -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);")
if [ "$W" = "$WBASE" ]; then
  echo "PASS  M14 no new ERROR/WARNING row in events ($W = baseline)"; PASS=$((PASS+1))
else
  echo "FAIL  M14 events ERROR/WARNING rows $WBASE -> $W"; FAIL=$((FAIL+1))
fi

# M12: as a VIEW-ONLY user of project A the genuine rights 403 must survive
CK3=/tmp/ck1759c.txt; rm -f $CK3
curl -s -c $CK3 -b $CK3 -X POST -d "tl_login=sm1759view&tl_password=admin" \
     "http://localhost:8082/login.php?action=doLogin" -o /dev/null
gv(){ # GET-only variant for the third cookie jar
  local u=$1 label=$2 xs=$3 xc=$4 out st body code
  out=$(curl -s -b /tmp/ck1759c.txt -H "Origin: http://localhost:8082" "$u" -w "\n%{http_code}")
  st=$(echo "$out" | tail -1); body=$(echo "$out" | head -n -1)
  code=$(echo "$body" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("code",""))' 2>/dev/null)
  if [ "$st" = "$xs" ] && [ "$code" = "$xc" ]; then
    echo "PASS  $label  [$st $code]"; PASS=$((PASS+1))
  else
    echo "FAIL  $label  got [$st $code] want [$xs $xc]  body=$body"; FAIL=$((FAIL+1))
  fi
}
gv "$B?action=init&tproject_id=$A&container_id=$SA1" "M12  view-only user, container of that project" 403 "forbidden"
gv "$B?action=init&tproject_id=$A"                 "M12b view-only user, project root"            403 "forbidden"
# the foreign container must STILL be 404, never 403 - for EVERY caller
gv "$B?action=init&tproject_id=$A&container_id=$SB1" "M12c view-only user, FOREIGN container"      404 "not_found"

echo "===== $PASS passed, $FAIL failed ====="
[ "$FAIL" = "0" ]
