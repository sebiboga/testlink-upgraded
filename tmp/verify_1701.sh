#!/usr/bin/env bash
# Regression harness for GitHub issue #1701
# "api/issuetracker check-connection: 502 catch block logs an unattributable
#  TypeError (__METHOD__ is "" at file top level, so the Event Viewer row names
#  no source)"
#
# Two defects are pinned, one code path:
#   A) issueTrackerInterface::connect() built $connection_args with SIMPLE string
#      interpolation ("$this->cfg->dbhost"). PHP resolves only ONE property level
#      there, so it stringified $this->cfg (a stdClass since setCfg() json_decodes
#      it) and threw "TypeError: Object of class stdClass could not be converted
#      to string" -- aborting the statement BEFORE the tLog() that records WHY the
#      connection failed. A dead-host check therefore logged nothing useful.
#   B) api/issuetracker/index.php logged tLog(__METHOD__ . ...); __METHOD__ at the
#      TOP LEVEL of a request script is "" in PHP 8, so the row had a bare 1-space
#      prefix and named no source at all.
#
# R1 reported repro        -> no TypeError, 200 + connected:false, ONE ERROR row
#                             naming the REAL cause (host/db/user + ADODB code)
# R2 GET  /{id}/check-con  -> 502, ERROR row prefixed "api/issuetracker/index.php::GET /{id}/check-connection ::"
# R3 POST /test-connection -> 502, ERROR row prefixed "api/issuetracker/index.php::POST /test-connection ::"
# R4 GET  /{bogus id}      -> 404 + "not found", 0 new event rows
# R5 GET  / (list)         -> 200, total == real row count, 0 new event rows
# R6 GET  /{LIVE}/check-con -> reachable control, connected:true, 0 new rows
# R7 Event Viewer sweep    -> 0 log_level=2 (E_WARNING) rows in events (events is
#                            emptied before every step, so this sweeps R6's own rows)
#
# Exit 0 = all PASS.
BASE="http://localhost:8082"
MY="mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink"
CJ=/tmp/cj1701_h.txt
PASS=0; FAIL=0
res() { # res <name> <desc> <0|1>
  if [ "$3" -eq 0 ]; then echo "PASS  $1 - $2"; PASS=$((PASS+1));
  else echo "FAIL  $1 - $2"; FAIL=$((FAIL+1)); fi
}
q()  { $MY -e "$1" 2>/dev/null; }
rows() { q "SELECT COUNT(*) FROM events;"; }
warns() { q "SELECT COUNT(*) FROM events WHERE log_level=2;"; }
lastdesc() { q "SELECT description FROM events ORDER BY id DESC LIMIT 1;"; }

# ---- fixtures: the reported repro + a driver that forces the catch block ----
q "DELETE FROM events; DELETE FROM issuetrackers;
   INSERT INTO issuetrackers (name,type,cfg) VALUES
   ('IT-1701-DEADHOST',2,'<issuetracker><uribase>http://127.0.0.1:1/</uribase><dbtype>mysql</dbtype><dbhost>127.0.0.1</dbhost><dbname>nodb</dbname><dbuser>nodb</dbuser><dbpassword>nodb</dbpassword></issuetracker>'),
   ('IT-1701-BADDRIVER',2,'<issuetracker><dbtype>zzz_no_such_driver</dbtype><dbhost>127.0.0.1</dbhost><dbname>nodb</dbname><dbuser>nodb</dbuser><dbpassword>nodb</dbpassword></issuetracker>'),
   ('IT-1701-REACHABLE',2,'<issuetracker><uribase>http://127.0.0.1/</uribase><dbtype>mysql</dbtype><dbhost>127.0.0.1</dbhost><dbname>testlink</dbname><dbuser>testlink</dbuser><dbpassword>testlink</dbpassword></issuetracker>');
   SELECT 1;" >/dev/null
DEAD=$(q "SELECT id FROM issuetrackers WHERE name='IT-1701-DEADHOST' LIMIT 1;")
BAD=$(q  "SELECT id FROM issuetrackers WHERE name='IT-1701-BADDRIVER' LIMIT 1;")
LIVE=$(q "SELECT id FROM issuetrackers WHERE name='IT-1701-REACHABLE' LIMIT 1;")
q "DELETE FROM events;" >/dev/null

rm -f "$CJ"
curl -s -b "$CJ" -c "$CJ" -o /dev/null "$BASE/index.php"
curl -s -b "$CJ" -c "$CJ" -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" \
  -d '{"login":"admin","password":"admin"}' -o /dev/null "$BASE/api/auth/login"
AUTH=(-b "$CJ" -H "X-Requested-With: XMLHttpRequest")

