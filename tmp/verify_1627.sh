#!/usr/bin/env bash
# Regression harness for Issue #1627 - lib/reqmgrsystems/reqMgrSystemEdit.php
# answered HTTP 500 with a 0-byte body whenever doAction was missing or was not in
# the command bean's white list, because init_args() threw an uncaught Exception.
#
# Run from repo root:  bash tmp/verify_1627.sh
# Exit 0 = all assertions pass, 1 = at least one failure.
#
# The fix touches the REJECTED branch only, so the suite must assert two opposite
# things at once:
#   (a) no input can reach the old fatal any more (empty / unknown / over-long);
#   (b) the 7 whitelisted actions still behave exactly as before, writes included
#       (a "graceful 302 everywhere" fix would pass (a) and silently break (b)).
set -u

BASE="${BASE:-http://localhost:8082}"
MYSQL="${MYSQL:-mysql -h 127.0.0.1 -utestlink -ptestlink testlink}"
CJ="$(mktemp -t cj1627.XXXXXX)"
TMPD="$(mktemp -d -t t1627.XXXXXX)"
trap 'rm -rf "$CJ" "$TMPD"' EXIT
PASS=0; FAIL=0; SEQ=0

ok() { # ok <exit> <label> <expected> <actual>
  SEQ=$((SEQ+1))
  if [ "$1" = "0" ]; then
    PASS=$((PASS+1)); printf '| %d | %s | PASS |\n' "$SEQ" "$2"
  else
    FAIL=$((FAIL+1)); printf '| %d | %s | **FAIL** (expected %s, got %s) |\n' \
      "$SEQ" "$2" "$3" "$4"
  fi
}
eq() { # eq <label> <expected> <actual>
  if [ "$2" = "$3" ]; then ok 0 "$1"; else ok 1 "$1" "$2" "$3"; fi
}

URL="$BASE/lib/reqmgrsystems/reqMgrSystemEdit.php"

get() { # get <query> -> "<http_code> <bytes> <redirect_url>"
  curl -s -b "$CJ" -o "$TMPD/body" -w '%{http_code} %{size_download} %{redirect_url}' "$URL$1"
}
post() { # post <urlencoded fields> -> "<http_code> <bytes> <redirect_url>"
  curl -s -b "$CJ" -o "$TMPD/body" -w '%{http_code} %{size_download} %{redirect_url}' \
       -H "Origin: $BASE" -H 'Content-Type: application/x-www-form-urlencoded' \
       --data "$1" "$URL"
}
sql() { $MYSQL -N -e "$1" 2>/dev/null; }

# --- login (the BFF is CSRF-guarded: a same-origin Origin header is REQUIRED) ---
curl -s -c "$CJ" -b "$CJ" "$BASE/login.php" -o /dev/null
curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/api/auth/login" \
     -H "Origin: $BASE" -d "login=admin&password=admin" -o /dev/null

EVENTS0="$(sql 'select coalesce(max(id),0) from events')"
SUFFIX="$$"
CREATE_NAME="RMSS1627A$SUFFIX"
UPDATED_NAME="RMSS1627U$SUFFIX"

echo "--- (a) the old fatal can not be reached any more ---"

# 1/2/3: missing doAction -> the create form, HTTP 200, no fatal
R="$(get '')"; C="$(echo "$R" | cut -d' ' -f1)"; B="$(echo "$R" | cut -d' ' -f2)"
eq "GET with no doAction answers 200 (was 500 / 0 bytes)" 200 "$C"
if [ "${B:-0}" -gt 5000 ]; then ok 0 "...and renders the create form (${B} bytes)"; \
  else ok 1 "...and renders the create form" ">5000 bytes" "${B:-0} bytes"; fi
if grep -q 'name="name"' "$TMPD/body"; then ok 0 "...containing the create form fields"; \
  else ok 1 "...containing the create form fields" "form" "no form"; fi

R="$(get '?doAction=')"; C="$(echo "$R" | cut -d' ' -f1)"
eq "GET with doAction= (empty) answers 200 (was 500 / 0 bytes)" 200 "$C"

# 4/5: not whitelisted -> 302 to the list screen, rejection still logged
R="$(get '?doAction=bogus')"; C="$(echo "$R" | cut -d' ' -f1)"; L="$(echo "$R" | cut -d' ' -f3)"
eq "GET with doAction=bogus answers 302 (was 500 / 0 bytes)" 302 "$C"
case "$L" in
  */gui/templates/reqmgrsystems/reqMgrSystemView.html*) ok 0 "...redirecting to the Requirements Manager list" ;;
  *) ok 1 "...redirecting to the Requirements Manager list" ".../reqMgrSystemView.html" "$L" ;;
esac

# 6/7: the other flavours of "not whitelisted"
R="$(get '?doAction=CREATE')"; C="$(echo "$R" | cut -d' ' -f1)"
eq "GET with doAction=CREATE (wrong case) answers 302" 302 "$C"
R="$(get "?doAction=$(printf 'a%.0s' $(seq 1 28))")"; C="$(echo "$R" | cut -d' ' -f1)"
eq "GET with a 28-char doAction (over maxLen 20) answers 302" 302 "$C"

