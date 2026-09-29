#!/usr/bin/env bash
# Regression harness for Issue #1722 - lib/reqmgrsystems/reqMgrSystemEdit.php
# answered HTTP 200 with a 0-byte BODY (a blank page) and ZERO Event Viewer rows
# for the two actions that are in reqMgrSystemCommands::$guiOpWhiteList but that
# renderGui() can not render:
#   - 'delete'          whitelisted, but no such method on the command class, so
#                       method_exists() is false and $op stays null -> no work at all
#   - 'checkConnection' whitelisted and a real method, but not one of the switch
#                       cases -> the bean is computed and then thrown away
# Both leave $renderType at 'none' and the second switch's "default: break;"
# ended the request silently.
#
# The fix replaces that sink with the graceful 302 that #1627 introduced in
# init_args() for a non-whitelisted doAction, plus one tLog ERROR row.
#
# Run from repo root:  bash tmp/verify_1722.sh
# Exit 0 = all assertions pass, 1 = at least one failure.
#
# The suite must assert two opposite things at once, exactly like verify_1627.sh:
#   (a) no doAction can produce a blank 200 any more;
#   (b) the actions that DO render still behave exactly as before, writes included
#       (a "302 everything" fix would pass (a) and silently break (b)).
set -u

BASE="${BASE:-http://localhost:8082}"
MYSQL="${MYSQL:-mysql -h 127.0.0.1 -utestlink -ptestlink testlink}"
CJ="$(mktemp -t cj1722.XXXXXX)"
TMPD="$(mktemp -d -t t1722.XXXXXX)"
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
LIST="$BASE/gui/templates/reqmgrsystems/reqMgrSystemView.html"

get() { # get <query> -> "<http_code> <bytes> <redirect_url>"
  curl -s -b "$CJ" -o "$TMPD/body" -w '%{http_code} %{size_download} %{redirect_url}' "$URL$1"
}
post() { # post <urlencoded fields> -> "<http_code> <bytes> <redirect_url>"
  curl -s -b "$CJ" -o "$TMPD/body" -w '%{http_code} %{size_download} %{redirect_url}' \
       -H "Origin: $BASE" -H 'Content-Type: application/x-www-form-urlencoded' \
       --data "$1" "$URL"
}
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
# ev <id> -> number of events rows with id > <id>
ev() { sql "select count(*) from events where id > $1"; }
# evdesc <id> -> distinct descriptions of the events rows added since <id>
evdesc() { sql "select distinct replace(left(description,80),'\n',' ') from events where id > $1"; }

# --- login (the BFF is CSRF-guarded: a same-origin Origin header is REQUIRED) ---
curl -s -c "$CJ" -b "$CJ" "$BASE/login.php" -o /dev/null
curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/api/auth/login" \
     -H "Origin: $BASE" -d "login=admin&password=admin" -o /dev/null

SUFFIX="$$"
CREATE_NAME="RMSS1722A$SUFFIX"
UPDATED_NAME="RMSS1722U$SUFFIX"
# N1: a run that dies between doCreate and doDelete must not leak its row
cleanup_row() {
  sql "delete from reqmgrsystems where name like 'RMSS1722A$SUFFIX%'" >/dev/null 2>&1
}
trap 'cleanup_row; rm -rf "$CJ" "$TMPD"' EXIT

echo "--- (a) no doAction can answer a blank 200 any more ---"

# 1: 'delete' is whitelisted, has no method -> was 200 / 0 bytes / 0 events, silent
M="$(sql 'select coalesce(max(id),0) from events')"
R="$(get '?doAction=delete')"; C="$(echo "$R" | cut -d' ' -f1)"; L="$(echo "$R" | cut -d' ' -f3)"
eq "GET ?doAction=delete answers 302 (was 200 / 0 bytes)" 302 "$C"
eq "GET ?doAction=delete sends an empty body" 0 "$(echo "$R" | cut -d' ' -f2)"
eq "GET ?doAction=delete redirects to the Requirements Manager list" "$LIST" "$L"
if evdesc "$M" | grep -q 'not renderable'; then
  ok 0 "...and logs the reason (was 0 events, i.e. undiagnosable)"
