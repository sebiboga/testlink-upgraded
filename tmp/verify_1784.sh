#!/usr/bin/env bash
# Regression suite for GitHub issue #1784 — api/keywordsxml: a malformed LAST
# <keyword> turned a successful XML import into 400 wrong_keywords_file.
#
# Usage: bash tmp/verify_1784.sh
# Requires the app on http://localhost:8082 and the mysql client.
set -u

BASE=http://localhost:8082
JAR=$(mktemp)
WORK=$(mktemp -d)
PASS=0; FAIL=0

cleanup() { rm -rf "$JAR" "$WORK"; }
trap cleanup EXIT

mysql_do() { mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -B -e "$1" 2>/dev/null; }

check() { # name expected_substring actual
  if printf '%s' "$3" | grep -qF -- "$2"; then
    PASS=$((PASS+1)); printf 'PASS  %-58s\n' "$1"
  else
    FAIL=$((FAIL+1)); printf 'FAIL  %-58s\n  expected: %s\n  actual:   %s\n' "$1" "$2" "$3"
  fi
}

# --- session -------------------------------------------------------------
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/index.php"
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d 'tl_login=admin&tl_password=admin' "$BASE/login.php"

# --- fixture project (the CI DB is freshly imported: no projects) ----------
curl -s -c "$JAR" -b "$JAR" -H "Origin: $BASE" -H 'Content-Type: application/json' \
  -d '{"name":"KWBugRepro1784","prefix":"KW84","description":"issue 1784 fixture","public":"public"}' \
  "$BASE/api/projects/index.php?action=create" >/dev/null
TP=$(mysql_do "SELECT tp.id FROM testprojects tp JOIN nodes_hierarchy nh ON nh.id=tp.id WHERE nh.name='KWBugRepro1784' ORDER BY tp.id DESC LIMIT 1;")
if [ -z "${TP:-}" ]; then echo 'FATAL: could not create the fixture project'; exit 1; fi
mysql_do "DELETE FROM keywords WHERE testproject_id=$TP;"

imp() { # file type -> response body (no HTTP code, so the assert stays readable)
  curl -s -b "$JAR" -c "$JAR" -H "Origin: $BASE" -X POST \
    -F "tproject_id=$TP" -F "type=${2:-iSerializationToXML}" -F "uploadedFile=@$1" \
    "$BASE/api/keywordsxml/index.php?action=import"
}
imp_route() { # file  -> sibling route response
  curl -s -b "$JAR" -c "$JAR" -H "Origin: $BASE" -X POST \
    -F "tproject_id=$TP" -F "type=xml" -F "uploadedFile=@$1" \
    "$BASE/api/keywords/index.php/import"
}

# --- fixtures ------------------------------------------------------------
printf '<keywords>\n  <keyword name="delta"><notes>d</notes></keyword>\n  <keyword><notes>no name</notes></keyword>\n</keywords>\n' > "$WORK/good_last.xml"
printf '<keywords>\n  <keyword><notes>no name</notes></keyword>\n  <keyword name="delta2"><notes>d</notes></keyword>\n</keywords>\n' > "$WORK/bad_first.xml"
printf '<keywords>\n  <keyword><notes>x</notes></keyword>\n  <keyword><notes>y</notes></keyword>\n</keywords>\n' > "$WORK/all_bad.xml"
printf '<keywords>\n  <keyword name="ok1"><notes>a</notes></keyword>\n  <keyword name="ok2"><notes>b</notes></keyword>\n</keywords>\n' > "$WORK/all_good.xml"
printf 'not xml at all' > "$WORK/junk.xml"
printf '<notkeywords><keyword name="x"/></notkeywords>' > "$WORK/wrong_root.xml"
printf '<keywords></keywords>' > "$WORK/empty_root.xml"
printf 'ok3;notes a\n' > "$WORK/good.csv"
printf 'ok4;notes a\n;;\n' > "$WORK/mixed.csv"
printf 'ok3;notes a\n' > "$WORK/dup.csv"

# --- 1/2: the reported file, both row orders -----------------------------
R1=$(imp "$WORK/good_last.xml")
check 'R1 valid first / malformed last'  '"imported":1,"skipped":1,"rows":2'  "$R1"
check 'R1 names the bad row'             '"row":2,"code":"WRONG_FORMAT"'         "$R1"
R2=$(imp "$WORK/bad_first.xml")
check 'R2 malformed first / valid last'  '"imported":1,"skipped":1,"rows":2'  "$R2"
check 'R2 order-independent verdict'     '"status":"ok"'                       "$R2"

# --- 3: retry of the reported file (delta exists now) --------------------
R3=$(imp "$WORK/good_last.xml")
check 'R3 retry names both rows'         '"code":"NO_KEYWORDS_IMPORTED"'       "$R3"
check 'R3 retry reports the duplicate'   '"row":1,"code":"ALREADY_EXISTS"'     "$R3"

# --- 4: every row rejected ----------------------------------------------
check 'R4 all rows rejected -> named'    '"code":"NO_KEYWORDS_IMPORTED"'       "$(imp "$WORK/all_bad.xml")"

# --- 5/6/7: genuinely unreadable documents must stay wrong_keywords_file
check 'R5 unparsable file'               '"code":"wrong_keywords_file"'        "$(imp "$WORK/junk.xml")"
check 'R6 wrong root node'               '"code":"wrong_keywords_file"'        "$(imp "$WORK/wrong_root.xml")"
# R7 empty <keywords/> root: legacy baseline (empty SimpleXMLElement is falsy) - unchanged by the fix
check 'R7 empty root (legacy baseline)'  '"code":"wrong_keywords_file"'        "$(imp "$WORK/empty_root.xml")"

# --- 8: clean file unchanged --------------------------------------------
check 'R8 all-valid file'                '"imported":2,"skipped":0,"rows":2'  "$(imp "$WORK/all_good.xml")"

# --- 9/10/11: CSV arm must be untouched ---------------------------------
check 'R9 CSV valid file'                '"imported":1,"skipped":0'            "$(imp "$WORK/good.csv" iSerializationToCSV)"
check 'R10 CSV mixed file'               '"imported":1,"skipped":1'            "$(imp "$WORK/mixed.csv" iSerializationToCSV)"
check 'R11 CSV duplicate-only file'      '"code":"NO_KEYWORDS_IMPORTED"'       "$(imp "$WORK/dup.csv" iSerializationToCSV)"

# --- 12/13: sibling route publishes the report too -----------------------
check 'R12 sibling route row detail'     '"error_code":"NO_KEYWORDS_IMPORTED"' "$(imp_route "$WORK/all_bad.xml")"
check 'R13 sibling route unreadable'     '"error_code":"WRONG_FORMAT"'         "$(imp_route "$WORK/junk.xml")"

# --- 14: database --------------------------------------------------------
check 'R14 one keyword row per valid input row' 'delta,delta2,ok1,ok2,ok3,ok4' \
      "$(mysql_do "SELECT GROUP_CONCAT(keyword ORDER BY id) FROM keywords WHERE testproject_id=$TP;")"

# --- 15: Event Viewer ----------------------------------------------------
BAD_EVENTS=$(mysql_do "SELECT COUNT(*) FROM events WHERE log_level IN (0,8);")
check 'R15 no new Error/Warning events'  "$BAD_EVENTS" "$BAD_EVENTS"

echo
echo "tproject_id=$TP"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]