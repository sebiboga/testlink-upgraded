#!/usr/bin/env bash
# Regression suite for GitHub issue #1859 — lib/testcases/tcEdit.php:327
# key(get_last_active_version()) TypeError (HTTP 500, 0 bytes) when opening the
# test case editor for a test case that has NO active version (or an id that
# does not exist). PHP 8 raised the latent key(null) to a fatal.
#
# Prep: php tmp/fixtures_1859.php   (creates tcase 3 = NO active version,
#                                    tcase 6 = active, both in project 1)
# Usage: bash tmp/verify_1859.sh
set -u

BASE=http://localhost:8082
JAR=$(mktemp)
PASS=0; FAIL=0

cleanup() { rm -rf "$JAR"; }
trap cleanup EXIT

mysql_do() { mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -B -e "$1" 2>/dev/null; }

check() { # name expected_http expected_bytes_gte url
  local code bytes
  read -r code bytes < <(curl -s -b "$JAR" -o /tmp/verify1859.out -w '%{http_code} %{size_download}' \
    "$BASE/lib/testcases/$4" 2>/dev/null)
  bytes=$(printf '%s' "$bytes" | tr -dc '0-9')
  if [ "$code" = "$2" ] && [ "$bytes" -ge "$3" ]; then
    PASS=$((PASS+1)); printf 'PASS  %-70s http=%s bytes=%s\n' "$1" "$code" "$bytes"
  else
    FAIL=$((FAIL+1)); printf 'FAIL  %-70s http=%s bytes=%s (want http=%s bytes>=%s)\n' "$1" "$code" "$bytes" "$2" "$3"
  fi
}

contains() { # name needle url
  curl -s -b "$JAR" -o /tmp/verify1859.out "$BASE/lib/testcases/$3" 2>/dev/null
  if grep -qF -- "$2" /tmp/verify1859.out; then
    PASS=$((PASS+1)); printf 'PASS  %-70s (contains "%s")\n' "$1" "$2"
  else
    FAIL=$((FAIL+1)); printf 'FAIL  %-70s (missing "%s")\n' "$1" "$2"
  fi
}

# --- capture log baseline BEFORE the matrix (pre-existing 500s must not fail us)
LOGLINES_BEFORE=$(wc -l < tmp/php_server.log 2>/dev/null || echo 0)
EVENTS_BEFORE=$(mysql_do "SELECT IFNULL(MAX(id),0) FROM events;" | tail -1)

# --- session -------------------------------------------------------------
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/index.php"
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d 'tl_login=admin&tl_password=admin' "$BASE/login.php"

# --- discover fixture ids by name (project ids grow across fixture re-runs) -
ID_NOACT=$(mysql_do "SELECT id FROM nodes_hierarchy WHERE name='TC1859-NOACTIVE' AND node_type_id=3 ORDER BY id DESC LIMIT 1;")
ID_ACT=$(mysql_do "SELECT id FROM nodes_hierarchy WHERE name='TC1859-ACTIVE' AND node_type_id=3 ORDER BY id DESC LIMIT 1;")
if [ -z "$ID_NOACT" ] || [ -z "$ID_ACT" ]; then
  echo 'FATAL: fixture test cases not found (run php tmp/fixtures_1859.php first)'
  exit 2
fi

# --- A: no doAction, test case with NO active version -> clean 200 ---------
check "A. tcEdit.php?tcase_id=$ID_NOACT (no active version) -> 200" 200 0 "tcEdit.php?tcase_id=$ID_NOACT"

# --- B: real edit link, no active version -> edit screen renders ------------
contains "B. tcEdit.php?edit_tc=1&tcase_id=$ID_NOACT renders editor w/ name" 'TC1859-NOACTIVE' "tcEdit.php?edit_tc=1&tcase_id=$ID_NOACT"

# --- C: id that does not exist -> clean 200 ---------------------------------
check 'C. tcEdit.php?tcase_id=999999 (absent id) -> 200' 200 0 'tcEdit.php?tcase_id=999999'

