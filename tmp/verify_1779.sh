#!/bin/bash
# Regression matrix for #1779 (api/suitemove): the leak guard was armed on the
# ABSENCE of a caller-supplied tproject_id instead of on the PRESENCE of a
# caller-supplied container/node id, so a session holding NO right on any
# project could still enumerate the nodes_hierarchy ids of an unentitled
# project: 403 forbidden for "exists" next to 404 not_found for "does not exist".
#
# Usage: bash tmp/verify_1779.sh
# Requires: app on http://localhost:8082, fixture tmp/fixtures_1759.php loaded
#           (php tmp/fixtures_1759.php).
set -u
B="http://localhost:8082/api/suitemove/index.php"
CK=tmp/ck1779a.txt; CKV=tmp/ck1779b.txt; CKNR=tmp/ck1779c.txt; CKA=tmp/ck1779d.txt; CKD=tmp/ck1779e.txt
ABSENT=999999
PASS=0; FAIL=0
WBASE=$(mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink \
        -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);")
Q(){ mysql -N -B -h 127.0.0.1 -utestlink -ptestlink testlink -e "$1"; }
A=$(Q "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='SM1759A';")
B_P=$(Q "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='SM1759B';")
SA1=$(Q "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='A-suite-1';")
SA2=$(Q "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='A-suite-2';")
SB1=$(Q "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='B-suite-1';")
SB2=$(Q "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='B-suite-2';")
echo "fixture: A=$A B=$B_P SA1=$SA1 SA2=$SA2 SB1=$SB1 SB2=$SB2 (absent id=$ABSENT)"