# ---- R1 the reported repro: dead host must log the REAL cause ---------------
q "DELETE FROM events;" >/dev/null
R=$(curl -s "${AUTH[@]}" -w '\n%{http_code}' "$BASE/api/issuetracker/$DEAD/check-connection")
C=$(printf '%s' "$R" | tail -1)
B=$(printf '%s' "$R" | head -1)
D=$(lastdesc)
N=$(rows)
res R1 "reported repro id=$DEAD: http=$C, 1 event row, no TypeError, row names the real cause" \
  "$(printf '%s' "$C" | grep -qx 200 \
     && printf '%s' "$B" | grep -q '"connected":false' \
     && [ "$N" = "1" ] \
     && ! printf '%s' "$D" | grep -q 'stdClass' \
     && printf '%s' "$D" | grep -q 'Connect to Bug Tracker database fails' \
     && printf '%s' "$D" | grep -q 'DBName: nodb' \
     && printf '%s' "$D" | grep -q '1045' && echo 0 || echo 1)"

# ---- R2 GET route, driver that forces the catch block ------------------------
q "DELETE FROM events;" >/dev/null
R=$(curl -s "${AUTH[@]}" -w '\n%{http_code}' "$BASE/api/issuetracker/$BAD/check-connection" 2>/dev/null)
C=$(printf '%s' "$R" | tail -1)
D=$(lastdesc); N=$(rows)
res R2 "GET id=$BAD (forces catch): http=$C, $N row(s), row prefixed with the file::route literal" \
  "$(printf '%s' "$C" | grep -qx 502 \
     && [ "$N" = "1" ] \
     && printf '%s' "$D" | grep -q '^api/issuetracker/index\.php::GET /{id}/check-connection :: ' \
     && echo 0 || echo 1)"

# ---- R3 POST route, same forced catch ---------------------------------------
q "DELETE FROM events;" >/dev/null
R=$(curl -s "${AUTH[@]}" -H "Content-Type: application/json" \
  -d '{"name":"probe","type":2,"cfg":"<issuetracker><dbtype>zzz_no_such_driver</dbtype><dbhost>h</dbhost><dbname>n</dbname><dbuser>u</dbuser><dbpassword>p</dbpassword></issuetracker>"}' \
  -w '\n%{http_code}' "$BASE/api/issuetracker/test-connection" 2>/dev/null)
C=$(printf '%s' "$R" | tail -1)
D=$(lastdesc); N=$(rows)
res R3 "POST /test-connection (forces catch): http=$C, $N row(s), row prefixed with the file::route literal" \
  "$(printf '%s' "$C" | grep -qx 502 \
     && [ "$N" = "1" ] \
     && printf '%s' "$D" | grep -q '^api/issuetracker/index\.php::POST /test-connection :: ' \
     && echo 0 || echo 1)"

# ---- R4 bogus id: 404, no event row (guard must not regress) -----------------
q "DELETE FROM events;" >/dev/null
R=$(curl -s "${AUTH[@]}" -w '\n%{http_code}' "$BASE/api/issuetracker/999999/check-connection")
C=$(printf '%s' "$R" | tail -1)
B=$(printf '%s' "$R" | grep -c 'not found')
N=$(rows)
res R4 "bogus id=999999: http=$C notFound=$B, $N new event rows" \
  "$([ "$C" = "404" ] && [ "$B" -ge 1 ] && [ "$N" = "0" ] && echo 0 || echo 1)"

# ---- R5 list: 200, real row count, no event row -----------------------------
q "DELETE FROM events;" >/dev/null
R=$(curl -s "${AUTH[@]}" "$BASE/api/issuetracker/?tproject_id=1")
T=$(printf '%s' "$R" | grep -o '"total":[0-9]*' | cut -d: -f2)
NR=$(q "SELECT COUNT(*) FROM issuetrackers;")
N=$(rows)
res R5 "list: total=$T == real rows=$NR, $N new event rows" \
  "$([ "$T" = "$NR" ] && [ "$N" = "0" ] && echo 0 || echo 1)"

# ---- R6 reachable control: a db that DOES answer -> connected:true, 0 rows ---
q "DELETE FROM events;" >/dev/null
R=$(curl -s "${AUTH[@]}" -w '\n%{http_code}' \
  "$BASE/api/issuetracker/$LIVE/check-connection" 2>/dev/null)
C=$(printf '%s' "$R" | tail -1)
B=$(printf '%s' "$R" | head -1)
N=$(rows)
res R6 "reachable-conn control id=$LIVE (real testlink DB): http=$C connected=true, $N event rows" \
  "$(printf '%s' "$C" | grep -qx 200 \
     && printf '%s' "$B" | grep -q '"connected":true' \
     && [ "$N" = "0" ] && echo 0 || echo 1)"

# ---- R7 Event Viewer sweep: 0 new E_WARNING over the whole run --------------
W=$(warns)
res R7 "Event Viewer: $W log_level=2 (E_WARNING) rows in total after the whole run" \
  "$([ "$W" = "0" ] && echo 0 || echo 1)"

echo "----- $PASS PASS / $FAIL FAIL -----"
[ "$FAIL" -eq 0 ]
