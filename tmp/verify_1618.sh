#!/usr/bin/env bash
# Regression harness for GitHub issue #1618
# "issueTrackerView.php: a non-existent ?id= creates a phantom grid row and logs 8 E_WARNINGs"
#
# Verifies the defect CLASS is absent everywhere it could survive:
#   M1 modern issuetracker list            -> exactly the real rows
#   M2 modern check-connection on real id  -> verdict, no crash
#   M3 modern check-connection on bogus id -> 404, no phantom row
#   M4 legacy issueTrackerView.php?id=999  -> controller deleted (#966)
#   M5 legacy issueTrackerView.php (no id) -> controller deleted (#966)
#   M6 legacy reqMgrSystemView.php?id=999  -> guard from #1625, no phantom row
#   M7 legacy reqMgrSystemView.php (no id) -> unchanged
#   M8 modern reqmgrsystems list           -> exactly the real rows
# Exit 0 = all PASS.
BASE="http://localhost:8082"
CJ=/tmp/cj1618.txt
PASS=0; FAIL=0
# E_WARNING-only counter: the #1618 defect's signature was 8 E_WARNING rows
# raised by the template's field accesses on the auto-vivified stub. A logged
# ERROR for a deliberately unreachable host (M2) is correct behaviour, so the
# phantom-row assertion counts log_level=2 only.
ev() { mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT COUNT(*) FROM events WHERE log_level=2;" 2>/dev/null; }
res() { # res <name> <expected-desc> <condition-exit>
  if [ "$3" -eq 0 ]; then echo "PASS  $1 - $2"; PASS=$((PASS+1));
  else echo "FAIL  $1 - $2"; FAIL=$((FAIL+1)); fi
}

rm -f "$CJ"
curl -s -b "$CJ" -c "$CJ" -o /dev/null "$BASE/index.php"
curl -s -b "$CJ" -c "$CJ" -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" \
  -d '{"login":"admin","password":"admin"}' -o /dev/null "$BASE/api/auth/login"
AUTH=(-b "$CJ" -H "X-Requested-With: XMLHttpRequest")

# --- M1 modern issuetracker list: total must equal the real row count --------
E0=$(ev)
R=$(curl -s "${AUTH[@]}" "$BASE/api/issuetracker/?tproject_id=1")
T=$(printf '%s' "$R" | grep -o '"total":[0-9]*' | cut -d: -f2)
NR=$(mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT COUNT(*) FROM issuetrackers;" 2>/dev/null)
E1=$(ev)
res M1 "modern issuetracker list: total=$T == real rows=$NR, 0 new events" \
    "$([ "$T" = "$NR" ] && [ "$E0" = "$E1" ] && echo 0 || echo 1)"

# --- M2 check-connection on the REAL id: must not crash ----------------------
E0=$(ev)
R=$(curl -s "${AUTH[@]}" -w '\n%{http_code}' "$BASE/api/issuetracker/1/check-connection")
C=$(printf '%s' "$R" | tail -1)
E1=$(ev)
V=$(printf '%s' "$R" | grep -c 'connected')
res M2 "check-connection real id=1: http=$C verdict=$V, 0 new E_WARNING" \
    "$(printf '%s' "$C" | grep -qE '^(200|502)$' && [ "$V" -ge 1 ] && [ "$E0" = "$E1" ] && echo 0 || echo 1)"

# --- M3 check-connection on a NON-EXISTENT id: 404, no phantom, no warnings --
E0=$(ev)
R=$(curl -s "${AUTH[@]}" -w '\n%{http_code}' "$BASE/api/issuetracker/999/check-connection")
C=$(printf '%s' "$R" | tail -1)
B=$(printf '%s' "$R" | grep -c 'not found')
E1=$(ev)
res M3 "check-connection bogus id=999: http=$C notFound=$B, 0 new events" \
    "$([ "$C" = "404" ] && [ "$B" -ge 1 ] && [ "$E0" = "$E1" ] && echo 0 || echo 1)"

# --- M4/M5 the reported controller itself ------------------------------------
E0=$(ev)
C4=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE/lib/issuetrackers/issueTrackerView.php?tproject_id=1&id=999")
C5=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE/lib/issuetrackers/issueTrackerView.php?tproject_id=1")
E1=$(ev)
res M4 "reported file issueTrackerView.php?id=999 -> http=$C4 (deleted in #966)" \
    "$([ "$C4" = "404" ] && echo 0 || echo 1)"
res M5 "reported file issueTrackerView.php (no id) -> http=$C5 (deleted in #966)" \
    "$([ "$C5" = "404" ] && echo 0 || echo 1)"

# --- M6/M7 surviving twin: phantom-row guard from #1625 -----------------------
E0=$(ev)
curl -s -b "$CJ" -o /tmp/rms1618.php.html "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1&id=999"
C6=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1&id=999")
E1=$(ev)
N6=$(grep -c 'doAction=edit' /tmp/rms1618.php.html 2>/dev/null || echo 0)
NR6=$(mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT COUNT(*) FROM reqmgrsystems;" 2>/dev/null)
res M6 "reqMgrSystemView.php?id=999: http=$C6 edit-links=$N6 == real rows=$NR6, 0 new events" \
    "$([ "$C6" = "200" ] && [ "$N6" = "$NR6" ] && [ "$E0" = "$E1" ] && echo 0 || echo 1)"

E0=$(ev)
curl -s -b "$CJ" -o /tmp/rms1618no.php.html "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1"
C7=$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1")
E1=$(ev)
N7=$(grep -c 'doAction=edit' /tmp/rms1618no.php.html 2>/dev/null || echo 0)
res M7 "reqMgrSystemView.php (no id): http=$C7 edit-links=$N7 == real rows=$NR6, 0 new events" \
    "$([ "$C7" = "200" ] && [ "$N7" = "$NR6" ] && [ "$E0" = "$E1" ] && echo 0 || echo 1)"

# --- M8 modern reqmgrsystems list --------------------------------------------
E0=$(ev)
R=$(curl -s "${AUTH[@]}" "$BASE/api/reqmgrsystems/?tproject_id=1")
T8=$(printf '%s' "$R" | grep -o '"total":[0-9]*' | cut -d: -f2)
E1=$(ev)
res M8 "modern reqmgrsystems list: total=$T8 == real rows=$NR6, 0 new events" \
    "$([ "$T8" = "$NR6" ] && [ "$E0" = "$E1" ] && echo 0 || echo 1)"

echo "-----"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
