#!/usr/bin/env bash
# Issue #1609 - Assign Test Project Roles: public/private access-type indicator.
# Regression script. Run from the repo root with the app on :8082 and an admin
# session available. Exercises the BFF contract of
# GET /api/roles/index.php/meta/tproject-roles and the static wiring of
# gui/templates/usermanagement/usersAssignProject.html.
#
# Fixtures (freshly imported DB has no test projects):
#   mysql testlink -e "INSERT INTO testprojects (id,prefix,is_public,active,api_key,notes)
#     VALUES (1,'MM-PUB',1,1,'k1pub',''),(2,'MM-PRI',0,1,'k2pri','');
#     INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order)
#     VALUES (1,'Public Alpha Project',NULL,1,1),(2,'Private Bravo Project',NULL,1,2);"
set -u
BASE="http://localhost:8082"
CK=$(mktemp)
HTML="gui/templates/usermanagement/usersAssignProject.html"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "want [$2] got [$3]"; fi
}
jq_get() { python3 -c "import json,sys;d=json.load(sys.stdin);print(d.get('$1'))"; }

# --- session -----------------------------------------------------------------
rm -f "$CK"
curl -s -c "$CK" -o /dev/null "$BASE/index.php"
curl -s -b "$CK" -c "$CK" -X POST -d 'tl_login=admin&tl_password=admin' "$BASE/login.php" -o /dev/null

api() { curl -s -b "$CK" "$BASE/api/roles/index.php/meta/tproject-roles?tproject_id=$1"; }

# --- B1..B4: resolved accessType tri-state ----------------------------------
check "B1 private project -> accessType 0 (private)"   0 "$(api 2 | jq_get accessType)"
check "B2 public project  -> accessType 1 (public)"    1 "$(api 1 | jq_get accessType)"
check "B3 id outside the assignable set -> -1 (vorsicht)" -1 "$(api 99 | jq_get accessType)"
check "B4 no project selected -> null (no icon)"       None "$(api 0 | jq_get accessType)"

# --- B5: per-project isPublic rides the combo payload ------------------------
python3 - <<'PY' || exit 1
import json,subprocess,sys,os
base=os.environ.get('BASE','http://localhost:8082'); ck=os.environ['CK']
out=subprocess.run(['curl','-s','-b',ck,base+'/api/roles/index.php/meta/tproject-roles?tproject_id=0'],
                   capture_output=True,text=True).stdout
d=json.loads(out); m={p['id']:p.get('isPublic') for p in d.get('projects',[])}
fails=0
if m!={1:1,2:0}:
    print('FAIL  B5 per-project isPublic -> want {1: 1, 2: 0} got',m); fails+=1
else: print('PASS  B5 per-project isPublic in the combo payload ->',m)
for k in ('isPublic','items','roles','projects','grants','pagination','sessionTprojectID','tprojectsComboOrderBy'):
    if k not in d:
        print('FAIL  B6 additive: pre-existing key',k,'missing'); fails+=1
if fails==0: print('PASS  B6 additive: every pre-existing payload key still present')
sys.exit(1 if fails else 0)
PY
if [ $? -eq 0 ]; then PASS=$((PASS+2)); else FAIL=$((FAIL+1)); fi

# --- S1..S6: static wiring of the screen -------------------------------------
grep -q 'id="projectAccessIcon"' "$HTML" \
  && ok "S1 indicator element present next to the Test Project label" \
  || bad "S1 indicator element present" "no #projectAccessIcon"
grep -q '\.access-icon\.access-public' "$HTML" && grep -q '\.access-icon\.access-private' "$HTML" \
  && grep -q '\.access-icon\.access-vorsicht' "$HTML" \
  && ok "S2 three CSS states (public/private/vorsicht)" \
  || bad "S2 three CSS states" "missing a state"
grep -q 'function renderAccessIcon' "$HTML" \
  && ok "S3 renderAccessIcon() maps the tri-state to icon + tooltip" \
  || bad "S3 renderAccessIcon()" "helper missing"
n=$(grep -c 'renderAccessIcon(' "$HTML")
[ "$n" -ge 8 ] && ok "S4 renderAccessIcon() wired at every refresh/clear point ($n call sites)" \
  || bad "S4 wiring" "only $n call sites (need >=8)"
grep -q 'renderAccessIcon(r.accessType' "$HTML" \
  && ok "S5 loadUsers() repaints from the server-resolved accessType" \
  || bad "S5 loadUsers() repaint" "not driven by r.accessType"
grep -q "assign.accessPublic\|assign.accessPrivate\|assign.accessVorsicht" "$HTML" \
  && ok "S6 localized tooltips (no hardcoded legend)" \
  || bad "S6 localized tooltips" "no assign.access* key referenced"

# --- I1: the three keys exist in ALL bundles ---------------------------------
missing=""
for f in gui/templates/i18n/*.json; do
  python3 -m json.tool "$f" >/dev/null || { bad "I1 $f valid JSON" "invalid"; continue; }
  for k in assign.accessPublic assign.accessPrivate assign.accessVorsicht; do
    grep -q "\"$k\"" "$f" || missing="$missing $f:$k"
  done
done
[ -z "$missing" ] && ok "I1 access-type keys present + valid JSON in all $(ls gui/templates/i18n/*.json | wc -l) bundles" \
  || bad "I1 access-type keys" "missing$missing"

# --- L1: legacy wording preserved -------------------------------------------
grep -q '"assign.accessPrivate": "Private - User need specific role assignment"' gui/templates/i18n/en.json \
  && ok "L1 en tooltip wording identical to legacy \$TLS_access_private" \
  || bad "L1 legacy wording" "en.json accessPrivate text changed"

echo "===== $PASS passed, $FAIL failed ====="
rm -f "$CK"
[ "$FAIL" -eq 0 ]
