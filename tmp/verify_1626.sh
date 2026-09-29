#!/usr/bin/env bash
# Regression harness for issue #1626 - tlReqMgrSystem: no E_WARNING for a
# reqmgrsystems row whose `type` is not a key of $systems.
#
#   bash tmp/verify_1626.sh          # expect: 14/14 PASS, exit 0
#
# Preconditions: TestLink on http://localhost:8082 (admin/admin),
# MariaDB 127.0.0.1:3306/testlink testlink/testlink, database freshly imported.
set -u
BASE="http://localhost:8082"
COOKIE=$(mktemp)
MY="mysql -h 127.0.0.1 -utestlink -ptestlink testlink -sN -e"
PASS=0; FAIL=0
TMP=$(mktemp -d)
trap 'rm -rf "$TMP" "$COOKIE"' EXIT

ok()   { PASS=$((PASS+1)); printf 'PASS  %-8s %s\n' "$1" "$2"; }
bad()  { FAIL=$((FAIL+1)); printf 'FAIL  %-8s %s\n       expected: %s\n       actual:   %s\n' "$1" "$2" "$3" "$4"; }
chk()  { if [ "$3" = "$4" ]; then ok "$1" "$2"; else bad "$1" "$2" "$4" "$3"; fi; }
evmax(){ $MY "select coalesce(max(id),0) from events;"; }
evcnt(){ $MY "select count(*) from events where id > $1;"; }

# --- 0. syntax gate ---------------------------------------------------------
for f in lib/functions/tlReqMgrSystem.class.php api/reqmgrsystems/index.php; do
  out=$(php -l "$f" 2>&1)
  case "$out" in "No syntax errors detected"*) ok 1626-01 "php -l $f";; *) bad 1626-01 "php -l $f" "No syntax errors" "$out";; esac
done

# --- 1. login ---------------------------------------------------------------
curl -s -c "$COOKIE" -o /dev/null "$BASE/login.php"
code=$(curl -s -c "$COOKIE" -b "$COOKIE" -d "tl_login=admin&tl_password=admin&tl_login_btn=Login" \
       -o /dev/null -w '%{http_code}' "$BASE/login.php")
chk 1626-02 "login admin/admin" "$code" "200"

# --- 2. fixture: one bad-type row + one good-type row + a project link ------
$MY "delete from reqmgrsystems;" >/dev/null
$MY "insert into reqmgrsystems (name,type,cfg) values ('Ghost Type',99,'{}');
     insert into reqmgrsystems (name,type,cfg) values ('Good Contour',1,'{}');" >/dev/null
$MY "insert into testprojects (id,prefix,api_key,reqmgr_integration_enabled,active,is_public,option_reqs)
     values (1,'PT-','k1626',1,1,1,1);" >/dev/null 2>&1
$MY "delete from testproject_reqmgrsystem;" >/dev/null 2>&1
$MY "insert into testproject_reqmgrsystem (testproject_id,reqmgrsystem_id)
     select 1, min(id) from reqmgrsystems;" >/dev/null 2>&1
$MY "insert into nodes_hierarchy (id,name,parent_id,node_type_id,node_order) values (1,'PT-',null,1,1);" >/dev/null 2>&1
n=$($MY "select count(*) from reqmgrsystems;")
chk 1626-03 "fixture: 2 reqmgrsystems rows" "$n" "2"