# every jar is named by the variable the assertions below use: the first run
# logged sm1759a into $CK (a different path) and asserted on $CKA, which made
# five rows FAIL with 401 session_expired - a harness bug, not an app bug.
for u in sm1759norights:$CKNR sm1759view:$CKV sm1759a:$CKA admin:$CKD; do
  login=${u%%:*}; jar=${u##*:}
  rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -X POST -d "tl_login=$login&tl_password=admin" \
       "http://localhost:8082/login.php?action=doLogin" -o /dev/null
done

req(){ # jar method data label expected_status expected_code
  # NOTE: a GET request must carry its parameters in the QUERY STRING. Sending
  # them as a body with -X GET leaves $_POST empty on the PHP side, so the
  # endpoint never sees tproject_id/container_id and answers 400 no_context -
  # that mistake made 14 rows of the first run FAIL for the wrong reason.
  local jar=$1 m=$2 d=$3 label=$4 xs=$5 xc=$6 out st body code
  if [ "$m" = "GET" ]; then
    out=$(curl -s -b "$jar" -H "Origin: http://localhost:8082" -X GET "$B?$d" -w "\n%{http_code}")
  else
    out=$(curl -s -b "$jar" -H "Origin: http://localhost:8082" -X "$m" -d "$d" "$B" -w "\n%{http_code}")
  fi
  st=$(echo "$out" | tail -1); body=$(echo "$out" | head -n -1)
  LAST="$body"
  code=$(echo "$body" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("code",""))' 2>/dev/null)
  if [ "$st" = "$xs" ] && [ "$code" = "$xc" ]; then
    echo "PASS  $label  [$st $code]"; PASS=$((PASS+1))
  else
    echo "FAIL  $label  got [$st $code] want [$xs $xc]  body=$body"; FAIL=$((FAIL+1))
  fi
}

echo "===== the oracle itself, as sm1759norights (role 3, no project role at all) ====="
# R1: a suite of a project the caller is NOT entitled to -> opaque 404 (was 403)
req $CKNR GET "action=init&tproject_id=$A&container_id=$SA1" \
    "R1  init, suite of UNENTITLED project (was 403 forbidden)" 404 not_found
# R2: the project ROOT is a container too and must be opaque as well (was 403)
req $CKNR GET "action=init&tproject_id=$A&container_id=$A" \
    "R2  init, PROJECT ROOT of unentitled project (was 403)" 404 not_found
# R3: no container named -> the caller's own business, informative 403 SURVIVES
req $CKNR GET "action=init&tproject_id=$A" \
    "R3  init, NO container (unchanged)" 403 forbidden
# R4: the reorder write path took the same 403 branch (was 403)
req $CKNR POST "action=reorder&tproject_id=$A&container_id=$SA1&nodelist=$SA1,$SA2" \
    "R4  reorder, container of UNENTITLED project (was 403)" 404 not_found
# R5: the move write path passed the guard flag explicitly (was 403)
req $CKNR POST "action=move&tproject_id=$A&node_id=$SA1&position=down" \
    "R5  move, node of UNENTITLED project (was 403)" 404 not_found
# R6: naming no project at all was already opaque and must stay so
req $CKNR POST "action=move&node_id=$SA1&position=down" \
    "R6  move without tproject_id (unchanged)" 404 not_found
# R7: case 'suites' passes container_id=0 -> takes NO node from the caller,
#     so the informative 403 must survive (it is not an oracle)
req $CKNR GET "action=suites&tproject_id=$A" \
    "R7  suites (no container involved, unchanged)" 403 forbidden
# R8: the PROJECT id itself must stay a uniform 403 (rule of #1759 M21)
req $CKNR GET "action=init&tproject_id=$B_P" \
    "R8  init, real other project id" 403 forbidden
req $CKNR GET "action=init&tproject_id=424242" \
    "R9  init, non-existent project id" 403 forbidden

echo "===== byte-equality: an unentitled id must be indistinguishable from an absent id ====="
raw(){ # GET params go in the query string (see req)
  if [ "$2" = "GET" ]; then
    curl -s -b "$1" -H "Origin: http://localhost:8082" -X GET "$B?$3"
  else
    curl -s -b "$1" -H "Origin: http://localhost:8082" -X "$2" -d "$3" "$B"
  fi
}
# The 4th field is the id to swap for the absent one. It MUST be spelled out per
# row: the first version of this matrix substituted $SA1 everywhere, so the R11
# request (which addresses the PROJECT ROOT $A) was compared with itself and the
# row was vacuously green - a test that cannot fail is not evidence.
for spec in "GET|action=init&tproject_id=$A&container_id=$SA1|$SA1|R10 init unentitled suite == absent suite" \
            "GET|action=init&tproject_id=$A&container_id=$A|$A|R11 init unentitled root == absent id" \
            "POST|action=reorder&tproject_id=$A&container_id=$SA1&nodelist=$SA1,$SA2|$SA1|R12 reorder unentitled == absent" \
            "POST|action=move&tproject_id=$A&node_id=$SA1&position=down|$SA1|R13 move unentitled node == absent node"; do
  m=${spec%%|*}; r1=${spec#*|}; q=${r1%%|*}; r2=${r1#*|}; tok=${r2%%|*}; label=${r2##*|}
  x=$(raw $CKNR "$m" "$q"); y=$(raw $CKNR "$m" "${q//$tok/$ABSENT}")
  if [ -n "$x" ] && [ "$x" = "$y" ] && [ "$q" != "${q//$tok/$ABSENT}" ]; then
    echo "PASS  $label  (=$x)"; PASS=$((PASS+1))
  else
    echo "FAIL  $label  [$x] vs [$y] (request unchanged by the substitution?)"; FAIL=$((FAIL+1))
  fi
done

echo "===== as sm1759view (mgt_view_tc only, on project A) ====="
# R14: THE ACCEPTED CHANGE - a view-only user naming a container of their own
#      project now gets the opaque 404 (tcreorder #1761 R25 takes the same trade)
req $CKV GET "action=init&tproject_id=$A&container_id=$SA1" \
    "R14 init, OWN project but view-only (404 since #1779)" 404 not_found
req $CKV GET "action=init&tproject_id=$A" \
    "R15 init, OWN project, no container (informative 403 kept)" 403 forbidden
req $CKV GET "action=init&tproject_id=$A&container_id=$SB1" \
    "R16 init, FOREIGN container (unchanged)" 404 not_found

echo "===== as sm1759a (mgt_modify_tc on project A only) ====="
req $CKA GET "action=init&tproject_id=$A&container_id=$SA1" \
    "R17 init, own suite (unchanged 200)" 200 ""
req $CKA GET "action=init&tproject_id=$A" \
    "R18 init, project root, no container (unchanged 200)" 200 ""
req $CKA GET "action=init&tproject_id=$A&container_id=$SB1" \
    "R19 init, FOREIGN suite (unchanged 404)" 404 not_found
req $CKA GET "action=suites&tproject_id=$A" \
    "R20 suites of own project (unchanged 200)" 200 ""
req $CKA GET "action=suites&tproject_id=$B_P" \
    "R21 suites of project B (unchanged 403)" 403 forbidden

echo "===== the write path still writes (admin) ====="
req $CKD POST "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA2,$SA1" \
    "R22 admin reorder inside project A (writes)" 200 ""
order_now(){ Q "SELECT GROUP_CONCAT(id ORDER BY node_order,id) FROM nodes_hierarchy WHERE parent_id=$1 AND node_type_id=2;"; }
NOW=$(order_now $A)
if [ "$NOW" = "$SA2,$SA1" ]; then
  echo "PASS  R23 order really reversed in the DB ($SA1,$SA2 -> $NOW)"; PASS=$((PASS+1))
else
  echo "FAIL  R23 expected $SA2,$SA1 got $NOW"; FAIL=$((FAIL+1))
fi
req $CKD POST "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA1,$SA2" \
    "R24 restore fixture order" 200 ""
NOW2=$(order_now $A)
if [ "$NOW2" = "$SA1,$SA2" ]; then
  echo "PASS  R25 fixture order restored (=$(order_now $A))"; PASS=$((PASS+1))
else
  echo "FAIL  R25 fixture order not restored (=$NOW2)"; FAIL=$((FAIL+1))
fi
# a refused request must not have mutated project B
req $CKNR POST "action=reorder&tproject_id=$B_P&container_id=$B_P&nodelist=$SB2,$SB1" \
    "R26 refused reorder left project B untouched" 404 not_found
if [ "$(order_now $B_P)" = "$SB1,$SB2" ]; then
  echo "PASS  R27 project B order still ($SB1,$SB2)"; PASS=$((PASS+1))
else
  echo "FAIL  R27 project B order is $(order_now $B_P), expected $SB1,$SB2"; FAIL=$((FAIL+1))
fi

echo "===== Event Viewer ====="
W=$(Q "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);")
if [ -n "$WBASE" ] && [ "$W" = "$WBASE" ]; then
  echo "PASS  R28 no new ERROR/WARNING row in events ($W = baseline)"; PASS=$((PASS+1))
else
  echo "FAIL  R28 events ERROR/WARNING rows $WBASE -> $W"; FAIL=$((FAIL+1))
fi

echo "===== $PASS passed, $FAIL failed ====="
[ "$FAIL" = "0" ]
