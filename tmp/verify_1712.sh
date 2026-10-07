#!/usr/bin/env bash
# Regression matrix for GitHub issue #1712
# 8 sibling issue-tracker interface classes share #1711's unguarded (string)
# cast on stdClass cfg members -> HTTP 502 on POST /api/issuetracker/test-connection
#
# R1-R3 the reported repro (one per class)   -> HTTP 200, no fatal, field named in a WARNING
# R4   all 8 affected types at once          -> HTTP 200 every time
# R5   repeated element (silently stored 'Array' pre-fix) -> HTTP 200 + named WARNING
# R6   control: bugzillaxmlrpc (#1711 twin) still behaves
# R7   Issue Tracker Management grid + list API (shared code path)
# R8   GET /{id}/check-connection (legacy route mirror)
# R9   Event Viewer sweep: ZERO "could not be converted to string" rows
# Exit 0 = all PASS.
BASE="http://localhost:8082"
MY="mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink"
CJ=/tmp/cj1712_v.txt
PASS=0; FAIL=0
res() { if [ "$2" -eq 0 ]; then echo "PASS  $1"; PASS=$((PASS+1)); else echo "FAIL  $1"; FAIL=$((FAIL+1)); fi; }
q()  { $MY -e "$1" 2>/dev/null; }
rows() { q "SELECT COUNT(*) FROM events;"; }

U='<uribase>http://127.0.0.1:1/</uribase>'
tc() { # tc <type> <cfg> -> echoes "<httpcode> <body>"
  curl -s -b "$CJ" -c "$CJ" -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
    -d "{\"name\":\"IT-1712\",\"type\":$1,\"cfg\":$(printf '%s' "$2" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))')}" \
    -w '|%{http_code}' "$BASE/api/issuetracker/test-connection"
}

rm -f "$CJ"
curl -s -b "$CJ" -c "$CJ" -o /dev/null "$BASE/index.php"
curl -s -b "$CJ" -c "$CJ" -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
  -d '{"login":"admin","password":"admin"}' -o /dev/null "$BASE/api/auth/login"

q "DELETE FROM events;"

echo "== R1 reported repro: element-valued field, 3 classes =="
for T in 3 5 10; do
  R=$(tc $T "<issuetracker>$U<uriwsdl><x/></uriwsdl></issuetracker>")
  case "$R" in *"|200"*) res "R1.$T type=$T -> HTTP 200 (no 502)" 0;; *) res "R1.$T type=$T -> $R" 1;; esac
done

echo "== R2 all 8 affected types =="
for T in 3 5 7 8 10 15 19 27; do
  R=$(tc $T "<issuetracker>$U<username><x/></username><password><x/></password></issuetracker>")
  case "$R" in *"|200"*) res "R2.$T type=$T -> HTTP 200" 0;; *) res "R2.$T type=$T -> $R" 1;; esac
done

echo "== R3 whitespace-only field =="
R=$(tc 27 "<issuetracker>$U<tracker>  </tracker></issuetracker>")
case "$R" in *"|200"*) res "R3 tuleap whitespace-only <tracker> -> HTTP 200" 0;; *) res "R3 -> $R" 1;; esac

echo "== R4 repeated element (pre-fix: silent 'Array') =="
q "DELETE FROM events;"
R=$(tc 3 "<issuetracker>$U<uriwsdl>a</uriwsdl><uriwsdl>b</uriwsdl></issuetracker>")
case "$R" in *"|200"*) res "R4 repeated <uriwsdl> -> HTTP 200" 0;; *) res "R4 -> $R" 1;; esac
N=$(q "SELECT COUNT(*) FROM events WHERE description LIKE '%Array to string conversion%';")
res "R4 no 'Array to string conversion' row (got $N)" "$([ "$N" = "0" ]; echo $?)"

echo "== R5 the offending field is NAMED in the Event Viewer =="
q "DELETE FROM events;"
R=$(tc 27 "<issuetracker>$U<tracker><x/></tracker></issuetracker>")
case "$R" in *"|200"*) res "R5 tuleap nested <tracker> -> HTTP 200" 0;; *) res "R5 -> $R" 1;; esac
N=$(q "SELECT COUNT(*) FROM events WHERE description LIKE '%cfg field <tracker> is not a text value%';")
res "R5 cfgWarn names <tracker> (got $N row(s))" "$([ "$N" -ge 1 ]; echo $?)"

echo "== R6 control: bugzillaxmlrpc (#1711 twin) unaffected =="
q "DELETE FROM events;"
R=$(tc 1 "<issuetracker>$U<version><x/></version><username>u</username><password>p</password></issuetracker>")
case "$R" in *"|200"*) res "R6 type=1 element-valued <version> -> HTTP 200" 0;; *) res "R6 -> $R" 1;; esac
R=$(tc 1 "<issuetracker>$U<username>u</username><password>p</password></issuetracker>")
case "$R" in *"|200"*) res "R6b type=1 valid cfg -> HTTP 200" 0;; *) res "R6b -> $R" 1;; esac

echo "== R7 Issue Tracker Management grid + list API (shared path) =="
q "DELETE FROM events;"
C=$(curl -s -b "$CJ" -c "$CJ" -o /tmp/i1712_grid.html -w '%{http_code}' "$BASE/gui/templates/issuetracker/issuetrackerView.html?tproject_id=9901")
res "R7 grid HTTP 200 (got $C)" "$([ "$C" = "200" ]; echo $?)"
C=$(curl -s -b "$CJ" -c "$CJ" -H 'X-Requested-With: XMLHttpRequest' -o /tmp/i1712_list.json -w '%{http_code}' "$BASE/api/issuetracker/")
res "R7b list API HTTP 200 (got $C)" "$([ "$C" = "200" ]; echo $?)"

echo "== R8 GET /{id}/check-connection (legacy route mirror) =="
q "DELETE FROM events;
  INSERT INTO issuetrackers (name,type,cfg) VALUES ('IT-1712-ROW',3,'<issuetracker><uribase>http://127.0.0.1:1/</uribase><uriwsdl><x/></uriwsdl></issuetracker>');"
ID=$(q "SELECT id FROM issuetrackers WHERE name='IT-1712-ROW' LIMIT 1;")
q "DELETE FROM events;"
C=$(curl -s -b "$CJ" -c "$CJ" -H 'X-Requested-With: XMLHttpRequest' -o /tmp/i1712_chk.json -w '%{http_code}' "$BASE/api/issuetracker/$ID/check-connection")
res "R8 check-connection HTTP 200 (got $C)" "$([ "$C" = "200" ]; echo $?)"
res "R8b response names connected state" "$(grep -q '"connected"' /tmp/i1712_chk.json; echo $?)"

echo "== R9 Event Viewer sweep: no fatal-signature rows =="
N=$(q "SELECT COUNT(*) FROM events WHERE description LIKE '%could not be converted to string%';")
res "R9 zero 'could not be converted to string' rows (got $N)" "$([ "$N" = "0" ]; echo $?)"
N=$(q "SELECT COUNT(*) FROM events WHERE log_level=1 AND description LIKE '%stdClass%';")
res "R9b zero ERROR rows mentioning stdClass (got $N)" "$([ "$N" = "0" ]; echo $?)"

echo "== R10 cleanup =="
q "DELETE FROM issuetrackers WHERE name='IT-1712-ROW'; DELETE FROM events;"
res "R10 fixtures + events removed" "$?"

echo
echo "$PASS PASS / $FAIL FAIL"
[ "$FAIL" -eq 0 ]