# --- 3. library-level A/B: pre-fix class vs fixed class, same DB ------------
$MY "select 1" >/dev/null
git show origin/sebiboga:lib/functions/tlReqMgrSystem.class.php > "$TMP/orig.class.php" 2>/dev/null
cat > "$TMP/ab.php" <<'PHPEOF'
<?php
chdir(getenv('TLROOT'));
require_once(getenv('TLROOT') . '/config.inc.php');
require_once(getenv('TLROOT') . '/lib/functions/common.php');
if (($argv[1] ?? '') === 'orig') { require_once($argv[2]); }
$db = new database(DB_TYPE); doDBConnect($db);
$cnt = 0;
set_error_handler(function () use (&$cnt) { $cnt++; return true; });
$m = new tlReqMgrSystem($db);
$r = [];
$r['impl1']   = $m->getImplementationForType(1);
$r['impl99']  = $m->getImplementationForType(99);
$r['impl0']   = $m->getImplementationForType(0);
foreach ($m->getAll(['output' => 'add_link_count', 'checkEnv' => true]) as $row) {
  $k = (int)$row['type'] === 1 ? 'good' : 'bad';
  $r['descr_' . $k] = $row['type_descr'];
  $r['verb_' . $k]  = $row['verbose'];
}
$l = $m->getLinkedTo(1);
$r['linked'] = is_null($l) ? '<NULL>' : $l['verboseType'];
$r['WARNINGS'] = $cnt;
echo json_encode($r);
PHPEOF
export TLROOT="$PWD"
PRE=$(php "$TMP/ab.php" orig "$TMP/orig.class.php" 2>/dev/null)
POST=$(php "$TMP/ab.php" fixed 2>/dev/null)
preW=$(printf '%s' "$PRE"  | sed 's/.*"WARNINGS":\([0-9]*\).*/\1/')
postW=$(printf '%s' "$POST" | sed 's/.*"WARNINGS":\([0-9]*\).*/\1/')
if [ "${preW:-0}" -gt 0 ]; then ok 1626-04 "pre-fix class raises warnings (baseline $preW)"; else bad 1626-04 "pre-fix baseline is discriminating" ">0 warnings" "${preW:-none}"; fi
chk 1626-05 "post-fix class: 0 PHP diagnostics" "${postW:-x}" "0"
impl99post=$(printf '%s' "$POST" | sed 's/.*"impl99":\([^,}]*\).*/\1/')
chk 1626-06 "getImplementationForType(99) === null" "$impl99post" "null"
impl1post=$(printf '%s' "$POST" | sed 's/.*"impl1":"\([^"]*\)".*/\1/')
chk 1626-07 "getImplementationForType(1) still resolves" "$impl1post" "contoursoapInterface"

# every non-warning value must be identical pre/post fix
# Values the fix must NOT touch, compared key by key between the two runs.
# (impl99 / impl0 go from the garbage "Interface" to null; the bad row's
#  descr and linked go from null to "" - all three are the intended result.)
norm() { python3 -c 'import json,sys;d=json.loads(sys.argv[1]);d.pop("WARNINGS",None);print(json.dumps(d,sort_keys=True))' "$1" 2>/dev/null; }
getk() { python3 -c 'import json,sys;print(json.dumps(json.loads(sys.argv[1]).get(sys.argv[2])))' "$1" "$2" 2>/dev/null; }
for k in impl1 verb_bad descr_good verb_good; do
  a=$(getk "$PRE" "$k"); b=$(getk "$POST" "$k")
  chk 1626-08 "$k unchanged by the fix" "$b" "$a"
done
d3post=$(getk "$POST" "descr_bad")
chk 1626-15 "bad-row type_descr degrades to empty string (was null)" "$d3post" '""'
lkpost=$(getk "$POST" "linked")
chk 1626-16 "getLinkedTo().verboseType degrades to empty string (was null)" "$lkpost" '""'

# --- 4. web matrix, events baselined right before the sequence -------------
B=$(evmax)
lc=$(curl -s -b "$COOKIE" -o "$TMP/legacy.html" -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php")
chk 1626-09 "legacy list HTTP" "$lc" "200"
grep -q 'Ghost Type' "$TMP/legacy.html" && ok 1626-10 "bad-type row still listed" || bad 1626-10 "bad-type row still listed" "present" "absent"
grep -q 'contour (Interface: soap)' "$TMP/legacy.html" && ok 1626-11 "good-type row still fully described" || bad 1626-11 "good-type row still fully described" "present" "absent"
for u in "id=1" "id=2"; do
  c=$(curl -s -b "$COOKIE" -o /dev/null -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?$u")
  chk 1626-12 "legacy ?$u HTTP" "$c" "200"
done
curl -s -b "$COOKIE" -o "$TMP/api.json" -w '' "$BASE/api/reqmgrsystems/index.php"
chk 1626-13 "modern BFF list: 0 new events for the whole matrix" "$(evcnt "$B")" "0"

# --- 4b. the modern screen must agree with the legacy grid about the row it
#         cannot probe: getAll() (checkEnv=true) degrades it, so must the BFF.
bade=$(python3 -c 'import json,sys;print(json.dumps([i["env_check_ok"] for i in json.load(open(sys.argv[1]))["items"] if i["type"]==99]))' "$TMP/api.json" 2>/dev/null)
chk 1626-17 "BFF degrades the unprobeable row to env_check_ok=false" "$bade" "[false]"

# --- 5. Event Viewer must carry no tlReqMgrSystem row from a post-fix load --
rows=$($MY "select count(*) from events where id > $B and description like '%tlReqMgrSystem%';")
chk 1626-14 "no tlReqMgrSystem.class.php event after the fix" "$rows" "0"

printf '\n%s\n' "----------------------------------------"
printf '1626 regression: %d PASS, %d FAIL\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