else
  ok 1 "...and logs the reason" "an 'not renderable' event row" "$(evdesc "$M" | tr '\n' ';')"
fi

# 2: 'checkConnection' is a real method but not a switch case -> was 200 / 0 bytes
M="$(sql 'select coalesce(max(id),0) from events')"
R="$(get '?doAction=checkConnection&id=1')"; C="$(echo "$R" | cut -d' ' -f1)"; L="$(echo "$R" | cut -d' ' -f3)"
eq "GET ?doAction=checkConnection&id=1 answers 302 (was 200 / 0 bytes)" 302 "$C"
eq "GET ?doAction=checkConnection&id=1 redirects to the Requirements Manager list" "$LIST" "$L"
if evdesc "$M" | grep -q 'not renderable'; then ok 0 "...and logs the reason"; \
  else ok 1 "...and logs the reason" "an 'not renderable' event row" "$(evdesc "$M" | tr '\n' ';')"; fi

# 3: the new branch must not swallow the sibling #1627 rejection (different wording,
#    different code path) - a non-whitelisted action never reaches renderGui()
M="$(sql 'select coalesce(max(id),0) from events')"
R="$(get '?doAction=bogus')"; C="$(echo "$R" | cut -d' ' -f1)"; L="$(echo "$R" | cut -d' ' -f3)"
eq "GET ?doAction=bogus still answers 302 (unchanged, #1627's path)" 302 "$C"
eq "GET ?doAction=bogus still redirects to the list" "$LIST" "$L"
if evdesc "$M" | grep -q 'white list validation failure'; then
  ok 0 "...still logged by #1627's own message (not the new branch)"
else
  ok 1 "...still logged by #1627's own message" "a 'white list validation failure' row" "$(evdesc "$M" | tr '\n' ';')"
fi
if evdesc "$M" | grep -q 'not renderable'; then
  ok 1 "...and does NOT also hit the new branch" "no 'not renderable' row" "it did"
else
  ok 0 "...and does NOT also hit the new branch"
fi

echo "--- (b) every action that does render is untouched ---"

# 4: empty doAction still maps to the create form (#1627), must not be diverted
M="$(sql 'select coalesce(max(id),0) from events')"
R="$(get '?doAction=')"; C="$(echo "$R" | cut -d' ' -f1)"; B="$(echo "$R" | cut -d' ' -f2)"
eq "GET ?doAction= (empty) still answers 200" 200 "$C"
if [ "${B:-0}" -gt 5000 ]; then ok 0 "...and still renders the create form (${B} bytes)"; \
  else ok 1 "...and still renders the create form" ">5000 bytes" "${B:-0} bytes"; fi
eq "...and logs no event at all" 0 "$(ev "$M")"

# 5: 'create' renders the form
M="$(sql 'select coalesce(max(id),0) from events')"
R="$(get '?doAction=create')"; C="$(echo "$R" | cut -d' ' -f1)"; B="$(echo "$R" | cut -d' ' -f2)"
eq "GET ?doAction=create answers 200" 200 "$C"
if [ "${B:-0}" -gt 5000 ]; then ok 0 "...and renders the form (${B} bytes)"; \
  else ok 1 "...and renders the form" ">5000 bytes" "${B:-0} bytes"; fi
eq "...and logs no event at all" 0 "$(ev "$M")"

# 6: the full create -> edit -> update -> delete cycle must still write to the DB.
#    This is the anti-regression core: the new default: must never fire for these.
M="$(sql 'select coalesce(max(id),0) from events')"
R="$(post "doAction=doCreate&name=$CREATE_NAME&cfg=host%3Dlocalhost&type=1")"
C="$(echo "$R" | cut -d' ' -f1)"; L="$(echo "$R" | cut -d' ' -f3)"
eq "POST ?doAction=doCreate answers 302" 302 "$C"
case "$L" in */reqMgrSystemView.php*) ok 0 "...redirecting to the view screen" ;; \
  *) ok 1 "...redirecting to the view screen" "*/reqMgrSystemView.php" "$L" ;; esac
