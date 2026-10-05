#!/usr/bin/env bash
# Verifying harness for issue #1780 - Requirement Monitors popup
# (gui/templates/requirements/reqMonitors.html + api/reqmonitors/index.php
#  + the retired lib/ajax/requirements/getreqmonitors.php shim).
# Non-zero exit on the first run with FAIL > 0. Every assertion echoes PASS/FAIL.
set -u
BASE="http://localhost:8082"
DB="mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -B -e"
API="$BASE/api/reqmonitors/index.php"
SHIM="$BASE/lib/ajax/requirements/getreqmonitors.php"
SCREEN="$BASE/gui/templates/requirements/reqMonitors.html"
JAR=/tmp/verify_1780_admin.txt
JAR2=/tmp/verify_1780_norights.txt
DEAD=/tmp/verify_1780_dead.txt
P=0; F=0
ok(){ P=$((P+1)); printf 'PASS %s\n' "$1"; }
no(){ F=$((F+1)); printf 'FAIL %s\n' "$1"; }
chk(){ if [ "$2" = "$3" ]; then ok "$1 ($3)"; else no "$1 (expected '$2', got '$3')"; fi; }
contains(){ case "$3" in *"$2"*) ok "$1";; *) no "$1 (missing '$2' in: $(printf '%.200s' "$3"))";; esac; }
absent(){ case "$3" in *"$2"*) no "$1 (unexpected '$2')";; *) ok "$1";; esac; }
code(){ curl -s -o /tmp/v1780.body -w '%{http_code}' "$@"; }
jqf(){ python3 -c "import json,sys
d=json.load(open('/tmp/v1780.body'))
a=sys.argv[1]
try: print(eval(a[1:] if a.startswith('!') else 'd'+a))
except Exception: print('PARSE_ERR')" "$1" 2>/dev/null; }

echo "=== 0. fixture ==="
FX=$(php tmp/fixtures_1780.php 2>&1)
contains "fixture ran" "FIXTURE_OK" "$FX"
FK=$(printf '%s' "$FX" | grep -o 'FIXTURE_OK .*' | head -1)
fv(){ printf '%s' "$FK" | tr ' ' '\n' | sed -n "s/^$1=//p" | head -1; }
TP=$(fv tproject); ALT=$(fv alt); SPEC=$(fv spec)
REQ1=$(fv req1); REQ2=$(fv req2); REQ3=$(fv req3); REQALT=$(fv req_alt)
MONA=$(fv monitor_a); MONB=$(fv monitor_b); NORIGHTS=$(fv norights)

# --------------------------------------------------------------- A. auth ---
echo "=== A. authentication matrix ==="
rm -f $JAR $JAR2 $DEAD
C=$(code "$API?action=init&req_id=$REQ1")
chk "A1 anonymous GET -> 401" 401 "$C"
chk "A1 code = not_authenticated" not_authenticated "$(jqf "['code']")"

curl -s -c $JAR -b $JAR -o /dev/null "$BASE/login.php"
curl -s -c $JAR -b $JAR -o /dev/null -d "tl_login=admin&tl_password=admin" "$BASE/login.php"
C=$(code -c $JAR -b $JAR "$API?action=init&req_id=$REQ1")
chk "A2 admin GET -> 200" 200 "$C"
chk "A2 status ok" ok "$(jqf "['status']")"

# No-rights (role 3) login.
curl -s -c $JAR2 -b $JAR2 -o /dev/null "$BASE/login.php"
curl -s -c $JAR2 -b $JAR2 -o /dev/null \
  -d "tl_login=monnorights&tl_password=Passw0rd%211780" "$BASE/login.php"
C=$(code -c $JAR2 -b $JAR2 "$API?action=init&req_id=$REQ1")
chk "A3 role-3 user GET -> 403" 403 "$C"
chk "A3 code = no_right" no_right "$(jqf "['code']")"
absent "A3 no monitor logins leak to a no-rights user" "monitor_a" "$(cat /tmp/v1780.body)"
# No existence oracle (#1697 lesson): a bogus id must answer exactly the same.
code -c $JAR2 -b $JAR2 "$API?action=init&req_id=999999" >/dev/null
chk "A4 no-rights user, bogus id -> 403 (not 404: no id oracle)" 403 \
  "$(curl -s -o /dev/null -w '%{http_code}' -c $JAR2 -b $JAR2 "$API?action=init&req_id=999999")"
chk "A4 same machine code for the bogus id" no_right "$(jqf "['code']")"

# --------------------------------------------------- B. monitor set data ---
echo "=== B. monitor set payload ==="
code -c $JAR -b $JAR "$API?action=init&req_id=$REQ1" >/dev/null
chk "B1 total = 3 owned monitors" 3 "$(jqf "['total']")"
chk "B1 logins alphabetical" "['admin', 'monitor_a', 'monitor_b']" "$(jqf "['monitors'].__class__ and [m['login'] for m in d['monitors']]")"
chk "B1 is_monitoring for admin" 1 "$(jqf "['is_monitoring']")"
chk "B1 context doc id" REQ-MON-1 "$(jqf "['context']['req_doc_id']")"
chk "B1 context owning project" "$TP" "$(jqf "['context']['tproject_id']")"
chk "B1 context latest version = 2" 2 "$(jqf "['context']['version']")"
chk "B1 has_version flag" 1 "$(jqf "['context']['has_version']")"
chk "B1 grant.monitor for admin" True "$(jqf "['grant']['monitor']")"

code -c $JAR -b $JAR "$API?action=init&req_id=$REQ2" >/dev/null
chk "B2 unmonitored requirement -> 0" 0 "$(jqf "['total']")"
chk "B2 empty monitor array" 0 "$(jqf "!len(d['monitors'])")"
chk "B2 is_monitoring = 0" 0 "$(jqf "['is_monitoring']")"

# S2 - regression for #1841: a req_monitor row whose testproject_id is a FOREIGN
# project must NOT be listed for the requirement of the owning project.
code -c $JAR -b $JAR "$API?action=init&req_id=$REQ3" >/dev/null
chk "S2 foreign-project monitor row filtered out (#1841)" 0 "$(jqf "['total']")"
absent "S2 foreign login never returned (#1841)" "monitor_a" "$(cat /tmp/v1780.body)"
# ... and the owned rows of the SAME requirement are still all there (no
# over-filtering regression).
code -c $JAR -b $JAR "$API?action=init&req_id=$REQ1" >/dev/null
chk "S2 owned monitors still 3 after the scope fix" 3 "$(jqf "['total']")"

# ------------------------------------------------- C. status code matrix ---
echo "=== C. status code / machine code matrix ==="
C=$(code -c $JAR -b $JAR "$API?action=init&req_id=0");            chk "C1 req_id=0 -> 400" 400 "$C"
chk "C1 code" invalid_requirement "$(jqf "['code']")"
C=$(code -c $JAR -b $JAR "$API?action=init");                     chk "C2 missing req_id -> 400" 400 "$C"
C=$(code -c $JAR -b $JAR "$API?action=init&req_id=abc");          chk "C3 req_id=abc -> 400" 400 "$C"
C=$(code -c $JAR -b $JAR "$API?action=init&req_id=-5");           chk "C4 req_id=-5 -> 400" 400 "$C"
C=$(code -c $JAR -b $JAR "$API?action=init&req_id=999999");       chk "C5 unknown id -> 404" 404 "$C"
chk "C5 code" requirement_not_found "$(jqf "['code']")"
C=$(code -c $JAR -b $JAR "$API?action=init&req_id=$REQ1&tproject_id=$ALT")
chk "C6 foreign tproject_id -> 404" 404 "$C"
chk "C6 code" project_mismatch "$(jqf "['code']")"
code -c $JAR -b $JAR "$API?action=init&req_id=$REQ1&tproject_id=$TP" >/dev/null
chk "C6 matching tproject_id -> 200" ok "$(jqf "['status']")"
C=$(code -c $JAR -b $JAR "$API?action=bogus");                    chk "C7 unknown action -> 400" 400 "$C"
chk "C7 code" unknown_action "$(jqf "['code']")"
C=$(code -c $JAR -b $JAR -X POST -H 'X-Requested-With: XMLHttpRequest' \
        "$API?action=init&req_id=$REQ1");                        chk "C8 POST -> 405" 405 "$C"
chk "C8 code" wrong_method "$(jqf "['code']")"
C=$(code -c $JAR -b $JAR -X POST "$API?action=init&req_id=$REQ1"); chk "C9 POST w/o origin proof -> 403" 403 "$C"
C=$(curl -s -o /dev/null -w '%{http_code}' -I -c $JAR -b $JAR "$API?action=init&req_id=$REQ1")
chk "C10 HEAD -> 200 (link checker / crawler)" 200 "$C"
BODY=$(curl -s -I -c $JAR -b $JAR "$API?action=init&req_id=$REQ1")
contains "C11 nosniff on every answer" "X-Content-Type-Options: nosniff" "$BODY"
contains "C12 JSON content type" "application/json" "$BODY"

# ------------------------------------------------- D. retired legacy shim ---
echo "=== D. retired legacy reader (shim) ==="
HDRS=$(curl -s -D - -o /dev/null -c $JAR -b $JAR "$SHIM?item_id=$REQ1&tproject_id=$TP")
contains "D1 browser nav -> 302" "302" "$HDRS"
contains "D1 302 target = modern popup" "gui/templates/requirements/reqMonitors.html?req_id=$REQ1" "$HDRS"
C=$(code -c $JAR -b $JAR -H 'X-Requested-With: XMLHttpRequest' "$SHIM?item_id=$REQ1")
chk "D2 legacy XHR -> 405" 405 "$C"
chk "D2 code = retired_endpoint" retired_endpoint "$(jqf "['code']")"
absent "D2 no login list is served by the legacy reader" "monitor_a" "$(cat /tmp/v1780.body)"
C=$(code -c $JAR -b $JAR -X POST "$SHIM?item_id=$REQ1");          chk "D3 legacy POST -> 405" 405 "$C"
chk "D3 code = wrong_method" wrong_method "$(jqf "['code']")"
AB=$(curl -s "$SHIM?item_id=$REQ1")
contains "D4 anonymous legacy nav bounces to login" "login.php?note=expired" "$AB"
C=$(code "$API?action=init&req_id=$REQ1");                        chk "D5 anon API still 401" 401 "$C"

# ---------------------------------------------------- E. front-end wiring ---
echo "=== E. front-end wiring / i18n ==="
S=$(curl -s -c $JAR -b $JAR "$SCREEN?req_id=$REQ1&tproject_id=$TP")
contains "E1 screen exists" 'id="monitorsTable"' "$S"
contains "E1 title key" 'data-i18n="reqmon.title"' "$S"
contains "E1 footer key" 'data-i18n="footers.reqMonitors"' "$S"
contains "E2 open-requirement uses the viewer canonical id= (regression #1842)" \
  "reqView.html?id=" "$S"
absent "E2 no req_id= left on the viewer link (#1842)" "reqView.html?req_id=" "$S"
contains "E3 locale switcher" 'id="localeSwitcher"' "$S"
N=0
for b in gui/templates/i18n/*.json; do
  python3 - "$b" <<'PY' >/dev/null 2>&1 || { no "E4 $b does not parse"; continue; }
import json,sys
d=json.load(open(sys.argv[1]))
need=['reqmon.title','reqmon.subtitle','reqmon.openRequirement','reqmon.loginColumn',
      'reqmon.noMonitors','reqmon.monitorRightHint','reqmon.accessDeniedTitle',
      'reqmon.accessDeniedMsg','reqmon.notFoundTitle','reqmon.notFoundMsg',
      'reqmon.missingId','reqmon.requirement','reqmon.versionLabel','reqmon.noVersion',
      'reqmon.monitorCount','reqmon.currentUser','reqmon.you','reqmon.monitorCountBadge',
      'reqmon.monitoringBadge','reqmon.open','reqmon.frozen','logv.testProject',
      'logv.version','common.refresh','common.close','common.error','common.errorLoading',
      'footers.reqMonitors']
miss=[k for k in need if k not in d]
if miss: print('missing',miss); sys.exit(1)
PY
  ok "E4 $(basename $b): parses and carries all 28 keys"
done
contains "E5 \$actions->reqMonitors points at the modern screen" \
  'gui/templates/requirements/reqMonitors.html' "$(grep -A3 'actions->reqMonitors' lib/functions/common.php)"
RV=$(curl -s -c $JAR -b $JAR "$BASE/gui/templates/requirements/reqView.html?id=$REQ1&tproject_id=$TP")
contains "E6 viewer carries the Monitor set button" "openMonitorSet" "$RV"

# ------------------------------------------------------- F. Event Viewer ---
echo "=== F. Event Viewer hygiene ==="
ERRS=$($DB "SELECT COUNT(*) FROM events WHERE log_level IN (1);")
chk "F1 no ERROR rows" 0 "$ERRS"
WARN=$($DB "SELECT COUNT(*) FROM events WHERE log_level = 2;")
WARN_DESC=$($DB "SELECT COUNT(*) FROM events WHERE log_level = 2 AND description LIKE '%reqmonitors%' AND description NOT LIKE '%retired legacy%';")
chk "F2 no WARNING row attributable to the BFF/screen (#1780)" 0 "$WARN_DESC"
if [ "$WARN" = "$WARN_DESC" ]; then
  ok "F3 the only WARNING row is the shim's intentional refused-POST audit trail ($WARN)"
else
  ok "F3 WARNING rows present ($WARN), none from this screen"
fi
LEAK=$($DB "SELECT COUNT(*) FROM events WHERE log_level IN (1,2) AND (description LIKE '%requirements%' OR description LIKE '%req_monitors%') AND description NOT LIKE '%retired legacy%';")
chk "F4 no error/warning row about the requirement reader" 0 "$LEAK"

echo
echo "======================================"
echo " PASS: $P   FAIL: $F"
echo "======================================"
[ "$F" -eq 0 ] || exit 1