# --- D: control, active test case still fine ---------------------------------
contains "D. tcEdit.php?edit_tc=1&tcase_id=$ID_ACT (active) renders" 'TC1859-ACTIVE' "tcEdit.php?edit_tc=1&tcase_id=$ID_ACT"
check   "D2. tcEdit.php?tcase_id=$ID_ACT (active, no doAction) -> 200" 200 0 "tcEdit.php?tcase_id=$ID_ACT"

# --- E: reach the no-active state through the real workflow -----------------
# (deactivate the version via SQL, emulating what deactivate_this_tcversion
#  does in the DB — the URL handler itself is covered by #1860)
TCV6=$(mysql_do "SELECT tcv.id FROM tcversions tcv JOIN nodes_hierarchy nh ON nh.id=tcv.id JOIN nodes_hierarchy p ON p.id=nh.parent_id WHERE p.name='TC1859-ACTIVE' AND tcv.active=1 ORDER BY tcv.id DESC LIMIT 1;")
mysql_do "UPDATE tcversions SET active=0 WHERE id=$TCV6;"
contains "E. edit after last version deactivated -> renders, no fatal" 'TC1859-ACTIVE' "tcEdit.php?edit_tc=1&tcase_id=$ID_ACT"
check   "E2. no-doAction after deactivate -> 200" 200 0 "tcEdit.php?tcase_id=$ID_ACT"
mysql_do "UPDATE tcversions SET active=1 WHERE id=$TCV6;"

# --- no NEW TypeError entries for tcEdit.php:327 during THIS run -----------
# (LOGLINES_BEFORE was captured before the session login)

LOGNEW=$(tail -n +$((LOGLINES_BEFORE + 1)) tmp/php_server.log 2>/dev/null | grep -c 'tcEdit.php:327.*TypeError\|Uncaught TypeError.*tcEdit.php' || true)
LOGNEW=$(printf '%s' "$LOGNEW" | tr -dc '0-9')
if [ "${LOGNEW:-0}" = "0" ]; then
  PASS=$((PASS+1)); printf 'PASS  %-70s\n' 'F. no NEW TypeError for tcEdit.php:327 added to server log'
else
  FAIL=$((FAIL+1)); printf 'FAIL  %-70s (log gained %s lines)\n' 'F. NEW TypeError for tcEdit.php:327 in server log' "$LOGNEW"
fi
NEWERR=$(mysql_do "SELECT COUNT(*) FROM events WHERE log_level = 1 AND id > $EVENTS_BEFORE;" | tail -1)
NEWWARN=$(mysql_do "SELECT COUNT(*) FROM events WHERE log_level = 2 AND id > $EVENTS_BEFORE;" | tail -1)
WARN_RE=$(mysql_do "SELECT COUNT(*) FROM events WHERE log_level = 2 AND id > $EVENTS_BEFORE AND description LIKE '%Undefined array key%testcase.class.php - Line 5700%';" | tail -1)
# The ONLY allowed warnings are the 4 keys of the #1863 absent-id list() signature
# (matrix item C sends exactly one absent-id request => 4 E_WARNING rows).
if [ "${NEWERR:-0}" = "0" ] && [ "${NEWWARN:-0}" = "4" ] && [ "${WARN_RE:-0}" = "4" ]; then
  PASS=$((PASS+1)); printf 'PASS  %-70s\n' 'G. events clean: 0 errors, only the 4 documented #1863 warnings'
else
  FAIL=$((FAIL+1)); printf 'FAIL  %-70s (errors=%s warnings=%s #1863-sig=%s)\n' 'G. events table not clean' "${NEWERR:-0}" "${NEWWARN:-0}" "${WARN_RE:-0}"
fi

echo
echo "RESULT: PASS=$PASS FAIL=$FAIL"
exit $((FAIL>0))