# 8: the rejections are still recorded in the Event Viewer (log_level 1 = ERROR)
N="$(sql "select count(*) from events where id > $EVENTS0 and log_level = 1 and description like '%white list validation failure%'")"
if [ "${N:-0}" -ge 3 ]; then ok 0 "the 3 rejections are logged in the Event Viewer (log_level 1)"; \
  else ok 1 "the 3 rejections are logged in the Event Viewer" ">= 3" "${N:-0}"; fi

echo "--- (b) the whitelisted actions are untouched ---"

# 9: the create form still renders
R="$(get '?doAction=create')"; C="$(echo "$R" | cut -d' ' -f1)"
eq "GET ?doAction=create answers 200" 200 "$C"

# 10/11/12: doCreate still writes (a successful write answers 302 -> reqMgrSystemView.php)
R="$(post "doAction=doCreate&id=0&name=$CREATE_NAME&type=1&cfg=host=127.0.0.1")"
C="$(echo "$R" | cut -d' ' -f1)"; L="$(echo "$R" | cut -d' ' -f3)"
eq "POST doAction=doCreate answers 302 (redirect to the view)" 302 "$C"
case "$L" in
  *reqMgrSystemView.php*) ok 0 "...redirecting to reqMgrSystemView.php" ;;
  *) ok 1 "...redirecting to reqMgrSystemView.php" ".../reqMgrSystemView.php" "$L" ;;
esac
ROWID="$(sql "select id from reqmgrsystems where name = '$CREATE_NAME'" | head -1)"
if [ -n "$ROWID" ]; then ok 0 "...and the row is really INSERTed (id=$ROWID)"; \
  else ok 1 "...and the row is really INSERTed" "a row named $CREATE_NAME" "no such row"; fi

# 13/14: the edit form still renders, pre-filled
R="$(get "?doAction=edit&id=$ROWID")"; C="$(echo "$R" | cut -d' ' -f1)"
eq "GET ?doAction=edit&id=$ROWID answers 200" 200 "$C"
if grep -q "value=\"$CREATE_NAME\"" "$TMPD/body"; then \
  ok 0 "...and the edit form is pre-filled with the stored name"; \
else ok 1 "...and the edit form is pre-filled with the stored name" "$CREATE_NAME" "not found"; fi

# 15/16: doUpdate still writes
R="$(post "doAction=doUpdate&id=$ROWID&name=$UPDATED_NAME&type=1&cfg=host=127.0.0.1")"
C="$(echo "$R" | cut -d' ' -f1)"
eq "POST doAction=doUpdate answers 302 (redirect to the view)" 302 "$C"
CNT="$(sql "select count(*) from reqmgrsystems where id = $ROWID and name = '$UPDATED_NAME'")"
eq "...and the row is really UPDATED" 1 "${CNT:-0}"

# 17: the shipped view form with its empty hidden doAction (the JS-less in-app path)
R="$(post "id=0&name=&type=1&cfg=")"; C="$(echo "$R" | cut -d' ' -f1)"
eq "POST of the view form with an EMPTY doAction answers 200 (was 500 / 0 bytes)" 200 "$C"

# 18/19: doDelete still deletes
R="$(post "doAction=doDelete&id=$ROWID")"; C="$(echo "$R" | cut -d' ' -f1)"
eq "POST doAction=doDelete answers 302 (redirect to the view)" 302 "$C"
CNT="$(sql "select count(*) from reqmgrsystems where id = $ROWID")"
eq "...and the row is really DELETED" 0 "${CNT:-x}"

# 20/21: the rights gate is untouched - anonymous still gets the login bounce
A="$(curl -s -o "$TMPD/anon" -w '%{http_code}' "$URL?doAction=create")"
eq "anonymous GET still answers 200" 200 "$A"
if grep -q "login.php?note=expired" "$TMPD/anon"; then ok 0 "...and is bounced to the login screen"; \
else ok 1 "...and is bounced to the login screen" "login.php?note=expired" "no bounce"; fi

# 22: no new E_WARNING/E_ERROR row comes from the controller itself.
# NOTE: rows mentioning the COMPILED TEMPLATE (gui/templates_c/*reqMgrSystemEdit.tpl.php)
# are excluded on purpose - "Trying to access array offset on null" is a PRE-EXISTING
# Smarty warning of that template ($gui->testProjectSet is null on the create screen),
# present in the pre-fix baseline too, and reported separately.
N="$(sql "select count(*) from events where id > $EVENTS0 and log_level <= 2 and description like '%reqMgrSystemEdit.php%' and description not like '%white list validation failure%'")"
eq "no new E_WARNING/E_ERROR row comes from the controller itself (the rejection audit rows are asserted in step 8)" 0 "${N:-0}"

# 23: no FATAL-class row (log_level 0) was written for the rejected inputs
N="$(sql "select count(*) from events where id > $EVENTS0 and log_level = 0 and description like '%white list validation failure%'")"
eq "no new FATAL-class row was written for the rejected input" 0 "${N:-0}"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