ID="$(sql "select id from reqmgrsystems where name='$CREATE_NAME' limit 1")"
if [ -n "$ID" ]; then ok 0 "...and inserted the row (id=$ID)"; \
  else ok 1 "...and inserted the row" "one row" "none"; fi

R="$(get "?doAction=edit&id=$ID")"; C="$(echo "$R" | cut -d' ' -f1)"; B="$(echo "$R" | cut -d' ' -f2)"
eq "GET ?doAction=edit&id=$ID answers 200" 200 "$C"
if [ "${B:-0}" -gt 5000 ]; then ok 0 "...and renders the edit form for the NEW row (${B} bytes)"; \
  else ok 1 "...and renders the edit form" ">5000 bytes" "${B:-0} bytes"; fi
if grep -q "$CREATE_NAME" "$TMPD/body"; then ok 0 "...pre-filled with the stored name"; \
  else ok 1 "...pre-filled with the stored name" "$CREATE_NAME" "not in the body"; fi

R="$(post "doAction=doUpdate&id=$ID&name=$UPDATED_NAME&cfg=host%3Dexample.org&type=1")"
C="$(echo "$R" | cut -d' ' -f1)"
eq "POST ?doAction=doUpdate answers 302" 302 "$C"
eq "...and the row was really updated" "$UPDATED_NAME" \
   "$(sql "select name from reqmgrsystems where id=$ID")"
eq "...and the cfg was really updated" "host=example.org" \
   "$(sql "select cfg from reqmgrsystems where id=$ID")"

R="$(get "?doAction=doDelete&id=$ID")"; C="$(echo "$R" | cut -d' ' -f1)"
eq "GET ?doAction=doDelete&id=$ID answers 302" 302 "$C"
eq "...and the row is gone" 0 "$(sql "select count(*) from reqmgrsystems where id=$ID")"

# 7: no PHP Warning may be produced anywhere in the whole cycle (log_level 2)
if [ "$(sql "select count(*) from events where id > $M and log_level = 2")" = "0" ]; then
  ok 0 "the whole create/edit/update/delete cycle produced ZERO E_WARNING rows"
else
  ok 1 "the whole create/edit/update/delete cycle produced ZERO E_WARNING rows" "0" \
     "$(sql "select count(*) from events where id > $M and log_level = 2")"
fi

# 8: the sink is gone for good. Structural greps cannot prove behaviour, so this is
#    a real sweep: NO value of doAction may ever make this controller answer a bare
#    200 / 0 bytes again. Pre-fix, 'delete' and 'checkConnection' both did exactly that,
#    so this single assertion fails on the unpatched file and is not self-referential.
BLANK=""
for probe in 'delete' 'checkConnection&id=1' 'DELETE' 'CheckConnection&id=1' 'delete&id=1' \
             'doDeleteX' 'editX' 'createX' 'zzz'; do
  R="$(get "?doAction=$probe")"
  if [ "$(echo "$R" | cut -d' ' -f1)" = "200" ] && [ "$(echo "$R" | cut -d' ' -f2)" = "0" ]; then
    BLANK="$BLANK $probe"
  fi
done
eq "no doAction value answers a bare 200 / 0 bytes any more" "" "$BLANK"

# 9: syntax gate is unconditional, so the assertion count does not depend on php being
#    on the PATH (the '31/31 PASS' figure quoted in the docs must be exact)
if command -v php >/dev/null 2>&1 && php -l lib/reqmgrsystems/reqMgrSystemEdit.php >/dev/null 2>&1
then
  ok 0 "php -l lib/reqmgrsystems/reqMgrSystemEdit.php is clean"
else
  ok 1 "php -l lib/reqmgrsystems/reqMgrSystemEdit.php is clean" "php present + no syntax error" \
     "php missing or syntax error"
fi

echo
echo "Regression #1722: $PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
exit 0
