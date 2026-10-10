#!/usr/bin/env bash
# Regression harness for GitHub issue #1735:
#   reqSpecSearch.php count($itemSet) on an uninitialised $itemSet -> HTTP 500.
# The legacy controller was retired by #1825; the modern BFF must serve the
# search with no 500 and the retired URL must redirect (browser) / point away
# (XHR). Self-contained, self-cleaning; exits non-zero on any failure.
#
# Usage:  bash tmp/verify_1735.sh
set -u
BASE="${BASE:-http://localhost:8082}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
JAR="$(mktemp)"
TMPD="$(mktemp -d)"
PASS=0; FAIL=0

ok(){ PASS=$((PASS+1)); echo "PASS: $1"; }
no(){ FAIL=$((FAIL+1)); echo "FAIL: $1"; }
chk(){ if [ "$2" = "$3" ]; then ok "$1 ($2)"; else no "$1 (expected $3, got $2)"; fi; }

cleanup(){ rm -f "$JAR"; rm -rf "$TMPD"; }
trap cleanup EXIT

# --- fixture (idempotent) -------------------------------------------------
php "$ROOT/tmp/fixtures_1735.php" >"$TMPD/fx.log" 2>&1 || { echo "fixture failed"; cat "$TMPD/fx.log"; exit 1; }
TP=$(sed -n 's/.*PROJECT=\([0-9]*\).*/\1/p' "$TMPD/fx.log" | tail -1)
[ -n "$TP" ] || { echo "fixture did not yield a project id"; cat "$TMPD/fx.log"; exit 1; }
echo "fixture project id = $TP"

# --- login ----------------------------------------------------------------
curl -s -c "$JAR" "$BASE/login.php" -o /dev/null
curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/login.php" \
     --data-urlencode "tl_login=admin" --data-urlencode "tl_password=admin" \
     --data-urlencode "login=Login" -o /dev/null
curl -s -b "$JAR" -c "$JAR" "$BASE/index.php?tproject_id=$TP" -o /dev/null

# --- 1. legacy URL: browser navigation redirects, never 500 ---------------
OUT=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE/lib/requirements/reqSpecSearch.php")
CODE=${OUT%%|*}; REDIR=${OUT##*|}
chk "legacy browser nav is not 500" "$CODE" "302"
case "$REDIR" in
  */gui/templates/requirements/searchReqSpec.html*) ok "legacy nav redirects to modern screen ($REDIR)";;
  *) no "legacy nav redirect target wrong ($REDIR)";;
esac

# --- 2. legacy URL: XHR gets a clean JSON pointer, never 500 --------------
CODE=$(curl -s -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' -o "$TMPD/xhr.json" -w '%{http_code}' "$BASE/lib/requirements/reqSpecSearch.php")
chk "legacy XHR is not 500" "$CODE" "405"
grep -q '"code":"retired_endpoint"' "$TMPD/xhr.json" && ok "legacy XHR JSON carries retired_endpoint" || no "legacy XHR JSON missing retired_endpoint"

# --- 3. modern screen loads ----------------------------------------------
CODE=$(curl -s -b "$JAR" -o "$TMPD/screen.html" -w '%{http_code}' "$BASE/gui/templates/requirements/searchReqSpec.html?tproject_id=$TP")
chk "modern screen HTTP" "$CODE" "200"
grep -qi "reqspec-search\|searchReqSpec" "$TMPD/screen.html" && ok "modern screen references the search BFF" || no "modern screen missing BFF reference"

# --- 4. BFF search: unfiltered returns both specs, no 500 -----------------
CODE=$(curl -s -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' -o "$TMPD/s_all.json" -w '%{http_code}' "$BASE/api/requirements/index.php/reqspec-search?tproject_id=$TP")
chk "BFF unfiltered HTTP" "$CODE" "200"
grep -q '"count":2' "$TMPD/s_all.json" && ok "BFF unfiltered returns count=2" || no "BFF unfiltered count wrong: $(cat "$TMPD/s_all.json")"

# --- 5. BFF search: matching criterion -----------------------------------
CODE=$(curl -s -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' -o "$TMPD/s_hit.json" -w '%{http_code}' "$BASE/api/requirements/index.php/reqspec-search?tproject_id=$TP&doc_id=R1735-A")
chk "BFF filtered HTTP" "$CODE" "200"
grep -q '"count":1' "$TMPD/s_hit.json" && ok "BFF filtered returns count=1" || no "BFF filtered count wrong: $(cat "$TMPD/s_hit.json")"

# --- 6. BFF search: no match -> no_records_found, not 500 ----------------
CODE=$(curl -s -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' -o "$TMPD/s_none.json" -w '%{http_code}' "$BASE/api/requirements/index.php/reqspec-search?tproject_id=$TP&doc_id=zzz-nomatch")
chk "BFF no-match HTTP" "$CODE" "200"
grep -q '"warning":"no_records_found"' "$TMPD/s_none.json" && ok "BFF no-match reports no_records_found" || no "BFF no-match warning wrong: $(cat "$TMPD/s_none.json")"

# --- 7. Event Viewer: no new Error/Warning rows --------------------------
BAD=$(mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);" 2>/dev/null)
chk "no Error/Warning rows in Event Viewer" "$BAD" "0"

# --- 8. static: the retired shim no longer carries the defect ------------
if grep -q 'count($itemSet)' "$ROOT/lib/requirements/reqSpecSearch.php"; then
  no "shim still contains count(\$itemSet)"
else
  ok "shim no longer contains count(\$itemSet)"
fi
if grep -q 'count($itemSet)' "$ROOT/api/requirements/index.php"; then
  no "BFF contains count(\$itemSet)"
else
  ok "BFF does not contain count(\$itemSet)"
fi

echo "-----"
echo "$PASS PASS / $FAIL FAIL"
[ "$FAIL" -eq 0 ]
